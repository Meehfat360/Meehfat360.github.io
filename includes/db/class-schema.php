<?php
namespace SSCA\DB;

defined( 'ABSPATH' ) || exit;

/**
 * Installs and manages all SSCA custom database tables.
 *
 * Tables:
 *  ssca_products        — product scoring, rotation history, performance data
 *  ssca_posts           — every generated post (scheduled, published, failed)
 *  ssca_analytics       — click events tied to posts
 *  ssca_attribution     — order → post attribution records
 *  ssca_ab_tests        — A/B test definitions & results
 *  ssca_api_logs        — API call log (health monitoring)
 *  ssca_hashtag_stats   — hashtag performance tracking
 */
class Schema {

    const DB_VERSION     = '1.0.0';
    const DB_VERSION_KEY = 'ssca_db_version';

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        // ── 1. Products Table ─────────────────────────────────────────────────
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}ssca_products (
                id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                product_id       BIGINT UNSIGNED NOT NULL,
                last_posted_at   DATETIME        DEFAULT NULL,
                total_posts      INT UNSIGNED    NOT NULL DEFAULT 0,
                total_clicks     INT UNSIGNED    NOT NULL DEFAULT 0,
                total_atc        INT UNSIGNED    NOT NULL DEFAULT 0  COMMENT 'Add-to-cart events',
                total_orders     INT UNSIGNED    NOT NULL DEFAULT 0,
                total_revenue    DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
                score            DECIMAL(6,2)    NOT NULL DEFAULT 0.00,
                score_updated_at DATETIME        DEFAULT NULL,
                last_category    VARCHAR(100)    DEFAULT NULL COMMENT 'Slot category: bestseller|margin|deal|underperform|wildcard',
                is_blacklisted   TINYINT(1)      NOT NULL DEFAULT 0,
                created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_product (product_id),
                KEY idx_score (score),
                KEY idx_last_posted (last_posted_at)
            ) $charset;
        " );

        // ── 2. Posts Table ────────────────────────────────────────────────────
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}ssca_posts (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                product_id      BIGINT UNSIGNED NOT NULL,
                platform        VARCHAR(30)     NOT NULL COMMENT 'facebook|instagram|pinterest',
                variant         VARCHAR(10)     NOT NULL DEFAULT 'A' COMMENT 'A|B|DEAL|EVERGREEN',
                ab_test_id      BIGINT UNSIGNED DEFAULT NULL,
                image_path      VARCHAR(512)    DEFAULT NULL,
                image_url       VARCHAR(512)    DEFAULT NULL,
                caption         TEXT            DEFAULT NULL,
                hashtags        TEXT            DEFAULT NULL,
                tracking_url    VARCHAR(512)    DEFAULT NULL,
                utm_params      VARCHAR(512)    DEFAULT NULL,
                scheduled_at    DATETIME        DEFAULT NULL,
                published_at    DATETIME        DEFAULT NULL,
                status          VARCHAR(20)     NOT NULL DEFAULT 'draft' COMMENT 'draft|scheduled|awaiting_approval|published|failed|cancelled',
                approved        TINYINT(1)      NOT NULL DEFAULT 0,
                approved_by     BIGINT UNSIGNED DEFAULT NULL,
                platform_post_id VARCHAR(200)   DEFAULT NULL,
                season_theme    VARCHAR(100)    DEFAULT NULL,
                error_msg       TEXT            DEFAULT NULL,
                retry_count     TINYINT UNSIGNED NOT NULL DEFAULT 0,
                clicks          INT UNSIGNED    NOT NULL DEFAULT 0,
                impressions     INT UNSIGNED    NOT NULL DEFAULT 0,
                created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_product   (product_id),
                KEY idx_platform  (platform),
                KEY idx_status    (status),
                KEY idx_scheduled (scheduled_at),
                KEY idx_variant   (variant),
                KEY idx_ab_test   (ab_test_id)
            ) $charset;
        " );

        // ── 3. Analytics (Click Events) ───────────────────────────────────────
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}ssca_analytics (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                post_id     BIGINT UNSIGNED NOT NULL,
                product_id  BIGINT UNSIGNED NOT NULL,
                platform    VARCHAR(30)     NOT NULL,
                event_type  VARCHAR(30)     NOT NULL COMMENT 'click|impression|add_to_cart',
                session_id  VARCHAR(64)     DEFAULT NULL,
                ip_hash     VARCHAR(64)     DEFAULT NULL COMMENT 'Hashed for GDPR',
                user_agent  VARCHAR(512)    DEFAULT NULL,
                referrer    VARCHAR(512)    DEFAULT NULL,
                country     VARCHAR(5)      DEFAULT NULL,
                event_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_post    (post_id),
                KEY idx_product (product_id),
                KEY idx_event   (event_type),
                KEY idx_date    (event_at)
            ) $charset;
        " );

        // ── 4. Order Attribution ──────────────────────────────────────────────
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}ssca_attribution (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id    BIGINT UNSIGNED NOT NULL,
                post_id     BIGINT UNSIGNED DEFAULT NULL,
                product_id  BIGINT UNSIGNED DEFAULT NULL,
                platform    VARCHAR(30)     DEFAULT NULL,
                order_total DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
                attributed_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                attribution_type VARCHAR(30) DEFAULT 'last_click',
                session_id  VARCHAR(64)     DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_order (order_id),
                KEY idx_post    (post_id),
                KEY idx_product (product_id),
                KEY idx_platform (platform)
            ) $charset;
        " );

        // ── 5. A/B Tests ──────────────────────────────────────────────────────
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}ssca_ab_tests (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                product_id      BIGINT UNSIGNED NOT NULL,
                platform        VARCHAR(30)     NOT NULL,
                post_id_a       BIGINT UNSIGNED NOT NULL,
                post_id_b       BIGINT UNSIGNED NOT NULL,
                winner_variant  VARCHAR(10)     DEFAULT NULL,
                winner_post_id  BIGINT UNSIGNED DEFAULT NULL,
                status          VARCHAR(20)     NOT NULL DEFAULT 'running' COMMENT 'running|completed|inconclusive',
                clicks_a        INT UNSIGNED    NOT NULL DEFAULT 0,
                clicks_b        INT UNSIGNED    NOT NULL DEFAULT 0,
                orders_a        INT UNSIGNED    NOT NULL DEFAULT 0,
                orders_b        INT UNSIGNED    NOT NULL DEFAULT 0,
                started_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                evaluated_at    DATETIME        DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_product  (product_id),
                KEY idx_status   (status)
            ) $charset;
        " );

        // ── 6. API Logs ───────────────────────────────────────────────────────
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}ssca_api_logs (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                platform     VARCHAR(30)     NOT NULL,
                endpoint     VARCHAR(255)    NOT NULL,
                method       VARCHAR(10)     NOT NULL DEFAULT 'POST',
                request_body TEXT            DEFAULT NULL,
                response_code SMALLINT       DEFAULT NULL,
                response_body TEXT           DEFAULT NULL,
                duration_ms  INT UNSIGNED    DEFAULT NULL,
                success      TINYINT(1)      NOT NULL DEFAULT 0,
                error_msg    TEXT            DEFAULT NULL,
                logged_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_platform (platform),
                KEY idx_success  (success),
                KEY idx_date     (logged_at)
            ) $charset;
        " );

        // ── 7. Hashtag Stats ──────────────────────────────────────────────────
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}ssca_hashtag_stats (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                hashtag     VARCHAR(150)    NOT NULL,
                platform    VARCHAR(30)     NOT NULL,
                category    VARCHAR(100)    DEFAULT NULL,
                uses        INT UNSIGNED    NOT NULL DEFAULT 0,
                clicks      INT UNSIGNED    NOT NULL DEFAULT 0,
                ctr         DECIMAL(5,2)    NOT NULL DEFAULT 0.00 COMMENT 'Click-through rate',
                tier        VARCHAR(10)     DEFAULT NULL COMMENT 'mega|mid|niche',
                is_banned   TINYINT(1)      NOT NULL DEFAULT 0,
                last_used   DATETIME        DEFAULT NULL,
                updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_tag_platform (hashtag, platform),
                KEY idx_ctr      (ctr),
                KEY idx_banned   (is_banned),
                KEY idx_category (category)
            ) $charset;
        " );

        update_option( self::DB_VERSION_KEY, self::DB_VERSION );
    }

    public static function needs_upgrade(): bool {
        return version_compare( get_option( self::DB_VERSION_KEY, '0' ), self::DB_VERSION, '<' );
    }

    public static function drop_all(): void {
        global $wpdb;
        $tables = [
            'ssca_products', 'ssca_posts', 'ssca_analytics',
            'ssca_attribution', 'ssca_ab_tests', 'ssca_api_logs', 'ssca_hashtag_stats',
        ];
        foreach ( $tables as $t ) {
            $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$t}" ); // phpcs:ignore
        }
        delete_option( self::DB_VERSION_KEY );
    }
}
