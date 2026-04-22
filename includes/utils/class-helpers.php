<?php
namespace SSCA\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * General helper functions used across the plugin.
 */
class Helpers {

    /**
     * Returns array of active/enabled platform slugs.
     * e.g. ['facebook', 'instagram', 'pinterest']
     */
    public static function get_active_platforms(): array {
        $all = [ 'facebook', 'instagram', 'pinterest' ];
        return array_filter( $all, fn( $p ) => get_option( "ssca_platform_{$p}_enabled", '1' ) === '1' );
    }

    /**
     * Returns posting schedule as  platform => [slot0, slot1, …]
     * Slots are datetime strings for today.
     */
    public static function get_posting_schedule(): array {
        return \SSCA\Publishers\Queue::get_schedule();
    }

    /**
     * Format a number for display (1200 → 1.2K, 1200000 → 1.2M).
     */
    public static function format_number( int $n ): string {
        if ( $n >= 1_000_000 ) return round( $n / 1_000_000, 1 ) . 'M';
        if ( $n >= 1_000    ) return round( $n / 1_000,     1 ) . 'K';
        return (string) $n;
    }

    /**
     * Sanitize and validate a hex color string.
     */
    public static function sanitize_hex_color( string $color ): string {
        $color = ltrim( $color, '#' );
        if ( preg_match( '/^[0-9A-Fa-f]{6}$/', $color ) ) {
            return '#' . strtoupper( $color );
        }
        return '#1E40AF'; // Default blue
    }

    /**
     * Convert a local datetime string to a Unix timestamp respecting site timezone.
     */
    public static function local_to_timestamp( string $datetime ): int {
        try {
            $dt = new \DateTime( $datetime, wp_timezone() );
            return $dt->getTimestamp();
        } catch ( \Exception $e ) {
            return time();
        }
    }

    /**
     * Return human-readable time diff (e.g. "2 hours ago", "in 3 days").
     */
    public static function human_time_diff_full( string $datetime ): string {
        $ts   = strtotime( $datetime );
        $diff = $ts - time();
        $abs  = abs( $diff );

        if ( $abs < 60 )                  $str = 'just now';
        elseif ( $abs < 3600 )            $str = (int)($abs/60)   . ' min'  . ( (int)($abs/60)   > 1 ? 's' : '' );
        elseif ( $abs < 86400 )           $str = (int)($abs/3600) . ' hour' . ( (int)($abs/3600) > 1 ? 's' : '' );
        else                              $str = (int)($abs/86400) . ' day'  . ( (int)($abs/86400)> 1 ? 's' : '' );

        return $diff < 0 ? $str . ' ago' : ( $str === 'just now' ? $str : 'in ' . $str );
    }

    /**
     * Get the WooCommerce product image URL (or a placeholder).
     */
    public static function get_product_image_url( \WC_Product $product, string $size = 'woocommerce_thumbnail' ): string {
        $id  = $product->get_image_id();
        $url = $id ? wp_get_attachment_image_url( $id, $size ) : '';
        return $url ?: wc_placeholder_img_src( $size );
    }

    /**
     * Nonce action string for SSCA admin actions.
     */
    public static function nonce_action( string $action ): string {
        return 'ssca_' . $action;
    }

    /**
     * Platform display label + color.
     */
    public static function platform_meta( string $platform ): array {
        return match( $platform ) {
            'facebook'  => [ 'label' => 'Facebook',  'color' => '#1877F2', 'icon' => '📘' ],
            'instagram' => [ 'label' => 'Instagram', 'color' => '#E1306C', 'icon' => '📷' ],
            'pinterest' => [ 'label' => 'Pinterest', 'color' => '#E60023', 'icon' => '📌' ],
            default     => [ 'label' => ucfirst($platform), 'color' => '#64748b', 'icon' => '🌐' ],
        };
    }

    /**
     * Status display meta.
     */
    public static function status_meta( string $status ): array {
        return match( $status ) {
            'scheduled'         => [ 'label' => 'Scheduled',         'color' => '#2563eb', 'badge' => 'blue'   ],
            'published'         => [ 'label' => 'Published',         'color' => '#16a34a', 'badge' => 'green'  ],
            'failed'            => [ 'label' => 'Failed',            'color' => '#dc2626', 'badge' => 'red'    ],
            'awaiting_approval' => [ 'label' => 'Awaiting Approval', 'color' => '#d97706', 'badge' => 'yellow' ],
            'cancelled'         => [ 'label' => 'Cancelled',         'color' => '#6b7280', 'badge' => 'gray'   ],
            'draft'             => [ 'label' => 'Draft',             'color' => '#9ca3af', 'badge' => 'gray'   ],
            default             => [ 'label' => ucfirst($status),    'color' => '#6b7280', 'badge' => 'gray'   ],
        };
    }
}
