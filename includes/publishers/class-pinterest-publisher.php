<?php
namespace SSCA\Publishers;

defined( 'ABSPATH' ) || exit;

/**
 * Pinterest Publisher — Pinterest API v5
 *
 * Creates Pins to a specified board.
 * Handles image upload + metadata.
 */
class PinterestPublisher {

    const API_BASE = 'https://api.pinterest.com/v5';

    private string $access_token;
    private string $board_id;

    public function __construct() {
        $this->access_token = get_option( 'ssca_pinterest_token', '' );
        $this->board_id     = get_option( 'ssca_pinterest_board_id', '' );
    }

    public function publish( array $post ): array {
        if ( empty( $this->access_token ) ) {
            return [ 'success' => false, 'error' => 'Pinterest access token not configured.' ];
        }
        if ( empty( $this->board_id ) ) {
            return [ 'success' => false, 'error' => 'Pinterest Board ID not configured.' ];
        }

        $image_gen  = new \SSCA\Generators\ImageGenerator();
        $image_url  = $image_gen->path_to_url( $post['image_path'] ?? '' );
        $product    = wc_get_product( $post['product_id'] );
        $link       = $post['tracking_url'] ?: ( $product ? get_permalink( $product->get_id() ) : '' );
        $title      = $product ? $product->get_name() : '';
        $description= $post['caption'] ?? '';

        $body = [
            'board_id'    => $this->board_id,
            'title'       => mb_substr( $title, 0, 100 ),
            'description' => mb_substr( $description, 0, 500 ),
            'link'        => $link,
            'media_source'=> [
                'source_type' => 'image_url',
                'url'         => $image_url,
            ],
        ];

        return $this->api_post( '/pins', $body );
    }

    public function health_check(): array {
        if ( empty( $this->access_token ) ) {
            return [ 'status' => 'error', 'message' => 'No access token configured.' ];
        }

        $response = $this->api_get( '/user_account' );
        if ( ! $response['success'] ) {
            return [ 'status' => 'error', 'message' => 'Pinterest token invalid or expired.' ];
        }

        $data = json_decode( $response['body'], true );
        return [
            'status'    => 'ok',
            'username'  => $data['username'] ?? 'Unknown',
        ];
    }

    // ── HTTP Helpers ──────────────────────────────────────────────────────────

    private function api_post( string $endpoint, array $body ): array {
        $start    = microtime( true );
        $url      = self::API_BASE . $endpoint;

        $response = wp_remote_post( $url, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->access_token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( $body ),
        ] );

        return $this->handle_response( $response, $endpoint, 'POST', microtime( true ) - $start );
    }

    private function api_get( string $endpoint ): array {
        $start    = microtime( true );
        $url      = self::API_BASE . $endpoint;

        $response = wp_remote_get( $url, [
            'timeout' => 15,
            'headers' => [ 'Authorization' => 'Bearer ' . $this->access_token ],
        ] );

        return $this->handle_response( $response, $endpoint, 'GET', microtime( true ) - $start );
    }

    private function handle_response( $response, string $endpoint, string $method, float $elapsed ): array {
        $duration = (int) ( $elapsed * 1000 );
        $code     = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
        $body     = is_wp_error( $response ) ? '' : wp_remote_retrieve_body( $response );
        $success  = $code >= 200 && $code < 300;

        $error = null;
        if ( ! $success ) {
            $data  = json_decode( $body, true );
            $error = $data['message'] ?? ( is_wp_error( $response ) ? $response->get_error_message() : "HTTP {$code}" );
        }

        \SSCA\DB\Repository::insert_api_log( [
            'platform'      => 'pinterest',
            'endpoint'      => $endpoint,
            'method'        => $method,
            'response_code' => $code,
            'duration_ms'   => $duration,
            'success'       => $success ? 1 : 0,
            'error_msg'     => $error,
        ] );

        $data = json_decode( $body, true );
        return [
            'success'          => $success,
            'body'             => $body,
            'platform_post_id' => $success ? ( $data['id'] ?? null ) : null,
            'error'            => $error,
        ];
    }
}
