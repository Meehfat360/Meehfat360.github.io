<?php
namespace SSCA\Publishers;

defined( 'ABSPATH' ) || exit;

/**
 * API Health Monitor
 *
 * - Periodically checks all platform connections
 * - Detects token expiry
 * - Sends admin alerts on failures
 * - Provides health status for dashboard
 */
class APIHealthMonitor {

    const HEALTH_CHECK_INTERVAL = 6 * HOUR_IN_SECONDS;
    const HEALTH_TRANSIENT      = 'ssca_api_health_status';

    public function __construct() {
        add_action( 'ssca_health_check', [ $this, 'run_health_checks' ] );
        add_action( 'admin_init',        [ $this, 'schedule_health_checks' ] );
    }

    public function schedule_health_checks(): void {
        if ( ! wp_next_scheduled( 'ssca_health_check' ) ) {
            wp_schedule_event( time(), 'twicedaily', 'ssca_health_check' );
        }
    }

    /**
     * Run all platform health checks and cache results.
     */
    public function run_health_checks(): void {
        $results = [];

        // Meta (Facebook + Instagram)
        $meta = new MetaPublisher();
        $results['facebook']  = $meta->health_check();
        $results['instagram'] = $results['facebook']; // Same token

        // Pinterest
        $pinterest = new PinterestPublisher();
        $results['pinterest'] = $pinterest->health_check();

        // OpenAI (caption generator)
        $results['openai'] = $this->check_openai();

        // Store results
        set_transient( self::HEALTH_TRANSIENT, $results, self::HEALTH_CHECK_INTERVAL );
        update_option( 'ssca_last_health_check', current_time( 'mysql' ) );

        // Alert on failures
        foreach ( $results as $platform => $result ) {
            if ( ( $result['status'] ?? '' ) === 'error' ) {
                \SSCA\Admin\Notifications::send_health_alert( $platform, $result['message'] ?? 'Unknown error' );
            }
            // Token expiry warning
            if ( ! empty( $result['token_expiring'] ) ) {
                \SSCA\Admin\Notifications::send_token_expiry_warning( $platform );
            }
        }
    }

    /**
     * Get cached health status (fast — from transient).
     */
    public static function get_status(): array {
        $cached = get_transient( self::HEALTH_TRANSIENT );
        if ( $cached ) return $cached;

        // Return "unknown" if never checked
        return [
            'facebook'  => [ 'status' => 'unknown', 'message' => 'Not checked yet.' ],
            'instagram' => [ 'status' => 'unknown', 'message' => 'Not checked yet.' ],
            'pinterest' => [ 'status' => 'unknown', 'message' => 'Not checked yet.' ],
            'openai'    => [ 'status' => 'unknown', 'message' => 'Not checked yet.' ],
        ];
    }

    /**
     * Force a fresh health check (e.g. after saving new API keys).
     */
    public static function force_check(): array {
        delete_transient( self::HEALTH_TRANSIENT );
        $monitor = new self();
        $monitor->run_health_checks();
        return self::get_status();
    }

    private function check_openai(): array {
        $api_key = get_option( 'ssca_openai_key', '' );
        if ( empty( $api_key ) ) {
            return [ 'status' => 'error', 'message' => 'OpenAI API key not configured.' ];
        }

        $response = wp_remote_get( 'https://api.openai.com/v1/models', [
            'timeout' => 10,
            'headers' => [ 'Authorization' => 'Bearer ' . $api_key ],
        ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return [ 'status' => 'error', 'message' => 'OpenAI API key invalid or unreachable.' ];
        }

        return [ 'status' => 'ok', 'message' => 'OpenAI connected.' ];
    }

    /**
     * Get platform API stats from DB logs.
     */
    public static function get_api_stats(): array {
        $platforms = [ 'facebook', 'instagram', 'pinterest', 'openai' ];
        $stats     = [];
        foreach ( $platforms as $p ) {
            $stats[ $p ] = \SSCA\DB\Repository::get_api_health( $p, 24 );
        }
        return $stats;
    }
}
