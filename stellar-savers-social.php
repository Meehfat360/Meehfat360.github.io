<?php
/**
 * Plugin Name: StellarSavers AI Social Commerce
 * Plugin URI:  https://stellarsavers.com/plugins/ai-social-commerce
 * Description: AI-powered social media automation for WooCommerce — auto-selects products, generates creatives, writes captions, and publishes to Facebook, Instagram & Pinterest on autopilot.
 * Version:     1.0.0
 * Author:      StellarSavers
 * Author URI:  https://stellarsavers.com
 * License:     GPL-2.0+
 * Text Domain: ssca
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 */

defined( 'ABSPATH' ) || exit;

// ─── Constants ───────────────────────────────────────────────────────────────
define( 'SSCA_VERSION',     '1.0.0' );
define( 'SSCA_FILE',        __FILE__ );
define( 'SSCA_DIR',         plugin_dir_path( __FILE__ ) );
define( 'SSCA_URL',         plugin_dir_url( __FILE__ ) );
define( 'SSCA_ASSETS',      SSCA_URL  . 'assets/' );
define( 'SSCA_BASENAME',    plugin_basename( __FILE__ ) );
define( 'SSCA_MIN_PHP',     '8.0' );
define( 'SSCA_MIN_WP',      '6.0' );
define( 'SSCA_MIN_WC',      '7.0' );

// ─── Autoloader ──────────────────────────────────────────────────────────────
spl_autoload_register( function ( $class ) {
    $prefix = 'SSCA\\';
    if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) return;

    $map = [
        'SSCA\\DB\\Schema'                      => 'db/class-schema.php',
        'SSCA\\DB\\Repository'                  => 'db/class-repository.php',
        'SSCA\\Engines\\ProductSelector'        => 'engines/class-product-selector.php',
        'SSCA\\Engines\\ScoringMatrix'          => 'engines/class-scoring-matrix.php',
        'SSCA\\Engines\\SeasonalEngine'         => 'engines/class-seasonal-engine.php',
        'SSCA\\Engines\\WinningProductDetector' => 'engines/class-winning-product-detector.php',
        'SSCA\\Engines\\EvergreenRecycler'      => 'engines/class-winning-product-detector.php',
        'SSCA\\Generators\\ImageGenerator'      => 'generators/class-image-generator.php',
        'SSCA\\Generators\\CaptionGenerator'    => 'generators/class-caption-generator.php',
        'SSCA\\Generators\\HashtagEngine'       => 'generators/class-hashtag-engine.php',
        'SSCA\\Publishers\\Queue'               => 'publishers/class-queue.php',
        'SSCA\\Publishers\\MetaPublisher'       => 'publishers/class-meta-publisher.php',
        'SSCA\\Publishers\\PinterestPublisher'  => 'publishers/class-pinterest-publisher.php',
        'SSCA\\Publishers\\APIHealthMonitor'    => 'publishers/class-api-health-monitor.php',
        'SSCA\\Analytics\\Tracker'              => 'analytics/class-tracker.php',
        'SSCA\\Analytics\\Attribution'          => 'analytics/class-attribution.php',
        'SSCA\\Analytics\\ABTest'               => 'analytics/class-ab-test.php',
        'SSCA\\Admin\\Menu'                     => 'admin/class-menu.php',
        'SSCA\\Admin\\Dashboard'                => 'admin/class-dashboard.php',
        'SSCA\\Admin\\Settings'                 => 'admin/class-settings.php',
        'SSCA\\Admin\\Calendar'                 => 'admin/class-calendar.php',
        'SSCA\\Admin\\Notifications'            => 'admin/class-notifications.php',
        'SSCA\\API\\RestController'             => 'api/class-rest-controller.php',
        'SSCA\\Utils\\Logger'                   => 'utils/class-logger.php',
        'SSCA\\Utils\\Helpers'                  => 'utils/class-helpers.php',
        'SSCA\\Utils\\BrandProfile'             => 'utils/class-brand-profile.php',
    ];

    if ( isset( $map[ $class ] ) ) {
        require_once SSCA_DIR . 'includes/' . $map[ $class ];
    }
} );

// ─── Boot ────────────────────────────────────────────────────────────────────
final class StellarSavers_Social_Commerce {

    private static ?self $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->check_requirements();
        $this->load_dependencies();
        $this->init_hooks();
    }

    private function check_requirements(): void {
        if ( version_compare( PHP_VERSION, SSCA_MIN_PHP, '<' ) ) {
            add_action( 'admin_notices', fn() => $this->requirement_notice(
                sprintf( __( 'SSCA requires PHP %s or higher. You are running %s.', 'ssca' ), SSCA_MIN_PHP, PHP_VERSION )
            ) );
            return;
        }
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', fn() => $this->requirement_notice(
                __( 'SSCA requires WooCommerce to be installed and active.', 'ssca' )
            ) );
        }
    }

    private function requirement_notice( string $msg ): void {
        echo '<div class="notice notice-error"><p><strong>StellarSavers Social Commerce:</strong> ' . esc_html( $msg ) . '</p></div>';
    }

    private function load_dependencies(): void {
        // Action Scheduler (bundled with WooCommerce — just reference it)
        if ( ! class_exists( 'ActionScheduler' ) && file_exists( WP_PLUGIN_DIR . '/woocommerce/packages/action-scheduler/action-scheduler.php' ) ) {
            require_once WP_PLUGIN_DIR . '/woocommerce/packages/action-scheduler/action-scheduler.php';
        }
    }

    private function init_hooks(): void {
        register_activation_hook(   SSCA_FILE, [ $this, 'activate'   ] );
        register_deactivation_hook( SSCA_FILE, [ $this, 'deactivate' ] );

        add_action( 'plugins_loaded',  [ $this, 'on_plugins_loaded'  ], 10 );
        add_action( 'init',            [ $this, 'on_init'            ], 10 );
        add_action( 'rest_api_init',   [ $this, 'register_rest_api'  ], 10 );
    }

    public function activate(): void {
        SSCA\DB\Schema::install();
        SSCA\Publishers\Queue::schedule_recurring();
        SSCA\Analytics\Tracker::register_rewrite_rules();
        flush_rewrite_rules();
        do_action( 'ssca_activated' );
    }

    public function deactivate(): void {
        SSCA\Publishers\Queue::unschedule_all();
        flush_rewrite_rules();
        do_action( 'ssca_deactivated' );
    }

    public function on_plugins_loaded(): void {
        load_plugin_textdomain( 'ssca', false, dirname( SSCA_BASENAME ) . '/languages' );

        if ( ! class_exists( 'WooCommerce' ) ) return;

        // Boot all subsystems
        new SSCA\Admin\Menu();
        new SSCA\Admin\Notifications();
        new SSCA\Analytics\Tracker();
        new SSCA\Analytics\Attribution();
        new SSCA\Publishers\APIHealthMonitor();
        new SSCA\Engines\SeasonalEngine();
        new SSCA\Engines\WinningProductDetector();
        new SSCA\Engines\EvergreenRecycler();

        // Action Scheduler hooks
        add_action( 'ssca_daily_workflow',     [ $this, 'run_daily_workflow'     ] );
        add_action( 'ssca_publish_post',       [ $this, 'run_publish_post'       ] );
        add_action( 'ssca_deal_alert',         [ $this, 'run_deal_alert'         ] );
        add_action( 'ssca_ab_test_evaluate',   [ $this, 'run_ab_test_evaluation' ] );

        // WooCommerce hooks
        add_action( 'woocommerce_product_set_sale_price', [ $this, 'on_product_sale'     ], 10, 2 );
        add_action( 'woocommerce_order_status_completed',  [ $this, 'on_order_completed' ], 10, 1 );
    }

    public function on_init(): void {
        SSCA\Analytics\Tracker::register_rewrite_rules();
    }

    public function register_rest_api(): void {
        $controller = new SSCA\API\RestController();
        $controller->register_routes();
    }

    // ── Daily Workflow ────────────────────────────────────────────────────────
    public function run_daily_workflow(): void {
        SSCA\Utils\Logger::info( 'Daily workflow started.' );

        $selector  = new SSCA\Engines\ProductSelector();
        $products  = $selector->select_daily_products();

        if ( empty( $products ) ) {
            SSCA\Utils\Logger::warning( 'No products selected for today.' );
            return;
        }

        $image_gen   = new SSCA\Generators\ImageGenerator();
        $caption_gen = new SSCA\Generators\CaptionGenerator();
        $queue       = new SSCA\Publishers\Queue();

        $platforms = SSCA\Utils\Helpers::get_active_platforms();
        $slots     = SSCA\Utils\Helpers::get_posting_schedule();

        foreach ( array_values( $products ) as $idx => $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product ) continue;

            $brand_profile  = new SSCA\Utils\BrandProfile();
            $season         = ( new SSCA\Engines\SeasonalEngine() )->get_current_theme();
            $approval_mode  = get_option( 'ssca_approval_mode', 'auto' );

            // Track post IDs per platform for A/B test registration
            $ab_post_ids = []; // platform => ['A' => id, 'B' => id]

            // Generate A/B variants
            foreach ( [ 'A', 'B' ] as $variant ) {
                $creatives = $image_gen->generate( $product, $variant, $season );
                $captions  = $caption_gen->generate( $product, $variant, $platforms, $brand_profile, $season );

                foreach ( $platforms as $platform ) {
                    $slot = $slots[ $platform ][ $idx ] ?? null;
                    if ( ! $slot ) continue;

                    // Stagger B variant 8 minutes after A
                    if ( $variant === 'B' ) {
                        $slot = date( 'Y-m-d H:i:s', strtotime( $slot ) + 480 );
                    }

                    $status = ( $approval_mode === 'manual' ) ? 'awaiting_approval' : 'scheduled';

                    $post_id = SSCA\DB\Repository::insert_post( [
                        'product_id'   => $product_id,
                        'platform'     => $platform,
                        'variant'      => $variant,
                        'image_path'   => $creatives[ $platform ] ?? '',
                        'caption'      => $captions[ $platform ]  ?? '',
                        'scheduled_at' => $slot,
                        'status'       => $status,
                        'approved'     => $approval_mode === 'auto' ? 1 : 0,
                        'season_theme' => $season,
                    ] );

                    // Build tracking URL now we have a post ID
                    SSCA\Analytics\Tracker::build_tracking_url( $post_id, $product_id, $platform, $variant );

                    $ab_post_ids[ $platform ][ $variant ] = $post_id;

                    // Only schedule Action Scheduler job for auto mode
                    if ( $status === 'scheduled' ) {
                        as_schedule_single_action(
                            strtotime( $slot ),
                            'ssca_publish_post',
                            [ 'post_id' => $post_id ],
                            'ssca'
                        );
                    }
                }
            }

            // Register A/B tests for each platform where both variants were created
            foreach ( $platforms as $platform ) {
                if ( ! empty( $ab_post_ids[ $platform ]['A'] ) && ! empty( $ab_post_ids[ $platform ]['B'] ) ) {
                    $test_id = SSCA\Analytics\ABTest::register(
                        $product_id,
                        $platform,
                        $ab_post_ids[ $platform ]['A'],
                        $ab_post_ids[ $platform ]['B']
                    );
                    // Link posts back to the test
                    SSCA\DB\Repository::update_post( $ab_post_ids[ $platform ]['A'], [ 'ab_test_id' => $test_id ] );
                    SSCA\DB\Repository::update_post( $ab_post_ids[ $platform ]['B'], [ 'ab_test_id' => $test_id ] );
                }
            }
        }

        SSCA\Utils\Logger::info( 'Daily workflow completed. Products processed: ' . count( $products ) );
    }

    // ── Publish Single Post ───────────────────────────────────────────────────
    public function run_publish_post( int $post_id ): void {
        $post = SSCA\DB\Repository::get_post( $post_id );
        if ( ! $post ) return;

        $approval_mode = get_option( 'ssca_approval_mode', 'auto' );
        if ( $approval_mode === 'manual' && $post['approved'] !== 1 ) {
            SSCA\Utils\Logger::info( "Post #{$post_id} awaiting manual approval." );
            return;
        }

        $publisher = match( $post['platform'] ) {
            'facebook', 'instagram' => new SSCA\Publishers\MetaPublisher(),
            'pinterest'             => new SSCA\Publishers\PinterestPublisher(),
            default                 => null,
        };

        if ( ! $publisher ) return;

        $result = $publisher->publish( $post );

        SSCA\DB\Repository::update_post( $post_id, [
            'status'       => $result['success'] ? 'published' : 'failed',
            'published_at' => $result['success'] ? current_time( 'mysql' ) : null,
            'platform_id'  => $result['platform_post_id'] ?? null,
            'error_msg'    => $result['error'] ?? null,
        ] );

        if ( ! $result['success'] ) {
            SSCA\Utils\Logger::error( "Publish failed for post #{$post_id}: " . ( $result['error'] ?? 'Unknown' ) );
            SSCA\Admin\Notifications::send_failure_alert( $post, $result['error'] ?? '' );
            // Re-queue retry
            as_schedule_single_action( time() + 3600, 'ssca_publish_post', [ 'post_id' => $post_id ], 'ssca' );
        }
    }

    // ── Deal Alert ────────────────────────────────────────────────────────────
    public function on_product_sale( $sale_price, $product ): void {
        if ( ! $sale_price || ! $product ) return;
        as_schedule_single_action( time() + 300, 'ssca_deal_alert', [ 'product_id' => $product->get_id() ], 'ssca' );
    }

    public function run_deal_alert( int $product_id ): void {
        $product   = wc_get_product( $product_id );
        if ( ! $product ) return;

        $image_gen   = new SSCA\Generators\ImageGenerator();
        $caption_gen = new SSCA\Generators\CaptionGenerator();
        $queue       = new SSCA\Publishers\Queue();
        $platforms   = SSCA\Utils\Helpers::get_active_platforms();

        foreach ( $platforms as $platform ) {
            $creative = $image_gen->generate_deal_alert( $product );
            $caption  = $caption_gen->generate_deal_alert( $product, $platform );

            $post_id = SSCA\DB\Repository::insert_post( [
                'product_id'   => $product_id,
                'platform'     => $platform,
                'variant'      => 'DEAL',
                'image_path'   => $creative,
                'caption'      => $caption,
                'scheduled_at' => current_time( 'mysql' ),
                'status'       => 'scheduled',
                'season_theme' => 'deal_alert',
            ] );

            as_schedule_single_action( time() + 60, 'ssca_publish_post', [ 'post_id' => $post_id ], 'ssca' );
        }

        SSCA\Utils\Logger::info( "Deal alert triggered for product #{$product_id}" );
    }

    // ── A/B Test Evaluation ───────────────────────────────────────────────────
    public function run_ab_test_evaluation(): void {
        ( new SSCA\Analytics\ABTest() )->evaluate_running_tests();
    }

    // ── Order Attribution ─────────────────────────────────────────────────────
    public function on_order_completed( int $order_id ): void {
        ( new SSCA\Analytics\Attribution() )->attribute_order( $order_id );
    }
}

// ─── Launch ───────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function() {
    StellarSavers_Social_Commerce::instance();
}, 5 );
