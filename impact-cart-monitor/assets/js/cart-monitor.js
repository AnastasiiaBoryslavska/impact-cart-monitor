/**
 * Impact Cart Quantity Monitor - Client Script
 *
 * Handles WooCommerce cart lifecycle events with resilient fallbacks.
 * Degrades gracefully if theme markup or DOM selectors change.
 */
(function ($) {
  "use strict";

  if (typeof $ === "undefined") {
    return;
  }

  /**
   * Renders an accessible, non-blocking toast alert notification.
   *
   * @param {Object} data Verified change payload from WooCommerce session.
   */
  function displayCartAlert(data) {
    if (!data || !data.message) {
      return;
    }

    // Remove any active toast before rendering new one
    $(".impact-cart-toast").remove();

    // Build nodes via DOM APIs and inject text with .text() so merchant-supplied
    // product names / SKUs can never be interpreted as HTML (XSS-safe rendering).
    var $toast = $("<div>", {
      class: "impact-cart-toast",
      role: "alert",
      "aria-live": "assertive",
    });

    $("<span>", { class: "impact-cart-toast-icon" }).text("✓").appendTo($toast);

    $("<span>", { class: "impact-cart-toast-text" })
      .text(data.message)
      .appendTo($toast);

    $("<button>", {
      type: "button",
      class: "impact-cart-toast-close",
      "aria-label": "Close",
    })
      .html("&times;")
      .appendTo($toast);

    $("body").append($toast);

    setTimeout(function () {
      $toast.addClass("impact-cart-toast-visible");
    }, 50);

    var dismissTimer = setTimeout(function () {
      closeToast($toast);
    }, 6000);

    $toast.find(".impact-cart-toast-close").on("click", function () {
      clearTimeout(dismissTimer);
      closeToast($toast);
    });
  }

  function closeToast($toast) {
    $toast.removeClass("impact-cart-toast-visible");
    setTimeout(function () {
      $toast.remove();
    }, 400);
  }

  // Session storage key to persist user interaction across full-page POST reloads
  var INTERACTION_STORAGE_KEY = "impact_last_interacted_cart_item";

  // Track the most recently edited cart item directly from shopper interactions.
  // Resilient selector: matches standard number inputs and .qty inputs, extracting the cart key.
  // If DOM is overhauled, this degrades gracefully and falls back to server-side timestamp sorting.
  $(document).on("input change", "input[type='number'], .qty", function () {
    var nameAttr = $(this).attr("name") || "";
    // Matches standard WooCommerce cart input naming: cart[<cart_item_key>][qty]
    var match = nameAttr.match(/cart\[([a-f0-9]{32})\]/i);
    var key = match ? match[1] : "";

    if (!key) {
      // Fallback: look for data-cart-item-key on parent rows or inputs
      key =
        $(this).data("cart-item-key") ||
        $(this).closest("[data-cart-item-key]").data("cart-item-key") ||
        "";
    }

    if (key) {
      try {
        sessionStorage.setItem(INTERACTION_STORAGE_KEY, key);
      } catch (e) {}
    }
  });

  // Check on initial page load in case of non-AJAX cart update redirect (standard POST)
  $(function () {
    requestCheck();
  });

  // State guards:
  // - isChecking: true while an endpoint AJAX request is in-flight
  // - recheckQueued: true if a new settlement arrives while a check is already in-flight
  // - debounceTimer: collapses paired classic events (updated_cart_totals + updated_wc_div) into one check
  var isChecking = false;
  var recheckQueued = false;
  var debounceTimer = null;

  function requestCheck() {
    if (isChecking) {
      recheckQueued = true;
      return;
    }
    checkLastChangeEndpoint();
  }

  // Classic Cart: WooCommerce core fires updated_cart_totals and updated_wc_div in close succession
  // (cart.js:166 then :169). We debounce these paired events by 50ms into a single check.
  $(document.body).on("updated_cart_totals updated_wc_div", function () {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(requestCheck, 50);
  });

  // Block Cart: Intercept Store API updates once quantity mutation finishes.
  // We monitor getItemsPendingQuantityUpdate() on the wc/store/cart data store.
  // In WooCommerce cart store (packages/public-api/block-data/cart/thunks.ts), itemIsPendingQuantity(cartItemKey)
  // is set to true during apiFetch, and cleared to false in finally{} after receiveCart() finishes.
  // Transitioning from pending to not pending guarantees the Store API request has settled and the server
  // has already committed staged changes to session before we query our endpoint.
  // Each settle is a distinct change, so we invoke requestCheck() directly.
  if (
    $(".wc-block-cart").length &&
    window.wp &&
    wp.data &&
    typeof wp.data.subscribe === "function"
  ) {
    var wasPending = false;

    wp.data.subscribe(function () {
      var cartSelect = wp.data.select("wc/store/cart");
      if (
        !cartSelect ||
        typeof cartSelect.getItemsPendingQuantityUpdate !== "function"
      ) {
        return;
      }

      var pendingItems = cartSelect.getItemsPendingQuantityUpdate();
      var isPending = Array.isArray(pendingItems) && pendingItems.length > 0;

      // Transition from pending -> settled: Store API quantity mutation confirmed
      if (wasPending && !isPending) {
        requestCheck();
      }

      wasPending = isPending;
    });
  }

  /**
   * Queries the dedicated AJAX endpoint for any confirmed quantity changes.
   */
  function checkLastChangeEndpoint() {
    if (isChecking) {
      return;
    }

    if (
      typeof impactCartMonitorData !== "undefined" &&
      impactCartMonitorData.ajaxUrl
    ) {
      isChecking = true;

      var lastInteractedKey = "";
      try {
        lastInteractedKey =
          sessionStorage.getItem(INTERACTION_STORAGE_KEY) || "";
        sessionStorage.removeItem(INTERACTION_STORAGE_KEY);
      } catch (e) {}

      $.ajax({
        url: impactCartMonitorData.ajaxUrl,
        type: "POST",
        dataType: "json",
        data: {
          nonce: impactCartMonitorData.nonce,
          last_interacted_key: lastInteractedKey,
        },
        success: function (response) {
          if (
            response &&
            response.success &&
            response.data &&
            response.data.change
          ) {
            displayCartAlert(response.data.change);
          }
        },
        error: function (xhr, status, error) {
          console.error(
            "[Impact Cart Monitor] Failed to verify cart change state:",
            error,
          );
        },
        complete: function () {
          isChecking = false;
          if (recheckQueued) {
            recheckQueued = false;
            checkLastChangeEndpoint();
          }
        },
      });
    }
  }
})(jQuery);
