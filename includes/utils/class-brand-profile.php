<?php
namespace SSCA\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Brand Profile
 *
 * Centralises all brand identity settings used across
 * image generation, caption writing, and publishing.
 */
class BrandProfile {

    private array $data;

    public function __construct() {
        $this->data = $this->load();
    }

    public function get(): array {
        return $this->data;
    }

    public function get_field( string $key, $default = '' ) {
        return $this->data[ $key ] ?? $default;
    }

    private function load(): array {
        $saved = get_option( 'ssca_brand_profile', [] );

        return array_merge( [
            'store_name'          => get_bloginfo( 'name' ),
            'tagline'             => get_bloginfo( 'description' ),
            'primary_color'       => '#1E40AF',
            'secondary_color'     => '#F8FAFC',
            'accent_color'        => '#FBBF24',
            'logo_attachment_id'  => 0,
            'logo_path'           => '',
            'logo_url'            => '',
            'tone'                => 'friendly and enthusiastic',
            'forbidden_words'     => [],
            'custom_cta'          => '',
            'add_ftc_disclosure'  => false,
            'website_url'         => home_url(),
            'currency_symbol'     => get_woocommerce_currency_symbol(),
        ], $saved );
    }

    public static function save( array $data ): void {
        // Sanitize
        $clean = [
            'store_name'         => sanitize_text_field( $data['store_name']         ?? '' ),
            'tagline'            => sanitize_text_field( $data['tagline']             ?? '' ),
            'primary_color'      => Helpers::sanitize_hex_color( $data['primary_color']   ?? '#1E40AF' ),
            'secondary_color'    => Helpers::sanitize_hex_color( $data['secondary_color'] ?? '#F8FAFC' ),
            'accent_color'       => Helpers::sanitize_hex_color( $data['accent_color']    ?? '#FBBF24' ),
            'logo_attachment_id' => (int) ( $data['logo_attachment_id'] ?? 0 ),
            'tone'               => sanitize_text_field( $data['tone']               ?? 'friendly and enthusiastic' ),
            'forbidden_words'    => array_map( 'sanitize_text_field', (array) ( $data['forbidden_words'] ?? [] ) ),
            'custom_cta'         => sanitize_text_field( $data['custom_cta']         ?? '' ),
            'add_ftc_disclosure' => (bool) ( $data['add_ftc_disclosure'] ?? false ),
        ];

        // Resolve logo path/url from attachment ID
        if ( $clean['logo_attachment_id'] > 0 ) {
            $clean['logo_path'] = get_attached_file( $clean['logo_attachment_id'] ) ?: '';
            $clean['logo_url']  = wp_get_attachment_url( $clean['logo_attachment_id'] ) ?: '';
        }

        update_option( 'ssca_brand_profile', $clean );
    }
}
