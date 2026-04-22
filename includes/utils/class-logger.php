<?php
namespace SSCA\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Logger — writes to WP debug log and a custom SSCA log.
 * Log levels: info, warning, error
 */
class Logger {

    const OPTION_LOG  = 'ssca_recent_logs';
    const MAX_ENTRIES = 200;

    public static function info( string $message ): void {
        self::write( 'INFO', $message );
    }

    public static function warning( string $message ): void {
        self::write( 'WARNING', $message );
    }

    public static function error( string $message ): void {
        self::write( 'ERROR', $message );
        // Errors also go to WP debug log
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions
            error_log( '[SSCA ERROR] ' . $message );
        }
    }

    private static function write( string $level, string $message ): void {
        $entry = [
            'level'   => $level,
            'message' => $message,
            'time'    => current_time( 'mysql' ),
        ];

        // Store in DB option (ring buffer)
        $logs = get_option( self::OPTION_LOG, [] );
        array_unshift( $logs, $entry );
        if ( count( $logs ) > self::MAX_ENTRIES ) {
            $logs = array_slice( $logs, 0, self::MAX_ENTRIES );
        }
        update_option( self::OPTION_LOG, $logs, false );
    }

    public static function get_recent( int $limit = 50, string $level = '' ): array {
        $logs = get_option( self::OPTION_LOG, [] );
        if ( $level ) {
            $logs = array_filter( $logs, fn( $e ) => $e['level'] === strtoupper( $level ) );
        }
        return array_slice( array_values( $logs ), 0, $limit );
    }

    public static function clear(): void {
        update_option( self::OPTION_LOG, [] );
    }
}
