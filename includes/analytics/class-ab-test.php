<?php
namespace SSCA\Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * A/B Test Engine
 *
 * Automatically evaluates running tests after 48+ hours.
 * Determines winners using click-through rate as primary signal,
 * with order conversion rate as tiebreaker.
 */
class ABTest {

    const MIN_HOURS_TO_EVALUATE = 48;
    const MIN_CLICKS_TO_CONCLUDE = 10;

    /**
     * Register an A/B test when a product has both variants published.
     */
    public static function register( int $product_id, string $platform, int $post_id_a, int $post_id_b ): int {
        return \SSCA\DB\Repository::insert_ab_test( [
            'product_id' => $product_id,
            'platform'   => $platform,
            'post_id_a'  => $post_id_a,
            'post_id_b'  => $post_id_b,
            'status'     => 'running',
            'started_at' => current_time( 'mysql' ),
        ] );
    }

    /**
     * Evaluate all running tests. Called daily via Action Scheduler.
     */
    public function evaluate_running_tests(): void {
        $tests = \SSCA\DB\Repository::get_running_ab_tests();
        foreach ( $tests as $test ) {
            $this->evaluate( $test );
        }
    }

    private function evaluate( array $test ): void {
        $started    = strtotime( $test['started_at'] );
        $hours_old  = ( time() - $started ) / HOUR_IN_SECONDS;

        if ( $hours_old < self::MIN_HOURS_TO_EVALUATE ) return; // Too early

        $clicks_a = \SSCA\DB\Repository::get_clicks_for_post( (int) $test['post_id_a'] );
        $clicks_b = \SSCA\DB\Repository::get_clicks_for_post( (int) $test['post_id_b'] );

        // Not enough data yet
        if ( ( $clicks_a + $clicks_b ) < self::MIN_CLICKS_TO_CONCLUDE ) {
            // Give it another 24 hours max
            if ( $hours_old > 168 ) { // 7 days
                \SSCA\DB\Repository::update_ab_test( (int) $test['id'], [
                    'status'       => 'inconclusive',
                    'evaluated_at' => current_time( 'mysql' ),
                ] );
            }
            return;
        }

        // Determine winner
        $orders_a  = $this->get_attributed_orders( (int) $test['post_id_a'] );
        $orders_b  = $this->get_attributed_orders( (int) $test['post_id_b'] );

        // Primary: CTR. Tiebreaker: CVR
        $cvr_a = $clicks_a > 0 ? $orders_a / $clicks_a : 0;
        $cvr_b = $clicks_b > 0 ? $orders_b / $clicks_b : 0;

        $winner_variant = null;
        $winner_post_id = null;

        if ( abs( $clicks_a - $clicks_b ) > ( ( $clicks_a + $clicks_b ) * 0.1 ) ) {
            // 10%+ difference in clicks = clear CTR winner
            if ( $clicks_a > $clicks_b ) {
                $winner_variant = 'A';
                $winner_post_id = $test['post_id_a'];
            } else {
                $winner_variant = 'B';
                $winner_post_id = $test['post_id_b'];
            }
        } elseif ( abs( $cvr_a - $cvr_b ) > 0.01 ) {
            // CVR tiebreaker
            if ( $cvr_a > $cvr_b ) {
                $winner_variant = 'A';
                $winner_post_id = $test['post_id_a'];
            } else {
                $winner_variant = 'B';
                $winner_post_id = $test['post_id_b'];
            }
        }

        $update = [
            'clicks_a'     => $clicks_a,
            'clicks_b'     => $clicks_b,
            'orders_a'     => $orders_a,
            'orders_b'     => $orders_b,
            'evaluated_at' => current_time( 'mysql' ),
        ];

        if ( $winner_variant ) {
            $update['status']         = 'completed';
            $update['winner_variant'] = $winner_variant;
            $update['winner_post_id'] = $winner_post_id;

            // Store winner variant preference for this product
            update_option( "ssca_winner_{$test['product_id']}_{$test['platform']}", $winner_variant );

            \SSCA\Utils\Logger::info( "A/B Test #{$test['id']} — Winner: Variant {$winner_variant} (clicks A:{$clicks_a} B:{$clicks_b})" );
        } else {
            $update['status'] = 'inconclusive';
        }

        \SSCA\DB\Repository::update_ab_test( (int) $test['id'], $update );
        do_action( 'ssca_ab_test_completed', $test['id'], $winner_variant, $test );
    }

    private function get_attributed_orders( int $post_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ssca_attribution WHERE post_id = %d",
                $post_id
            )
        );
    }

    /**
     * Get the winning variant for a product+platform combo.
     */
    public static function get_winning_variant( int $product_id, string $platform ): string {
        return get_option( "ssca_winner_{$product_id}_{$platform}", 'A' );
    }

    /**
     * Get all tests with results for the admin UI.
     */
    public static function get_tests_for_display( int $limit = 20 ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT t.*, p.post_title
             FROM {$wpdb->prefix}ssca_ab_tests t
             LEFT JOIN {$wpdb->prefix}posts p ON t.product_id = p.ID
             ORDER BY t.started_at DESC
             LIMIT %d",
            $limit
        ), ARRAY_A ) ?: [];
    }
}
