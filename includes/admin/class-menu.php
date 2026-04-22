<?php
namespace SSCA\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Admin Menu — registers all SSCA admin pages under a top-level menu.
 */
class Menu {

    const CAPABILITY = 'manage_woocommerce';
    const MENU_SLUG  = 'ssca-dashboard';

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menu'     ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets'    ] );
        add_filter( 'plugin_action_links_' . SSCA_BASENAME, [ $this, 'add_plugin_links' ] );
    }

    public function register_menu(): void {
        // Top-level page
        add_menu_page(
            __( 'StellarSavers Social', 'ssca' ),
            __( 'SS Social', 'ssca' ),
            self::CAPABILITY,
            self::MENU_SLUG,
            [ new Dashboard(), 'render' ],
            $this->get_menu_icon(),
            56
        );

        // Sub pages
        $pages = [
            [ 'Dashboard',     self::MENU_SLUG,         [ new Dashboard(),    'render' ] ],
            [ 'Content Calendar', 'ssca-calendar',       [ new Calendar(),     'render' ] ],
            [ 'Settings',      'ssca-settings',         [ new Settings(),     'render' ] ],
            [ 'Analytics',     'ssca-analytics',        [ new Dashboard(),    'render_analytics' ] ],
            [ 'A/B Tests',     'ssca-ab-tests',         [ new Dashboard(),    'render_ab_tests' ] ],
            [ 'Activity Log',  'ssca-log',              [ new Dashboard(),    'render_log' ] ],
        ];

        foreach ( $pages as [ $title, $slug, $callback ] ) {
            add_submenu_page(
                self::MENU_SLUG,
                sprintf( __( '%s — StellarSavers', 'ssca' ), $title ),
                __( $title, 'ssca' ),
                self::CAPABILITY,
                $slug,
                $callback
            );
        }

        // Remove duplicate first entry
        remove_submenu_page( self::MENU_SLUG, self::MENU_SLUG );
        add_submenu_page(
            self::MENU_SLUG,
            __( 'Dashboard — StellarSavers', 'ssca' ),
            __( 'Dashboard', 'ssca' ),
            self::CAPABILITY,
            self::MENU_SLUG,
            [ new Dashboard(), 'render' ]
        );
    }

    public function enqueue_assets( string $hook ): void {
        // Only load on SSCA pages
        if ( ! str_contains( $hook, 'ssca' ) && ! str_contains( $hook, 'ss-social' ) ) return;

        wp_enqueue_style(
            'ssca-admin',
            SSCA_ASSETS . 'css/admin.css',
            [],
            SSCA_VERSION
        );

        wp_enqueue_script(
            'ssca-admin',
            SSCA_ASSETS . 'js/admin.js',
            [ 'jquery', 'wp-api-fetch' ],
            SSCA_VERSION,
            true
        );

        wp_localize_script( 'ssca-admin', 'SSCA_Admin', [
            'ajax_url'    => admin_url( 'admin-ajax.php' ),
            'rest_url'    => rest_url( 'ssca/v1/' ),
            'nonce'       => wp_create_nonce( 'ssca_admin' ),
            'rest_nonce'  => wp_create_nonce( 'wp_rest' ),
            'plugin_url'  => SSCA_URL,
            'i18n'        => [
                'confirm_run'      => __( 'Run the daily workflow now? This will select products and schedule posts.', 'ssca' ),
                'confirm_cancel'   => __( 'Cancel this scheduled post?', 'ssca' ),
                'confirm_approve'  => __( 'Approve and schedule this post?', 'ssca' ),
                'saving'           => __( 'Saving…', 'ssca' ),
                'saved'            => __( 'Saved!', 'ssca' ),
                'error'            => __( 'An error occurred. Please try again.', 'ssca' ),
            ],
        ] );

        // Media uploader for brand logo
        if ( str_contains( $hook, 'ssca-settings' ) ) {
            wp_enqueue_media();
        }
    }

    public function add_plugin_links( array $links ): array {
        $custom = [
            '<a href="' . admin_url( 'admin.php?page=' . self::MENU_SLUG ) . '">'    . __( 'Dashboard', 'ssca' ) . '</a>',
            '<a href="' . admin_url( 'admin.php?page=ssca-settings' ) . '">'         . __( 'Settings',  'ssca' ) . '</a>',
        ];
        return array_merge( $custom, $links );
    }

    private function get_menu_icon(): string {
        // Inline SVG star icon (base64 encoded for WP menu)
        return 'data:image/svg+xml;base64,' . base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="white">
                <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
            </svg>'
        );
    }
}
