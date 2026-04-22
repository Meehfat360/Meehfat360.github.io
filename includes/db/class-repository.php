<?php
namespace SSCA\DB;

defined( 'ABSPATH' ) || exit;

/**
 * Central repository for all DB reads/writes.
 * All methods use $wpdb with prepared statements.
 */
class Repository {

    // ── Posts ─────────────────────────────────────────────────────────────────

    public static function insert_post( array $data ): int {
        global $wpdb;
        $defaults = [
            'variant'      => 'A',
            'status'       => 'scheduled',
            'approved'     => 0,
            'retry_count'  => 0,
            'season_theme' => '',
        ];
        $data = array_merge( $defaults, $data );
        $wpdb->insert( $wpdb->prefix . 'ssca_posts', $data );
        return (int) $wpdb->insert_id;
    }

    public static function get_post( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ssca_posts WHERE id = %d", $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function update_post( int $id, array $data ): void {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'ssca_posts', $data, [ 'id' => $id ] );
    }

    public static function get_posts( array $args = [] ): array {
        global $wpdb;
        $where  = [ '1=1' ];
        $params = [];

        if ( ! empty( $args['status'] ) ) {
            $where[]  = 'status = %s';
            $params[] = $args['status'];
        }
        if ( ! empty( $args['platform'] ) ) {
            $where[]  = 'platform = %s';
            $params[] = $args['platform'];
        }
        if ( ! empty( $args['product_id'] ) ) {
            $where[]  = 'product_id = %d';
            $params[] = $args['product_id'];
        }
        if ( ! empty( $args['date_from'] ) ) {
            $where[]  = 'scheduled_at >= %s';
            $params[] = $args['date_from'];
        }
        if ( ! empty( $args['date_to'] ) ) {
            $where[]  = 'scheduled_at <= %s';
            $params[] = $args['date_to'];
        }
        if ( ! empty( $args['variant'] ) ) {
            $where[]  = 'variant = %s';
            $params[] = $args['variant'];
        }

        $limit   = isset( $args['limit'] )  ? (int) $args['limit']  : 100;
        $offset  = isset( $args['offset'] ) ? (int) $args['offset'] : 0;
        $orderby = isset( $args['orderby'] ) ? sanitize_sql_orderby( $args['orderby'] ) : 'scheduled_at DESC';

        $where_sql = implode( ' AND ', $where );
        $sql       = "SELECT * FROM {$wpdb->prefix}ssca_posts WHERE {$where_sql} ORDER BY {$orderby} LIMIT %d OFFSET %d";
        $params[]  = $limit;
        $params[]  = $offset;

        return $wpdb->get_results(
            empty( array_filter( $params ) ) ? $sql : $wpdb->prepare( $sql, ...$params ),
            ARRAY_A
        ) ?: [];
    }

    public static function count_posts( array $args = [] ): int {
        global $wpdb;
        $where  = [ '1=1' ];
        $params = [];

        if ( ! empty( $args['status'] ) ) { $where[] = 'status = %s'; $params[] = $args['status']; }
        if ( ! empty( $args['platform'] ) ) { $where[] = 'platform = %s'; $params[] = $args['platform']; }
        if ( ! empty( $args['date_from'] ) ) { $where[] = 'scheduled_at >= %s'; $params[] = $args['date_from']; }
        if ( ! empty( $args['date_to'] ) ) { $where[] = 'scheduled_at <= %s'; $params[] = $args['date_to']; }

        $where_sql = implode( ' AND ', $where );
        $sql = "SELECT COUNT(*) FROM {$wpdb->prefix}ssca_posts WHERE {$where_sql}";

        return (int) ( empty( $params )
            ? $wpdb->get_var( $sql )
            : $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) )
        );
    }

    // ── Products ──────────────────────────────────────────────────────────────

    public static function get_product_record( int $product_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ssca_products WHERE product_id = %d", $product_id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function upsert_product_record( int $product_id, array $data ): void {
        global $wpdb;
        $existing = self::get_product_record( $product_id );
        if ( $existing ) {
            $wpdb->update( $wpdb->prefix . 'ssca_products', $data, [ 'product_id' => $product_id ] );
        } else {
            $wpdb->insert( $wpdb->prefix . 'ssca_products', array_merge( [ 'product_id' => $product_id ], $data ) );
        }
    }

    public static function get_recently_posted_product_ids( int $days = 30 ): array {
        global $wpdb;
        return $wpdb->get_col(
            $wpdb->prepare(
                "SELECT product_id FROM {$wpdb->prefix}ssca_products WHERE last_posted_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        ) ?: [];
    }

    public static function mark_product_posted( int $product_id, string $category ): void {
        self::upsert_product_record( $product_id, [
            'last_posted_at' => current_time( 'mysql' ),
            'last_category'  => $category,
        ] );
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}ssca_products SET total_posts = total_posts + 1 WHERE product_id = %d",
                $product_id
            )
        );
    }

    public static function update_product_score( int $product_id, float $score ): void {
        self::upsert_product_record( $product_id, [
            'score'            => $score,
            'score_updated_at' => current_time( 'mysql' ),
        ] );
    }

    // ── Analytics ─────────────────────────────────────────────────────────────

    public static function insert_analytics_event( array $data ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'ssca_analytics', $data );
        return (int) $wpdb->insert_id;
    }

    public static function get_analytics_summary( string $date_from, string $date_to ): array {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) AS total_events,
                SUM(CASE WHEN event_type = 'click'       THEN 1 ELSE 0 END) AS total_clicks,
                SUM(CASE WHEN event_type = 'impression'  THEN 1 ELSE 0 END) AS total_impressions,
                SUM(CASE WHEN event_type = 'add_to_cart' THEN 1 ELSE 0 END) AS total_atc
             FROM {$wpdb->prefix}ssca_analytics
             WHERE event_at BETWEEN %s AND %s",
            $date_from, $date_to
        ), ARRAY_A ) ?: [];
    }

    public static function get_platform_stats( string $date_from, string $date_to ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT platform,
                    COUNT(*) AS events,
                    SUM(CASE WHEN event_type = 'click' THEN 1 ELSE 0 END) AS clicks
             FROM {$wpdb->prefix}ssca_analytics
             WHERE event_at BETWEEN %s AND %s
             GROUP BY platform",
            $date_from, $date_to
        ), ARRAY_A ) ?: [];
    }

    public static function get_clicks_for_post( int $post_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ssca_analytics WHERE post_id = %d AND event_type = 'click'",
                $post_id
            )
        );
    }

    // ── Attribution ───────────────────────────────────────────────────────────

    public static function insert_attribution( array $data ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'ssca_attribution', $data );
        return (int) $wpdb->insert_id;
    }

    public static function get_attribution_revenue( string $date_from, string $date_to ): float {
        global $wpdb;
        return (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(order_total),0) FROM {$wpdb->prefix}ssca_attribution WHERE attributed_at BETWEEN %s AND %s",
            $date_from, $date_to
        ) );
    }

    public static function get_order_attribution( int $order_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ssca_attribution WHERE order_id = %d", $order_id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    // ── A/B Tests ─────────────────────────────────────────────────────────────

    public static function insert_ab_test( array $data ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'ssca_ab_tests', $data );
        return (int) $wpdb->insert_id;
    }

    public static function get_running_ab_tests(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}ssca_ab_tests WHERE status = 'running'",
            ARRAY_A
        ) ?: [];
    }

    public static function update_ab_test( int $id, array $data ): void {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'ssca_ab_tests', $data, [ 'id' => $id ] );
    }

    // ── API Logs ──────────────────────────────────────────────────────────────

    public static function insert_api_log( array $data ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'ssca_api_logs', $data );
    }

    public static function get_api_health( string $platform, int $hours = 24 ): array {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(success) AS successes,
                SUM(1 - success) AS failures,
                AVG(duration_ms) AS avg_ms
             FROM {$wpdb->prefix}ssca_api_logs
             WHERE platform = %s AND logged_at >= DATE_SUB(NOW(), INTERVAL %d HOUR)",
            $platform, $hours
        ), ARRAY_A ) ?: [ 'total' => 0, 'successes' => 0, 'failures' => 0, 'avg_ms' => 0 ];
    }

    // ── Hashtags ──────────────────────────────────────────────────────────────

    public static function get_hashtags( string $platform, string $category = '', int $limit = 20 ): array {
        global $wpdb;
        if ( $category ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ssca_hashtag_stats WHERE platform = %s AND category = %s AND is_banned = 0 ORDER BY ctr DESC, uses ASC LIMIT %d",
                $platform, $category, $limit
            ), ARRAY_A ) ?: [];
        }
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ssca_hashtag_stats WHERE platform = %s AND is_banned = 0 ORDER BY ctr DESC LIMIT %d",
            $platform, $limit
        ), ARRAY_A ) ?: [];
    }

    public static function record_hashtag_use( string $hashtag, string $platform ): void {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}ssca_hashtag_stats (hashtag, platform, uses, last_used)
             VALUES (%s, %s, 1, NOW())
             ON DUPLICATE KEY UPDATE uses = uses + 1, last_used = NOW()",
            $hashtag, $platform
        ) );
    }

    // ── Dashboard Metrics ─────────────────────────────────────────────────────

    public static function get_dashboard_metrics(): array {
        global $wpdb;

        $today_start = current_time( 'Y-m-d' ) . ' 00:00:00';
        $today_end   = current_time( 'Y-m-d' ) . ' 23:59:59';
        $month_start = current_time( 'Y-m' )   . '-01 00:00:00';
        $month_end   = current_time( 'mysql' );

        return [
            'today_scheduled'   => self::count_posts( [ 'status' => 'scheduled', 'date_from' => $today_start, 'date_to' => $today_end ] ),
            'today_published'   => self::count_posts( [ 'status' => 'published', 'date_from' => $today_start, 'date_to' => $today_end ] ),
            'month_published'   => self::count_posts( [ 'status' => 'published', 'date_from' => $month_start, 'date_to' => $month_end ] ),
            'month_clicks'      => (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(clicks), 0) FROM {$wpdb->prefix}ssca_posts WHERE status = 'published' AND published_at BETWEEN %s AND %s",
                $month_start, $month_end
            ) ),
            'month_orders'      => (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ssca_attribution WHERE attributed_at BETWEEN %s AND %s",
                $month_start, $month_end
            ) ),
            'month_revenue'     => self::get_attribution_revenue( $month_start, $month_end ),
            'pending_approval'  => self::count_posts( [ 'status' => 'awaiting_approval' ] ),
            'failed_posts'      => self::count_posts( [ 'status' => 'failed' ] ),
        ];
    }

    public static function get_top_performing_products( int $limit = 10 ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT p.*, 
                    (p.total_clicks / NULLIF(p.total_posts, 0)) AS ctr,
                    (p.total_orders / NULLIF(p.total_clicks, 0)) AS cvr
             FROM {$wpdb->prefix}ssca_products p
             ORDER BY p.total_revenue DESC, p.total_orders DESC
             LIMIT %d",
            $limit
        ), ARRAY_A ) ?: [];
    }
}
