<?php
namespace SSCA\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Admin Dashboard
 * Renders: Dashboard, Analytics, A/B Tests, Activity Log pages.
 */
class Dashboard {

    public function render(): void {
        $metrics      = \SSCA\DB\Repository::get_dashboard_metrics();
        $queue_status = \SSCA\Publishers\Queue::get_status();
        $health       = \SSCA\Publishers\APIHealthMonitor::get_status();
        $season       = ( new \SSCA\Engines\SeasonalEngine() )->get_current_theme();
        $season_meta  = ( new \SSCA\Engines\SeasonalEngine() )->get_theme_meta( $season );
        $today_posts  = \SSCA\DB\Repository::get_posts( [
            'date_from' => current_time( 'Y-m-d' ) . ' 00:00:00',
            'date_to'   => current_time( 'Y-m-d' ) . ' 23:59:59',
            'limit'     => 20,
            'orderby'   => 'scheduled_at ASC',
        ] );
        $pending_approval = \SSCA\DB\Repository::get_posts( [ 'status' => 'awaiting_approval', 'limit' => 10 ] );
        $top_products     = \SSCA\DB\Repository::get_top_performing_products( 5 );
        $winners          = \SSCA\Engines\WinningProductDetector::get_winners( 5 );

        ?>
        <div class="ssca-wrap">
            <?php $this->render_header( 'Dashboard' ); ?>

            <!-- Season Banner -->
            <div class="ssca-season-banner" style="background: linear-gradient(135deg, <?php echo esc_attr( $season_meta['colors'][0] ); ?>, <?php echo esc_attr( $season_meta['colors'][1] ); ?>);">
                <span class="ssca-season-emoji"><?php echo esc_html( $season_meta['emoji'] ); ?></span>
                <div>
                    <strong><?php echo esc_html( $season_meta['label'] ); ?> Campaign Active</strong>
                    <span><?php echo esc_html( $season_meta['cta'] ); ?></span>
                </div>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ssca-settings#season' ) ); ?>" class="ssca-btn ssca-btn-sm ssca-btn-white">Change Theme</a>
            </div>

            <!-- Stat Cards -->
            <div class="ssca-stat-grid">
                <?php
                $stats = [
                    [ 'label' => 'Scheduled Today',    'value' => $metrics['today_scheduled'],  'icon' => '📅', 'color' => 'blue'   ],
                    [ 'label' => 'Published Today',    'value' => $metrics['today_published'],  'icon' => '✅', 'color' => 'green'  ],
                    [ 'label' => 'Posts This Month',   'value' => $metrics['month_published'],  'icon' => '📤', 'color' => 'purple' ],
                    [ 'label' => 'Clicks This Month',  'value' => \SSCA\Utils\Helpers::format_number( $metrics['month_clicks'] ),   'icon' => '👆', 'color' => 'orange' ],
                    [ 'label' => 'Orders Attributed',  'value' => $metrics['month_orders'],     'icon' => '🛒', 'color' => 'teal'   ],
                    [ 'label' => 'Revenue Attributed', 'value' => wc_price( $metrics['month_revenue'] ), 'icon' => '💰', 'color' => 'gold', 'raw' => true ],
                ];
                foreach ( $stats as $s ) : ?>
                    <div class="ssca-stat-card ssca-stat-<?php echo esc_attr( $s['color'] ); ?>">
                        <div class="ssca-stat-icon"><?php echo esc_html( $s['icon'] ); ?></div>
                        <div class="ssca-stat-body">
                            <div class="ssca-stat-value"><?php echo ! empty( $s['raw'] ) ? wp_kses_post( $s['value'] ) : esc_html( $s['value'] ); ?></div>
                            <div class="ssca-stat-label"><?php echo esc_html( $s['label'] ); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="ssca-dashboard-grid">

                <!-- Today's Queue -->
                <div class="ssca-card ssca-card-full">
                    <div class="ssca-card-header">
                        <h2>📋 Today's Post Queue</h2>
                        <div class="ssca-card-actions">
                            <?php if ( $metrics['pending_approval'] > 0 ) : ?>
                                <span class="ssca-badge ssca-badge-yellow"><?php echo (int) $metrics['pending_approval']; ?> awaiting approval</span>
                            <?php endif; ?>
                            <button class="ssca-btn ssca-btn-primary" id="ssca-run-workflow">
                                ▶ Run Daily Workflow Now
                            </button>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ssca-calendar' ) ); ?>" class="ssca-btn ssca-btn-secondary">
                                📅 Open Calendar
                            </a>
                        </div>
                    </div>
                    <?php if ( empty( $today_posts ) ) : ?>
                        <div class="ssca-empty-state">
                            <div class="ssca-empty-icon">📭</div>
                            <p>No posts scheduled for today yet.</p>
                            <button class="ssca-btn ssca-btn-primary" id="ssca-run-workflow-2">Run Workflow to Generate Posts</button>
                        </div>
                    <?php else : ?>
                        <table class="ssca-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Platform</th>
                                    <th>Variant</th>
                                    <th>Scheduled</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $today_posts as $post ) :
                                    $product  = wc_get_product( $post['product_id'] );
                                    $plt_meta = \SSCA\Utils\Helpers::platform_meta( $post['platform'] );
                                    $st_meta  = \SSCA\Utils\Helpers::status_meta( $post['status'] );
                                ?>
                                <tr data-post-id="<?php echo (int) $post['id']; ?>">
                                    <td>
                                        <div class="ssca-product-cell">
                                            <?php if ( $product ) : ?>
                                                <img src="<?php echo esc_url( \SSCA\Utils\Helpers::get_product_image_url( $product, 'thumbnail' ) ); ?>" alt="" width="40" height="40">
                                                <div>
                                                    <strong><?php echo esc_html( $product->get_name() ); ?></strong>
                                                    <small><?php echo wp_kses_post( wc_price( (float) $product->get_price() ) ); ?></small>
                                                </div>
                                            <?php else : ?>
                                                <span>Product #<?php echo (int) $post['product_id']; ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="ssca-platform-badge" style="background:<?php echo esc_attr( $plt_meta['color'] ); ?>20; color:<?php echo esc_attr( $plt_meta['color'] ); ?>">
                                            <?php echo esc_html( $plt_meta['icon'] . ' ' . $plt_meta['label'] ); ?>
                                        </span>
                                    </td>
                                    <td><span class="ssca-variant-badge ssca-variant-<?php echo esc_attr( strtolower( $post['variant'] ) ); ?>"><?php echo esc_html( $post['variant'] ); ?></span></td>
                                    <td><?php echo esc_html( \SSCA\Utils\Helpers::human_time_diff_full( $post['scheduled_at'] ) ); ?></td>
                                    <td><span class="ssca-status-badge ssca-status-<?php echo esc_attr( $st_meta['badge'] ); ?>"><?php echo esc_html( $st_meta['label'] ); ?></span></td>
                                    <td>
                                        <div class="ssca-row-actions">
                                            <button class="ssca-btn-icon ssca-preview-post" data-id="<?php echo (int) $post['id']; ?>" title="Preview">👁</button>
                                            <?php if ( $post['status'] === 'awaiting_approval' ) : ?>
                                                <button class="ssca-btn-icon ssca-approve-post" data-id="<?php echo (int) $post['id']; ?>" title="Approve">✅</button>
                                            <?php endif; ?>
                                            <?php if ( in_array( $post['status'], [ 'scheduled', 'awaiting_approval' ], true ) ) : ?>
                                                <button class="ssca-btn-icon ssca-cancel-post" data-id="<?php echo (int) $post['id']; ?>" title="Cancel">❌</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- API Health -->
                <div class="ssca-card">
                    <div class="ssca-card-header">
                        <h2>🔌 Platform Health</h2>
                        <button class="ssca-btn ssca-btn-sm" id="ssca-refresh-health">Refresh</button>
                    </div>
                    <div class="ssca-health-list">
                        <?php foreach ( $health as $platform => $status ) :
                            $ok    = ( $status['status'] ?? '' ) === 'ok';
                            $color = $ok ? '#16a34a' : ( $status['status'] === 'unknown' ? '#d97706' : '#dc2626' );
                            $icon  = $ok ? '🟢' : ( $status['status'] === 'unknown' ? '🟡' : '🔴' );
                            $plt   = \SSCA\Utils\Helpers::platform_meta( $platform );
                        ?>
                            <div class="ssca-health-item">
                                <span><?php echo esc_html( $icon . ' ' . $plt['label'] ); ?></span>
                                <span class="ssca-health-msg" style="color:<?php echo esc_attr( $color ); ?>">
                                    <?php echo esc_html( $ok ? 'Connected' : ( $status['message'] ?? 'Error' ) ); ?>
                                    <?php if ( ! empty( $status['token_expiring'] ) ) echo ' ⚠️ Token expiring soon'; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="ssca-health-footer">
                        <small>Last checked: <?php echo esc_html( get_option( 'ssca_last_health_check', 'Never' ) ); ?></small>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ssca-settings#apis' ) ); ?>" class="ssca-link">Manage APIs →</a>
                    </div>
                </div>

                <!-- Queue Status -->
                <div class="ssca-card">
                    <div class="ssca-card-header"><h2>⚙️ Automation Status</h2></div>
                    <div class="ssca-queue-info">
                        <div class="ssca-queue-item">
                            <span>Next Daily Run</span>
                            <strong><?php echo esc_html( $queue_status['next_daily_run'] ?? 'Not scheduled' ); ?></strong>
                        </div>
                        <div class="ssca-queue-item">
                            <span>Posts Queued</span>
                            <strong><?php echo (int) ( $queue_status['pending_publish'] ?? 0 ); ?></strong>
                        </div>
                        <div class="ssca-queue-item">
                            <span>Failed Posts</span>
                            <strong class="<?php echo $metrics['failed_posts'] > 0 ? 'ssca-text-red' : ''; ?>">
                                <?php echo (int) $metrics['failed_posts']; ?>
                            </strong>
                        </div>
                        <div class="ssca-queue-item">
                            <span>Active Season</span>
                            <strong><?php echo esc_html( $season_meta['emoji'] . ' ' . $season_meta['label'] ); ?></strong>
                        </div>
                    </div>
                </div>

                <!-- Top Winners -->
                <div class="ssca-card">
                    <div class="ssca-card-header">
                        <h2>🏆 Winning Products</h2>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ssca-analytics' ) ); ?>" class="ssca-link">View All →</a>
                    </div>
                    <?php if ( empty( $winners ) ) : ?>
                        <p class="ssca-muted">Not enough data yet. Winners appear after products are promoted.</p>
                    <?php else : ?>
                        <div class="ssca-winners-list">
                            <?php foreach ( $winners as $i => $w ) :
                                $product = wc_get_product( $w['product_id'] );
                                if ( ! $product ) continue;
                            ?>
                                <div class="ssca-winner-item">
                                    <span class="ssca-winner-rank">#<?php echo $i + 1; ?></span>
                                    <img src="<?php echo esc_url( \SSCA\Utils\Helpers::get_product_image_url( $product, 'thumbnail' ) ); ?>" width="36" height="36" alt="">
                                    <div class="ssca-winner-info">
                                        <strong><?php echo esc_html( $product->get_name() ); ?></strong>
                                        <small>Score: <?php echo esc_html( $w['score'] ); ?> | Revenue: <?php echo wp_kses_post( wc_price( (float) $w['total_revenue'] ) ); ?></small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

            </div><!-- .ssca-dashboard-grid -->

            <!-- Post Preview Modal -->
            <div id="ssca-post-modal" class="ssca-modal" style="display:none;">
                <div class="ssca-modal-content">
                    <div class="ssca-modal-header">
                        <h3>Post Preview</h3>
                        <button class="ssca-modal-close">×</button>
                    </div>
                    <div class="ssca-modal-body" id="ssca-modal-body">
                        <div class="ssca-loading">Loading…</div>
                    </div>
                </div>
            </div>

        </div><!-- .ssca-wrap -->
        <?php
    }

    public function render_analytics(): void {
        $from = sanitize_text_field( $_GET['from'] ?? date( 'Y-m-01' ) );
        $to   = sanitize_text_field( $_GET['to']   ?? date( 'Y-m-d' ) );

        $from_dt = $from . ' 00:00:00';
        $to_dt   = $to   . ' 23:59:59';

        $summary       = \SSCA\DB\Repository::get_analytics_summary( $from_dt, $to_dt );
        $platform_stats= \SSCA\DB\Repository::get_platform_stats( $from_dt, $to_dt );
        $attr_summary  = \SSCA\Analytics\Attribution::get_summary( $from_dt, $to_dt );
        $top_products  = \SSCA\DB\Repository::get_top_performing_products( 10 );
        ?>
        <div class="ssca-wrap">
            <?php $this->render_header( 'Analytics' ); ?>

            <!-- Date Filter -->
            <div class="ssca-card ssca-filters">
                <form method="get">
                    <input type="hidden" name="page" value="ssca-analytics">
                    <label>From: <input type="date" name="from" value="<?php echo esc_attr( $from ); ?>"></label>
                    <label>To: <input type="date" name="to" value="<?php echo esc_attr( $to ); ?>"></label>
                    <button type="submit" class="ssca-btn ssca-btn-primary">Apply</button>
                </form>
            </div>

            <!-- Summary Metrics -->
            <div class="ssca-stat-grid">
                <?php
                $s = $summary ?: [];
                $stats = [
                    [ 'Total Clicks',      $s['total_clicks']      ?? 0,   '👆' ],
                    [ 'Total Impressions', $s['total_impressions']  ?? 0,   '👀' ],
                    [ 'Add to Carts',      $s['total_atc']         ?? 0,   '🛒' ],
                    [ 'Attributed Orders', array_sum( array_column( $attr_summary, 'attributed_orders' ) ), '📦' ],
                    [ 'Attributed Revenue',wc_price( array_sum( array_column( $attr_summary, 'attributed_revenue' ) ) ), '💰', true ],
                ];
                foreach ( $stats as $stat ) :
                    [ $label, $value, $icon ] = $stat;
                    $raw = $stat[3] ?? false;
                    ?>
                    <div class="ssca-stat-card">
                        <div class="ssca-stat-icon"><?php echo esc_html( $icon ); ?></div>
                        <div class="ssca-stat-body">
                            <div class="ssca-stat-value"><?php echo $raw ?? false ? wp_kses_post( $value ) : esc_html( \SSCA\Utils\Helpers::format_number( (int) $value ) ); ?></div>
                            <div class="ssca-stat-label"><?php echo esc_html( $label ); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Platform Breakdown -->
            <div class="ssca-dashboard-grid">
                <div class="ssca-card">
                    <div class="ssca-card-header"><h2>📊 Platform Breakdown</h2></div>
                    <table class="ssca-table">
                        <thead><tr><th>Platform</th><th>Events</th><th>Clicks</th><th>Orders</th><th>Revenue</th></tr></thead>
                        <tbody>
                            <?php foreach ( $platform_stats as $row ) :
                                $attr   = array_filter( $attr_summary, fn($a) => $a['platform'] === $row['platform'] );
                                $attr   = reset( $attr ) ?: [];
                                $plt    = \SSCA\Utils\Helpers::platform_meta( $row['platform'] );
                            ?>
                            <tr>
                                <td><span style="color:<?php echo esc_attr( $plt['color'] ); ?>"><?php echo esc_html( $plt['icon'] . ' ' . $plt['label'] ); ?></span></td>
                                <td><?php echo esc_html( \SSCA\Utils\Helpers::format_number( (int) $row['events'] ) ); ?></td>
                                <td><?php echo esc_html( \SSCA\Utils\Helpers::format_number( (int) $row['clicks'] ) ); ?></td>
                                <td><?php echo esc_html( $attr['attributed_orders'] ?? 0 ); ?></td>
                                <td><?php echo wp_kses_post( wc_price( (float) ( $attr['attributed_revenue'] ?? 0 ) ) ); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Top Products by Performance -->
                <div class="ssca-card">
                    <div class="ssca-card-header"><h2>🏅 Top Products by Revenue</h2></div>
                    <table class="ssca-table">
                        <thead><tr><th>Product</th><th>Posts</th><th>Clicks</th><th>Orders</th><th>Revenue</th></tr></thead>
                        <tbody>
                            <?php foreach ( $top_products as $row ) :
                                $product = wc_get_product( $row['product_id'] );
                                if ( ! $product ) continue;
                            ?>
                            <tr>
                                <td><?php echo esc_html( $product->get_name() ); ?></td>
                                <td><?php echo (int) $row['total_posts']; ?></td>
                                <td><?php echo (int) $row['total_clicks']; ?></td>
                                <td><?php echo (int) $row['total_orders']; ?></td>
                                <td><?php echo wp_kses_post( wc_price( (float) $row['total_revenue'] ) ); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_ab_tests(): void {
        $tests = \SSCA\Analytics\ABTest::get_tests_for_display( 30 );
        ?>
        <div class="ssca-wrap">
            <?php $this->render_header( 'A/B Tests' ); ?>
            <div class="ssca-card">
                <div class="ssca-card-header"><h2>🧪 A/B Test Results</h2></div>
                <?php if ( empty( $tests ) ) : ?>
                    <div class="ssca-empty-state">
                        <div class="ssca-empty-icon">🧪</div>
                        <p>No A/B tests run yet. They start automatically once posts are published.</p>
                    </div>
                <?php else : ?>
                <table class="ssca-table">
                    <thead>
                        <tr>
                            <th>Product</th><th>Platform</th>
                            <th>Clicks A</th><th>Clicks B</th>
                            <th>Orders A</th><th>Orders B</th>
                            <th>Winner</th><th>Status</th><th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $tests as $t ) :
                            $plt    = \SSCA\Utils\Helpers::platform_meta( $t['platform'] );
                            $winner = $t['winner_variant'] ?? null;
                        ?>
                        <tr>
                            <td><?php echo esc_html( $t['post_title'] ?? 'Product #' . $t['product_id'] ); ?></td>
                            <td><span style="color:<?php echo esc_attr( $plt['color'] ); ?>"><?php echo esc_html( $plt['icon'] . ' ' . $plt['label'] ); ?></span></td>
                            <td class="<?php echo $winner === 'A' ? 'ssca-winner-cell' : ''; ?>"><?php echo (int) $t['clicks_a']; ?></td>
                            <td class="<?php echo $winner === 'B' ? 'ssca-winner-cell' : ''; ?>"><?php echo (int) $t['clicks_b']; ?></td>
                            <td><?php echo (int) $t['orders_a']; ?></td>
                            <td><?php echo (int) $t['orders_b']; ?></td>
                            <td>
                                <?php if ( $winner ) : ?>
                                    <span class="ssca-badge ssca-badge-green">Variant <?php echo esc_html( $winner ); ?> 🏆</span>
                                <?php elseif ( $t['status'] === 'inconclusive' ) : ?>
                                    <span class="ssca-badge ssca-badge-gray">Inconclusive</span>
                                <?php else : ?>
                                    <span class="ssca-badge ssca-badge-blue">Running…</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $t['status'] ); ?></td>
                            <td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $t['started_at'] ) ) ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function render_log(): void {
        $level = sanitize_text_field( $_GET['level'] ?? '' );
        $logs  = \SSCA\Utils\Logger::get_recent( 100, $level );
        ?>
        <div class="ssca-wrap">
            <?php $this->render_header( 'Activity Log' ); ?>
            <div class="ssca-card">
                <div class="ssca-card-header">
                    <h2>📋 Activity Log</h2>
                    <div class="ssca-card-actions">
                        <a href="?page=ssca-log&level=ERROR" class="ssca-btn ssca-btn-sm <?php echo $level === 'ERROR' ? 'ssca-btn-primary' : ''; ?>">Errors Only</a>
                        <a href="?page=ssca-log" class="ssca-btn ssca-btn-sm <?php echo ! $level ? 'ssca-btn-primary' : ''; ?>">All</a>
                        <button id="ssca-clear-log" class="ssca-btn ssca-btn-sm ssca-btn-danger">Clear Log</button>
                    </div>
                </div>
                <div class="ssca-log-container">
                    <?php foreach ( $logs as $entry ) :
                        $class = match( $entry['level'] ) {
                            'ERROR'   => 'ssca-log-error',
                            'WARNING' => 'ssca-log-warning',
                            default   => 'ssca-log-info',
                        };
                    ?>
                        <div class="ssca-log-entry <?php echo esc_attr( $class ); ?>">
                            <span class="ssca-log-time"><?php echo esc_html( $entry['time'] ); ?></span>
                            <span class="ssca-log-level"><?php echo esc_html( $entry['level'] ); ?></span>
                            <span class="ssca-log-msg"><?php echo esc_html( $entry['message'] ); ?></span>
                        </div>
                    <?php endforeach; ?>
                    <?php if ( empty( $logs ) ) : ?>
                        <div class="ssca-empty-state"><p>No log entries found.</p></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    // ── Shared Header (public alias for external classes) ─────────────────────
    public function render_header_only( string $page_title ): void {
        $this->render_header( $page_title );
    }

    // ── Shared Header ─────────────────────────────────────────────────────────
    private function render_header( string $page_title ): void {
        $nav_items = [
            'ssca-dashboard' => [ '🏠', 'Dashboard'  ],
            'ssca-calendar'  => [ '📅', 'Calendar'   ],
            'ssca-analytics' => [ '📊', 'Analytics'  ],
            'ssca-ab-tests'  => [ '🧪', 'A/B Tests'  ],
            'ssca-log'       => [ '📋', 'Log'        ],
            'ssca-settings'  => [ '⚙️', 'Settings'   ],
        ];
        $current = sanitize_text_field( $_GET['page'] ?? 'ssca-dashboard' );
        ?>
        <div class="ssca-page-header">
            <div class="ssca-logo">
                <span class="ssca-logo-icon">⭐</span>
                <div>
                    <h1>StellarSavers Social Commerce</h1>
                    <small><?php echo esc_html( $page_title ); ?></small>
                </div>
            </div>
            <nav class="ssca-nav">
                <?php foreach ( $nav_items as $slug => [ $icon, $label ] ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>"
                       class="ssca-nav-item <?php echo $current === $slug ? 'ssca-nav-active' : ''; ?>">
                        <?php echo esc_html( $icon . ' ' . $label ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>
        <?php
    }
}
