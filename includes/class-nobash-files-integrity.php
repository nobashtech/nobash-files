<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WordPress-aware integrity and change monitoring.
 *
 * Design rules:
 * - Never silently trust the current filesystem as a baseline.
 * - Core verification uses official WordPress checksums when available.
 * - Plugin/theme manifests are explicit administrator-created baselines.
 * - Scans are bounded; a truncated scan is reported as incomplete, never clean.
 * - Symlinks and Nobash private recovery storage are never traversed.
 */
final class Nobash_Files_Integrity {
    private const BASELINE_OPTION = 'nobash_files_integrity_baseline_v1';
    private const LAST_SCAN_OPTION = 'nobash_files_integrity_last_scan_v1';
    private const HISTORY_OPTION   = 'nobash_files_integrity_history_v1';
    private const DEFAULT_MAX_FILES = 50000;
    private const DEFAULT_MAX_SECONDS = 18.0;
    private const MAX_RESULT_ITEMS = 250;

    private string $root;
    private float $started_at;
    private int $files_seen = 0;
    private bool $truncated = false;
    private array $warnings = array();

    public function __construct() {
        $root = realpath( ABSPATH );
        if ( false === $root ) {
            throw new RuntimeException( 'Unable to resolve the WordPress root for integrity scanning.' );
        }
        $this->root = rtrim( wp_normalize_path( $root ), '/' );
        $this->started_at = microtime( true );
    }

    public static function settings(): array {
        $settings = array(
            'max_files'   => max( 5000, min( 100000, (int) get_option( 'nobash_files_integrity_max_files', self::DEFAULT_MAX_FILES ) ) ),
            'max_seconds' => max( 5.0, min( 45.0, (float) get_option( 'nobash_files_integrity_max_seconds', self::DEFAULT_MAX_SECONDS ) ) ),
        );
        return (array) apply_filters( 'nobash_files_runtime_integrity_settings', $settings );
    }

    public static function update_settings( array $settings ): array {
        if ( array_key_exists( 'max_files', $settings ) ) {
            update_option( 'nobash_files_integrity_max_files', max( 5000, min( 100000, absint( $settings['max_files'] ) ) ) );
        }
        if ( array_key_exists( 'max_seconds', $settings ) ) {
            $seconds = (float) $settings['max_seconds'];
            update_option( 'nobash_files_integrity_max_seconds', max( 5, min( 45, $seconds ) ) );
        }
        return self::settings();
    }

    public static function baseline_meta(): array {
        $baseline = get_option( self::BASELINE_OPTION, array() );
        if ( ! is_array( $baseline ) || empty( $baseline['files'] ) || ! is_array( $baseline['files'] ) ) {
            return array( 'exists' => false, 'created_at' => '', 'created_by' => 0, 'file_count' => 0 );
        }
        return array(
            'exists'      => true,
            'created_at'  => (string) ( $baseline['created_at'] ?? '' ),
            'created_by'  => (int) ( $baseline['created_by'] ?? 0 ),
            'file_count'  => count( $baseline['files'] ),
            'fingerprint' => (string) ( $baseline['fingerprint'] ?? '' ),
        );
    }

    public function create_baseline(): array {
        $this->reset_budget();
        $manifest = $this->content_manifest();
        if ( $this->truncated ) {
            throw new RuntimeException( 'The baseline scan reached its safety budget. Increase the Integrity scan limits or reduce the filesystem scope before creating a trusted baseline.' );
        }
        $baseline = array(
            'version'     => 1,
            'created_at'  => current_time( 'mysql' ),
            'created_by'  => get_current_user_id(),
            'fingerprint' => $this->manifest_fingerprint( $manifest ),
            'files'       => $manifest,
        );
        if ( false === get_option( self::BASELINE_OPTION, false ) ) {
            add_option( self::BASELINE_OPTION, $baseline, '', false );
        } else {
            update_option( self::BASELINE_OPTION, $baseline, false );
        }
        // A baseline change invalidates the previous comparison result. Keep history, but
        // require a fresh scan before showing a current integrity status.
        delete_option( self::LAST_SCAN_OPTION );
        Nobash_Files_DB::log( 'integrity_baseline_created', '', array( 'files' => count( $manifest ), 'fingerprint' => $baseline['fingerprint'] ) );
        return self::baseline_meta();
    }

    public static function delete_baseline(): void {
        delete_option( self::BASELINE_OPTION );
        delete_option( self::LAST_SCAN_OPTION );
        Nobash_Files_DB::log( 'integrity_baseline_deleted', '', array() );
    }

    public function scan(): array {
        $this->reset_budget();
        $advanced = (bool) apply_filters( 'nobash_files_integrity_advanced_enabled', true );
        $baseline = $advanced ? get_option( self::BASELINE_OPTION, array() ) : array();
        $baseline_files = is_array( $baseline ) && isset( $baseline['files'] ) && is_array( $baseline['files'] ) ? $baseline['files'] : array();

        $core = $this->scan_core();
        $current_manifest = $advanced ? $this->content_manifest() : array();
        $content = $advanced ? $this->compare_manifest( $baseline_files, $current_manifest ) : array( 'modified' => array(), 'added' => array(), 'removed' => array(), 'unchanged' => 0 );
        $suspicious = $advanced ? $this->scan_suspicious( $content, $core ) : array();

        $result = array(
            'scanned_at' => current_time( 'mysql' ),
            'duration_ms' => (int) round( ( microtime( true ) - $this->started_at ) * 1000 ),
            'files_seen' => $this->files_seen,
            'complete' => ! $this->truncated,
            'warnings' => array_values( array_unique( $this->warnings ) ),
            'baseline' => $advanced ? self::baseline_meta() : array( 'exists' => false, 'advanced_disabled' => true, 'created_at' => '', 'created_by' => 0, 'file_count' => 0 ),
            'core' => $core,
            'content' => $content,
            'suspicious' => $suspicious,
        );
        $result['summary'] = $this->summarize( $result );

        if ( false === get_option( self::LAST_SCAN_OPTION, false ) ) {
            add_option( self::LAST_SCAN_OPTION, $result, '', false );
        } else {
            update_option( self::LAST_SCAN_OPTION, $result, false );
        }
        $this->append_history( $result );
        Nobash_Files_DB::log( 'integrity_scan', '', array(
            'status' => $result['summary']['status'],
            'complete' => $result['complete'],
            'issues' => $result['summary']['issues'],
            'files_seen' => $result['files_seen'],
        ) );
        return $result;
    }

    public static function last_scan(): array {
        $scan = get_option( self::LAST_SCAN_OPTION, array() );
        return is_array( $scan ) ? $scan : array();
    }

    public static function history(): array {
        $history = get_option( self::HISTORY_OPTION, array() );
        return is_array( $history ) ? array_slice( $history, 0, 20 ) : array();
    }

    private function reset_budget(): void {
        $this->started_at = microtime( true );
        $this->files_seen = 0;
        $this->truncated = false;
        $this->warnings = array();
    }

    private function budget_ok(): bool {
        $settings = self::settings();
        if ( $this->files_seen >= $settings['max_files'] ) {
            $this->truncated = true;
            $this->warnings[] = 'File-count safety limit reached. The scan is incomplete.';
            return false;
        }
        if ( microtime( true ) - $this->started_at >= $settings['max_seconds'] ) {
            $this->truncated = true;
            $this->warnings[] = 'Time safety limit reached. The scan is incomplete.';
            return false;
        }
        return true;
    }

    private function relative( string $absolute ): string {
        $absolute = wp_normalize_path( $absolute );
        if ( $absolute === $this->root ) {
            return '';
        }
        if ( ! str_starts_with( $absolute . '/', $this->root . '/' ) ) {
            throw new RuntimeException( 'Integrity scanner refused a path outside the WordPress root.' );
        }
        return ltrim( substr( $absolute, strlen( $this->root ) ), '/' );
    }

    private function is_private_or_symlink( string $path ): bool {
        $path = wp_normalize_path( $path );
        if ( is_link( $path ) ) {
            return true;
        }
        return str_contains( '/' . ltrim( $path, '/' ) . '/', '/.nobash-files/' );
    }

    private function content_roots(): array {
        $roots = array();
        $candidates = array(
            'plugins' => defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins',
            'mu-plugins' => defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins',
            'themes' => WP_CONTENT_DIR . '/themes',
        );
        foreach ( $candidates as $type => $path ) {
            $real = realpath( $path );
            if ( false === $real || ! is_dir( $real ) ) {
                continue;
            }
            $real = rtrim( wp_normalize_path( $real ), '/' );
            if ( $real !== $this->root && ! str_starts_with( $real . '/', $this->root . '/' ) ) {
                $this->warnings[] = sprintf( '%s directory is outside the WordPress root and was skipped by the local safety boundary.', ucfirst( $type ) );
                continue;
            }
            $roots[ $type ] = $real;
        }
        return $roots;
    }

    private function content_manifest(): array {
        $manifest = array();
        foreach ( $this->content_roots() as $type => $root ) {
            $this->walk_files( $root, function ( string $file ) use ( &$manifest, $type, $root ): void {
                if ( ! $this->budget_ok() ) {
                    return;
                }
                $this->files_seen++;
                $relative = $this->relative( $file );
                $component_rel = ltrim( substr( $file, strlen( $root ) ), '/' );
                $parts = explode( '/', $component_rel );
                $component = $parts[0] ?: basename( $file );
                $size = @filesize( $file );
                $mtime = @filemtime( $file );
                $hash = @hash_file( 'sha256', $file );
                if ( false === $hash ) {
                    $this->warnings[] = 'One or more plugin/theme files could not be hashed.';
                    return;
                }
                $manifest[ $relative ] = array(
                    'sha256' => $hash,
                    'size' => false === $size ? 0 : (int) $size,
                    'mtime' => false === $mtime ? 0 : (int) $mtime,
                    'type' => $type,
                    'component' => $component,
                );
            } );
            if ( $this->truncated ) {
                break;
            }
        }
        ksort( $manifest, SORT_STRING );
        return $manifest;
    }

    private function walk_files( string $root, callable $callback ): void {
        $stack = array( $root );
        while ( $stack && ! $this->truncated ) {
            if ( ! $this->budget_ok() ) {
                break;
            }
            $dir = array_pop( $stack );
            if ( is_link( $dir ) || ! is_dir( $dir ) ) {
                continue;
            }
            $entries = @scandir( $dir );
            if ( false === $entries ) {
                $this->warnings[] = 'One or more directories could not be read.';
                continue;
            }
            foreach ( $entries as $name ) {
                if ( '.' === $name || '..' === $name ) {
                    continue;
                }
                $path = wp_normalize_path( $dir . '/' . $name );
                if ( $this->is_private_or_symlink( $path ) ) {
                    continue;
                }
                if ( is_dir( $path ) ) {
                    $stack[] = $path;
                    continue;
                }
                if ( is_file( $path ) ) {
                    $callback( $path );
                    if ( $this->truncated ) {
                        break;
                    }
                }
            }
        }
    }

    private function scan_core(): array {
        global $wp_version;
        $locale = function_exists( 'get_locale' ) ? get_locale() : 'en_US';
        if ( ! function_exists( 'get_core_checksums' ) ) {
            $update_file = ABSPATH . 'wp-admin/includes/update.php';
            if ( is_readable( $update_file ) ) {
                require_once $update_file;
            }
        }
        if ( ! function_exists( 'get_core_checksums' ) ) {
            return array( 'available' => false, 'version' => (string) $wp_version, 'locale' => $locale, 'verified' => 0, 'modified' => array(), 'missing' => array(), 'unexpected' => array(), 'message' => 'WordPress checksum API is unavailable.' );
        }
        $checksums = get_core_checksums( (string) $wp_version, $locale );
        $used_locale = $locale;
        if ( ! is_array( $checksums ) && 'en_US' !== $locale ) {
            $checksums = get_core_checksums( (string) $wp_version, 'en_US' );
            $used_locale = 'en_US';
        }
        if ( ! is_array( $checksums ) ) {
            return array( 'available' => false, 'version' => (string) $wp_version, 'locale' => $used_locale, 'verified' => 0, 'modified' => array(), 'missing' => array(), 'unexpected' => array(), 'message' => 'Official WordPress checksums could not be retrieved. Check outbound HTTPS connectivity and try again.' );
        }

        $modified = array();
        $missing = array();
        $verified = 0;
        $expected = array();
        foreach ( $checksums as $relative => $md5 ) {
            if ( ! $this->budget_ok() ) {
                break;
            }
            $relative = ltrim( wp_normalize_path( (string) $relative ), '/' );
            if ( str_starts_with( $relative, 'wp-content/' ) ) {
                continue;
            }
            $expected[ $relative ] = true;
            $absolute = wp_normalize_path( $this->root . '/' . $relative );
            if ( ! file_exists( $absolute ) ) {
                $missing[] = $relative;
                continue;
            }
            if ( ! is_file( $absolute ) || is_link( $absolute ) ) {
                $modified[] = $relative;
                continue;
            }
            $this->files_seen++;
            $actual = @md5_file( $absolute );
            if ( false === $actual || ! hash_equals( strtolower( (string) $md5 ), strtolower( $actual ) ) ) {
                $modified[] = $relative;
            } else {
                $verified++;
            }
        }

        $unexpected = array();
        foreach ( array( 'wp-admin', 'wp-includes' ) as $core_dir ) {
            $absolute_dir = $this->root . '/' . $core_dir;
            if ( ! is_dir( $absolute_dir ) ) {
                continue;
            }
            $this->walk_files( $absolute_dir, function ( string $file ) use ( &$unexpected, $expected ): void {
                if ( count( $unexpected ) >= self::MAX_RESULT_ITEMS || ! $this->budget_ok() ) {
                    return;
                }
                $this->files_seen++;
                $relative = $this->relative( $file );
                if ( ! isset( $expected[ $relative ] ) ) {
                    $unexpected[] = $relative;
                }
            } );
            if ( $this->truncated ) {
                break;
            }
        }

        return array(
            'available' => true,
            'version' => (string) $wp_version,
            'locale' => $used_locale,
            'verified' => $verified,
            'modified' => array_slice( array_values( array_unique( $modified ) ), 0, self::MAX_RESULT_ITEMS ),
            'missing' => array_slice( array_values( array_unique( $missing ) ), 0, self::MAX_RESULT_ITEMS ),
            'unexpected' => array_slice( array_values( array_unique( $unexpected ) ), 0, self::MAX_RESULT_ITEMS ),
            'message' => '',
        );
    }

    private function compare_manifest( array $baseline, array $current ): array {
        if ( empty( $baseline ) ) {
            return array(
                'baseline_exists' => false,
                'current_files' => count( $current ),
                'modified' => array(),
                'added' => array(),
                'removed' => array(),
                'unchanged' => 0,
            );
        }
        $modified = array();
        $added = array();
        $removed = array();
        $unchanged = 0;
        foreach ( $current as $path => $meta ) {
            if ( ! isset( $baseline[ $path ] ) ) {
                $added[] = $this->change_item( $path, $meta );
                continue;
            }
            $old_hash = (string) ( $baseline[ $path ]['sha256'] ?? '' );
            if ( '' === $old_hash || ! hash_equals( $old_hash, (string) $meta['sha256'] ) ) {
                $item = $this->change_item( $path, $meta );
                $item['previous_sha256'] = $old_hash;
                $modified[] = $item;
            } else {
                $unchanged++;
            }
        }
        foreach ( $baseline as $path => $meta ) {
            if ( ! isset( $current[ $path ] ) ) {
                $removed[] = $this->change_item( (string) $path, is_array( $meta ) ? $meta : array() );
            }
        }
        return array(
            'baseline_exists' => true,
            'current_files' => count( $current ),
            'modified' => array_slice( $modified, 0, self::MAX_RESULT_ITEMS ),
            'added' => array_slice( $added, 0, self::MAX_RESULT_ITEMS ),
            'removed' => array_slice( $removed, 0, self::MAX_RESULT_ITEMS ),
            'unchanged' => $unchanged,
        );
    }

    private function change_item( string $path, array $meta ): array {
        return array(
            'path' => $path,
            'type' => (string) ( $meta['type'] ?? '' ),
            'component' => (string) ( $meta['component'] ?? '' ),
            'size' => (int) ( $meta['size'] ?? 0 ),
            'mtime' => (int) ( $meta['mtime'] ?? 0 ),
            'sha256' => (string) ( $meta['sha256'] ?? '' ),
        );
    }

    private function scan_suspicious( array $content, array $core ): array {
        $items = array();
        $uploads = wp_upload_dir( null, false );
        $uploads_dir = isset( $uploads['basedir'] ) ? realpath( (string) $uploads['basedir'] ) : false;
        if ( false !== $uploads_dir ) {
            $uploads_dir = wp_normalize_path( $uploads_dir );
            if ( $uploads_dir === $this->root || str_starts_with( $uploads_dir . '/', $this->root . '/' ) ) {
                $this->walk_files( $uploads_dir, function ( string $file ) use ( &$items ): void {
                    if ( count( $items ) >= self::MAX_RESULT_ITEMS || ! $this->budget_ok() ) {
                        return;
                    }
                    $this->files_seen++;
                    $ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
                    $relative = $this->relative( $file );
                    if ( in_array( $ext, array( 'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'cgi', 'pl' ), true ) ) {
                        $items[] = array( 'severity' => 'high', 'path' => $relative, 'reason' => 'Executable/server-side file found inside the uploads directory.', 'rule' => 'executable_upload' );
                    } elseif ( in_array( basename( $file ), array( '.htaccess', '.user.ini' ), true ) ) {
                        $items[] = array( 'severity' => 'medium', 'path' => $relative, 'reason' => 'Server-configuration file found inside the uploads directory. Review if it is expected.', 'rule' => 'upload_config' );
                    }
                } );
            }
        }

        $review_paths = array();
        foreach ( array( 'added', 'modified' ) as $kind ) {
            foreach ( (array) ( $content[ $kind ] ?? array() ) as $item ) {
                if ( is_array( $item ) && ! empty( $item['path'] ) ) {
                    $review_paths[] = (string) $item['path'];
                }
            }
        }
        foreach ( (array) ( $core['unexpected'] ?? array() ) as $path ) {
            $review_paths[] = (string) $path;
        }
        $review_paths = array_slice( array_values( array_unique( $review_paths ) ), 0, 500 );
        foreach ( $review_paths as $relative ) {
            if ( count( $items ) >= self::MAX_RESULT_ITEMS || ! $this->budget_ok() ) {
                break;
            }
            $absolute = wp_normalize_path( $this->root . '/' . ltrim( $relative, '/' ) );
            if ( ! is_file( $absolute ) || is_link( $absolute ) ) {
                continue;
            }
            $ext = strtolower( pathinfo( $absolute, PATHINFO_EXTENSION ) );
            if ( ! in_array( $ext, array( 'php', 'phtml', 'inc', 'js' ), true ) ) {
                continue;
            }
            $size = @filesize( $absolute );
            if ( false === $size || $size > 2097152 ) {
                continue;
            }
            $sample = @file_get_contents( $absolute );
            if ( false === $sample ) {
                continue;
            }
            $rules = array(
                'encoded_eval' => '/\beval\s*\(\s*(?:base64_decode|gzinflate|gzuncompress|str_rot13)\s*\(/i',
                'shell_execution' => '/\b(?:shell_exec|passthru|proc_open|popen)\s*\(/i',
                'variable_function_exec' => '/\$[A-Za-z_][A-Za-z0-9_]*\s*\(\s*\$_(?:GET|POST|REQUEST|COOKIE)/i',
            );
            foreach ( $rules as $rule => $pattern ) {
                if ( preg_match( $pattern, $sample ) ) {
                    $items[] = array( 'severity' => 'medium', 'path' => $relative, 'reason' => 'Changed code matches a high-risk execution pattern. Review manually; this is a heuristic, not a malware verdict.', 'rule' => $rule );
                    break;
                }
            }
        }
        return array_values( $items );
    }

    private function summarize( array $result ): array {
        $core = $result['core'];
        $content = $result['content'];
        $suspicious = $result['suspicious'];
        $core_modified = count( (array) ( $core['modified'] ?? array() ) );
        $core_missing = count( (array) ( $core['missing'] ?? array() ) );
        $core_unexpected = count( (array) ( $core['unexpected'] ?? array() ) );
        $content_changes = count( (array) ( $content['modified'] ?? array() ) ) + count( (array) ( $content['added'] ?? array() ) ) + count( (array) ( $content['removed'] ?? array() ) );
        $high = count( array_filter( $suspicious, static fn( $item ) => is_array( $item ) && 'high' === ( $item['severity'] ?? '' ) ) );
        $medium = count( $suspicious ) - $high;
        $issues = $core_modified + $core_missing + $core_unexpected + $content_changes + count( $suspicious );

        if ( empty( $result['complete'] ) ) {
            $status = 'incomplete';
            $message = 'The scan stopped at its safety budget. Results are partial and must not be treated as a clean bill of health.';
        } elseif ( $core_modified || $core_missing || $high ) {
            $status = 'critical';
            $message = 'Important integrity changes need review.';
        } elseif ( $core_unexpected || $content_changes || $medium ) {
            $status = 'warning';
            $message = 'Filesystem changes were detected and should be reviewed.';
        } elseif ( empty( $core['available'] ) || empty( $content['baseline_exists'] ) ) {
            $status = 'needs_baseline';
            $message = 'No confirmed change was found, but verification is incomplete until core checksums and a trusted plugin/theme baseline are available.';
        } else {
            $status = 'clean';
            $message = 'Core files match official checksums and plugin/theme files match the trusted baseline.';
        }
        return array(
            'status' => $status,
            'message' => $message,
            'issues' => $issues,
            'core_modified' => $core_modified,
            'core_missing' => $core_missing,
            'core_unexpected' => $core_unexpected,
            'content_changes' => $content_changes,
            'suspicious_high' => $high,
            'suspicious_medium' => $medium,
        );
    }

    private function append_history( array $result ): void {
        $history = self::history();
        array_unshift( $history, array(
            'scanned_at' => $result['scanned_at'],
            'status' => $result['summary']['status'],
            'issues' => $result['summary']['issues'],
            'complete' => $result['complete'],
            'files_seen' => $result['files_seen'],
            'duration_ms' => $result['duration_ms'],
        ) );
        $history = array_slice( $history, 0, 20 );
        if ( false === get_option( self::HISTORY_OPTION, false ) ) {
            add_option( self::HISTORY_OPTION, $history, '', false );
        } else {
            update_option( self::HISTORY_OPTION, $history, false );
        }
    }

    private function manifest_fingerprint( array $manifest ): string {
        $parts = array();
        foreach ( $manifest as $path => $meta ) {
            $parts[] = $path . ':' . (string) ( $meta['sha256'] ?? '' );
        }
        return hash( 'sha256', implode( "\n", $parts ) );
    }
}
