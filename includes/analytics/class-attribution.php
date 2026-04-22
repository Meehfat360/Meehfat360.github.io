<?php
namespace SSCA\Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * Order Attribution
 *
 * When an order is completed, checks if the customer came via an SSCA social post.
 * Uses last-click attribution via session cookie.
 * Updates product revenue totals.
 */
class Attribution {

    public function __construct() {
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'capture_checkout_attribution' ], 10, 3 );
    }

    /**
     * Capture attribution data at checkout (before order status changes).
     * Stores the attribution cookie data as order meta.
     */
    public function capture_checkout_attribution( int $order_id, array $posted_data, \WC_Order $order ): void {
        if ( empty( $_COOKIE[ Tracker::ATTRIBUTION_KEY ] ) ) return;

        $data = json_decode( stripslashes( $_COOKIE[ Tracker::ATTRIBUTION_KEY ] ), true );
        if ( ! is_array( $data ) ) return;

        $order->update_meta_data( '_ssca_attribution', wp_json_encode( $data ) );
        $order->save();
    }

    /**
     * Run attribution on order completion.
     * Called via woocommerce_order_status_completed hook.
     */
    public function attribute_order( int $order_id ): void {
        // Avoid double attribution
        if ( \SSCA\DB\Repository::get_order_attribution( $order_id ) ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $attribution_raw = $order->get_meta( '_ssca_attribution' );
        if ( empty( $attribution_raw ) ) {
            // Fallback: check UTM params stored in session (order meta set by WC)
            $this->try_utm_attribution( $order );
            return;
        }

        $attribution = json_decode( $attribution_raw, true );
        if ( ! is_array( $attribution ) || empty( $attribution['post_id'] ) ) return;

        $post_id    = (int) $attribution['post_id'];
        $product_id = (int) ( $attribution['product_id'] ?? 0 );
        $platform   = sanitize_text_field( $attribution['platform'] ?? '' );
        $session_id = sanitize_text_field( $attribution['session_id'] ?? '' );

        // Verify the post exists
        $post = \SSCA\DB\Repository::get_post( $post_id );
        if ( ! $post ) return;

        $order_total = (float) $order->get_total();

        // Record attribution
        \SSCA\DB\Repository::insert_attribution( [
            'order_id'         => $order_id,
            'post_id'          => $post_id,
            'product_id'       => $product_id,
            'platform'         => $platform,
            'order_total'      => $order_total,
            'session_id'       => $session_id,
            'attribution_type' => 'last_click',
            'attributed_at'    => current_time( 'mysql' ),
        ] );

        // Update product revenue stats
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}ssca_products
             SET total_orders  = total_orders + 1,
                 total_revenue = total_revenue + %f
             WHERE product_id = %d",
            $order_total, $product_id
        ) );

        // Update post-level stats
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}ssca_posts SET clicks = clicks + 0 WHERE id = %d", // triggers updated_at
            $post_id
        ) );

        \SSCA\Utils\Logger::info( "Order #{$order_id} attributed to post #{$post_id} (platform: {$platform}, revenue: {$order_total})" );

        do_action( 'ssca_order_attributed', $order_id, $post_id, $product_id, $platform, $order_total );
    }

    /**
     * Fallback: check if order contains UTM params stored by WC.
     */
    private function try_utm_attribution( \WC_Order $order ): void {
        // WooCommerce sometimes stores UTM source in order meta
        $utm_source = $order->get_meta( '_wc_order_attribution_utm_source' );
        if ( ! $utm_source ) return;

        $ssca_pid = $order->get_meta( '_wc_order_attribution_session_entry' );
        if ( $ssca_pid && preg_match( '/ssca_pid=(\d+)/', $ssca_pid, $m ) ) {
            $post_id = (int) $m[1];
            $post    = \SSCA\DB\Repository::get_post( $post_id );
            if ( ! $post ) return;

            \SSCA\DB\Repository::insert_attribution( [
                'order_id'         => $order->get_id(),
                'post_id'          => $post_id,
                'product_id'       => $post['product_id'],
                'platform'         => $post['platform'],
                'order_total'      => (float) $order->get_total(),
                'attribution_type' => 'utm_fallback',
                'attributed_at'    => current_time( 'mysql' ),
            ] );
        }
    }

    /**
     * Get attribution summary for a date range.
     */
    public static function get_summary( string $from, string $to ): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.platform,
                    COUNT(a.id)       AS attributed_orders,
                    SUM(a.order_total) AS attributed_revenue,
                    COUNT(DISTINCT a.product_id) AS unique_products
             FROM {$wpdb->prefix}ssca_attribution a
             WHERE a.attributed_at BETWEEN %s AND %s
             GROUP BY a.platform
             ORDER BY attributed_revenue DESC",
            $from, $to
        ), ARRAY_A );

        return $rows ?: [];
    }
}
