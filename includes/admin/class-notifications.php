<?php
namespace SSCA\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Notifications
 *
 * Sends admin email alerts for:
 *  - Platform API failures
 *  - Token expiry warnings
 *  - Publish failures
 *  - A/B test winners
 *  - Weekly performance digest
 */
class Notifications {

    public function __construct() {
        add_action( 'admin_notices',         [ $this, 'display_admin_notices' ] );
        add_action( 'ssca_ab_test_completed',[ $this, 'on_ab_test_completed'  ], 10, 3 );
        // Weekly digest — every Monday 9 AM
        if ( ! wp_next_scheduled( 'ssca_weekly_digest' ) ) {
            wp_schedule_event( strtotime( 'next monday 09:00:00' ), 'weekly', 'ssca_weekly_digest' );
        }
        add_action( 'ssca_weekly_digest', [ $this, 'send_weekly_digest' ] );
    }

    // ── Admin Notice Banner ───────────────────────────────────────────────────

    public function display_admin_notices(): void {
        if ( ! current_user_can( Menu::CAPABILITY ) ) return;
        $screen = get_current_screen();
        if ( ! $screen || ! str_contains( $screen->id, 'ssca' ) ) return;

        // Saved confirmation
        if ( ! empty( $_GET['saved'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ <strong>SSCA:</strong> Settings saved successfully.</p></div>';
        }

        // Failed posts alert
        $failed = \SSCA\DB\Repository::count_posts( [ 'status' => 'failed' ] );
        if ( $failed > 0 ) {
            printf(
                '<div class="notice notice-error is-dismissible"><p>🔴 <strong>SSCA:</strong> %d post(s) failed to publish. <a href="%s">View Log →</a></p></div>',
                $failed,
                esc_url( admin_url( 'admin.php?page=ssca-log&level=ERROR' ) )
            );
        }

        // Token expiry warning
        $expiry = (int) get_option( 'ssca_meta_token_expiry', 0 );
        if ( $expiry > 0 && $expiry < ( time() + ( 7 * DAY_IN_SECONDS ) ) ) {
            echo '<div class="notice notice-warning is-dismissible"><p>⚠️ <strong>SSCA:</strong> Your Meta (Facebook/Instagram) access token expires soon. <a href="' . esc_url( admin_url( 'admin.php?page=ssca-settings&section=apis' ) ) . '">Renew it →</a></p></div>';
        }

        // Pending approval
        $pending = \SSCA\DB\Repository::count_posts( [ 'status' => 'awaiting_approval' ] );
        if ( $pending > 0 ) {
            printf(
                '<div class="notice notice-info is-dismissible"><p>🔔 <strong>SSCA:</strong> %d post(s) waiting for your approval. <a href="%s">Review →</a></p></div>',
                $pending,
                esc_url( admin_url( 'admin.php?page=ssca-dashboard' ) )
            );
        }
    }

    // ── Email Alerts ──────────────────────────────────────────────────────────

    public static function send_failure_alert( array $post, string $error ): void {
        $email    = get_option( 'ssca_approval_email', get_option( 'admin_email' ) );
        $product  = wc_get_product( $post['product_id'] );
        $plt      = \SSCA\Utils\Helpers::platform_meta( $post['platform'] );

        $subject  = sprintf( '[SSCA] Failed to publish on %s', $plt['label'] );
        $body     = self::wrap_email( sprintf(
            '<h2>Post Publish Failure</h2>
            <p><strong>Platform:</strong> %s %s</p>
            <p><strong>Product:</strong> %s</p>
            <p><strong>Error:</strong> %s</p>
            <p><strong>Time:</strong> %s</p>
            <p><a href="%s" style="background:#2563eb;color:white;padding:10px 20px;text-decoration:none;border-radius:6px">View Activity Log</a></p>',
            $plt['icon'], esc_html( $plt['label'] ),
            $product ? esc_html( $product->get_name() ) : '#' . $post['product_id'],
            esc_html( $error ),
            esc_html( current_time( 'mysql' ) ),
            esc_url( admin_url( 'admin.php?page=ssca-log&level=ERROR' ) )
        ) );

        wp_mail( $email, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );
    }

    public static function send_health_alert( string $platform, string $message ): void {
        // Throttle: only send once per 6 hours per platform
        $throttle_key = 'ssca_health_alert_sent_' . $platform;
        if ( get_transient( $throttle_key ) ) return;
        set_transient( $throttle_key, 1, 6 * HOUR_IN_SECONDS );

        $email   = get_option( 'ssca_approval_email', get_option( 'admin_email' ) );
        $plt     = \SSCA\Utils\Helpers::platform_meta( $platform );
        $subject = sprintf( '[SSCA] ⚠️ %s connection issue', $plt['label'] );
        $body    = self::wrap_email( sprintf(
            '<h2>%s Platform Alert</h2>
            <p>Your <strong>%s</strong> connection has a problem:</p>
            <p style="background:#fee2e2;padding:12px;border-radius:6px;color:#991b1b">%s</p>
            <p><a href="%s" style="background:#2563eb;color:white;padding:10px 20px;text-decoration:none;border-radius:6px">Fix API Settings →</a></p>',
            $plt['icon'] . ' ' . $plt['label'],
            esc_html( $plt['label'] ),
            esc_html( $message ),
            esc_url( admin_url( 'admin.php?page=ssca-settings&section=apis' ) )
        ) );

        wp_mail( $email, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );
    }

    public static function send_token_expiry_warning( string $platform ): void {
        $throttle_key = 'ssca_token_expiry_warned_' . $platform;
        if ( get_transient( $throttle_key ) ) return;
        set_transient( $throttle_key, 1, 24 * HOUR_IN_SECONDS );

        $email   = get_option( 'ssca_approval_email', get_option( 'admin_email' ) );
        $plt     = \SSCA\Utils\Helpers::platform_meta( $platform );
        $subject = sprintf( '[SSCA] %s token expiring soon', $plt['label'] );
        $body    = self::wrap_email( sprintf(
            '<h2>Token Expiry Warning</h2>
            <p>Your <strong>%s</strong> access token expires within 7 days.</p>
            <p>Renew it now to avoid disruption to your automated posts.</p>
            <p><a href="%s" style="background:#d97706;color:white;padding:10px 20px;text-decoration:none;border-radius:6px">Renew Token →</a></p>',
            esc_html( $plt['label'] ),
            esc_url( admin_url( 'admin.php?page=ssca-settings&section=apis' ) )
        ) );

        wp_mail( $email, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );
    }

    public function on_ab_test_completed( int $test_id, ?string $winner, array $test ): void {
        if ( ! $winner ) return;
        $email   = get_option( 'ssca_approval_email', get_option( 'admin_email' ) );
        $product = wc_get_product( $test['product_id'] );
        $plt     = \SSCA\Utils\Helpers::platform_meta( $test['platform'] );
        $subject = sprintf( '[SSCA] 🏆 A/B Test Winner: Variant %s', $winner );
        $body    = self::wrap_email( sprintf(
            '<h2>🏆 A/B Test Result</h2>
            <p><strong>Product:</strong> %s</p>
            <p><strong>Platform:</strong> %s</p>
            <p><strong>Winner:</strong> Variant %s</p>
            <p><strong>Clicks A:</strong> %d | <strong>Clicks B:</strong> %d</p>
            <p>Future posts for this product on %s will use Variant %s automatically.</p>
            <p><a href="%s" style="background:#16a34a;color:white;padding:10px 20px;text-decoration:none;border-radius:6px">View A/B Tests →</a></p>',
            $product ? esc_html( $product->get_name() ) : '#' . $test['product_id'],
            esc_html( $plt['icon'] . ' ' . $plt['label'] ),
            esc_html( $winner ),
            (int) $test['clicks_a'], (int) $test['clicks_b'],
            esc_html( $plt['label'] ),
            esc_html( $winner ),
            esc_url( admin_url( 'admin.php?page=ssca-ab-tests' ) )
        ) );
        wp_mail( $email, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );
    }

    public function send_weekly_digest(): void {
        $email    = get_option( 'ssca_approval_email', get_option( 'admin_email' ) );
        $metrics  = \SSCA\DB\Repository::get_dashboard_metrics();
        $top      = \SSCA\DB\Repository::get_top_performing_products( 3 );

        $top_html = '';
        foreach ( $top as $i => $p ) {
            $product  = wc_get_product( $p['product_id'] );
            if ( ! $product ) continue;
            $top_html .= sprintf(
                '<tr><td>%d</td><td>%s</td><td>%d</td><td>%d</td><td>%s</td></tr>',
                $i + 1,
                esc_html( $product->get_name() ),
                $p['total_clicks'],
                $p['total_orders'],
                wp_strip_all_tags( wc_price( (float) $p['total_revenue'] ) )
            );
        }

        $subject = sprintf( '[SSCA] Weekly Performance Digest — %s', get_bloginfo( 'name' ) );
        $body    = self::wrap_email( sprintf(
            '<h2>📊 Weekly Performance Digest</h2>
            <table style="width:100%%;border-collapse:collapse">
                <tr><td style="padding:8px;border-bottom:1px solid #e5e7eb">Posts this month</td><td><strong>%d</strong></td></tr>
                <tr><td style="padding:8px;border-bottom:1px solid #e5e7eb">Total clicks</td><td><strong>%s</strong></td></tr>
                <tr><td style="padding:8px;border-bottom:1px solid #e5e7eb">Attributed orders</td><td><strong>%d</strong></td></tr>
                <tr><td style="padding:8px">Attributed revenue</td><td><strong>%s</strong></td></tr>
            </table>
            <h3 style="margin-top:24px">🏆 Top Performing Products</h3>
            <table style="width:100%%;border-collapse:collapse">
                <thead><tr style="background:#f8fafc"><th>Rank</th><th>Product</th><th>Clicks</th><th>Orders</th><th>Revenue</th></tr></thead>
                <tbody>%s</tbody>
            </table>
            <p style="margin-top:24px"><a href="%s" style="background:#2563eb;color:white;padding:10px 20px;text-decoration:none;border-radius:6px">View Full Dashboard →</a></p>',
            $metrics['month_published'],
            esc_html( \SSCA\Utils\Helpers::format_number( $metrics['month_clicks'] ) ),
            $metrics['month_orders'],
            wp_strip_all_tags( wc_price( $metrics['month_revenue'] ) ),
            $top_html,
            esc_url( admin_url( 'admin.php?page=ssca-analytics' ) )
        ) );

        wp_mail( $email, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );
    }

    // ── Email Template ────────────────────────────────────────────────────────

    private static function wrap_email( string $content ): string {
        $store = get_bloginfo( 'name' );
        return <<<HTML
<!DOCTYPE html>
<html>
<body style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f3f4f6;margin:0;padding:20px">
    <div style="max-width:600px;margin:0 auto;background:white;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.1)">
        <div style="background:linear-gradient(135deg,#1e40af,#7c3aed);padding:24px;text-align:center">
            <h1 style="color:white;margin:0;font-size:20px">⭐ StellarSavers Social Commerce</h1>
            <p style="color:#bfdbfe;margin:4px 0 0;font-size:13px">{$store}</p>
        </div>
        <div style="padding:32px">
            {$content}
        </div>
        <div style="background:#f9fafb;padding:16px;text-align:center;font-size:12px;color:#9ca3af">
            You received this because you manage StellarSavers Social Commerce. <a href="%s" style="color:#6b7280">Unsubscribe</a>
        </div>
    </div>
</body>
</html>
HTML;
    }
}
