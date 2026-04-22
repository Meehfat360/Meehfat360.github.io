<?php
namespace SSCA\Engines;

defined( 'ABSPATH' ) || exit;

/**
 * Scoring Matrix — assigns a composite score to every WooCommerce product.
 *
 * Score components (configurable weights):
 *   - Sales velocity        (how many sold in last 30 days)
 *   - Margin score          (profit margin %)
 *   - Stock urgency         (overstock or low-stock alert)
 *   - Discount depth        (current sale % off)
 *   - Recency penalty       (reduce score if posted recently)
 *   - Impression deficit    (boost products never/rarely promoted)
 *   - Seasonal relevance    (season theme match)
 *   - Performance history   (CTR and CVR from SSCA data)
 */
class ScoringMatrix {

    private array $weights;

    public function __construct() {
        $saved = get_option( 'ssca_scoring_weights', [] );
        $this->weights = array_merge( [
            'sales_velocity'     => 25,
            'margin'             => 20,
            'stock_urgency'      => 15,
            'discount_depth'     => 15,
            'impression_deficit' => 10,
            'seasonal_relevance' => 10,
            'performance_history'=> 5,
        ], $saved );
    }

    /**
     * Score a single product. Returns float 0–100.
     */
    public function score( int $product_id, string $season_theme = '' ): float {
        $product = wc_get_product( $product_id );
        if ( ! $product || ! $product->is_purchasable() || ! $product->is_visible() ) {
            return 0.0;
        }

        $record = \SSCA\DB\Repository::get_product_record( $product_id );

        $scores = [
            'sales_velocity'      => $this->score_sales_velocity( $product ),
            'margin'              => $this->score_margin( $product ),
            'stock_urgency'       => $this->score_stock_urgency( $product ),
            'discount_depth'      => $this->score_discount( $product ),
            'impression_deficit'  => $this->score_impression_deficit( $record ),
            'seasonal_relevance'  => $this->score_seasonal( $product, $season_theme ),
            'performance_history' => $this->score_performance_history( $record ),
        ];

        $total_weight = array_sum( $this->weights );
        $weighted_sum = 0.0;
        foreach ( $scores as $key => $raw ) {
            $weighted_sum += ( $raw * ( $this->weights[ $key ] ?? 0 ) );
        }

        return $total_weight > 0 ? round( $weighted_sum / $total_weight, 2 ) : 0.0;
    }

    // ── Component Scorers ─────────────────────────────────────────────────────

    /**
     * Sales velocity: units sold in past 30 days, normalised 0–100.
     */
    private function score_sales_velocity( \WC_Product $product ): float {
        global $wpdb;
        $sold = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(oim.meta_value)
             FROM {$wpdb->prefix}woocommerce_order_items oi
             JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
             JOIN {$wpdb->prefix}posts p ON oi.order_id = p.ID
             WHERE oim.meta_key = '_qty'
             AND oi.order_item_type = 'line_item'
             AND p.post_status IN ('wc-completed','wc-processing')
             AND p.post_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             AND oi.order_item_id IN (
                 SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_itemmeta
                 WHERE meta_key = '_product_id' AND meta_value = %d
             )",
            $product->get_id()
        ) );
        return min( 100, $sold * 5 ); // 20+ sales = 100
    }

    /**
     * Margin: use _ssca_margin meta if set, else estimate from price.
     */
    private function score_margin( \WC_Product $product ): float {
        $margin_pct = (float) $product->get_meta( '_ssca_margin_pct' );
        if ( $margin_pct <= 0 ) {
            $cost  = (float) $product->get_meta( '_wc_cog_cost' ); // WC Cost of Goods plugin
            $price = (float) $product->get_price();
            if ( $cost > 0 && $price > 0 ) {
                $margin_pct = ( ( $price - $cost ) / $price ) * 100;
            }
        }
        if ( $margin_pct <= 0 ) return 50.0; // neutral if unknown
        return min( 100, $margin_pct ); // 100% margin = 100
    }

    /**
     * Stock urgency: overstocked (>100 units) OR low stock (<5) both score high.
     */
    private function score_stock_urgency( \WC_Product $product ): float {
        if ( ! $product->managing_stock() ) return 30.0;
        $qty = $product->get_stock_quantity();
        if ( $qty === null ) return 30.0;

        $overstock_threshold = (int) get_option( 'ssca_overstock_threshold', 100 );
        $lowstock_threshold  = (int) get_option( 'ssca_lowstock_threshold', 5 );

        if ( $qty >= $overstock_threshold ) return 90.0; // Promote to move stock
        if ( $qty <= $lowstock_threshold && $qty > 0 ) return 85.0; // Scarcity urgency
        if ( $qty <= 0 ) return 0.0; // Out of stock — never promote

        // Normalise: 0–99 maps to 20–70
        return 20 + min( 50, ( $qty / $overstock_threshold ) * 50 );
    }

    /**
     * Discount depth: % off regular price, capped at 100.
     */
    private function score_discount( \WC_Product $product ): float {
        if ( ! $product->is_on_sale() ) return 0.0;
        $regular = (float) $product->get_regular_price();
        $sale    = (float) $product->get_sale_price();
        if ( $regular <= 0 ) return 0.0;
        $pct = ( ( $regular - $sale ) / $regular ) * 100;
        return min( 100, $pct * 1.5 ); // Amplify discount signal
    }

    /**
     * Impression deficit: products never (or rarely) promoted get a boost.
     */
    private function score_impression_deficit( ?array $record ): float {
        if ( ! $record ) return 100.0; // Never promoted = max boost
        $posts = (int) ( $record['total_posts'] ?? 0 );
        if ( $posts === 0 ) return 100.0;
        if ( $posts < 3 )  return 80.0;
        if ( $posts < 10 ) return 50.0;
        return 20.0;
    }

    /**
     * Seasonal relevance: bump if product matches current season tags.
     */
    private function score_seasonal( \WC_Product $product, string $season_theme ): float {
        if ( empty( $season_theme ) || $season_theme === 'default' ) return 50.0;

        $seasonal_map = ( new SeasonalEngine() )->get_product_keyword_map();
        $keywords     = $seasonal_map[ $season_theme ] ?? [];
        if ( empty( $keywords ) ) return 50.0;

        $product_text = strtolower(
            $product->get_name() . ' ' . $product->get_short_description()
            . ' ' . implode( ' ', wp_get_post_terms( $product->get_id(), 'product_tag', [ 'fields' => 'names' ] ) )
        );

        $match_count = 0;
        foreach ( $keywords as $kw ) {
            if ( str_contains( $product_text, strtolower( $kw ) ) ) $match_count++;
        }

        return $match_count > 0 ? min( 100, 50 + ( $match_count * 15 ) ) : 30.0;
    }

    /**
     * Performance history: based on SSCA CTR and CVR.
     */
    private function score_performance_history( ?array $record ): float {
        if ( ! $record ) return 50.0;
        $posts  = (int) ( $record['total_posts']  ?? 0 );
        $clicks = (int) ( $record['total_clicks'] ?? 0 );
        $orders = (int) ( $record['total_orders'] ?? 0 );

        if ( $posts === 0 ) return 50.0;

        $ctr = $posts > 0 ? $clicks / $posts : 0;
        $cvr = $clicks > 0 ? $orders / $clicks : 0;

        $ctr_score = min( 100, $ctr * 200 );   // 0.5 CTR = 100
        $cvr_score = min( 100, $cvr * 1000 );  // 0.1 CVR = 100

        return ( $ctr_score + $cvr_score ) / 2;
    }
}
