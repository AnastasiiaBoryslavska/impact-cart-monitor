# Impact Cart Quantity Monitor

**Plugin Name:** Impact Cart Quantity Monitor (`impact-cart-monitor`)

---

## 1. Project Overview & Architecture

This repository contains the production-ready submission for the impact.com Technical Services Engineer assessment:

1. **Source Code (`impact-cart-monitor/`):** A custom WordPress/WooCommerce plugin that detects cart item quantity changes and immediately alerts the shopper, adhering strictly to WooCommerce's internal calculation lifecycle and edge-case requirements across both Classic Cart and Block Cart environments.
2. **Technical Documentation (`README.md`):** Deep dive into the event-driven architecture, exact hook rationale, line-accurate code citations, testing protocol, and troubleshooting playbooks.

### Key Architectural Pillars

- **Server-Side Authority (Single Source of Truth):** The server decides whether a change occurred, whether it was a no-op, and what message to display. The browser triggers verification but never dictates business logic.
- **Two-Stage Confirmation Pattern:** Changes are staged in an in-memory buffer via `woocommerce_after_cart_item_quantity_update`, and committed to `WC()->session` only when WooCommerce confirms the cart update action: via `woocommerce_update_cart_action_cart_updated` for Classic Cart, or via `rest_request_after_callbacks` strictly scoped to `/wc/store/v1/cart/update-item` for Block Cart. All other cart routes—including `/wc/store/v1/cart/add-item`—explicitly discard staged changes. This provides literal _"after WooCommerce confirms"_ semantics and prevents false positives from `WC_Cart::add_to_cart()` on both classic and block stores.
- **Atomic Read-and-Clear with Freshness TTL:** The dedicated endpoint `wc_ajax_impact_get_last_change` retrieves the change, enforces a 15-second freshness TTL, and atomically unsets the session state (`WC()->session->__unset()`), guaranteeing that subsequent page refreshes (`Cmd + R`) remain completely silent.
- **Client-Reported Recency with Timestamp Fallback:** When multiple items are updated in batch, the client captures the most recently interacted input key in `sessionStorage` and sends it to the server. If DOM inputs are restructured or omitted, the server degrades gracefully by sorting changes descending by microsecond timestamp.

---

## 2. Plugin File Structure

```text
impact-cart-monitor/
├── impact-cart-monitor.php     # Bootstrap, lifecycle hooks, staging buffer, session handler, & AJAX endpoint
└── assets/
    ├── css/
    │   └── cart-monitor.css    # Accessible, responsive toast notification styling
    └── js/
        └── cart-monitor.js     # Lifecycle event listener, interaction recency tracker, & endpoint client
README.md                       # Architecture, hook justification, verification guide, & TSE troubleshooting runbook
```

---

## 3. How the Implementation Satisfies Every Constraint

| #      | Requirement / Constraint                            | Implementation Mechanism                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               | Exact Code Reference                                                           |
| :----- | :-------------------------------------------------- | :----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | :----------------------------------------------------------------------------- |
| **1a** | **Only trigger after WooCommerce confirms change**  | Two-stage lifecycle: `woocommerce_after_cart_item_quantity_update` stages diffs into `$staged_changes`; `woocommerce_update_cart_action_cart_updated` commits them to session only when `$cart_updated === true` and not proceeding to checkout (using `add_filter` and returning `$cart_updated` to preserve core totals calculation). Store API commits via `rest_request_after_callbacks` strictly scoped to `/wc/store/v1/cart/update-item`. Browser triggers check on debounced core events (`updated_cart_totals` / `updated_wc_div`) or Store API `getItemsPendingQuantityUpdate()` settlement. | `impact-cart-monitor.php:36–55, 112–163`<br>`cart-monitor.js:122–126, 136–163` |
| **1b** | **No trigger on initial page load or page refresh** | Session state is cleared immediately on first read via `WC()->session->__unset()`. A 15-second TTL discards any stale session entries. Plain refreshes (`Cmd + R`) find an empty session and render nothing. Clicking "Proceed to checkout" also discards staged changes so they cannot be stranded.                                                                                                                                                                                                                                                                                                   | `impact-cart-monitor.php:117–120, 236–283`                                     |
| **1c** | **No trigger on unchanged / no-op updates**         | Upstream short-circuit + defense-in-depth: WooCommerce core's form handler (`class-wc-form-handler.php:854`) skips unchanged items before calling `set_quantity()`. Our server guard `if ( (int) $quantity === (int) $old_quantity ) return;` provides defense-in-depth for paths where core's skip does not apply (`WC_Cart::add_to_cart()` and Store API).                                                                                                                                                                                                                                           | `impact-cart-monitor.php:68–71`                                                |
| **2**  | **Missing SKU Edge Case**                           | Evaluates `'' !== trim( (string) $sku )`. If empty or whitespace-only (or numeric string `'0'`), properly discriminates missing vs valid SKU and maintains consistent `$has_sku` in payload. Produces exact prompt-mandated literal: `"You just changed the quantity of this product to X"`. Otherwise outputs `"You just changed the quantity of product (SKU: Y) to X"`.                                                                                                                                                                                                                             | `impact-cart-monitor.php:79–96`                                                |
| **3**  | **Multiple Item Scenario (Most Recent Only)**       | Client captures `cart[<key>]` on `input`/`change` into `sessionStorage`. Server resolves `last_interacted_key` against confirmed changes. If unavailable or DOM changes, falls back to microsecond timestamp sorting (`$b['timestamp'] <=> $a['timestamp']`).                                                                                                                                                                                                                                                                                                                                          | `cart-monitor.js:78–97`<br>`impact-cart-monitor.php:261–279`                   |
| **4**  | **Code Hygiene & Security**                         | Zero inline scripts. Enqueued via `wp_enqueue_script()` and `wp_localize_script()` with nonces verified via `check_ajax_referer()`. Conditionally declares `'wp-data'` script dependency when `has_block('woocommerce/cart')` is true. Toast message injected via DOM `.text()` (never string-concatenated HTML) to prevent XSS.                                                                                                                                                                                                                                                                       | `impact-cart-monitor.php:173–213, 219`<br>`cart-monitor.js:37–39`              |

---

## 4. Technical Architecture & Data Flow

```text
Shopper modifies quantity in Cart (e.g., Wireless Mouse: 1 -> 2, Mousepad: 1 -> 4)
                        │
                        ▼
       [Client-Side Interaction Tracking]
       - Listens to input/change on cart inputs
       - Extracts cart item key via regex cart[([a-f0-9]{32})]
       - Stores active key in sessionStorage['impact_last_interacted_cart_item']
                        │
                        ▼
       [Shopper clicks "Update cart" -> AJAX POST /?wc-ajax=update_cart]
                        │
                        ▼
       [Stage 1: Calculation Loop - woocommerce_after_cart_item_quantity_update]
       - Fires for each item inside WC_Cart::set_quantity()
       - Defense-in-depth: (int)$quantity === (int)$old_quantity -> abort early
         (WooCommerce core's class-wc-form-handler.php:854 also short-circuits unchanged quantities upstream)
       - Inspects: $product->get_sku() -> checks trim((string)$sku) -> applies required format
       - Stages into in-memory array: $this->staged_changes[] (NOT session yet)
                        │
                        ▼
       [Stage 2: Confirmation - woocommerce_update_cart_action_cart_updated]
       - Filter hook fires when WooCommerce confirms cart update succeeded ($cart_updated === true)
       - Plugin returns $cart_updated to preserve core totals calculation and 'Cart updated.' notice
       - Commits $this->staged_changes into WC()->session['impact_last_cart_change']
                        │
                        ▼
       [Response Delivery to Browser]
       - WooCommerce updates cart table and triggers core event 'updated_cart_totals'
                        │
                        ▼
       [Client Verification Dispatch - cart-monitor.js]
       - Intercepts 'updated_cart_totals' on $(document.body)
       - Reads lastInteractedKey from sessionStorage and clears storage
       - Dispatches POST to /?wc-ajax=impact_get_last_change with nonce + key
                        │
                        ▼
       [Server Resolution & Atomic Clear - ajax_get_and_clear_change]
       - check_ajax_referer() validates security nonce
       - Atomically unsets WC()->session['impact_last_cart_change']
       - Filters out entries older than 15s freshness TTL
       - Resolves client-interacted item (or falls back to latest timestamp)
       - Returns JSON: { success: true, data: { change: { message: "...", ... } } }
                        │
                        ▼
       [Accessible Toast Rendered in Browser]
       - Builds DOM nodes via jQuery: role="alert", aria-live="assertive"
       - Injects message safely using .text() (XSS-safe)
       - Displays for 6 seconds with manual dismiss option
```

---

## 5. Verification & Live Testing Protocol

| Step   | Action                                                                                       | Expected Behavior                                                                                                                                                                                                                  | Verification Status |
| :----- | :------------------------------------------------------------------------------------------- | :--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | :-----------------: |
| **1**  | Navigate to `/cart/` initially                                                               | Wireless Mouse (SKU: `WM-1001`) and Mousepad (No SKU) in cart. No alert appears on initial visit.                                                                                                                                  |      **PASS**       |
| **2**  | Refresh page (`Cmd + R` / `F5`)                                                              | Page reloads cleanly. Session is empty; zero alerts rendered.                                                                                                                                                                      |      **PASS**       |
| **3**  | Click "Update cart" with unchanged quantities                                                | WooCommerce form handler skips no-ops; server stages nothing. Zero alerts rendered.                                                                                                                                                |      **PASS**       |
| **4**  | Change Wireless Mouse (1 -> 3) -> Click "Update cart"                                        | Toast notification renders: `"You just changed the quantity of product (SKU: WM-1001) to 3"`. Subtotal recalculates and core `"Cart updated."` notice appears.                                                                     |      **PASS**       |
| **5**  | Hard refresh page after update (`Cmd + Shift + R`)                                           | Cart persists new quantity (3). Session was unset on read; zero alerts rendered.                                                                                                                                                   |      **PASS**       |
| **6**  | Change Mousepad (1 -> 5) -> Click "Update cart"                                              | Toast notification renders exact required string: `"You just changed the quantity of this product to 5"`.                                                                                                                          |      **PASS**       |
| **7**  | Add Product A from shop, add A again, then visit `/cart/` (tested on Classic & Block stores) | Staging buffer prevents false positives: `WC_Cart::add_to_cart()` calls `set_quantity()`, but update actions (`update_cart` or `/cart/update-item`) did not run; staged changes are explicitly discarded. Zero alerts on `/cart/`. |      **PASS**       |
| **8**  | Edit Wireless Mouse, then edit Mousepad -> Click "Update cart"                               | Client recency tracker prioritizes Mousepad. Alert displays only the Mousepad change.                                                                                                                                              |      **PASS**       |
| **9**  | Block Cart: Change item quantity in Block Cart                                               | `getItemsPendingQuantityUpdate()` settles; toast notification renders with correct SKU/fallback message.                                                                                                                           |      **PASS**       |
| **10** | Block Cart: Change quantity, then refresh page within 15s                                    | Store API drain: toast rendered on the change; session was consumed and cleared by endpoint. Page refresh displays zero alerts.                                                                                                    |      **PASS**       |
| **11** | Refresh page after any alert                                                                 | Toast disappears. On reload, endpoint returns null; zero duplicate alerts.                                                                                                                                                         |      **PASS**       |

---

## 6. Installation & Deployment

1. Download or clone this repository into your WordPress plugins directory:
   ```bash
   cd wp-content/plugins/
   git clone https://github.com/AnastasiiaBoryslavska/impact-cart-monitor.git
   ```
2. Navigate to **WordPress Admin → Plugins → Installed Plugins**.
3. Locate **Impact Cart Quantity Monitor** and click **Activate**.
4. Navigate to your WooCommerce Cart page (`/cart/`) to test quantity adjustments.
