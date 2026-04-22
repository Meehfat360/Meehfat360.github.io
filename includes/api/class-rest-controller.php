<?php
namespace SSCA\API;

defined( 'ABSPATH' ) || exit;

/**
 * REST API Controller
 * Namespace: /wp-json/ssca/v1/
 *
 * Routes:
 *  GET  /status          — Dashboard metrics snapshot
 *  POST /workflow/run    — Trigger daily workflow manually
 *  GET  /posts           — List posts (with filters)
 *  GET  /posts/{id}      — Get single post with preview data
 *  POST /posts/{id}/approve  — Approve a pending post
 *  POST /posts/{id}/cancel   — Cancel a scheduled post
 *  GET  /health          — API health status
 *  POST /health/check    — Force refresh health check
 *  GET  /analytics       — Analytics summary
 *  POST /log/clear       — Clear activity log
 */
class RestController {

    const NAMESPACE = 'ssca/v1';

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/status', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_status' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/workflow/run', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'run_workflow' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/posts', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_posts' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args' => [
                'status'    => [ 'sanitize_callback' => 'sanitize_text_field' ],
                'platform'  => [ 'sanitize_callback' => 'sanitize_key' ],
                'date_from' => [ 'sanitize_callback' => 'sanitize_text_field' ],
                'date_to'   => [ 'sanitize_callback' => 'sanitize_text_field' ],
                'limit'     => [ 'sanitize_callback' => 'absint', 'default' => 50 ],
                'offset'    => [ 'sanitize_callback' => 'absint', 'default' => 0 ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/posts/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_post' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/posts/(?P<id>\d+)/approve', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'approve_post' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/posts/(?P<id>\d+)/cancel', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'cancel_post' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/health', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_health' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/health/check', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'force_health_check' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/analytics', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_analytics' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args' => [
                'from' => [ 'sanitize_callback' => 'sanitize_text_field', 'default' => date( 'Y-m-01' ) ],
                'to'   => [ 'sanitize_callback' => 'sanitize_text_field', 'default' => date( 'Y-m-d'  ) ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/log/clear', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'clear_log' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/season', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_season' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );
    }

    // ── Endpoints ─────────────────────────────────────────────────────────────

    public function get_status(): \WP_REST_Response {
        return new \WP_REST_Response( [
            'metrics'      => \SSCA\DB\Repository::get_dashboard_metrics(),
            'queue_status' => \SSCA\Publishers\Queue::get_status(),
            'health'       => \SSCA\Publishers\APIHealthMonitor::get_status(),
            'season'       => ( new \SSCA\Engines\SeasonalEngine() )->get_theme_meta(
                ( new \SSCA\Engines\SeasonalEngine() )->get_current_theme()
            ),
        ], 200 );
    }

    public function run_workflow(): \WP_REST_Response {
        \SSCA\Publishers\Queue::trigger_now();
        \SSCA\Utils\Logger::info( 'Manual workflow trigger via REST API by user #' . get_current_user_id() );
        return new \WP_REST_Response( [ 'success' => true, 'message' => 'Workflow triggered. Posts will be generated momentarily.' ], 200 );
    }

    public function get_posts( \WP_REST_Request $request ): \WP_REST_Response {
        $posts = \SSCA\DB\Repository::get_posts( [
            'status'    => $request->get_param( 'status' ),
            'platform'  => $request->get_param( 'platform' ),
            'date_from' => $request->get_param( 'date_from' ),
            'date_to'   => $request->get_param( 'date_to' ),
            'limit'     => $request->get_param( 'limit' ),
            'offset'    => $request->get_param( 'offset' ),
        ] );

        return new \WP_REST_Response( array_map( [ $this, 'format_post' ], $posts ), 200 );
    }

    public function get_post( \WP_REST_Request $request ): \WP_REST_Response {
        $post = \SSCA\DB\Repository::get_post( (int) $request['id'] );
        if ( ! $post ) return new \WP_REST_Response( [ 'error' => 'Post not found.' ], 404 );

        $formatted = $this->format_post( $post );

        // Add product info
        $product = wc_get_product( $post['product_id'] );
        if ( $product ) {
            $formatted['product'] = [
                'name'      => $product->get_name(),
                'price'     => wp_strip_all_tags( wc_price( (float) $product->get_price() ) ),
                'image_url' => \SSCA\Utils\Helpers::get_product_image_url( $product ),
                'url'       => get_permalink( $product->get_id() ),
            ];
        }

        return new \WP_REST_Response( $formatted, 200 );
    }

    public function approve_post( \WP_REST_Request $request ): \WP_REST_Response {
        $id   = (int) $request['id'];
        $post = \SSCA\DB\Repository::get_post( $id );
        if ( ! $post ) return new \WP_REST_Response( [ 'error' => 'Post not found.' ], 404 );

        \SSCA\DB\Repository::update_post( $id, [
            'status'      => 'scheduled',
            'approved'    => 1,
            'approved_by' => get_current_user_id(),
        ] );

        // Re-schedule
        as_schedule_single_action(
            strtotime( $post['scheduled_at'] ) ?: ( time() + 60 ),
            'ssca_publish_post',
            [ 'post_id' => $id ],
            'ssca'
        );

        return new \WP_REST_Response( [ 'success' => true, 'message' => 'Post approved and scheduled.' ], 200 );
    }

    public function cancel_post( \WP_REST_Request $request ): \WP_REST_Response {
        $id = (int) $request['id'];
        \SSCA\Publishers\Queue::cancel_post( $id );
        return new \WP_REST_Response( [ 'success' => true, 'message' => 'Post cancelled.' ], 200 );
    }

    public function get_health(): \WP_REST_Response {
        return new \WP_REST_Response( [
            'platforms' => \SSCA\Publishers\APIHealthMonitor::get_status(),
            'api_stats' => \SSCA\Publishers\APIHealthMonitor::get_api_stats(),
            'last_check'=> get_option( 'ssca_last_health_check', 'Never' ),
        ], 200 );
    }

    public function force_health_check(): \WP_REST_Response {
        $result = \SSCA\Publishers\APIHealthMonitor::force_check();
        return new \WP_REST_Response( $result, 200 );
    }

    public function get_analytics( \WP_REST_Request $request ): \WP_REST_Response {
        $from = $request->get_param( 'from' ) . ' 00:00:00';
        $to   = $request->get_param( 'to'   ) . ' 23:59:59';

        return new \WP_REST_Response( [
            'summary'       => \SSCA\DB\Repository::get_analytics_summary( $from, $to ),
            'by_platform'   => \SSCA\DB\Repository::get_platform_stats( $from, $to ),
            'attribution'   => \SSCA\Analytics\Attribution::get_summary( $from, $to ),
            'top_products'  => \SSCA\DB\Repository::get_top_performing_products( 10 ),
        ], 200 );
    }

    public function clear_log(): \WP_REST_Response {
        \SSCA\Utils\Logger::clear();
        return new \WP_REST_Response( [ 'success' => true ], 200 );
    }

    public function get_season(): \WP_REST_Response {
        $engine  = new \SSCA\Engines\SeasonalEngine();
        $current = $engine->get_current_theme();
        return new \WP_REST_Response( [
            'theme' => $current,
            'meta'  => $engine->get_theme_meta( $current ),
        ], 200 );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function format_post( array $post ): array {
        $plt = \SSCA\Utils\Helpers::platform_meta( $post['platform'] );
        $st  = \SSCA\Utils\Helpers::status_meta( $post['status'] );

        return [
            'id'           => (int) $post['id'],
            'product_id'   => (int) $post['product_id'],
            'platform'     => $post['platform'],
            'platform_label' => $plt['label'],
            'platform_icon'  => $plt['icon'],
            'platform_color' => $plt['color'],
            'variant'      => $post['variant'],
            'status'       => $post['status'],
            'status_label' => $st['label'],
            'status_badge' => $st['badge'],
            'caption'      => $post['caption'],
            'scheduled_at' => $post['scheduled_at'],
            'published_at' => $post['published_at'],
            'image_url'    => $post['image_path']
                ? ( new \SSCA\Generators\ImageGenerator() )->path_to_url( $post['image_path'] )
                : null,
            'tracking_url' => $post['tracking_url'],
            'clicks'       => (int) $post['clicks'],
            'impressions'  => (int) $post['impressions'],
            'error_msg'    => $post['error_msg'],
        ];
    }

    public function check_permission(): bool {
        return current_user_can( \SSCA\Admin\Menu::CAPABILITY );
    }
}
