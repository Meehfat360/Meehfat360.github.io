<?php
namespace SSCA\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Settings Page
 * Sections: APIs, Brand Profile, Automation, Posting Schedule, Season Override
 */
class Settings {

    public function __construct() {
        add_action( 'admin_post_ssca_save_settings', [ $this, 'handle_save' ] );
        add_action( 'wp_ajax_ssca_test_connection',  [ $this, 'ajax_test_connection' ] );
        add_action( 'wp_ajax_ssca_force_health_check', [ $this, 'ajax_force_health_check' ] );
    }

    public function render(): void {
        $saved   = [];
        $section = sanitize_text_field( $_GET['section'] ?? 'apis' );
        ?>
        <div class="ssca-wrap">
            <?php ( new Dashboard() )->render_header_only( 'Settings' ); ?>

            <div class="ssca-settings-layout">

                <!-- Settings Sidebar Nav -->
                <nav class="ssca-settings-nav">
                    <?php
                    $sections = [
                        'apis'      => [ '🔑', 'API Connections'   ],
                        'brand'     => [ '🎨', 'Brand Profile'      ],
                        'automation'=> [ '⚙️', 'Automation Rules'   ],
                        'schedule'  => [ '🕐', 'Posting Schedule'   ],
                        'season'    => [ '🌸', 'Season Override'    ],
                        'approval'  => [ '✅', 'Approval Workflow'  ],
                        'advanced'  => [ '🔧', 'Advanced'           ],
                    ];
                    foreach ( $sections as $s => [ $icon, $label ] ) : ?>
                        <a href="?page=ssca-settings&section=<?php echo esc_attr( $s ); ?>"
                           class="ssca-settings-nav-item <?php echo $section === $s ? 'active' : ''; ?>">
                            <?php echo esc_html( $icon . ' ' . $label ); ?>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <!-- Settings Form -->
                <div class="ssca-settings-content">
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                        <?php wp_nonce_field( 'ssca_save_settings', 'ssca_nonce' ); ?>
                        <input type="hidden" name="action"  value="ssca_save_settings">
                        <input type="hidden" name="section" value="<?php echo esc_attr( $section ); ?>">

                        <?php
                        match( $section ) {
                            'apis'       => $this->section_apis(),
                            'brand'      => $this->section_brand(),
                            'automation' => $this->section_automation(),
                            'schedule'   => $this->section_schedule(),
                            'season'     => $this->section_season(),
                            'approval'   => $this->section_approval(),
                            'advanced'   => $this->section_advanced(),
                            default      => $this->section_apis(),
                        };
                        ?>

                        <div class="ssca-settings-footer">
                            <button type="submit" class="ssca-btn ssca-btn-primary ssca-btn-lg">💾 Save Settings</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    // ── Sections ──────────────────────────────────────────────────────────────

    private function section_apis(): void {
        $meta_token   = get_option( 'ssca_meta_access_token', '' );
        $meta_app_id  = get_option( 'ssca_meta_app_id', '' );
        $meta_secret  = get_option( 'ssca_meta_app_secret', '' );
        $fb_page_id   = get_option( 'ssca_fb_page_id', '' );
        $ig_acct_id   = get_option( 'ssca_ig_account_id', '' );
        $pinterest_tk = get_option( 'ssca_pinterest_token', '' );
        $pinterest_bd = get_option( 'ssca_pinterest_board_id', '' );
        $openai_key   = get_option( 'ssca_openai_key', '' );
        $health       = \SSCA\Publishers\APIHealthMonitor::get_status();
        ?>
        <div class="ssca-settings-section">
            <h2>🔑 API Connections</h2>
            <p class="ssca-desc">Connect your social platforms and AI provider. <a href="#" target="_blank">Setup guide →</a></p>

            <!-- Meta / Facebook -->
            <div class="ssca-api-block">
                <div class="ssca-api-header">
                    <span class="ssca-api-icon" style="background:#1877F2">📘</span>
                    <div>
                        <h3>Facebook & Instagram (Meta)</h3>
                        <span class="ssca-health-inline"><?php $this->health_badge( $health['facebook'] ?? [] ); ?></span>
                    </div>
                </div>
                <div class="ssca-field-grid">
                    <div class="ssca-field">
                        <label>App ID</label>
                        <input type="text" name="ssca_meta_app_id" value="<?php echo esc_attr( $meta_app_id ); ?>" placeholder="Your Meta App ID">
                    </div>
                    <div class="ssca-field">
                        <label>App Secret</label>
                        <input type="password" name="ssca_meta_app_secret" value="<?php echo esc_attr( $meta_secret ); ?>" placeholder="Your Meta App Secret">
                    </div>
                    <div class="ssca-field ssca-field-full">
                        <label>Access Token (Long-lived)</label>
                        <input type="password" name="ssca_meta_access_token" value="<?php echo esc_attr( $meta_token ); ?>" placeholder="EAAxxxxxx...">
                        <small>Token expires every 60 days. <button type="button" class="ssca-link" id="ssca-exchange-token">Exchange short token →</button></small>
                    </div>
                    <div class="ssca-field">
                        <label>Facebook Page ID</label>
                        <input type="text" name="ssca_fb_page_id" value="<?php echo esc_attr( $fb_page_id ); ?>" placeholder="123456789">
                    </div>
                    <div class="ssca-field">
                        <label>Instagram Business Account ID</label>
                        <input type="text" name="ssca_ig_account_id" value="<?php echo esc_attr( $ig_acct_id ); ?>" placeholder="987654321">
                    </div>
                    <div class="ssca-field">
                        <label>Facebook Enabled</label>
                        <label class="ssca-toggle">
                            <input type="checkbox" name="ssca_platform_facebook_enabled" value="1" <?php checked( get_option( 'ssca_platform_facebook_enabled', '1' ), '1' ); ?>>
                            <span class="ssca-toggle-slider"></span>
                        </label>
                    </div>
                    <div class="ssca-field">
                        <label>Instagram Enabled</label>
                        <label class="ssca-toggle">
                            <input type="checkbox" name="ssca_platform_instagram_enabled" value="1" <?php checked( get_option( 'ssca_platform_instagram_enabled', '1' ), '1' ); ?>>
                            <span class="ssca-toggle-slider"></span>
                        </label>
                    </div>
                </div>
                <button type="button" class="ssca-btn ssca-btn-sm" data-test="facebook">Test Facebook Connection</button>
            </div>

            <!-- Pinterest -->
            <div class="ssca-api-block">
                <div class="ssca-api-header">
                    <span class="ssca-api-icon" style="background:#E60023">📌</span>
                    <div>
                        <h3>Pinterest</h3>
                        <span class="ssca-health-inline"><?php $this->health_badge( $health['pinterest'] ?? [] ); ?></span>
                    </div>
                </div>
                <div class="ssca-field-grid">
                    <div class="ssca-field ssca-field-full">
                        <label>Access Token</label>
                        <input type="password" name="ssca_pinterest_token" value="<?php echo esc_attr( $pinterest_tk ); ?>" placeholder="pina_xxxxxx">
                    </div>
                    <div class="ssca-field">
                        <label>Board ID</label>
                        <input type="text" name="ssca_pinterest_board_id" value="<?php echo esc_attr( $pinterest_bd ); ?>" placeholder="1234567890123456789">
                    </div>
                    <div class="ssca-field">
                        <label>Pinterest Enabled</label>
                        <label class="ssca-toggle">
                            <input type="checkbox" name="ssca_platform_pinterest_enabled" value="1" <?php checked( get_option( 'ssca_platform_pinterest_enabled', '1' ), '1' ); ?>>
                            <span class="ssca-toggle-slider"></span>
                        </label>
                    </div>
                </div>
                <button type="button" class="ssca-btn ssca-btn-sm" data-test="pinterest">Test Pinterest Connection</button>
            </div>

            <!-- OpenAI -->
            <div class="ssca-api-block">
                <div class="ssca-api-header">
                    <span class="ssca-api-icon" style="background:#10a37f">🤖</span>
                    <div>
                        <h3>OpenAI (Caption Generator)</h3>
                        <span class="ssca-health-inline"><?php $this->health_badge( $health['openai'] ?? [] ); ?></span>
                    </div>
                </div>
                <div class="ssca-field-grid">
                    <div class="ssca-field ssca-field-full">
                        <label>API Key</label>
                        <input type="password" name="ssca_openai_key" value="<?php echo esc_attr( $openai_key ); ?>" placeholder="sk-xxxxxxxx">
                        <small>Without this key, fallback template captions are used.</small>
                    </div>
                </div>
                <button type="button" class="ssca-btn ssca-btn-sm" data-test="openai">Test OpenAI Key</button>
            </div>

        </div>
        <?php
    }

    private function section_brand(): void {
        $brand = ( new \SSCA\Utils\BrandProfile() )->get();
        $forbidden = is_array( $brand['forbidden_words'] ) ? implode( ', ', $brand['forbidden_words'] ) : '';
        ?>
        <div class="ssca-settings-section">
            <h2>🎨 Brand Profile</h2>
            <p class="ssca-desc">Your brand identity applied to every generated image and caption.</p>

            <div class="ssca-field-grid">
                <div class="ssca-field">
                    <label>Store Name</label>
                    <input type="text" name="brand[store_name]" value="<?php echo esc_attr( $brand['store_name'] ); ?>">
                </div>
                <div class="ssca-field">
                    <label>Tagline / Signature</label>
                    <input type="text" name="brand[tagline]" value="<?php echo esc_attr( $brand['tagline'] ); ?>" placeholder="Shop Smart. Save Big.">
                    <small>Appended to every generated caption.</small>
                </div>
                <div class="ssca-field">
                    <label>Primary Color</label>
                    <div class="ssca-color-field">
                        <input type="color" name="brand[primary_color]" value="<?php echo esc_attr( $brand['primary_color'] ); ?>">
                        <input type="text"  name="brand[primary_color_hex]" value="<?php echo esc_attr( $brand['primary_color'] ); ?>" class="ssca-hex-input">
                    </div>
                </div>
                <div class="ssca-field">
                    <label>Secondary Color</label>
                    <div class="ssca-color-field">
                        <input type="color" name="brand[secondary_color]" value="<?php echo esc_attr( $brand['secondary_color'] ); ?>">
                        <input type="text"  name="brand[secondary_color_hex]" value="<?php echo esc_attr( $brand['secondary_color'] ); ?>" class="ssca-hex-input">
                    </div>
                </div>
                <div class="ssca-field ssca-field-full">
                    <label>Brand Logo</label>
                    <div class="ssca-media-field">
                        <?php if ( $brand['logo_url'] ) : ?>
                            <img src="<?php echo esc_url( $brand['logo_url'] ); ?>" height="60" alt="Logo" id="ssca-logo-preview">
                        <?php else : ?>
                            <div id="ssca-logo-preview" class="ssca-logo-placeholder">No logo set</div>
                        <?php endif; ?>
                        <input type="hidden" name="brand[logo_attachment_id]" id="ssca-logo-id" value="<?php echo (int) $brand['logo_attachment_id']; ?>">
                        <button type="button" class="ssca-btn ssca-btn-sm" id="ssca-upload-logo">Upload Logo</button>
                        <?php if ( $brand['logo_url'] ) : ?>
                            <button type="button" class="ssca-btn ssca-btn-sm ssca-btn-danger" id="ssca-remove-logo">Remove</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="ssca-field ssca-field-full">
                    <label>Brand Voice / Tone</label>
                    <select name="brand[tone]">
                        <?php
                        $tones = [
                            'friendly and enthusiastic'  => 'Friendly & Enthusiastic',
                            'professional and trustworthy' => 'Professional & Trustworthy',
                            'urgent and deal-focused'    => 'Urgent & Deal-Focused',
                            'premium and luxurious'      => 'Premium & Luxurious',
                            'playful and fun'            => 'Playful & Fun',
                            'casual and relatable'       => 'Casual & Relatable',
                        ];
                        foreach ( $tones as $val => $label ) : ?>
                            <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $brand['tone'], $val ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ssca-field ssca-field-full">
                    <label>Forbidden Words</label>
                    <input type="text" name="brand[forbidden_words_raw]" value="<?php echo esc_attr( $forbidden ); ?>" placeholder="cheap, discount, inferior (comma-separated)">
                    <small>These words will never appear in AI-generated captions.</small>
                </div>
                <div class="ssca-field ssca-field-full">
                    <label>Custom CTA Override</label>
                    <input type="text" name="brand[custom_cta]" value="<?php echo esc_attr( $brand['custom_cta'] ); ?>" placeholder="e.g. Shop at stellarsavers.com today!">
                </div>
                <div class="ssca-field">
                    <label>Add FTC #ad Disclosure</label>
                    <label class="ssca-toggle">
                        <input type="checkbox" name="brand[add_ftc_disclosure]" value="1" <?php checked( $brand['add_ftc_disclosure'] ); ?>>
                        <span class="ssca-toggle-slider"></span>
                    </label>
                </div>
            </div>
        </div>
        <?php
    }

    private function section_automation(): void {
        ?>
        <div class="ssca-settings-section">
            <h2>⚙️ Automation Rules</h2>
            <div class="ssca-field-grid">
                <div class="ssca-field">
                    <label>Products Per Day</label>
                    <input type="number" name="ssca_daily_products" value="<?php echo (int) get_option( 'ssca_daily_products', 5 ); ?>" min="1" max="10">
                    <small>How many products to post per day (1–10).</small>
                </div>
                <div class="ssca-field">
                    <label>Rotation Window (days)</label>
                    <input type="number" name="ssca_rotation_days" value="<?php echo (int) get_option( 'ssca_rotation_days', 30 ); ?>" min="7" max="90">
                    <small>Don't repeat a product within this many days.</small>
                </div>
                <div class="ssca-field">
                    <label>Overstock Threshold (units)</label>
                    <input type="number" name="ssca_overstock_threshold" value="<?php echo (int) get_option( 'ssca_overstock_threshold', 100 ); ?>" min="10">
                    <small>Products above this quantity are considered overstocked.</small>
                </div>
                <div class="ssca-field">
                    <label>Low-Stock Threshold (units)</label>
                    <input type="number" name="ssca_lowstock_threshold" value="<?php echo (int) get_option( 'ssca_lowstock_threshold', 5 ); ?>" min="1">
                    <small>Products at or below this get scarcity-boost in captions.</small>
                </div>
                <div class="ssca-field">
                    <label>Deal Alert Automation</label>
                    <label class="ssca-toggle">
                        <input type="checkbox" name="ssca_deal_alerts_enabled" value="1" <?php checked( get_option( 'ssca_deal_alerts_enabled', '1' ), '1' ); ?>>
                        <span class="ssca-toggle-slider"></span>
                    </label>
                    <small>Auto-post when a product goes on sale.</small>
                </div>
                <div class="ssca-field">
                    <label>Evergreen Recycling</label>
                    <label class="ssca-toggle">
                        <input type="checkbox" name="ssca_evergreen_enabled" value="1" <?php checked( get_option( 'ssca_evergreen_enabled', '1' ), '1' ); ?>>
                        <span class="ssca-toggle-slider"></span>
                    </label>
                    <small>Auto-republish best-performing posts.</small>
                </div>
                <div class="ssca-field">
                    <label>Recycle After (days)</label>
                    <input type="number" name="ssca_evergreen_days" value="<?php echo (int) get_option( 'ssca_evergreen_days', 45 ); ?>" min="14" max="180">
                </div>
                <div class="ssca-field">
                    <label>Min Score to Recycle</label>
                    <input type="number" name="ssca_evergreen_min_score" value="<?php echo (float) get_option( 'ssca_evergreen_min_score', 60 ); ?>" min="0" max="100" step="5">
                    <small>Only recycle posts from products scoring above this.</small>
                </div>
            </div>
        </div>
        <?php
    }

    private function section_schedule(): void {
        $times = get_option( 'ssca_posting_times', [
            'facebook'  => '10:00',
            'instagram' => '19:00',
            'pinterest' => '14:00',
        ] );
        $workflow_time = get_option( 'ssca_workflow_time', '06:00' );
        ?>
        <div class="ssca-settings-section">
            <h2>🕐 Posting Schedule</h2>
            <p class="ssca-desc">Set what time each platform posts daily. Times are in your site timezone (<?php echo esc_html( wp_timezone_string() ); ?>).</p>
            <div class="ssca-field-grid">
                <div class="ssca-field">
                    <label>📘 Facebook Post Time</label>
                    <input type="time" name="ssca_posting_times[facebook]" value="<?php echo esc_attr( $times['facebook'] ); ?>">
                </div>
                <div class="ssca-field">
                    <label>📷 Instagram Post Time</label>
                    <input type="time" name="ssca_posting_times[instagram]" value="<?php echo esc_attr( $times['instagram'] ); ?>">
                </div>
                <div class="ssca-field">
                    <label>📌 Pinterest Post Time</label>
                    <input type="time" name="ssca_posting_times[pinterest]" value="<?php echo esc_attr( $times['pinterest'] ); ?>">
                </div>
                <div class="ssca-field">
                    <label>⚙️ Daily Workflow Runs At</label>
                    <input type="time" name="ssca_workflow_time" value="<?php echo esc_attr( $workflow_time ); ?>">
                    <small>Should be before all post times.</small>
                </div>
            </div>
        </div>
        <?php
    }

    private function section_season(): void {
        $override        = get_option( 'ssca_season_override', '' );
        $override_expiry = get_option( 'ssca_season_override_expiry', 0 );
        $engine          = new \SSCA\Engines\SeasonalEngine();
        $current_auto    = $engine->get_current_theme();
        $current_meta    = $engine->get_theme_meta( $current_auto );
        $all_themes      = array_keys( $engine->get_product_keyword_map() );
        array_unshift( $all_themes, 'default', 'new_year_sale', 'valentines_day', 'winter_clearance', 'spring_preview', 'spring_sale', 'easter', 'mothers_day', 'summer_preview', 'fathers_day', 'summer_sale', 'back_to_school', 'late_summer', 'fall_preview', 'halloween', 'pre_black_friday', 'black_friday', 'christmas', 'year_end_sale', 'deal_alert' );
        $all_themes = array_unique( $all_themes );
        ?>
        <div class="ssca-settings-section">
            <h2>🌸 Season Override</h2>
            <div class="ssca-season-current">
                <strong>Auto-detected theme:</strong>
                <span style="color:<?php echo esc_attr( $current_meta['colors'][0] ); ?>"><?php echo esc_html( $current_meta['emoji'] . ' ' . $current_meta['label'] ); ?></span>
            </div>
            <div class="ssca-field-grid">
                <div class="ssca-field ssca-field-full">
                    <label>Manual Override Theme</label>
                    <select name="ssca_season_override">
                        <option value="">— Use Auto-Detection —</option>
                        <?php foreach ( $all_themes as $t ) :
                            $m = $engine->get_theme_meta( $t );
                        ?>
                            <option value="<?php echo esc_attr( $t ); ?>" <?php selected( $override, $t ); ?>>
                                <?php echo esc_html( $m['emoji'] . ' ' . $m['label'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ssca-field">
                    <label>Override Duration (days)</label>
                    <input type="number" name="ssca_season_override_days" value="7" min="1" max="60">
                </div>
                <?php if ( $override && $override_expiry ) : ?>
                <div class="ssca-field ssca-field-full">
                    <div class="ssca-notice ssca-notice-info">
                        Override active: <strong><?php echo esc_html( $override ); ?></strong> — expires <?php echo esc_html( human_time_diff( $override_expiry ) . ' from now' ); ?>.
                        <a href="?page=ssca-settings&section=season&clear_override=1" class="ssca-link">Clear Override</a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        if ( ! empty( $_GET['clear_override'] ) ) {
            delete_option( 'ssca_season_override' );
            delete_option( 'ssca_season_override_expiry' );
        }
    }

    private function section_approval(): void {
        ?>
        <div class="ssca-settings-section">
            <h2>✅ Approval Workflow</h2>
            <div class="ssca-field-grid">
                <div class="ssca-field ssca-field-full">
                    <label>Publishing Mode</label>
                    <select name="ssca_approval_mode">
                        <option value="auto" <?php selected( get_option( 'ssca_approval_mode', 'auto' ), 'auto' ); ?>>🤖 Full Automation — Post without review</option>
                        <option value="manual" <?php selected( get_option( 'ssca_approval_mode' ), 'manual' ); ?>>👁 Manual Approval — Review before every post</option>
                        <option value="low_confidence" <?php selected( get_option( 'ssca_approval_mode' ), 'low_confidence' ); ?>>⚠️ Smart Mode — Only flag low-confidence posts</option>
                    </select>
                </div>
                <div class="ssca-field ssca-field-full">
                    <label>Approval Notification Email</label>
                    <input type="email" name="ssca_approval_email" value="<?php echo esc_attr( get_option( 'ssca_approval_email', get_option( 'admin_email' ) ) ); ?>">
                </div>
            </div>
        </div>
        <?php
    }

    private function section_advanced(): void {
        ?>
        <div class="ssca-settings-section">
            <h2>🔧 Advanced</h2>
            <div class="ssca-field-grid">
                <div class="ssca-field">
                    <label>Delete Data on Uninstall</label>
                    <label class="ssca-toggle">
                        <input type="checkbox" name="ssca_delete_on_uninstall" value="1" <?php checked( get_option( 'ssca_delete_on_uninstall' ), '1' ); ?>>
                        <span class="ssca-toggle-slider"></span>
                    </label>
                    <small class="ssca-text-red">⚠️ This will delete all posts, analytics, and settings on plugin removal.</small>
                </div>
                <div class="ssca-field ssca-field-full">
                    <label>Force Health Check Now</label>
                    <button type="button" class="ssca-btn ssca-btn-secondary" id="ssca-force-health">Run Health Check</button>
                </div>
                <div class="ssca-field ssca-field-full">
                    <label>Debug Log</label>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=ssca-log' ) ); ?>" class="ssca-btn ssca-btn-secondary">View Activity Log</a>
                    <button type="button" class="ssca-btn ssca-btn-secondary" id="ssca-clear-log-settings">Clear Log</button>
                </div>
            </div>
        </div>
        <?php
    }

    // ── Save Handler ──────────────────────────────────────────────────────────

    public function handle_save(): void {
        check_admin_referer( 'ssca_save_settings', 'ssca_nonce' );
        if ( ! current_user_can( Menu::CAPABILITY ) ) wp_die( 'Unauthorized' );

        $section = sanitize_text_field( $_POST['section'] ?? '' );

        switch ( $section ) {
            case 'apis':
                $this->save_apis();
                break;
            case 'brand':
                $this->save_brand();
                break;
            case 'automation':
                $this->save_automation();
                break;
            case 'schedule':
                $this->save_schedule();
                break;
            case 'season':
                $this->save_season();
                break;
            case 'approval':
                $options = [ 'ssca_approval_mode', 'ssca_approval_email' ];
                foreach ( $options as $opt ) {
                    if ( isset( $_POST[ $opt ] ) ) update_option( $opt, sanitize_text_field( $_POST[ $opt ] ) );
                }
                break;
            case 'advanced':
                update_option( 'ssca_delete_on_uninstall', isset( $_POST['ssca_delete_on_uninstall'] ) ? '1' : '0' );
                break;
        }

        wp_redirect( add_query_arg( [ 'page' => 'ssca-settings', 'section' => $section, 'saved' => '1' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    private function save_apis(): void {
        $fields = [
            'ssca_meta_app_id', 'ssca_meta_app_secret', 'ssca_meta_access_token',
            'ssca_fb_page_id', 'ssca_ig_account_id',
            'ssca_pinterest_token', 'ssca_pinterest_board_id',
            'ssca_openai_key',
        ];
        foreach ( $fields as $f ) {
            if ( isset( $_POST[ $f ] ) ) update_option( $f, sanitize_text_field( $_POST[ $f ] ) );
        }
        // Platform toggles
        foreach ( [ 'facebook', 'instagram', 'pinterest' ] as $p ) {
            update_option( "ssca_platform_{$p}_enabled", isset( $_POST[ "ssca_platform_{$p}_enabled" ] ) ? '1' : '0' );
        }
        // Force health check after API changes
        \SSCA\Publishers\APIHealthMonitor::force_check();
    }

    private function save_brand(): void {
        $raw = $_POST['brand'] ?? [];
        $data = [
            'store_name'         => sanitize_text_field( $raw['store_name'] ?? '' ),
            'tagline'            => sanitize_text_field( $raw['tagline'] ?? '' ),
            'primary_color'      => sanitize_text_field( $raw['primary_color'] ?? '#1E40AF' ),
            'secondary_color'    => sanitize_text_field( $raw['secondary_color'] ?? '#F8FAFC' ),
            'logo_attachment_id' => (int) ( $raw['logo_attachment_id'] ?? 0 ),
            'tone'               => sanitize_text_field( $raw['tone'] ?? 'friendly and enthusiastic' ),
            'forbidden_words'    => array_map( 'trim', explode( ',', sanitize_text_field( $raw['forbidden_words_raw'] ?? '' ) ) ),
            'custom_cta'         => sanitize_text_field( $raw['custom_cta'] ?? '' ),
            'add_ftc_disclosure' => ! empty( $raw['add_ftc_disclosure'] ),
        ];
        \SSCA\Utils\BrandProfile::save( $data );
    }

    private function save_automation(): void {
        $int_fields = [
            'ssca_daily_products', 'ssca_rotation_days',
            'ssca_overstock_threshold', 'ssca_lowstock_threshold',
            'ssca_evergreen_days', 'ssca_evergreen_max_recycles',
        ];
        foreach ( $int_fields as $f ) {
            if ( isset( $_POST[ $f ] ) ) update_option( $f, (int) $_POST[ $f ] );
        }
        update_option( 'ssca_deal_alerts_enabled', isset( $_POST['ssca_deal_alerts_enabled'] ) ? '1' : '0' );
        update_option( 'ssca_evergreen_enabled', isset( $_POST['ssca_evergreen_enabled'] ) ? '1' : '0' );
        if ( isset( $_POST['ssca_evergreen_min_score'] ) ) {
            update_option( 'ssca_evergreen_min_score', (float) $_POST['ssca_evergreen_min_score'] );
        }
    }

    private function save_schedule(): void {
        if ( isset( $_POST['ssca_posting_times'] ) && is_array( $_POST['ssca_posting_times'] ) ) {
            $times = [];
            foreach ( $_POST['ssca_posting_times'] as $platform => $time ) {
                $times[ sanitize_key( $platform ) ] = sanitize_text_field( $time );
            }
            update_option( 'ssca_posting_times', $times );
        }
        if ( isset( $_POST['ssca_workflow_time'] ) ) {
            update_option( 'ssca_workflow_time', sanitize_text_field( $_POST['ssca_workflow_time'] ) );
        }
        // Reschedule
        \SSCA\Publishers\Queue::unschedule_all();
        \SSCA\Publishers\Queue::schedule_recurring();
    }

    private function save_season(): void {
        $override = sanitize_text_field( $_POST['ssca_season_override'] ?? '' );
        $days     = max( 1, (int) ( $_POST['ssca_season_override_days'] ?? 7 ) );
        if ( $override ) {
            ( new \SSCA\Engines\SeasonalEngine() )->set_override( $override, $days );
        } else {
            delete_option( 'ssca_season_override' );
            delete_option( 'ssca_season_override_expiry' );
        }
    }

    // ── AJAX Handlers ─────────────────────────────────────────────────────────

    public function ajax_test_connection(): void {
        check_ajax_referer( 'ssca_admin', 'nonce' );
        $platform = sanitize_key( $_POST['platform'] ?? '' );
        $result   = match( $platform ) {
            'facebook', 'instagram' => ( new \SSCA\Publishers\MetaPublisher() )->health_check(),
            'pinterest' => ( new \SSCA\Publishers\PinterestPublisher() )->health_check(),
            'openai'    => ( function() {
                $key = get_option( 'ssca_openai_key', '' );
                if ( ! $key ) return [ 'status' => 'error', 'message' => 'No API key set.' ];
                $r = wp_remote_get( 'https://api.openai.com/v1/models', [
                    'headers' => [ 'Authorization' => 'Bearer ' . $key ], 'timeout' => 10,
                ]);
                return wp_remote_retrieve_response_code( $r ) === 200
                    ? [ 'status' => 'ok', 'message' => 'Connected!' ]
                    : [ 'status' => 'error', 'message' => 'API key invalid.' ];
            } )(),
            default => [ 'status' => 'error', 'message' => 'Unknown platform.' ],
        };
        wp_send_json_success( $result );
    }

    public function ajax_force_health_check(): void {
        check_ajax_referer( 'ssca_admin', 'nonce' );
        $result = \SSCA\Publishers\APIHealthMonitor::force_check();
        wp_send_json_success( $result );
    }

    private function health_badge( array $status ): void {
        $ok    = ( $status['status'] ?? '' ) === 'ok';
        $class = $ok ? 'ssca-badge-green' : ( $status['status'] === 'unknown' ? 'ssca-badge-yellow' : 'ssca-badge-red' );
        $label = $ok ? 'Connected' : ucfirst( $status['status'] ?? 'Not configured' );
        echo '<span class="ssca-badge ' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
    }
}
