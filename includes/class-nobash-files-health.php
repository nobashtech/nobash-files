<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nobash_Files_Health {
    public static function init(): void {
        add_filter( 'site_status_tests', array( __CLASS__, 'site_status_tests' ) );
        add_filter( 'debug_information', array( __CLASS__, 'debug_information' ) );
        add_action( 'admin_init', array( __CLASS__, 'privacy_policy_content' ) );
    }

    public static function site_status_tests( array $tests ): array {
        $tests['direct']['nobash_files_private_storage'] = array(
            'label' => __( 'Nobash Files private storage', 'nobash-files' ),
            'test'  => array( __CLASS__, 'test_private_storage' ),
        );
        $tests['direct']['nobash_files_php_resources'] = array(
            'label' => __( 'Nobash Files PHP resources', 'nobash-files' ),
            'test'  => array( __CLASS__, 'test_php_resources' ),
        );
        $tests['direct']['nobash_files_runtime_compatibility'] = array(
            'label' => __( 'Nobash Files compatibility', 'nobash-files' ),
            'test'  => array( __CLASS__, 'test_runtime_compatibility' ),
        );
        return $tests;
    }

    public static function test_private_storage(): array {
        $result = array(
            'label'       => __( 'Nobash private recovery storage is writable', 'nobash-files' ),
            'status'      => 'good',
            'badge'       => array( 'label' => __( 'Nobash Files', 'nobash-files' ), 'color' => 'blue' ),
            'description' => '<p>' . esc_html__( 'Recovery snapshots, upload chunks and temporary packages can be stored outside the public file browser.', 'nobash-files' ) . '</p>',
            'actions'     => '',
            'test'        => 'nobash_files_private_storage',
        );
        try {
            $fs = new Nobash_Files_Filesystem();
            $dir = $fs->private_dir();
            if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
                throw new RuntimeException( 'Private storage is not writable.' );
            }
        } catch ( Throwable $e ) {
            $result['status'] = 'critical';
            $result['label'] = __( 'Nobash private recovery storage is unavailable', 'nobash-files' );
            $result['description'] = '<p>' . esc_html__( 'Safe edits, Trash recovery and resumable operations need writable private storage. Check filesystem permissions before using destructive operations.', 'nobash-files' ) . '</p>';
        }
        return $result;
    }

    public static function test_php_resources(): array {
        $profile = class_exists( 'Nobash_Files_Runtime' ) ? Nobash_Files_Runtime::profile() : array();
        $low = ! empty( $profile['low_resource'] );
        $memory = (int) ( $profile['memory_limit'] ?? -1 );
        $effective = (int) ( $profile['effective_chunk_bytes'] ?? get_option( 'nobash_files_chunk_bytes', 2097152 ) );
        return array(
            'label'       => $low ? __( 'Nobash is adapting to constrained PHP resources', 'nobash-files' ) : __( 'PHP resources are suitable for bounded file operations', 'nobash-files' ),
            'status'      => $low ? 'recommended' : 'good',
            'badge'       => array( 'label' => __( 'Nobash Files', 'nobash-files' ), 'color' => 'blue' ),
            'description' => '<p>' . esc_html( sprintf( 'PHP memory limit: %s. Effective upload chunk: %s. Directory browsing, editor operations and integrity scans remain bounded to the current host.', class_exists( 'Nobash_Files_Runtime' ) ? Nobash_Files_Runtime::format_bytes( $memory ) : self::format_bytes( $memory ), class_exists( 'Nobash_Files_Runtime' ) ? Nobash_Files_Runtime::format_bytes( $effective ) : self::format_bytes( $effective ) ) ) . '</p>',
            'actions'     => '',
            'test'        => 'nobash_files_php_resources',
        );
    }

    public static function test_runtime_compatibility(): array {
        $profile = class_exists( 'Nobash_Files_Runtime' ) ? Nobash_Files_Runtime::profile() : array();
        $ok = ! empty( $profile['php_supported'] ) && ! empty( $profile['wp_supported'] ) && ! empty( $profile['hash_sha256'] );
        $optional = array();
        if ( empty( $profile['ziparchive'] ) && empty( $profile['phardata'] ) ) { $optional[] = 'ZIP/TAR creation is limited because neither ZipArchive nor PharData is available.'; }
        $desc = sprintf( 'WordPress %s · PHP %s. Minimum supported by Nobash: WordPress %s and PHP %s.', (string) ( $profile['wordpress_version'] ?? 'unknown' ), (string) ( $profile['php_version'] ?? PHP_VERSION ), class_exists( 'Nobash_Files_Runtime' ) ? Nobash_Files_Runtime::MIN_WP : '6.5', class_exists( 'Nobash_Files_Runtime' ) ? Nobash_Files_Runtime::MIN_PHP : '8.1' );
        if ( $optional ) { $desc .= ' ' . implode( ' ', $optional ); }
        return array(
            'label'       => $ok ? __( 'Nobash Files runtime requirements are met', 'nobash-files' ) : __( 'Nobash Files runtime requirements need attention', 'nobash-files' ),
            'status'      => $ok ? ( $optional ? 'recommended' : 'good' ) : 'critical',
            'badge'       => array( 'label' => __( 'Nobash Files', 'nobash-files' ), 'color' => 'blue' ),
            'description' => '<p>' . esc_html( $desc ) . '</p>',
            'actions'     => '',
            'test'        => 'nobash_files_runtime_compatibility',
        );
    }

    public static function debug_information( array $info ): array {
        $private = (string) get_option( 'nobash_files_private_storage_path', '' );
        $info['nobash-files'] = array(
            'label'  => __( 'Nobash Files', 'nobash-files' ),
            'fields' => array(
                'version' => array( 'label' => __( 'Core version', 'nobash-files' ), 'value' => defined( 'NOBASH_FILES_VERSION' ) ? NOBASH_FILES_VERSION : '' ),
                'wordpress_version' => array( 'label' => __( 'WordPress version', 'nobash-files' ), 'value' => class_exists( 'Nobash_Files_Runtime' ) ? (string) ( Nobash_Files_Runtime::profile()['wordpress_version'] ?? '' ) : '' ),
                'php_version' => array( 'label' => __( 'PHP version', 'nobash-files' ), 'value' => PHP_VERSION ),
                'memory_limit' => array( 'label' => __( 'PHP memory limit', 'nobash-files' ), 'value' => class_exists( 'Nobash_Files_Runtime' ) ? Nobash_Files_Runtime::format_bytes( (int) Nobash_Files_Runtime::profile()['memory_limit'] ) : self::format_bytes( self::memory_limit_bytes() ) ),
                'request_body_limit' => array( 'label' => __( 'Request upload ceiling', 'nobash-files' ), 'value' => class_exists( 'Nobash_Files_Runtime' ) ? Nobash_Files_Runtime::format_bytes( (int) Nobash_Files_Runtime::profile()['request_body_max'] ) : 'Unknown' ),
                'effective_chunk' => array( 'label' => __( 'Effective upload chunk', 'nobash-files' ), 'value' => class_exists( 'Nobash_Files_Runtime' ) ? Nobash_Files_Runtime::format_bytes( Nobash_Files_Runtime::effective_chunk_bytes() ) : '' ),
                'safe_mode' => array( 'label' => __( 'Safe Mode', 'nobash-files' ), 'value' => '1' === get_option( 'nobash_files_safe_mode', '1' ) ? 'On' : 'Off' ),
                'directory_page_size' => array( 'label' => __( 'Directory page size', 'nobash-files' ), 'value' => (string) (int) get_option( 'nobash_files_directory_page_size', 250 ) ),
                'editor_limit' => array( 'label' => __( 'Editor size limit', 'nobash-files' ), 'value' => self::format_bytes( (int) get_option( 'nobash_files_max_edit_bytes', 1048576 ) ) ),
                'ziparchive' => array( 'label' => __( 'ZipArchive', 'nobash-files' ), 'value' => class_exists( 'ZipArchive' ) ? 'Available' : 'Not available' ),
                'phardata' => array( 'label' => __( 'PharData', 'nobash-files' ), 'value' => class_exists( 'PharData' ) ? 'Available' : 'Not available' ),
                'private_storage' => array( 'label' => __( 'Private storage', 'nobash-files' ), 'value' => '' !== $private ? wp_basename( $private ) . ' (' . ( is_writable( $private ) ? 'writable' : 'not writable' ) . ')' : 'Not initialized', 'private' => true ),
                'uninstall_policy' => array( 'label' => __( 'Delete policy', 'nobash-files' ), 'value' => 'remove' === get_option( 'nobash_files_uninstall_policy', 'keep' ) ? 'Remove Nobash data' : 'Keep Nobash data' ),
            ),
        );
        return $info;
    }

    public static function privacy_policy_content(): void {
        if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) { return; }
        $content = '<p>' . esc_html__( 'Nobash Files stores administrator/user IDs with file-operation activity, safety versions, undo metadata, favorites and recent-file preferences in the WordPress database. File contents saved as safety versions and Trash/temporary recovery data may be stored in a private Nobash recovery directory on the server.', 'nobash-files' ) . '</p>';
        $content .= '<p>' . esc_html__( 'Nobash Files manages local WordPress files and does not require an external account. Administrator-initiated integrity checks and repair can contact WordPress.org. Optional monitoring email uses the site\'s configured mail provider.', 'nobash-files' ) . '</p>';
        wp_add_privacy_policy_content( __( 'Nobash Files', 'nobash-files' ), wp_kses_post( $content ) );
    }

    private static function memory_limit_bytes(): int {
        $value = trim( (string) ini_get( 'memory_limit' ) );
        if ( '' === $value || '-1' === $value ) { return -1; }
        $unit = strtolower( substr( $value, -1 ) );
        $number = (float) $value;
        if ( 'g' === $unit ) { $number *= 1024; $unit = 'm'; }
        if ( 'm' === $unit ) { $number *= 1024; $unit = 'k'; }
        if ( 'k' === $unit ) { $number *= 1024; }
        return (int) $number;
    }

    private static function format_bytes( int $bytes ): string {
        if ( $bytes < 0 ) { return 'Unlimited'; }
        if ( $bytes >= 1073741824 ) { return round( $bytes / 1073741824, 1 ) . ' GB'; }
        if ( $bytes >= 1048576 ) { return round( $bytes / 1048576, 1 ) . ' MB'; }
        if ( $bytes >= 1024 ) { return round( $bytes / 1024, 1 ) . ' KB'; }
        return $bytes . ' B';
    }
}
