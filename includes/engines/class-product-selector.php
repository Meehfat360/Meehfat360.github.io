<?php
namespace SSCA\Engines;

defined( 'ABSPATH' ) || exit;

/**
 * Product Selection Engine
 *
 * Each day it fills 5 "slots":
 *   Slot 0 — Bestseller
 *   Slot 1 — High-margin product
 *   Slot 2 — Discounted deal
 *   Slot 3 — Underperforming / low-impression product
 *   Slot 4 — Wildcard / trending / seasonal
 *
 * Rules:
 *   - No product repeated within 30 days (configurable)
 *   - Blacklisted products excluded
 *   - Out-of-stock excluded
 *   - Hidden / draft products excluded
 */
class ProductSelector {

    private ScoringMatrix $matrix;
    private int           $rotation_days;
    private int           $daily_count;

    public function __construct() {
        $this->matrix        = new ScoringMatrix();
        $this->rotation_days = (int) get_option( 'ssca_rotation_days', 30 );
        $this->daily_count   = (int) get_option( 'ssca_daily_products', 5 );
    }

    /**
     * Select today's products. Returns array of product IDs indexed by slot name.
     *
     * @return array<string, int>  e.g. ['bestseller' => 42, 'margin' => 17, ...]
     */
    public function select_daily_products(): array {
        $excluded    = \SSCA\DB\Repository::get_recently_posted_product_ids( $this->rotation_days );
        $season      = ( new SeasonalEngine() )->get_current_theme();
        $all_ids     = $this->get_all_eligible_product_ids( $excluded );

        if ( empty( $all_ids ) ) {
            // If rotation window means nothing is available, reset and try again
            \SSCA\Utils\Logger::warning( 'No eligible products. Relaxing rotation window.' );
            $all_ids = $this->get_all_eligible_product_ids( [] );
        }

        if ( empty( $all_ids ) ) return [];

        // Score all eligible products
        $scored = [];
        foreach ( $all_ids as $pid ) {
            $scored[ $pid ] = $this->matrix->score( (int) $pid, $season );
        }
        arsort( $scored ); // highest score first

        $selection = [
            'bestseller'   => $this->pick_bestseller( $scored ),
            'margin'       => $this->pick_high_margin( $scored ),
            'deal'         => $this->pick_deal( $scored ),
            'underperform' => $this->pick_underperformer( $scored ),
            'wildcard'     => $this->pick_wildcard( $scored, $season ),
        ];

        // Remove nulls
        $selection = array_filter( $selection );

        // Ensure no duplicate product IDs across slots
        $seen = [];
        foreach ( $selection as $slot => $pid ) {
            if ( in_array( $pid, $seen, true ) ) {
                $selection[ $slot ] = $this->pick_fallback( $scored, $seen );
            }
            if ( $selection[ $slot ] ) {
                $seen[] = $selection[ $slot ];
            }
        }

        // Mark all as posted
        foreach ( $selection as $slot => $pid ) {
            if ( $pid ) {
                \SSCA\DB\Repository::mark_product_posted( (int) $pid, $slot );
            }
        }

        \SSCA\Utils\Logger::info( 'Product selection complete: ' . wp_json_encode( $selection ) );
        return array_filter( $selection );
    }

    // ── Slot Pickers ──────────────────────────────────────────────────────────

    private function pick_bestseller( array $scored ): ?int {
        global $wpdb;
        $top_ids = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->prefix}woocommerce_order_itemmeta
             WHERE meta_key = '_product_id'
             AND order_item_id IN (
                 SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_items
                 WHERE order_item_type = 'line_item'
                 AND order_id IN (
                     SELECT ID FROM {$wpdb->prefix}posts
                     WHERE post_type = 'shop_order'
                     AND post_status IN ('wc-completed','wc-processing')
                     AND post_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                 )
             )
             GROUP BY meta_value
             ORDER BY COUNT(*) DESC
             LIMIT 20"
        ) ?: [];

        foreach ( $top_ids as $pid ) {
            if ( isset( $scored[ $pid ] ) ) return (int) $pid;
        }
        return $this->highest_in( $scored );
    }

    private function pick_high_margin( array $scored ): ?int {
        // Get products where margin_pct meta is set and high
        global $wpdb;
        $high_margin_ids = $wpdb->get_col(
            "SELECT post_id FROM {$wpdb->prefix}postmeta
             WHERE meta_key = '_ssca_margin_pct'
             AND CAST(meta_value AS DECIMAL) >= 40
             ORDER BY CAST(meta_value AS DECIMAL) DESC
             LIMIT 50"
        ) ?: [];

        foreach ( $high_margin_ids as $pid ) {
            if ( isset( $scored[ $pid ] ) ) return (int) $pid;
        }
        // Fallback: second-highest overall score
        $ids = array_keys( $scored );
        return isset( $ids[1] ) ? (int) $ids[1] : null;
    }

    private function pick_deal( array $scored ): ?int {
        // Find on-sale products with highest discount %
        $on_sale = wc_get_product_ids_on_sale();
        $best    = null;
        $best_pct = 0;

        foreach ( $on_sale as $pid ) {
            if ( ! isset( $scored[ $pid ] ) ) continue;
            $product  = wc_get_product( $pid );
            $regular  = (float) $product->get_regular_price();
            $sale     = (float) $product->get_sale_price();
            if ( $regular <= 0 ) continue;
            $pct = ( ( $regular - $sale ) / $regular ) * 100;
            if ( $pct > $best_pct ) {
                $best_pct = $pct;
                $best     = $pid;
            }
        }

        return $best ? (int) $best : $this->highest_in( array_slice( $scored, 2, 20, true ) );
    }

    private function pick_underperformer( array $scored ): ?int {
        global $wpdb;
        // Products with most posts but fewest clicks
        $underperformers = $wpdb->get_col(
            "SELECT product_id FROM {$wpdb->prefix}ssca_products
             WHERE total_posts > 0
             ORDER BY (total_clicks / total_posts) ASC
             LIMIT 20"
        ) ?: [];

        foreach ( $underperformers as $pid ) {
            if ( isset( $scored[ $pid ] ) ) return (int) $pid;
        }

        // Also pick products with 0 posts (never shown)
        $never_shown = $wpdb->get_col(
            "SELECT p.ID FROM {$wpdb->prefix}posts p
             LEFT JOIN {$wpdb->prefix}ssca_products sp ON p.ID = sp.product_id
             WHERE p.post_type = 'product'
             AND p.post_status = 'publish'
             AND sp.product_id IS NULL
             LIMIT 20"
        ) ?: [];

        foreach ( $never_shown as $pid ) {
            if ( isset( $scored[ $pid ] ) ) return (int) $pid;
        }

        return null;
    }

    private function pick_wildcard( array $scored, string $season ): ?int {
        if ( $season !== 'default' ) {
            // Pick top seasonal match
            $seasonal_ids = array_keys( $scored );
            foreach ( $seasonal_ids as $pid ) {
                $product      = wc_get_product( $pid );
                $season_score = ( new ScoringMatrix() )->score( (int) $pid, $season );
                if ( $season_score >= 70 ) return (int) $pid;
            }
        }

        // Random from top 30%
        $top = array_slice( array_keys( $scored ), 0, max( 1, (int) ( count( $scored ) * 0.3 ) ), true );
        return $top ? (int) $top[ array_rand( $top ) ] : null;
    }

    private function pick_fallback( array $scored, array $exclude ): ?int {
        foreach ( array_keys( $scored ) as $pid ) {
            if ( ! in_array( (int) $pid, $exclude, true ) ) return (int) $pid;
        }
        return null;
    }

    private function highest_in( array $scored ): ?int {
        if ( empty( $scored ) ) return null;
        return (int) array_key_first( $scored );
    }

    // ── Eligible Product IDs ──────────────────────────────────────────────────

    private function get_all_eligible_product_ids( array $excluded ): array {
        $args = [
            'status'         => 'publish',
            'limit'          => -1,
            'return'         => 'ids',
            'stock_status'   => 'instock',
            'visibility'     => 'visible',
        ];

        if ( ! empty( $excluded ) ) {
            $args['exclude'] = array_map( 'intval', $excluded );
        }

        // Also exclude blacklisted products
        $blacklisted = $this->get_blacklisted_ids();
        if ( ! empty( $blacklisted ) ) {
            $args['exclude'] = array_unique(
                array_merge( $args['exclude'] ?? [], $blacklisted )
            );
        }

        return wc_get_products( $args ) ?: [];
    }

    private function get_blacklisted_ids(): array {
        global $wpdb;
        return array_map( 'intval', $wpdb->get_col(
            "SELECT product_id FROM {$wpdb->prefix}ssca_products WHERE is_blacklisted = 1"
        ) ?: [] );
    }
}
