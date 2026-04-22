<?php
namespace SSCA\Publishers;

defined( 'ABSPATH' ) || exit;

/**
 * Publishing Queue
 *
 * Manages scheduled publishing via Action Scheduler.
 * Handles:
 *  - Daily workflow scheduling
 *  - Staggered post publishing per platform
 *  - Retry logic for failed posts
 *  - Unscheduling on deactivation
 */
class Queue {

    const DAILY_ACTION    = 'ssca_daily_workflow';
    const PUBLISH_ACTION  = 'ssca_publish_post';
    const AB_EVAL_ACTION  = 'ssca_ab_test_evaluate';
    const GROUP           = 'ssca';

    /**
     * Register recurring schedules on activation.
     */
    public static function schedule_recurring(): void {
        if ( ! self::as_available() ) return;

        // Daily workflow — default 6 AM site time
        $workflow_time = get_option( 'ssca_workflow_time', '06:00' );
        $timestamp     = self::next_occurrence_of( $workflow_time );

        if ( ! as_has_scheduled_action( self::DAILY_ACTION, [], self::GROUP ) ) {
            as_schedule_recurring_action( $timestamp, DAY_IN_SECONDS, self::DAILY_ACTION, [], self::GROUP );
        }

        // A/B test evaluation — daily at 11 PM
        $eval_timestamp = self::next_occurrence_of( '23:00' );
        if ( ! as_has_scheduled_action( self::AB_EVAL_ACTION, [], self::GROUP ) ) {
            as_schedule_recurring_action( $eval_timestamp, DAY_IN_SECONDS, self::AB_EVAL_ACTION, [], self::GROUP );
        }
    }

    /**
     * Unschedule all SSCA actions (deactivation).
     */
    public static function unschedule_all(): void {
        if ( ! self::as_available() ) return;
        as_unschedule_all_actions( self::DAILY_ACTION,   [], self::GROUP );
        as_unschedule_all_actions( self::AB_EVAL_ACTION, [], self::GROUP );
        // Note: individual publish actions are NOT cancelled — let queued posts finish
    }

    /**
     * Get the posting schedule: platform → list of timestamps.
     *
     * Returns an array like:
     *   'facebook'  => ['2024-01-15 10:00:00', '2024-01-15 10:15:00', ...]
     *   'instagram' => ['2024-01-15 19:00:00', ...]
     *   'pinterest' => ['2024-01-15 14:00:00', ...]
     *
     * Slots are staggered by 15 minutes per product.
     */
    public static function get_schedule( \DateTime $base = null ): array {
        $base = $base ?? new \DateTime( 'now', wp_timezone() );

        $platform_times = get_option( 'ssca_posting_times', [
            'facebook'  => '10:00',
            'instagram' => '19:00',
            'pinterest' => '14:00',
        ] );

        $daily_count = (int) get_option( 'ssca_daily_products', 5 );
        $stagger_min = 15; // minutes between posts per platform
        $result      = [];

        foreach ( $platform_times as $platform => $time ) {
            [ $hour, $minute ] = explode( ':', $time );
            $slots = [];
            for ( $i = 0; $i < $daily_count; $i++ ) {
                $slot = clone $base;
                $slot->setTime( (int) $hour, (int) $minute + ( $i * $stagger_min ) );
                $slots[] = $slot->format( 'Y-m-d H:i:s' );
            }
            $result[ $platform ] = $slots;
        }

        return $result;
    }

    /**
     * Check if a specific post is already scheduled.
     */
    public static function is_scheduled( int $post_id ): bool {
        if ( ! self::as_available() ) return false;
        return (bool) as_has_scheduled_action( self::PUBLISH_ACTION, [ 'post_id' => $post_id ], self::GROUP );
    }

    /**
     * Cancel a pending post.
     */
    public static function cancel_post( int $post_id ): void {
        if ( ! self::as_available() ) return;
        as_unschedule_action( self::PUBLISH_ACTION, [ 'post_id' => $post_id ], self::GROUP );
        \SSCA\DB\Repository::update_post( $post_id, [ 'status' => 'cancelled' ] );
    }

    /**
     * Manually trigger the daily workflow immediately (for testing).
     */
    public static function trigger_now(): void {
        if ( ! self::as_available() ) {
            do_action( self::DAILY_ACTION );
            return;
        }
        as_schedule_single_action( time(), self::DAILY_ACTION, [], self::GROUP );
    }

    /**
     * Get queue status summary.
     */
    public static function get_status(): array {
        if ( ! self::as_available() ) return [];

        return [
            'next_daily_run'  => self::get_next_run_time( self::DAILY_ACTION ),
            'pending_publish' => self::count_pending( self::PUBLISH_ACTION ),
            'next_ab_eval'    => self::get_next_run_time( self::AB_EVAL_ACTION ),
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function as_available(): bool {
        return class_exists( 'ActionScheduler' );
    }

    private static function next_occurrence_of( string $time ): int {
        [ $h, $m ] = explode( ':', $time );
        $dt = new \DateTime( 'now', wp_timezone() );
        $dt->setTime( (int) $h, (int) $m, 0 );
        if ( $dt->getTimestamp() <= time() ) {
            $dt->modify( '+1 day' );
        }
        return $dt->getTimestamp();
    }

    private static function get_next_run_time( string $hook ): ?string {
        if ( ! self::as_available() ) return null;
        $next = as_next_scheduled_action( $hook, [], self::GROUP );
        return $next ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next ) : null;
    }

    private static function count_pending( string $hook ): int {
        if ( ! self::as_available() ) return 0;
        return as_get_scheduled_actions( [
            'hook'     => $hook,
            'status'   => \ActionScheduler_Store::STATUS_PENDING,
            'group'    => self::GROUP,
            'per_page' => -1,
        ], 'ids' ) ? count( as_get_scheduled_actions( [
            'hook'   => $hook,
            'status' => \ActionScheduler_Store::STATUS_PENDING,
            'group'  => self::GROUP,
        ], 'ids' ) ) : 0;
    }
}
