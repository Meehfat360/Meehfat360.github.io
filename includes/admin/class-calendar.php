<?php
namespace SSCA\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Content Calendar
 * Visual 30-day calendar view of all scheduled/published posts.
 */
class Calendar {

    public function render(): void {
        $view      = sanitize_text_field( $_GET['view'] ?? 'month' );
        $year      = (int) ( $_GET['year']  ?? current_time( 'Y' ) );
        $month     = (int) ( $_GET['month'] ?? current_time( 'n' ) );

        // Fetch posts for this month
        $month_from = sprintf( '%04d-%02d-01 00:00:00', $year, $month );
        $month_to   = sprintf( '%04d-%02d-%02d 23:59:59', $year, $month, cal_days_in_month( CAL_GREGORIAN, $month, $year ) );

        $posts = \SSCA\DB\Repository::get_posts( [
            'date_from' => $month_from,
            'date_to'   => $month_to,
            'limit'     => 500,
            'orderby'   => 'scheduled_at ASC',
        ] );

        // Group posts by day
        $by_day = [];
        foreach ( $posts as $post ) {
            $day = (int) date( 'j', strtotime( $post['scheduled_at'] ) );
            $by_day[ $day ][] = $post;
        }

        $prev_month = $month - 1 < 1  ? [ 'month' => 12, 'year' => $year - 1 ] : [ 'month' => $month - 1, 'year' => $year ];
        $next_month = $month + 1 > 12 ? [ 'month' => 1,  'year' => $year + 1 ] : [ 'month' => $month + 1, 'year' => $year ];

        $month_name  = date_i18n( 'F Y', mktime( 0, 0, 0, $month, 1, $year ) );
        $first_dow   = (int) date( 'w', mktime( 0, 0, 0, $month, 1, $year ) ); // 0 = Sunday
        $days_in_month = cal_days_in_month( CAL_GREGORIAN, $month, $year );
        $today_day   = ( $year === (int) current_time( 'Y' ) && $month === (int) current_time( 'n' ) )
                       ? (int) current_time( 'j' )
                       : -1;
        ?>
        <div class="ssca-wrap">
            <?php ( new Dashboard() )->render_header_only( 'Content Calendar' ); ?>

            <!-- Calendar Controls -->
            <div class="ssca-calendar-controls">
                <div class="ssca-calendar-nav">
                    <a href="<?php echo esc_url( add_query_arg( [ 'year' => $prev_month['year'], 'month' => $prev_month['month'] ] ) ); ?>" class="ssca-btn ssca-btn-secondary">← Prev</a>
                    <h2><?php echo esc_html( $month_name ); ?></h2>
                    <a href="<?php echo esc_url( add_query_arg( [ 'year' => $next_month['year'], 'month' => $next_month['month'] ] ) ); ?>" class="ssca-btn ssca-btn-secondary">Next →</a>
                </div>
                <div class="ssca-calendar-actions">
                    <span class="ssca-calendar-legend">
                        <span class="ssca-dot ssca-dot-blue"></span> Scheduled
                        <span class="ssca-dot ssca-dot-green"></span> Published
                        <span class="ssca-dot ssca-dot-red"></span> Failed
                        <span class="ssca-dot ssca-dot-yellow"></span> Pending Approval
                    </span>
                    <button class="ssca-btn ssca-btn-primary" id="ssca-run-workflow">▶ Run Workflow</button>
                </div>
            </div>

            <!-- Calendar Grid -->
            <div class="ssca-calendar">
                <!-- Day headers -->
                <?php foreach ( [ 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ] as $d ) : ?>
                    <div class="ssca-cal-header"><?php echo esc_html( $d ); ?></div>
                <?php endforeach; ?>

                <!-- Empty cells before first day -->
                <?php for ( $i = 0; $i < $first_dow; $i++ ) : ?>
                    <div class="ssca-cal-day ssca-cal-empty"></div>
                <?php endfor; ?>

                <!-- Days -->
                <?php for ( $day = 1; $day <= $days_in_month; $day++ ) :
                    $day_posts  = $by_day[ $day ] ?? [];
                    $is_today   = $day === $today_day;
                    $total      = count( $day_posts );
                    $published  = count( array_filter( $day_posts, fn($p) => $p['status'] === 'published' ) );
                    $scheduled  = count( array_filter( $day_posts, fn($p) => $p['status'] === 'scheduled' ) );
                    $failed     = count( array_filter( $day_posts, fn($p) => $p['status'] === 'failed' ) );
                    $pending    = count( array_filter( $day_posts, fn($p) => $p['status'] === 'awaiting_approval' ) );
                ?>
                    <div class="ssca-cal-day <?php echo $is_today ? 'ssca-cal-today' : ''; ?> <?php echo $total > 0 ? 'ssca-cal-has-posts' : ''; ?>"
                         data-date="<?php echo esc_attr( sprintf( '%04d-%02d-%02d', $year, $month, $day ) ); ?>">

                        <div class="ssca-cal-day-num">
                            <?php echo esc_html( $day ); ?>
                            <?php if ( $is_today ) echo '<span class="ssca-today-dot">●</span>'; ?>
                        </div>

                        <?php if ( $total > 0 ) : ?>
                            <div class="ssca-cal-dots">
                                <?php if ( $published ) echo "<span class='ssca-dot ssca-dot-green' title='{$published} published'></span>"; ?>
                                <?php if ( $scheduled ) echo "<span class='ssca-dot ssca-dot-blue' title='{$scheduled} scheduled'></span>"; ?>
                                <?php if ( $pending   ) echo "<span class='ssca-dot ssca-dot-yellow' title='{$pending} pending approval'></span>"; ?>
                                <?php if ( $failed    ) echo "<span class='ssca-dot ssca-dot-red' title='{$failed} failed'></span>"; ?>
                            </div>

                            <!-- Post previews (first 3) -->
                            <div class="ssca-cal-posts">
                                <?php foreach ( array_slice( $day_posts, 0, 3 ) as $post ) :
                                    $plt   = \SSCA\Utils\Helpers::platform_meta( $post['platform'] );
                                    $st    = \SSCA\Utils\Helpers::status_meta( $post['status'] );
                                    $prod  = wc_get_product( $post['product_id'] );
                                    $name  = $prod ? mb_substr( $prod->get_name(), 0, 18 ) . ( mb_strlen( $prod->get_name() ) > 18 ? '…' : '' ) : '#' . $post['product_id'];
                                ?>
                                    <div class="ssca-cal-post-chip ssca-cal-status-<?php echo esc_attr( $post['status'] ); ?>"
                                         data-post-id="<?php echo (int) $post['id']; ?>"
                                         style="border-left: 3px solid <?php echo esc_attr( $plt['color'] ); ?>"
                                         title="<?php echo esc_attr( $plt['label'] . ' — ' . $name . ' — ' . $st['label'] ); ?>">
                                        <?php echo esc_html( $plt['icon'] . ' ' . $name ); ?>
                                    </div>
                                <?php endforeach; ?>
                                <?php if ( $total > 3 ) : ?>
                                    <div class="ssca-cal-more">+<?php echo $total - 3; ?> more</div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>

                <!-- Trailing empty cells -->
                <?php
                $total_cells  = $first_dow + $days_in_month;
                $trailing     = ( 7 - ( $total_cells % 7 ) ) % 7;
                for ( $i = 0; $i < $trailing; $i++ ) : ?>
                    <div class="ssca-cal-day ssca-cal-empty"></div>
                <?php endfor; ?>
            </div><!-- .ssca-calendar -->

            <!-- Day Detail Panel (shown on click) -->
            <div id="ssca-day-panel" class="ssca-day-panel" style="display:none;">
                <div class="ssca-day-panel-header">
                    <h3 id="ssca-day-panel-title"></h3>
                    <button class="ssca-day-panel-close">×</button>
                </div>
                <div id="ssca-day-panel-body"></div>
            </div>

        </div><!-- .ssca-wrap -->

        <!-- Inline day data for JS -->
        <script>
        window.SSCA_Calendar = {
            posts: <?php echo wp_json_encode( $this->posts_for_js( $posts ) ); ?>,
            year:  <?php echo (int) $year; ?>,
            month: <?php echo (int) $month; ?>,
        };
        </script>
        <?php
    }

    private function posts_for_js( array $posts ): array {
        $result = [];
        foreach ( $posts as $post ) {
            $product = wc_get_product( $post['product_id'] );
            $plt     = \SSCA\Utils\Helpers::platform_meta( $post['platform'] );
            $st      = \SSCA\Utils\Helpers::status_meta( $post['status'] );

            $result[] = [
                'id'           => (int) $post['id'],
                'product_id'   => (int) $post['product_id'],
                'product_name' => $product ? $product->get_name() : 'Unknown',
                'platform'     => $post['platform'],
                'platform_label' => $plt['label'],
                'platform_icon'  => $plt['icon'],
                'platform_color' => $plt['color'],
                'variant'      => $post['variant'],
                'status'       => $post['status'],
                'status_label' => $st['label'],
                'status_badge' => $st['badge'],
                'scheduled_at' => $post['scheduled_at'],
                'caption_excerpt' => $post['caption'] ? mb_substr( $post['caption'], 0, 100 ) . '…' : '',
                'image_url'    => $post['image_path'] ? ( new \SSCA\Generators\ImageGenerator() )->path_to_url( $post['image_path'] ) : '',
                'clicks'       => (int) $post['clicks'],
            ];
        }
        return $result;
    }
}
