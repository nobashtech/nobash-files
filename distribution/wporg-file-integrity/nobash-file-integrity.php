<?php
/**
 * Plugin Name: Nobash File Integrity
 * Description: Read-only checks of WordPress core files, executable uploads and risky file permissions.
 * Version: 1.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: Nobash
 * Author URI: https://nobash.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: nobash-file-integrity
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Read-only, administrator-initiated site file diagnostics.
 * This package deliberately does not provide browsing, downloads, uploads,
 * code editing, deletion, repair, archive extraction, or code execution.
 */
final class Nobash_File_Integrity {
    private const OPTION = 'nobash_file_integrity_latest_report';
    private const LIMIT = 10000;
    private const MAX_FINDINGS = 40;
    private const MAX_SECONDS = 18;

    public static function init(): void {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_post_nobash_file_integrity_scan', array( __CLASS__, 'handle_scan' ) );
    }

    public static function menu(): void {
        add_management_page(
            __( 'Nobash File Integrity', 'nobash-file-integrity' ),
            __( 'File Integrity', 'nobash-file-integrity' ),
            'manage_options',
            'nobash-file-integrity',
            array( __CLASS__, 'render' )
        );
    }

    /** @return array<string, mixed> */
    public static function scan(): array {
        if ( ! current_user_can( 'manage_options' ) ) {
            return array( 'status' => 'denied', 'complete' => false, 'findings' => array() );
        }
        $started = microtime( true );
        $report = array(
            'time'       => current_time( 'mysql' ),
            'wp_version' => get_bloginfo( 'version' ),
            'status'     => 'completed',
            'complete'   => true,
            'core_checked' => 0,
            'upload_checked' => 0,
            'counts' => array( 'core_changed' => 0, 'core_missing' => 0, 'core_unreadable' => 0, 'upload_executable' => 0, 'world_writable' => 0 ),
            'findings' => array(),
            'notes' => array(),
        );

        require_once ABSPATH . 'wp-admin/includes/update.php';
        global $wp_version;
        $version = isset( $wp_version ) ? (string) $wp_version : (string) get_bloginfo( 'version' );
        $checksums = get_core_checksums( $version, get_locale() );
        if ( ! is_array( $checksums ) || empty( $checksums ) ) {
            $report['status'] = 'incomplete';
            $report['complete'] = false;
            $report['notes'][] = 'Official WordPress core checksums were unavailable. No clean result is claimed.';
        } else {
            foreach ( $checksums as $relative => $expected ) {
                if ( microtime( true ) - $started > self::MAX_SECONDS ) {
                    self::mark_partial( $report, 'Time limit reached before all checks could finish.' );
                    break;
                }
                // Core only: never treat a custom plugin/theme or user upload
                // as identical to a distribution-supplied wp-content file.
                if ( ! is_string( $relative ) || ! preg_match( '~^(?:wp-admin/|wp-includes/|[^/]+$)~', $relative ) || str_contains( $relative, '..' ) ) {
                    continue;
                }
                if ( ! is_string( $expected ) || ! preg_match( '/^[0-9a-f]{32}$/i', $expected ) ) {
                    self::mark_partial( $report, 'An invalid checksum entry was received.' );
                    continue;
                }
                $absolute = wp_normalize_path( ABSPATH . ltrim( $relative, '/' ) );
                if ( is_link( $absolute ) ) {
                    self::finding( $report, 'core_unreadable', $relative );
                    continue;
                }
                if ( ! is_file( $absolute ) ) {
                    self::finding( $report, 'core_missing', $relative );
                    continue;
                }
                if ( ! is_readable( $absolute ) ) {
                    self::finding( $report, 'core_unreadable', $relative );
                    continue;
                }
                $actual = md5_file( $absolute ); // Official WordPress core manifest uses MD5.
                $report['core_checked']++;
                if ( false === $actual || ! hash_equals( strtolower( $expected ), strtolower( $actual ) ) ) {
                    self::finding( $report, 'core_changed', $relative );
                }
                self::check_permissions( $report, $absolute, $relative );
            }
        }

        $uploads = wp_get_upload_dir();
        if ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
            $base = realpath( $uploads['basedir'] );
            if ( false !== $base && is_dir( $base ) && ! is_link( $uploads['basedir'] ) ) {
                self::inspect_uploads( $report, $base, $started );
            } else {
                self::mark_partial( $report, 'Upload directory could not be scanned.' );
            }
        } else {
            self::mark_partial( $report, 'The WordPress uploads directory was not available.' );
        }
        if ( ! $report['complete'] ) {
            $report['status'] = 'incomplete';
        } elseif ( array_sum( $report['counts'] ) > 0 ) {
            $report['status'] = 'findings';
        }
        update_option( self::OPTION, $report, false );
        return $report;
    }

    private static function inspect_uploads( array &$report, string $base, float $started ): void {
        $base = rtrim( wp_normalize_path( $base ), '/' );
        $pending = array( $base );
        while ( $pending ) {
            if ( microtime( true ) - $started > self::MAX_SECONDS || $report['upload_checked'] >= self::LIMIT ) {
                self::mark_partial( $report, 'Upload scan reached its time/file safety limit.' );
                return;
            }
            $dir = array_pop( $pending );
            try {
                $entries = new DirectoryIterator( $dir );
            } catch ( UnexpectedValueException $e ) {
                self::mark_partial( $report, 'A directory could not be listed.' );
                continue;
            }
            foreach ( $entries as $entry ) {
                if ( $entry->isDot() || $entry->isLink() ) { continue; }
                $path = $entry->getPathname();
                $filename = $entry->getFilename();
                $rel = ltrim( substr( wp_normalize_path( $path ), strlen( $base ) ), '/' );
                if ( $entry->isDir() ) {
                    $pending[] = $path;
                    continue;
                }
                if ( ! $entry->isFile() ) { continue; }
                $report['upload_checked']++;
                if ( preg_match( '/\.(?:php\d*|phtml|phar|cgi|pl|py|sh|shtml)$/i', $filename ) || in_array( strtolower( $filename ), array( '.htaccess', '.user.ini', 'web.config' ), true ) ) {
                    self::finding( $report, 'upload_executable', 'uploads/' . $rel );
                }
                self::check_permissions( $report, $path, 'uploads/' . $rel );
                if ( $report['upload_checked'] >= self::LIMIT || microtime( true ) - $started > self::MAX_SECONDS ) {
                    self::mark_partial( $report, 'Upload scan reached its time/file safety limit.' );
                    return;
                }
            }
        }
    }

    private static function check_permissions( array &$report, string $absolute, string $relative ): void {
        $perms = fileperms( $absolute );
        if ( false !== $perms && ( $perms & 0002 ) ) {
            self::finding( $report, 'world_writable', $relative );
        }
    }

    private static function finding( array &$report, string $kind, string $path ): void {
        $report['counts'][ $kind ]++;
        if ( count( $report['findings'] ) < self::MAX_FINDINGS ) {
            $report['findings'][] = array( 'kind' => $kind, 'path' => $path );
        }
    }

    private static function mark_partial( array &$report, string $note ): void {
        $report['complete'] = false;
        if ( ! in_array( $note, $report['notes'], true ) ) {
            $report['notes'][] = $note;
        }
    }

    public static function handle_scan(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'nobash-file-integrity' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'nobash_file_integrity_scan' );
        self::scan();
        wp_safe_redirect( admin_url( 'tools.php?page=nobash-file-integrity&scan=complete' ) );
        exit;
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $report = get_option( self::OPTION, array() );
        ?>
        <div class="wrap">
          <h1><?php esc_html_e( 'Nobash File Integrity', 'nobash-file-integrity' ); ?></h1>
          <p><?php esc_html_e( 'Read-only file diagnostics. A finding is not proof of a compromise; confirm it before taking action.', 'nobash-file-integrity' ); ?></p>
          <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="nobash_file_integrity_scan" />
            <?php wp_nonce_field( 'nobash_file_integrity_scan' ); ?>
            <?php submit_button( __( 'Run integrity check', 'nobash-file-integrity' ) ); ?>
          </form>
          <?php if ( is_array( $report ) && ! empty( $report['time'] ) ) : ?>
            <h2><?php esc_html_e( 'Latest check', 'nobash-file-integrity' ); ?></h2>
            <p><?php echo esc_html( sprintf( /* translators: 1: Scan date, 2: Scan status. */ __( 'Checked %1$s — %2$s', 'nobash-file-integrity' ), (string) $report['time'], (string) $report['status'] ) ); ?></p>
            <p><?php echo esc_html( sprintf( /* translators: 1: Checked core files, 2: Checked upload files. */ __( 'Core files checked: %1$d. Upload files checked: %2$d.', 'nobash-file-integrity' ), (int) $report['core_checked'], (int) $report['upload_checked'] ) ); ?></p>
            <table class="widefat striped" style="max-width:780px"><thead><tr><th><?php esc_html_e( 'Check', 'nobash-file-integrity' ); ?></th><th><?php esc_html_e( 'Count', 'nobash-file-integrity' ); ?></th></tr></thead><tbody>
            <?php foreach ( (array) ( $report['counts'] ?? array() ) as $kind => $count ) : ?>
              <tr><td><?php echo esc_html( str_replace( '_', ' ', ucwords( (string) $kind, '_' ) ) ); ?></td><td><?php echo esc_html( (string) absint( $count ) ); ?></td></tr>
            <?php endforeach; ?></tbody></table>
            <?php foreach ( (array) ( $report['notes'] ?? array() ) as $note ) : ?><p><strong><?php echo esc_html( (string) $note ); ?></strong></p><?php endforeach; ?>
            <?php if ( ! empty( $report['findings'] ) ) : ?>
            <h2><?php esc_html_e( 'Sample findings (up to 40)', 'nobash-file-integrity' ); ?></h2>
            <table class="widefat striped" style="max-width:960px"><thead><tr><th><?php esc_html_e( 'Type', 'nobash-file-integrity' ); ?></th><th><?php esc_html_e( 'Path', 'nobash-file-integrity' ); ?></th></tr></thead><tbody>
            <?php foreach ( $report['findings'] as $finding ) : ?>
              <tr><td><?php echo esc_html( (string) $finding['kind'] ); ?></td><td><code><?php echo esc_html( (string) $finding['path'] ); ?></code></td></tr>
            <?php endforeach; ?></tbody></table>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <?php
    }
}
add_action( 'plugins_loaded', array( 'Nobash_File_Integrity', 'init' ) );
