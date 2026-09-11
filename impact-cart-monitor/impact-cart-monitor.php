<?php
/**
 * Plugin Name: Impact Cart Quantity Monitor
 * Description: Detects cart item quantity updates adhering to WooCommerce lifecycle, handling missing SKUs and multiple-item changes.
 * Version:     1.0.0
 * Author:      Anastasiia Boryslavska
 * Text Domain: impact-cart-monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Prevent direct access
}

class Impact_Cart_Monitor {

    private static $instance = null;
    private $session_key = 'impact_last_cart_change';

    /**
     * In-memory buffer of quantity changes detected during the current request.
     * Committed to WC()->session only after WooCommerce confirms the cart update.
     *
     * @var array
     */
    private $staged_changes = array();

    public static function get_instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Stage quantity changes during WooCommerce's cart calculation loop (Classic Cart)
        add_action( 'woocommerce_after_cart_item_quantity_update', array( $this, 'track_quantity_change' ), 10, 4 );

        // Commit staged changes only when WooCommerce confirms the cart update action succeeded.
        // NOTE: woocommerce_update_cart_action_cart_updated is an apply_filters hook in class-wc-form-handler.php:889!
        // We must use add_filter and return $cart_updated to avoid breaking WooCommerce's calculation of totals
        // and its 'Cart updated.' notice.
        add_filter( 'woocommerce_update_cart_action_cart_updated', array( $this, 'commit_staged_changes_classic' ), 10, 1 );

        // Support for Store API / Block Cart updates:
        // Hook into rest_request_after_callbacks to commit staged changes only when a Store API cart update request succeeds.
        add_filter( 'rest_request_after_callbacks', array( $this, 'commit_staged_changes_store_api' ), 10, 3 );

        // Enqueue frontend scripts and styles cleanly via WordPress APIs on cart page only
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // Dedicated AJAX endpoint for client to retrieve and acknowledge confirmed changes.
        // Single source of truth: the session is only consumed here, which avoids
        // race conditions where WooCommerce's own fragment refreshes could
        // read-and-clear the payload before the client has a chance to display it.
        add_action( 'wc_ajax_impact_get_last_change', array( $this, 'ajax_get_and_clear_change' ) );
    }

    /**
     * Records validated quantity changes during WooCommerce's cart calculation cycle.
     *
     * @param string  $cart_item_key Unique cart item hash.
     * @param int     $quantity      New requested quantity.
     * @param int     $old_quantity  Prior quantity before update.
     * @param WC_Cart $cart          WooCommerce cart instance.
     */
    public function track_quantity_change( $cart_item_key, $quantity, $old_quantity, $cart ) {
        // Ignore unchanged / no-op submissions
        if ( (int) $quantity === (int) $old_quantity ) {
            return;
        }

        $cart_item = $cart->get_cart_item( $cart_item_key );
        if ( ! $cart_item || ! isset( $cart_item['data'] ) ) {
            return;
        }

        /** @var WC_Product $product */
        $product = $cart_item['data'];
        $sku     = $product->get_sku();
        $has_sku = ( '' !== trim( (string) $sku ) );

        // Format message: fallback for missing or whitespace-only SKU vs standard SKU presentation
        if ( ! $has_sku ) {
            $message = sprintf( 'You just changed the quantity of this product to %d', $quantity );
        } else {
            $message = sprintf( 'You just changed the quantity of product (SKU: %s) to %d', $sku, $quantity );
        }

        $change_payload = array(
            'cart_item_key' => $cart_item_key,
            'product_id'    => $product->get_id(),
            'product_name'  => $product->get_name(),
            'sku'           => $sku,
            'has_sku'       => $has_sku,
            'old_qty'       => (int) $old_quantity,
            'new_qty'       => (int) $quantity,
            'message'       => $message,
            'timestamp'     => microtime( true ),
        );

        // Stage into memory buffer; do NOT write to session yet.
        // It will be committed only if WooCommerce confirms the cart update action.
        $this->staged_changes[] = $change_payload;
    }

    /**
     * Commits staged quantity changes to WooCommerce session when cart update action succeeds.
     *
     * @param bool $cart_updated True if cart was updated by WooCommerce cart action.
     * @return bool Must return $cart_updated to preserve WooCommerce calculation of totals.
     */
    public function commit_staged_changes_classic( $cart_updated ) {
        // Nonce already verified by WC_Form_Handler::update_cart_action() before this filter fires.
        // If update failed, no changes staged, or customer clicked "Proceed to checkout"
        // (class-wc-form-handler.php:895 immediately redirects to checkout), discard staged changes
        // so an un-drained session change cannot be stranded and trigger on a later cart visit.
        if ( ! $cart_updated || empty( $this->staged_changes )
            || ! empty( $_POST['proceed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $this->staged_changes = array();
            return $cart_updated;
        }

        $this->save_staged_to_session();
        return $cart_updated;
    }

    /**
     * Commits staged quantity changes for Store API / Block Cart updates upon request completion.
     * Strictly scopes commitment to '/wc/store/v1/cart/update-item' (the specific route invoked
     * by Block Cart quantity modifications). All other cart routes (such as '/wc/store/v1/cart/add-item',
     * which also internally invokes set_quantity() for items already in the cart) explicitly discard
     * staged changes, preventing false-positive alerts when a shopper navigates to /cart/.
     *
     * @param WP_REST_Response|WP_Error $response The response from the REST route callback.
     * @param array                     $handler  The handler that handled the request.
     * @param WP_REST_Request           $request  The REST request instance.
     * @return WP_REST_Response|WP_Error Unmodified response.
     */
    public function commit_staged_changes_store_api( $response, $handler, $request ) {
        if ( '/wc/store/v1/cart/update-item' === $request->get_route()
            && ! is_wp_error( $response )
            && ! empty( $this->staged_changes ) ) {
            $this->save_staged_to_session();
        } else {
            // Discard on all other cart routes (e.g., add-item, remove-item, coupons)
            $this->staged_changes = array();
        }
        return $response;
    }

    /**
     * Persists staged changes into WC session with freshness timestamp.
     */
    private function save_staged_to_session() {
        if ( ! WC()->session ) {
            return;
        }

        $existing_changes = WC()->session->get( $this->session_key, array() );
        if ( ! is_array( $existing_changes ) ) {
            $existing_changes = array();
        }

        $existing_changes       = array_merge( $existing_changes, $this->staged_changes );
        $this->staged_changes   = array();

        WC()->session->set( $this->session_key, $existing_changes );
    }

    /**
     * Enqueues frontend assets without inline scripts in templates.
     */
    public function enqueue_assets() {
        // Enqueue only on cart page where quantity changes occur.
        // Classic checkout has no quantity inputs, avoiding useless script runs.
        if ( ! is_cart() ) {
            return;
        }

        wp_enqueue_style(
            'impact-cart-monitor-css',
            plugins_url( 'assets/css/cart-monitor.css', __FILE__ ),
            array(),
            '1.0.0'
        );

        // Conditionally declare wp-data dependency if Block Cart is present, ensuring
        // proper script ordering without pulling @wordpress/data onto classic-only carts.
        $deps = array( 'jquery' );
        if ( function_exists( 'has_block' ) && has_block( 'woocommerce/cart' ) ) {
            $deps[] = 'wp-data';
        }

        wp_enqueue_script(
            'impact-cart-monitor-js',
            plugins_url( 'assets/js/cart-monitor.js', __FILE__ ),
            $deps,
            '1.0.0',
            true // Load in footer
        );

        // Provide localized data: AJAX endpoint URL and security nonce
        wp_localize_script(
            'impact-cart-monitor-js',
            'impactCartMonitorData',
            array(
                'ajaxUrl' => WC_AJAX::get_endpoint( 'impact_get_last_change' ),
                'nonce'   => wp_create_nonce( 'impact_cart_monitor_nonce' ),
            )
        );
    }

    /**
     * Fallback AJAX endpoint to retrieve and acknowledge changes.
     */
    public function ajax_get_and_clear_change() {
        check_ajax_referer( 'impact_cart_monitor_nonce', 'nonce' );

        $client_key = isset( $_POST['last_interacted_key'] ) ? sanitize_text_field( wp_unslash( $_POST['last_interacted_key'] ) ) : '';

        $resolved_change = $this->get_and_clear_most_recent_change( $client_key );

        wp_send_json_success( array(
            'change' => $resolved_change,
        ) );
    }

    /**
     * Resolves the single most recent change from the batch and clears the session state.
     * If the client tracked which item was interacted with last, priority is given to that item.
     * Includes a freshness TTL (15 seconds) to ensure stale session entries are discarded.
     *
     * @param string $client_key Optional cart item key or product identifier that user edited last.
     * @return array|null The most recent verified change payload, or null if empty/expired.
     */
    private function get_and_clear_most_recent_change( $client_key = '' ) {
        if ( ! WC()->session ) {
            return null;
        }

        $changes = WC()->session->get( $this->session_key, array() );

        // Always clear the session key immediately so subsequent calls or page reloads remain silent
        WC()->session->__unset( $this->session_key );

        if ( empty( $changes ) || ! is_array( $changes ) ) {
            return null;
        }

        // Freshness check: discard changes older than 15 seconds (prevents stale alerts across navigations)
        $now = microtime( true );
        $changes = array_values( array_filter( $changes, function( $item ) use ( $now ) {
            return isset( $item['timestamp'] ) && ( $now - (float) $item['timestamp'] ) <= 15.0;
        } ) );

        if ( empty( $changes ) ) {
            return null;
        }

        $most_recent = null;

        // If client reported the last interacted item, see if it is in the confirmed changes
        if ( ! empty( $client_key ) ) {
            foreach ( $changes as $change ) {
                if ( isset( $change['cart_item_key'] ) && $change['cart_item_key'] === $client_key ) {
                    if ( ! $most_recent || $change['timestamp'] > $most_recent['timestamp'] ) {
                        $most_recent = $change;
                    }
                }
            }
        }

        // Fallback: sort descending by timestamp so latest updated item is picked
        if ( ! $most_recent ) {
            usort( $changes, function( $a, $b ) {
                return ( $b['timestamp'] <=> $a['timestamp'] );
            } );
            $most_recent = $changes[0];
        }

        return $most_recent;
    }
}

// Bootstrap plugin once WooCommerce is active
add_action( 'plugins_loaded', function() {
    if ( class_exists( 'WooCommerce' ) ) {
        Impact_Cart_Monitor::get_instance();
    }
} );
