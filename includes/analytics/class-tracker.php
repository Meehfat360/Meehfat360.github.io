<?php
namespace SSCA\Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * First-Party Analytics Tracker
 *
 * - Registers /ssca-track/click/ rewrite endpoint
 * - Tracks clicks through redirect
 * - Records add-to-cart events via AJAX
 * - Stores session data for attribution
 * - Builds UTM parameters for all published links
 */
class Tracker {

    const TRACKING_SLUG   = 'ssca-track';
    const SESSION_COOKIE  = 'ssca_session';
    const ATTRIBUTION_KEY = 'ssca_last_click';

    public function __construct() {
        add_action( 'template_redirect',    [ $this, 'handle_tracking_redirect' ], 1 );
        add_action( 'wp_enqueue_scripts',   [ $this, 'enqueue_tracking_script'  ] );
        add_action( 'wp_ajax_ssca_track',        [ $this, 'ajax_track_event' ] );
        add_action( 'wp_ajax_nopriv_ssca_track', [ $this, 'ajax_track_event' ] );
        add_filter( 'woocommerce_add_to_cart_redirect', [ $this, 'track_add_to_cart' ], 10, 2 );
    }

    /**
     * Register custom rewrite rule for tracking links.
     */
    public static function register_rewrite_rules(): void {
        add_rewrite_rule(
            '^' . self::TRACKING_SLUG . '/click/([0-9]+)/?$',
            'index.php?ssca_track_post_id=$matches[1]',
            'top'
        );
        add_filter( 'query_vars', function( $vars ) {
            $vars[] = 'ssca_track_post_id';
            return $vars;
        } );
    }

    /**
     * Handle the tracking redirect.
     * URL format: /ssca-track/click/{post_id}/
     */
    public function handle_tracking_redirect(): void {
        $post_id = (int) get_query_var( 'ssca_track_post_id' );
        if ( ! $post_id ) return;

        $post = \SSCA\DB\Repository::get_post( $post_id );
        if ( ! $post ) wp_die( 'Invalid tracking link.' );

        // Record click event
        $session_id = $this->get_or_create_session();
        \SSCA\DB\Repository::insert_analytics_event( [
            'post_id'    => $post_id,
            'product_id' => $post['product_id'],
            'platform'   => $post['platform'],
            'event_type' => 'click',
            'session_id' => $session_id,
            'ip_hash'    => $this->hash_ip(),
            'user_agent' => substr( sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 512 ),
            'referrer'   => esc_url_raw( wp_get_referer() ?: '' ),
            'event_at'   => current_time( 'mysql' ),
        ] );

        // Update post click count
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}ssca_posts SET clicks = clicks + 1 WHERE id = %d",
            $post_id
        ) );

        // Update product click count
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}ssca_products SET total_clicks = total_clicks + 1 WHERE product_id = %d",
            $post['product_id']
        ) );

        // Store for attribution
        $this->store_attribution_cookie( $post_id, (int) $post['product_id'], $post['platform'], $session_id );

        // Redirect to destination
        $destination = $post['tracking_url'] ?: get_permalink( $post['product_id'] );
        wp_redirect( esc_url_raw( $destination ), 302 );
        exit;
    }

    /**
     * Enqueue front-end tracking script for impression tracking.
     */
    public function enqueue_tracking_script(): void {
        wp_enqueue_script(
            'ssca-tracker',
            SSCA_ASSETS . 'js/tracker.js',
            [],
            SSCA_VERSION,
            true
        );
        wp_localize_script( 'ssca-tracker', 'SSCA', [
            'ajax_url'  => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'ssca_track' ),
        ] );
    }

    /**
     * AJAX handler for tracking events from JS.
     */
    public function ajax_track_event(): void {
        check_ajax_referer( 'ssca_track', 'nonce' );

        $event_type = sanitize_text_field( $_POST['event_type'] ?? '' );
        $post_id    = (int) ( $_POST['post_id'] ?? 0 );

        if ( ! in_array( $event_type, [ 'impression', 'add_to_cart' ], true ) || ! $post_id ) {
            wp_send_json_error( 'Invalid event.' );
        }

        $post = \SSCA\DB\Repository::get_post( $post_id );
        if ( ! $post ) wp_send_json_error( 'Post not found.' );

        \SSCA\DB\Repository::insert_analytics_event( [
            'post_id'    => $post_id,
            'product_id' => $post['product_id'],
            'platform'   => $post['platform'],
            'event_type' => $event_type,
            'session_id' => $this->get_or_create_session(),
            'ip_hash'    => $this->hash_ip(),
            'event_at'   => current_time( 'mysql' ),
        ] );

        wp_send_json_success();
    }

    /**
     * Track when a product from a social post gets added to cart.
     */
    public function track_add_to_cart( $redirect, $product_id ): string {
        $attribution = $this->get_attribution_from_cookie();
        if ( $attribution && (int) $attribution['product_id'] === (int) $product_id ) {
            \SSCA\DB\Repository::insert_analytics_event( [
                'post_id'    => $attribution['post_id'],
                'product_id' => $product_id,
                'platform'   => $attribution['platform'],
                'event_type' => 'add_to_cart',
                'session_id' => $attribution['session_id'],
                'event_at'   => current_time( 'mysql' ),
            ] );

            // Update product ATC count
            global $wpdb;
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->prefix}ssca_products SET total_atc = total_atc + 1 WHERE product_id = %d",
                $product_id
            ) );
        }
        return $redirect;
    }

    // ── UTM Builder ───────────────────────────────────────────────────────────

    /**
     * Build a tracked URL for a post.
     *
     * @param int    $post_id    ssca_posts ID
     * @param int    $product_id WC product ID
     * @param string $platform
     * @param string $variant
     * @return string  Tracking URL (redirects through /ssca-track/click/)
     */
    public static function build_tracking_url( int $post_id, int $product_id, string $platform, string $variant = 'A' ): string {
        $tracking_base = home_url( '/' . self::TRACKING_SLUG . '/click/' . $post_id . '/' );

        // Also keep the UTM params so GA/etc still gets data
        $product_url = get_permalink( $product_id );
        $utm_url     = add_query_arg( [
            'utm_source'   => $platform,
            'utm_medium'   => 'social',
            'utm_campaign' => 'ssca_auto',
            'utm_content'  => "post_{$post_id}_v{$variant}",
            'utm_term'     => "product_{$product_id}",
            'ssca_pid'     => $post_id,
        ], $product_url );

        // Store UTM URL in post record for display
        \SSCA\DB\Repository::update_post( $post_id, [
            'utm_params'   => $utm_url,
            'tracking_url' => $tracking_base,
        ] );

        return $tracking_base;
    }

    // ── Session & Cookie ──────────────────────────────────────────────────────

    private function get_or_create_session(): string {
        if ( ! empty( $_COOKIE[ self::SESSION_COOKIE ] ) ) {
            return sanitize_text_field( $_COOKIE[ self::SESSION_COOKIE ] );
        }
        $session = wp_generate_uuid4();
        setcookie( self::SESSION_COOKIE, $session, time() + ( 30 * DAY_IN_SECONDS ), COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
        return $session;
    }

    private function store_attribution_cookie( int $post_id, int $product_id, string $platform, string $session_id ): void {
        $data = wp_json_encode( compact( 'post_id', 'product_id', 'platform', 'session_id' ) );
        setcookie( self::ATTRIBUTION_KEY, $data, time() + ( 7 * DAY_IN_SECONDS ), COOKIEPATH, COOKIE_DOMAIN, is_ssl(), false );
    }

    private function get_attribution_from_cookie(): ?array {
        if ( empty( $_COOKIE[ self::ATTRIBUTION_KEY ] ) ) return null;
        $data = json_decode( stripslashes( $_COOKIE[ self::ATTRIBUTION_KEY ] ), true );
        return is_array( $data ) ? $data : null;
    }

    private function hash_ip(): string {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $ip = explode( ',', $ip )[0]; // First IP if forwarded
        return hash( 'sha256', trim( $ip ) . wp_salt() ); // Salted hash for GDPR
    }
}
