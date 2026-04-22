<?php
namespace SSCA\Engines;

defined( 'ABSPATH' ) || exit;

/**
 * Winning Product Detector
 *
 * Continuously learns which products generate:
 *  - Most clicks
 *  - Most add-to-carts
 *  - Most orders and revenue
 *
 * Scores are updated after every A/B evaluation and daily.
 * High-scoring products get boosted in the selection matrix.
 */
class WinningProductDetector {

    public function __construct() {
        add_action( 'ssca_order_attributed',    [ $this, 'on_order_attributed'    ], 10, 5 );
        add_action( 'ssca_ab_test_completed',   [ $this, 'on_ab_test_completed'   ], 10, 3 );
        add_action( 'ssca_daily_workflow',      [ $this, 'refresh_product_scores' ], 5   );
    }

    /**
     * Refresh performance scores for all tracked products.
     * Runs at start of each daily workflow.
     */
    public function refresh_product_scores(): void {
        global $wpdb;

        $products = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}ssca_products WHERE total_posts > 0",
            ARRAY_A
        ) ?: [];

        foreach ( $products as $record ) {
            $score = $this->compute_performance_score( $record );
            \SSCA\DB\Repository::update_product_score( (int) $record['product_id'], $score );
        }
    }

    private function compute_performance_score( array $record ): float {
        $posts   = max( 1, (int) $record['total_posts'] );
        $clicks  = (int) $record['total_clicks'];
        $atc     = (int) $record['total_atc'];
        $orders  = (int) $record['total_orders'];
        $revenue = (float) $record['total_revenue'];

        $ctr     = $clicks / $posts;          // clicks per post
        $atc_rate= $clicks > 0 ? $atc / $clicks : 0;
        $cvr     = $clicks > 0 ? $orders / $clicks : 0;
        $rpc     = $posts > 0 ? $revenue / $posts : 0;  // Revenue per post

        // Weighted composite
        $score = 0;
        $score += min( 30, $ctr * 60 );       // CTR weight 30% (0.5 CTR = max)
        $score += min( 20, $atc_rate * 200 ); // ATC rate weight 20% (0.1 = max)
        $score += min( 30, $cvr * 300 );      // Conversion weight 30% (0.1 = max)
        $score += min( 20, $rpc / 10 );       // Revenue weight 20% ($200/post = max)

        return round( $score, 2 );
    }

    public function on_order_attributed( int $order_id, int $post_id, int $product_id, string $platform, float $revenue ): void {
        // Refresh score for this specific product
        $record = \SSCA\DB\Repository::get_product_record( $product_id );
        if ( $record ) {
            $score = $this->compute_performance_score( $record );
            \SSCA\DB\Repository::update_product_score( $product_id, $score );
        }
    }

    public function on_ab_test_completed( int $test_id, ?string $winner, array $test ): void {
        $this->refresh_product_scores();
    }

    /**
     * Get top-performing products ranked by score.
     */
    public static function get_winners( int $limit = 10 ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT sp.*, p.post_title AS product_name
             FROM {$wpdb->prefix}ssca_products sp
             JOIN {$wpdb->prefix}posts p ON sp.product_id = p.ID
             WHERE sp.total_posts >= 3
             ORDER BY sp.score DESC
             LIMIT %d",
            $limit
        ), ARRAY_A ) ?: [];
    }
}


/**
 * Evergreen Recycler
 *
 * Best-performing posts are automatically recycled after 45–60 days.
 * Only top performers (score above threshold) are recycled.
 */
class EvergreenRecycler {

    const MIN_SCORE_TO_RECYCLE  = 60.0;
    const RECYCLE_AFTER_DAYS    = 45;
    const MAX_RECYCLES_PER_POST = 3;

    public function __construct() {
        add_action( 'ssca_daily_workflow', [ $this, 'check_evergreen_queue' ], 20 );
    }

    /**
     * Check for posts eligible for recycling and queue them.
     */
    public function check_evergreen_queue(): void {
        $recycle_enabled = get_option( 'ssca_evergreen_enabled', '1' );
        if ( $recycle_enabled !== '1' ) return;

        $recycling_after_days = (int) get_option( 'ssca_evergreen_days', self::RECYCLE_AFTER_DAYS );
        $min_score            = (float) get_option( 'ssca_evergreen_min_score', self::MIN_SCORE_TO_RECYCLE );
        $max_recycles         = (int) get_option( 'ssca_evergreen_max_recycles', self::MAX_RECYCLES_PER_POST );

        global $wpdb;

        // Find posts that:
        // 1. Were published at least X days ago
        // 2. Have high click count (top performers)
        // 3. Haven't been recycled too many times
        $candidates = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.*, sp.score
             FROM {$wpdb->prefix}ssca_posts p
             JOIN {$wpdb->prefix}ssca_products sp ON p.product_id = sp.product_id
             WHERE p.status = 'published'
             AND p.published_at <= DATE_SUB(NOW(), INTERVAL %d DAY)
             AND sp.score >= %f
             AND (
                 SELECT COUNT(*) FROM {$wpdb->prefix}ssca_posts p2
                 WHERE p2.product_id = p.product_id
                 AND p2.variant = 'EVERGREEN'
             ) < %d
             ORDER BY p.clicks DESC, sp.score DESC
             LIMIT 3",
            $recycling_after_days, $min_score, $max_recycles
        ), ARRAY_A ) ?: [];

        foreach ( $candidates as $original ) {
            $this->recycle( $original );
        }
    }

    private function recycle( array $original_post ): void {
        // Create a new post record based on the original
        $season     = ( new SeasonalEngine() )->get_current_theme();
        $platforms  = [ $original_post['platform'] ];
        $schedule   = \SSCA\Publishers\Queue::get_schedule();

        $new_post_id = \SSCA\DB\Repository::insert_post( [
            'product_id'   => $original_post['product_id'],
            'platform'     => $original_post['platform'],
            'variant'      => 'EVERGREEN',
            'image_path'   => $original_post['image_path'], // Reuse original image
            'caption'      => $original_post['caption'],     // Reuse original caption
            'scheduled_at' => $schedule[ $original_post['platform'] ][0] ?? current_time( 'mysql' ),
            'status'       => 'scheduled',
            'season_theme' => $season,
        ] );

        as_schedule_single_action(
            strtotime( $schedule[ $original_post['platform'] ][0] ?? 'now' ),
            'ssca_publish_post',
            [ 'post_id' => $new_post_id ],
            'ssca'
        );

        \SSCA\Utils\Logger::info( "Evergreen recycled post #{$original_post['id']} → new post #{$new_post_id}" );
    }
}
