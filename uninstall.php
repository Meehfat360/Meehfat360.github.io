<?php
// Uninstall script for StellarSavers AI Social Commerce
// Runs when the plugin is deleted (not just deactivated)

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Only delete if setting enabled
if ( get_option( 'ssca_delete_on_uninstall' ) !== '1' ) {
    return;
}

global $wpdb;

// Drop custom tables
$tables = [
    'ssca_products', 'ssca_posts', 'ssca_analytics',
    'ssca_attribution', 'ssca_ab_tests', 'ssca_api_logs', 'ssca_hashtag_stats',
];
foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore
}

// Delete options
$options = $wpdb->get_col(
    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'ssca_%'"
);
foreach ( $options as $opt ) {
    delete_option( $opt );
}

// Delete transients
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ssca_%' OR option_name LIKE '_transient_timeout_ssca_%'" ); // phpcs:ignore

// Unschedule all WP cron events
wp_clear_scheduled_hook( 'ssca_health_check' );
wp_clear_scheduled_hook( 'ssca_weekly_digest' );

// Clear Action Scheduler actions
if ( function_exists( 'as_unschedule_all_actions' ) ) {
    as_unschedule_all_actions( '', [], 'ssca' );
}

// Delete uploaded creative images
$upload = wp_upload_dir();
$dir    = $upload['basedir'] . '/ssca-creatives/';
if ( is_dir( $dir ) ) {
    $files = glob( $dir . '*.jpg' );
    if ( $files ) {
        foreach ( $files as $file ) {
            @unlink( $file ); // phpcs:ignore
        }
    }
    @rmdir( $dir ); // phpcs:ignore
}
