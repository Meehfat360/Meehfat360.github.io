<?php
namespace SSCA\Publishers;

defined( 'ABSPATH' ) || exit;

/**
 * Meta Publisher — Facebook & Instagram Graph API
 *
 * Handles:
 *  - Facebook Page posts (photo + caption)
 *  - Instagram Business posts (via Instagram Graph API)
 *  - Token refresh & health checks
 *  - Rate limit handling
 */
class MetaPublisher {

    const API_BASE    = 'https://graph.facebook.com/v19.0';
    const TOKEN_OPTION = 'ssca_meta_access_token';

    private string $access_token;
    private string $fb_page_id;
    private string $ig_account_id;

    public function __construct() {
        $this->access_token  = get_option( self::TOKEN_OPTION, '' );
        $this->fb_page_id    = get_option( 'ssca_fb_page_id', '' );
        $this->ig_account_id = get_option( 'ssca_ig_account_id', '' );
    }

    /**
     * Publish a post array to the appropriate Meta platform.
     *
     * @param array $post  Row from ssca_posts
     * @return array{success: bool, platform_post_id: ?string, error: ?string}
     */
    public function publish( array $post ): array {
        if ( empty( $this->access_token ) ) {
            return [ 'success' => false, 'error' => 'Meta access token not configured.' ];
        }

        return match( $post['platform'] ) {
            'facebook'  => $this->publish_facebook( $post ),
            'instagram' => $this->publish_instagram( $post ),
            default     => [ 'success' => false, 'error' => 'Unknown Meta platform: ' . $post['platform'] ],
        };
    }

    // ── Facebook ──────────────────────────────────────────────────────────────

    private function publish_facebook( array $post ): array {
        if ( empty( $this->fb_page_id ) ) {
            return [ 'success' => false, 'error' => 'Facebook Page ID not configured.' ];
        }

        $caption     = $post['caption'] ?? '';
        $product_url = $this->extract_url( $caption );

        // Get Page access token
        $page_token = $this->get_page_token();
        if ( ! $page_token ) {
            return [ 'success' => false, 'error' => 'Could not retrieve Facebook Page token.' ];
        }

        // Upload image and publish
        if ( ! empty( $post['image_path'] ) && file_exists( $post['image_path'] ) ) {
            return $this->fb_publish_photo( $post, $page_token, $caption );
        }

        // Text-only fallback
        return $this->fb_publish_text( $post, $page_token, $caption, $product_url );
    }

    private function fb_publish_photo( array $post, string $page_token, string $caption ): array {
        $image_url = ( new \SSCA\Generators\ImageGenerator() )->path_to_url( $post['image_path'] );

        $body = [
            'url'          => $image_url,
            'message'      => $caption,
            'access_token' => $page_token,
        ];

        if ( ! empty( $post['tracking_url'] ) ) {
            $body['link'] = $post['tracking_url'];
        }

        return $this->api_post( "/{$this->fb_page_id}/photos", $body, 'facebook' );
    }

    private function fb_publish_text( array $post, string $page_token, string $caption, ?string $link ): array {
        $body = [
            'message'      => $caption,
            'access_token' => $page_token,
        ];
        if ( $link ) $body['link'] = $link;

        return $this->api_post( "/{$this->fb_page_id}/feed", $body, 'facebook' );
    }

    private function get_page_token(): ?string {
        $cached = get_transient( 'ssca_fb_page_token_' . $this->fb_page_id );
        if ( $cached ) return $cached;

        $response = $this->api_get( '/me/accounts', [
            'access_token' => $this->access_token,
            'fields'       => 'id,access_token',
        ], 'facebook' );

        if ( ! $response['success'] ) return null;

        $data = json_decode( $response['body'], true );
        foreach ( $data['data'] ?? [] as $page ) {
            if ( $page['id'] === $this->fb_page_id ) {
                set_transient( 'ssca_fb_page_token_' . $this->fb_page_id, $page['access_token'], HOUR_IN_SECONDS );
                return $page['access_token'];
            }
        }
        return null;
    }

    // ── Instagram ─────────────────────────────────────────────────────────────

    private function publish_instagram( array $post ): array {
        if ( empty( $this->ig_account_id ) ) {
            return [ 'success' => false, 'error' => 'Instagram Account ID not configured.' ];
        }

        $image_url = ( new \SSCA\Generators\ImageGenerator() )->path_to_url( $post['image_path'] );
        $caption   = $post['caption'] ?? '';

        // Step 1: Create media container
        $container = $this->api_post( "/{$this->ig_account_id}/media", [
            'image_url'    => $image_url,
            'caption'      => $caption,
            'access_token' => $this->access_token,
        ], 'instagram' );

        if ( ! $container['success'] ) return $container;

        $body         = json_decode( $container['body'], true );
        $container_id = $body['id'] ?? null;

        if ( ! $container_id ) {
            return [ 'success' => false, 'error' => 'Failed to create Instagram media container.' ];
        }

        // Step 2: Wait for container to be ready
        $ready = $this->wait_for_ig_container( $container_id );
        if ( ! $ready ) {
            return [ 'success' => false, 'error' => 'Instagram media container timed out.' ];
        }

        // Step 3: Publish container
        $publish = $this->api_post( "/{$this->ig_account_id}/media_publish", [
            'creation_id'  => $container_id,
            'access_token' => $this->access_token,
        ], 'instagram' );

        return $publish;
    }

    private function wait_for_ig_container( string $container_id, int $attempts = 5 ): bool {
        for ( $i = 0; $i < $attempts; $i++ ) {
            sleep( 3 );
            $check = $this->api_get( "/{$container_id}", [
                'fields'       => 'status_code',
                'access_token' => $this->access_token,
            ], 'instagram' );

            if ( ! $check['success'] ) continue;
            $data = json_decode( $check['body'], true );
            if ( ( $data['status_code'] ?? '' ) === 'FINISHED' ) return true;
            if ( ( $data['status_code'] ?? '' ) === 'ERROR' ) return false;
        }
        return false;
    }

    // ── Token Management ──────────────────────────────────────────────────────

    /**
     * Exchange short-lived token for long-lived (60-day) token.
     */
    public function exchange_token( string $short_token ): ?string {
        $app_id     = get_option( 'ssca_meta_app_id', '' );
        $app_secret = get_option( 'ssca_meta_app_secret', '' );

        if ( ! $app_id || ! $app_secret ) return null;

        $response = wp_remote_get( add_query_arg( [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => $app_id,
            'client_secret'     => $app_secret,
            'fb_exchange_token' => $short_token,
        ], self::API_BASE . '/oauth/access_token' ), [ 'timeout' => 15 ] );

        if ( is_wp_error( $response ) ) return null;

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        $long = $data['access_token'] ?? null;
        if ( $long ) {
            update_option( self::TOKEN_OPTION, $long );
            // Store expiry (55 days from now for safety margin)
            update_option( 'ssca_meta_token_expiry', time() + ( 55 * DAY_IN_SECONDS ) );
        }
        return $long;
    }

    /**
     * Check if token needs refresh soon (within 7 days).
     */
    public function token_expiring_soon(): bool {
        $expiry = (int) get_option( 'ssca_meta_token_expiry', 0 );
        return $expiry > 0 && $expiry < ( time() + ( 7 * DAY_IN_SECONDS ) );
    }

    // ── Health Check ──────────────────────────────────────────────────────────

    public function health_check(): array {
        if ( empty( $this->access_token ) ) {
            return [ 'status' => 'error', 'message' => 'No access token configured.' ];
        }

        $response = $this->api_get( '/me', [
            'fields'       => 'id,name',
            'access_token' => $this->access_token,
        ], 'facebook' );

        if ( ! $response['success'] ) {
            return [ 'status' => 'error', 'message' => 'Token invalid or expired.' ];
        }

        $data = json_decode( $response['body'], true );
        return [
            'status'         => 'ok',
            'user'           => $data['name'] ?? 'Unknown',
            'token_expiring' => $this->token_expiring_soon(),
        ];
    }

    // ── HTTP Helpers ──────────────────────────────────────────────────────────

    private function api_post( string $endpoint, array $body, string $platform ): array {
        $start    = microtime( true );
        $url      = self::API_BASE . $endpoint;

        $response = wp_remote_post( $url, [
            'timeout' => 30,
            'body'    => $body,
        ] );

        return $this->handle_response( $response, $endpoint, 'POST', $platform, $start );
    }

    private function api_get( string $endpoint, array $params, string $platform ): array {
        $start    = microtime( true );
        $url      = add_query_arg( $params, self::API_BASE . $endpoint );
        $response = wp_remote_get( $url, [ 'timeout' => 15 ] );
        return $this->handle_response( $response, $endpoint, 'GET', $platform, $start );
    }

    private function handle_response( $response, string $endpoint, string $method, string $platform, float $start ): array {
        $duration = (int) ( ( microtime( true ) - $start ) * 1000 );
        $code     = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
        $body     = is_wp_error( $response ) ? '' : wp_remote_retrieve_body( $response );
        $success  = $code >= 200 && $code < 300;

        $error = null;
        if ( ! $success ) {
            $data  = json_decode( $body, true );
            $error = $data['error']['message'] ?? ( is_wp_error( $response ) ? $response->get_error_message() : "HTTP {$code}" );
        }

        \SSCA\DB\Repository::insert_api_log( [
            'platform'      => $platform,
            'endpoint'      => $endpoint,
            'method'        => $method,
            'response_code' => $code,
            'duration_ms'   => $duration,
            'success'       => $success ? 1 : 0,
            'error_msg'     => $error,
        ] );

        $data = json_decode( $body, true );
        return [
            'success'           => $success,
            'body'              => $body,
            'platform_post_id'  => $success ? ( $data['id'] ?? null ) : null,
            'error'             => $error,
        ];
    }

    private function extract_url( string $text ): ?string {
        preg_match( '/https?:\/\/[^\s]+/', $text, $matches );
        return $matches[0] ?? null;
    }
}
