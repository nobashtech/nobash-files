<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Runtime compatibility and low-resource safety profile.
 *
 * This class intentionally does not raise server limits. It adapts Nobash work
 * to the limits the host already exposes and reports what could not be tested.
 */
final class Nobash_Files_Runtime {
    public const MIN_WP  = '6.5';
    public const MIN_PHP = '8.1';

    private const MAINTENANCE_HOOK = 'nobash_files_core_maintenance';

    public static function init(): void {
        add_action( self::MAINTENANCE_HOOK, array( __CLASS__, 'run_maintenance' ) );
        add_action( 'admin_init', array( __CLASS__, 'ensure_maintenance_schedule' ) );
        add_filter( 'nobash_files_runtime_chunk_bytes', array( __CLASS__, 'filter_chunk_bytes' ), 10, 1 );
        add_filter( 'nobash_files_runtime_integrity_settings', array( __CLASS__, 'filter_integrity_settings' ), 10, 1 );
    }


    public static function activate(): void {
        self::ensure_maintenance_schedule();
    }

    public static function deactivate(): void {
        if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
            wp_clear_scheduled_hook( self::MAINTENANCE_HOOK );
        }
    }

    public static function ensure_maintenance_schedule(): void {
        if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' ) && ! wp_next_scheduled( self::MAINTENANCE_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::MAINTENANCE_HOOK );
        }
    }

    public static function run_maintenance(): void {
        try {
            if ( class_exists( 'Nobash_Files_Filesystem' ) ) {
                $retention = max( DAY_IN_SECONDS, (int) apply_filters( 'nobash_files_transient_work_retention_seconds', 3 * DAY_IN_SECONDS ) );
                ( new Nobash_Files_Filesystem() )->cleanup_stale_work( $retention );
            }
        } catch ( Throwable $e ) {
            // Maintenance is best-effort and must never take down wp-cron/admin.
        }
        do_action( 'nobash_files_core_maintenance_completed' );
    }

    public static function compatible(): bool {
        $profile = self::profile();
        return ! empty( $profile['php_supported'] ) && ! empty( $profile['wp_supported'] );
    }

    public static function profile(): array {
        global $wp_version;

        $wp = is_string( $wp_version ?? null ) ? $wp_version : '';
        $memory = self::memory_limit_bytes();
        $upload = self::ini_bytes( (string) ini_get( 'upload_max_filesize' ) );
        $post = self::ini_bytes( (string) ini_get( 'post_max_size' ) );
        $execution = (int) ini_get( 'max_execution_time' );
        $configured_chunk = (int) get_option( 'nobash_files_chunk_bytes', 2097152 );
        $effective_chunk = self::effective_chunk_bytes( $configured_chunk );
        $integrity = self::effective_integrity_settings(
            array(
                'max_files'   => (int) get_option( 'nobash_files_integrity_max_files', 50000 ),
                'max_seconds' => (float) get_option( 'nobash_files_integrity_max_seconds', 18 ),
            )
        );

        $request_cap = self::request_body_limit_bytes();
        $low_memory = $memory > 0 && $memory < 134217728;
        $low_request = $request_cap > 0 && $request_cap < 2097152;
        $low_time = $execution > 0 && $execution < 20;

        return array(
            'php_version'      => PHP_VERSION,
            'php_supported'    => version_compare( PHP_VERSION, self::MIN_PHP, '>=' ),
            'wordpress_version'=> $wp,
            'wp_supported'     => '' === $wp ? true : version_compare( $wp, self::MIN_WP, '>=' ),
            'multisite'        => function_exists( 'is_multisite' ) ? is_multisite() : false,
            'memory_limit'     => $memory,
            'upload_max'       => $upload,
            'post_max'         => $post,
            'request_body_max' => $request_cap,
            'max_execution_time'=> $execution,
            'configured_chunk_bytes' => $configured_chunk,
            'effective_chunk_bytes'  => $effective_chunk,
            'integrity_max_files'    => (int) $integrity['max_files'],
            'integrity_max_seconds'  => (float) $integrity['max_seconds'],
            'low_resource'     => $low_memory || $low_request || $low_time,
            'low_memory'       => $low_memory,
            'low_request_limit'=> $low_request,
            'low_execution_time'=> $low_time,
            'ziparchive'       => class_exists( 'ZipArchive' ),
            'phardata'         => class_exists( 'PharData' ),
            'fileinfo'         => function_exists( 'finfo_open' ) || function_exists( 'mime_content_type' ),
            'hash_sha256'      => in_array( 'sha256', hash_algos(), true ),
            'server_software'  => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( (string) $_SERVER['SERVER_SOFTWARE'] ) : '',
        );
    }

    public static function effective_chunk_bytes( ?int $configured = null ): int {
        if ( null === $configured ) {
            $configured = (int) get_option( 'nobash_files_chunk_bytes', 2097152 );
        }
        $configured = max( 262144, min( 8388608, $configured ) );
        $cap = $configured;

        $memory = self::memory_limit_bytes();
        if ( $memory > 0 ) {
            // Keep upload requests comfortably below the process memory limit.
            if ( $memory < 67108864 ) {
                $cap = min( $cap, 1048576 );
            } elseif ( $memory < 134217728 ) {
                $cap = min( $cap, 2097152 );
            } elseif ( $memory < 268435456 ) {
                $cap = min( $cap, 4194304 );
            }
        }

        $request = self::request_body_limit_bytes();
        if ( $request > 0 ) {
            // Leave room for multipart form overhead and WordPress request parsing.
            $safe_request = max( 262144, $request - 524288 );
            $cap = min( $cap, $safe_request );
        }

        // Keep chunks on a predictable 256 KiB boundary for retry/checkpoint logic.
        $cap = max( 262144, (int) floor( $cap / 262144 ) * 262144 );
        return min( $configured, $cap );
    }

    public static function filter_chunk_bytes( $configured ): int {
        return self::effective_chunk_bytes( (int) $configured );
    }

    public static function effective_integrity_settings( array $settings ): array {
        $max_files = max( 5000, min( 100000, (int) ( $settings['max_files'] ?? 50000 ) ) );
        $max_seconds = max( 5.0, min( 45.0, (float) ( $settings['max_seconds'] ?? 18 ) ) );

        $memory = self::memory_limit_bytes();
        if ( $memory > 0 && $memory < 67108864 ) {
            $max_files = min( $max_files, 10000 );
        } elseif ( $memory > 0 && $memory < 134217728 ) {
            $max_files = min( $max_files, 25000 );
        }

        $execution = (int) ini_get( 'max_execution_time' );
        if ( $execution > 0 ) {
            // Leave a meaningful tail for JSON serialization, logging and HTTP response.
            $safe = max( 5.0, min( 45.0, floor( $execution * 0.55 ) ) );
            $max_seconds = min( $max_seconds, $safe );
        }

        return array( 'max_files' => $max_files, 'max_seconds' => $max_seconds );
    }

    public static function filter_integrity_settings( $settings ): array {
        return self::effective_integrity_settings( is_array( $settings ) ? $settings : array() );
    }

    public static function request_body_limit_bytes(): int {
        $limits = array_filter(
            array(
                self::ini_bytes( (string) ini_get( 'upload_max_filesize' ) ),
                self::ini_bytes( (string) ini_get( 'post_max_size' ) ),
            ),
            static fn( $value ) => $value > 0
        );
        return empty( $limits ) ? -1 : min( $limits );
    }

    public static function memory_limit_bytes(): int {
        return self::ini_bytes( (string) ini_get( 'memory_limit' ) );
    }

    public static function ini_bytes( string $value ): int {
        $value = trim( $value );
        if ( '' === $value || '-1' === $value ) { return -1; }
        if ( is_numeric( $value ) ) { return (int) $value; }
        $unit = strtolower( substr( $value, -1 ) );
        $number = (float) substr( $value, 0, -1 );
        switch ( $unit ) {
            case 'g': $number *= 1024;
            case 'm': $number *= 1024;
            case 'k': $number *= 1024;
        }
        return (int) $number;
    }

    public static function format_bytes( int $bytes ): string {
        if ( $bytes < 0 ) { return 'Unlimited'; }
        if ( $bytes >= 1073741824 ) { return round( $bytes / 1073741824, 1 ) . ' GB'; }
        if ( $bytes >= 1048576 ) { return round( $bytes / 1048576, 1 ) . ' MB'; }
        if ( $bytes >= 1024 ) { return round( $bytes / 1024, 1 ) . ' KB'; }
        return $bytes . ' B';
    }
}
