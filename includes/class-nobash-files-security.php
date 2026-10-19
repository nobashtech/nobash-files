<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Phase 11 security, quarantine and guarded repair services.
 *
 * Safety rules:
 * - Quarantine blobs live only in hardened private storage and lose executable extensions.
 * - Core repair is limited to files listed by official checksums for the running WP version.
 * - Every downloaded repair file must match its official checksum before touching the live site.
 * - Existing core files are backed up first and repairs can be rolled back while the snapshot exists.
 * - Plugin/theme verification is read-only and only claims package verification when the exact
 *   installed version can be downloaded from downloads.wordpress.org.
 * - Permission repair only removes the world-write bit; it never recursively normalizes a tree.
 */
final class Nobash_Files_Security {
    private const QUARANTINE_DIR = 'quarantine';
    private const REPAIR_DIR = 'security-repairs';
    private const MAX_QUARANTINE_ITEMS = 200;
    private const MAX_REPAIR_FILES = 25;
    private const MAX_PERMISSION_FINDINGS = 200;
    private const MAX_PERMISSION_SCAN_FILES = 20000;
    private const MAX_PACKAGE_FILES = 15000;
    private const PERMISSION_OPTION = 'nobash_files_security_permission_scan_v1';

    private Nobash_Files_Filesystem $fs;
    private string $root;
    private string $private;

    public function __construct() {
        $this->fs = new Nobash_Files_Filesystem();
        $this->root = rtrim( wp_normalize_path( $this->fs->root() ), '/' );
        $this->private = rtrim( wp_normalize_path( $this->fs->private_dir() ), '/' );
        $this->ensure_private_dir( $this->private . '/' . self::QUARANTINE_DIR );
        $this->ensure_private_dir( $this->private . '/' . self::REPAIR_DIR );
    }

    public function payload(): array {
        $last = Nobash_Files_Integrity::last_scan();
        return array(
            'quarantine' => $this->quarantine_items(),
            'permissions' => $this->permission_diagnostics(),
            'repairable_core' => $this->repairable_core_from_scan( $last ),
            'core' => array(
                'version' => function_exists( 'wp_get_wp_version' ) ? wp_get_wp_version() : (string) ( $GLOBALS['wp_version'] ?? '' ),
                'locale' => function_exists( 'get_locale' ) ? get_locale() : 'en_US',
                'scan_available' => ! empty( $last ),
            ),
            'limits' => array(
                'max_core_repair_files' => self::MAX_REPAIR_FILES,
                'max_permission_findings' => self::MAX_PERMISSION_FINDINGS,
            ),
        );
    }

    private function ensure_private_dir( string $dir ): void {
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            throw new RuntimeException( 'Unable to prepare private security storage.' );
        }
        if ( ! is_writable( $dir ) ) {
            throw new RuntimeException( 'Private security storage is not writable.' );
        }
    }

    private function new_id( string $prefix ): string {
        return $prefix . '-' . gmdate( 'YmdHis' ) . '-' . strtolower( wp_generate_password( 12, false, false ) );
    }

    private function write_json_atomic( string $path, array $data ): void {
        $json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( false === $json ) {
            throw new RuntimeException( 'Unable to encode security metadata.' );
        }
        $tmp = $path . '.tmp-' . wp_generate_password( 8, false, false );
        if ( false === @file_put_contents( $tmp, $json, LOCK_EX ) ) {
            throw new RuntimeException( 'Unable to write security metadata.' );
        }
        if ( ! @rename( $tmp, $path ) ) {
            @unlink( $tmp );
            throw new RuntimeException( 'Unable to finalize security metadata.' );
        }
    }

    private function read_json( string $path ): array {
        if ( ! is_file( $path ) ) {
            return array();
        }
        $raw = @file_get_contents( $path );
        $data = false === $raw ? null : json_decode( $raw, true );
        return is_array( $data ) ? $data : array();
    }

    private function remove_tree( string $path ): void {
        if ( is_link( $path ) || is_file( $path ) ) {
            @unlink( $path );
            return;
        }
        if ( ! is_dir( $path ) ) {
            return;
        }
        $items = @scandir( $path );
        if ( false !== $items ) {
            foreach ( $items as $name ) {
                if ( '.' === $name || '..' === $name ) {
                    continue;
                }
                $this->remove_tree( wp_normalize_path( $path . '/' . $name ) );
            }
        }
        @rmdir( $path );
    }

    public function quarantine_items(): array {
        $base = $this->private . '/' . self::QUARANTINE_DIR;
        $dirs = @scandir( $base );
        if ( false === $dirs ) {
            return array();
        }
        $items = array();
        foreach ( $dirs as $id ) {
            if ( '.' === $id || '..' === $id || ! preg_match( '/^q-[a-z0-9-]+$/', $id ) ) {
                continue;
            }
            $meta = $this->read_json( $base . '/' . $id . '/meta.json' );
            if ( empty( $meta['id'] ) || empty( $meta['original_path'] ) || empty( $meta['sha256'] ) ) {
                continue;
            }
            unset( $meta['blob'] );
            $items[] = $meta;
        }
        usort( $items, static fn( array $a, array $b ): int => strcmp( (string) ( $b['quarantined_at'] ?? '' ), (string) ( $a['quarantined_at'] ?? '' ) ) );
        return array_slice( $items, 0, self::MAX_QUARANTINE_ITEMS );
    }

    public function quarantine_file( string $relative, string $reason = 'Manual security quarantine' ): array {
        $relative = $this->fs->normalize_relative( $relative );
        $critical = array( 'wp-config.php', '.htaccess', '.user.ini', 'index.php' );
        if ( '' === $relative || ( ! str_contains( $relative, '/' ) && in_array( strtolower( $relative ), $critical, true ) ) ) {
            throw new RuntimeException( 'This critical root configuration/bootstrap file cannot be quarantined from Nobash.' );
        }
        $absolute = $this->fs->resolve_existing( $relative );
        if ( ! is_file( $absolute ) || is_link( $absolute ) ) {
            throw new RuntimeException( 'Only regular files can be quarantined.' );
        }
        if ( ! is_writable( dirname( $absolute ) ) ) {
            throw new RuntimeException( 'The file parent directory is not writable, so the file cannot be safely removed after quarantine.' );
        }
        $hash = @hash_file( 'sha256', $absolute );
        $size = @filesize( $absolute );
        if ( false === $hash || false === $size ) {
            throw new RuntimeException( 'Unable to fingerprint the file before quarantine.' );
        }
        $id = $this->new_id( 'q' );
        $dir = $this->private . '/' . self::QUARANTINE_DIR . '/' . $id;
        $this->ensure_private_dir( $dir );
        $blob = $dir . '/payload.blob';
        $tmp = $dir . '/payload.tmp';
        if ( ! @copy( $absolute, $tmp ) ) {
            $this->remove_tree( $dir );
            throw new RuntimeException( 'Unable to copy the file into private quarantine storage.' );
        }
        $copy_hash = @hash_file( 'sha256', $tmp );
        if ( false === $copy_hash || ! hash_equals( $hash, $copy_hash ) ) {
            $this->remove_tree( $dir );
            throw new RuntimeException( 'Quarantine verification failed; the live file was not changed.' );
        }
        if ( ! @rename( $tmp, $blob ) ) {
            $this->remove_tree( $dir );
            throw new RuntimeException( 'Unable to finalize the private quarantine copy.' );
        }
        @chmod( $blob, 0600 );
        $meta = array(
            'id' => $id,
            'original_path' => $relative,
            'original_name' => basename( $absolute ),
            'sha256' => $hash,
            'size' => (int) $size,
            'mode' => (int) ( @fileperms( $absolute ) ?: 0 ) & 0777,
            'mtime' => (int) ( @filemtime( $absolute ) ?: 0 ),
            'reason' => sanitize_text_field( $reason ),
            'quarantined_at' => current_time( 'mysql' ),
            'quarantined_by' => get_current_user_id(),
            'blob' => 'payload.blob',
        );
        $this->write_json_atomic( $dir . '/meta.json', $meta );
        if ( ! @unlink( $absolute ) ) {
            $this->remove_tree( $dir );
            throw new RuntimeException( 'The verified quarantine copy was created, but the live file could not be removed. Nothing was quarantined.' );
        }
        Nobash_Files_DB::log( 'security_quarantined', $relative, array( 'quarantine_id' => $id, 'sha256' => $hash, 'reason' => $meta['reason'] ) );
        unset( $meta['blob'] );
        return $meta;
    }

    private function load_quarantine( string $id ): array {
        if ( ! preg_match( '/^q-[a-z0-9-]+$/', $id ) ) {
            throw new InvalidArgumentException( 'Invalid quarantine item.' );
        }
        $dir = $this->private . '/' . self::QUARANTINE_DIR . '/' . $id;
        $meta = $this->read_json( $dir . '/meta.json' );
        $blob = $dir . '/payload.blob';
        if ( empty( $meta['id'] ) || ! is_file( $blob ) ) {
            throw new RuntimeException( 'Quarantine item is unavailable.' );
        }
        $hash = @hash_file( 'sha256', $blob );
        if ( false === $hash || ! hash_equals( (string) $meta['sha256'], $hash ) ) {
            throw new RuntimeException( 'Quarantine item failed integrity verification and cannot be restored.' );
        }
        return array( 'dir' => $dir, 'blob' => $blob, 'meta' => $meta );
    }

    public function restore_quarantine( string $id, bool $keep_both = true ): array {
        $item = $this->load_quarantine( $id );
        $meta = $item['meta'];
        $relative = $this->fs->normalize_relative( (string) $meta['original_path'] );
        $parent_rel = dirname( $relative );
        if ( '.' === $parent_rel ) {
            $parent_rel = '';
        }
        $parent = $this->fs->resolve_existing( $parent_rel );
        if ( ! is_dir( $parent ) || ! is_writable( $parent ) ) {
            throw new RuntimeException( 'The original parent folder is unavailable or not writable.' );
        }
        $name = basename( $relative );
        $target = wp_normalize_path( $parent . '/' . $name );
        if ( file_exists( $target ) ) {
            if ( ! $keep_both ) {
                throw new RuntimeException( 'A file already exists at the original path. Choose Keep Both or review it first.' );
            }
            $info = pathinfo( $name );
            $stem = (string) ( $info['filename'] ?? $name );
            $ext = isset( $info['extension'] ) ? '.' . $info['extension'] : '';
            for ( $i = 1; $i <= 1000; $i++ ) {
                $candidate = $parent . '/' . $stem . '-restored' . ( 1 === $i ? '' : '-' . $i ) . $ext;
                if ( ! file_exists( $candidate ) ) {
                    $target = wp_normalize_path( $candidate );
                    break;
                }
            }
        }
        $tmp = $parent . '/.nobash-files-quarantine-restore-' . wp_generate_password( 12, false, false ) . '.tmp';
        if ( ! @copy( $item['blob'], $tmp ) ) {
            throw new RuntimeException( 'Unable to prepare the restored file.' );
        }
        $hash = @hash_file( 'sha256', $tmp );
        if ( false === $hash || ! hash_equals( (string) $meta['sha256'], $hash ) ) {
            @unlink( $tmp );
            throw new RuntimeException( 'Restored file verification failed.' );
        }
        $mode = (int) ( $meta['mode'] ?? 0644 );
        @chmod( $tmp, $mode > 0 ? $mode : 0644 );
        if ( ! @rename( $tmp, $target ) ) {
            @unlink( $tmp );
            throw new RuntimeException( 'Unable to finalize the restored file.' );
        }
        $restored_rel = $this->fs->relative_from_absolute( $target );
        $this->remove_tree( $item['dir'] );
        Nobash_Files_DB::log( 'security_quarantine_restored', $restored_rel, array( 'quarantine_id' => $id, 'original_path' => $relative ) );
        return array( 'restored_path' => $restored_rel, 'sha256' => $hash );
    }

    public function delete_quarantine( string $id ): void {
        $item = $this->load_quarantine( $id );
        $relative = (string) ( $item['meta']['original_path'] ?? '' );
        $this->remove_tree( $item['dir'] );
        Nobash_Files_DB::log( 'security_quarantine_deleted', $relative, array( 'quarantine_id' => $id ) );
    }

    public function permission_diagnostics( bool $deep = false ): array {
        $targets = array(
            array( 'label' => 'WordPress root', 'path' => $this->root ),
            array( 'label' => 'wp-admin', 'path' => $this->root . '/wp-admin' ),
            array( 'label' => 'wp-includes', 'path' => $this->root . '/wp-includes' ),
            array( 'label' => 'wp-content', 'path' => wp_normalize_path( WP_CONTENT_DIR ) ),
            array( 'label' => 'Plugins', 'path' => wp_normalize_path( defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins' ) ),
            array( 'label' => 'Themes', 'path' => wp_normalize_path( WP_CONTENT_DIR . '/themes' ) ),
        );
        $uploads = wp_upload_dir( null, false );
        if ( ! empty( $uploads['basedir'] ) ) {
            $targets[] = array( 'label' => 'Uploads', 'path' => wp_normalize_path( (string) $uploads['basedir'] ) );
        }
        $overview = array();
        foreach ( $targets as $target ) {
            $path = (string) $target['path'];
            if ( ! file_exists( $path ) ) {
                continue;
            }
            $mode = (int) ( @fileperms( $path ) ?: 0 ) & 0777;
            $overview[] = array(
                'label' => (string) $target['label'],
                'path' => $this->relative_if_inside( $path ),
                'mode' => sprintf( '%04o', $mode ),
                'writable' => is_writable( $path ),
                'world_writable' => (bool) ( $mode & 0002 ),
            );
        }
        if ( $deep ) {
            $world_writable = $this->find_world_writable();
            $world_writable['scanned_at'] = current_time( 'mysql' );
            update_option( self::PERMISSION_OPTION, $world_writable, false );
            Nobash_Files_DB::log( 'security_permission_scan', '', array( 'findings' => count( (array) $world_writable['items'] ), 'files_checked' => (int) $world_writable['files_checked'], 'truncated' => (bool) $world_writable['truncated'] ) );
        } else {
            $saved = get_option( self::PERMISSION_OPTION, array() );
            $world_writable = is_array( $saved ) ? $saved : array();
            $world_writable = array_merge( array( 'items' => array(), 'files_checked' => 0, 'truncated' => false, 'scanned_at' => '' ), $world_writable );
        }
        return array(
            'overview' => $overview,
            'world_writable' => $world_writable,
            'note' => 'Nobash only offers a targeted repair that removes the world-write bit. Hosting-specific ownership/group policy is left untouched.',
        );
    }

    private function relative_if_inside( string $path ): string {
        $real = realpath( $path );
        if ( false === $real ) {
            return '';
        }
        $real = wp_normalize_path( $real );
        if ( $real === $this->root ) {
            return '';
        }
        if ( str_starts_with( $real . '/', $this->root . '/' ) ) {
            return ltrim( substr( $real, strlen( $this->root ) ), '/' );
        }
        return '[outside WordPress root]';
    }

    private function find_world_writable(): array {
        $findings = array();
        $seen = 0;
        $stack = array( $this->root );
        while ( $stack && $seen < self::MAX_PERMISSION_SCAN_FILES && count( $findings ) < self::MAX_PERMISSION_FINDINGS ) {
            $dir = array_pop( $stack );
            if ( is_link( $dir ) || str_starts_with( rtrim( wp_normalize_path( $dir ), '/' ) . '/', $this->private . '/' ) ) {
                continue;
            }
            $entries = @scandir( $dir );
            if ( false === $entries ) {
                continue;
            }
            foreach ( $entries as $name ) {
                if ( '.' === $name || '..' === $name ) {
                    continue;
                }
                $path = wp_normalize_path( $dir . '/' . $name );
                if ( is_link( $path ) || $path === $this->private || str_starts_with( $path . '/', $this->private . '/' ) ) {
                    continue;
                }
                $seen++;
                $mode = (int) ( @fileperms( $path ) ?: 0 ) & 0777;
                if ( $mode & 0002 ) {
                    $findings[] = array(
                        'path' => $this->fs->relative_from_absolute( $path ),
                        'type' => is_dir( $path ) ? 'directory' : 'file',
                        'mode' => sprintf( '%04o', $mode ),
                    );
                    if ( count( $findings ) >= self::MAX_PERMISSION_FINDINGS ) {
                        break 2;
                    }
                }
                if ( is_dir( $path ) ) {
                    $stack[] = $path;
                }
                if ( $seen >= self::MAX_PERMISSION_SCAN_FILES ) {
                    break 2;
                }
            }
        }
        return array( 'items' => $findings, 'files_checked' => $seen, 'truncated' => $seen >= self::MAX_PERMISSION_SCAN_FILES || count( $findings ) >= self::MAX_PERMISSION_FINDINGS );
    }

    public function repair_world_write( string $relative ): array {
        $relative = $this->fs->normalize_relative( $relative );
        $absolute = $this->fs->resolve_existing( $relative );
        if ( is_link( $absolute ) ) {
            throw new RuntimeException( 'Symlink permissions are not changed.' );
        }
        $before = (int) ( @fileperms( $absolute ) ?: 0 ) & 0777;
        if ( ! ( $before & 0002 ) ) {
            return array( 'path' => $relative, 'before' => sprintf( '%04o', $before ), 'after' => sprintf( '%04o', $before ), 'changed' => false );
        }
        $after = $before & ~0002;
        if ( ! @chmod( $absolute, $after ) ) {
            throw new RuntimeException( 'The server refused the permission change. Review filesystem ownership/group policy with your host.' );
        }
        clearstatcache( true, $absolute );
        $actual = (int) ( @fileperms( $absolute ) ?: 0 ) & 0777;
        if ( $actual & 0002 ) {
            throw new RuntimeException( 'World-write permission is still present after chmod.' );
        }
        Nobash_Files_DB::log( 'security_permission_repaired', $relative, array( 'before' => sprintf( '%04o', $before ), 'after' => sprintf( '%04o', $actual ) ) );
        return array( 'path' => $relative, 'before' => sprintf( '%04o', $before ), 'after' => sprintf( '%04o', $actual ), 'changed' => true );
    }

    private function repairable_core_from_scan( array $scan ): array {
        $core = is_array( $scan['core'] ?? null ) ? $scan['core'] : array();
        $items = array();
        foreach ( array( 'modified' => 'Modified', 'missing' => 'Missing' ) as $kind => $label ) {
            foreach ( (array) ( $core[ $kind ] ?? array() ) as $path ) {
                $path = ltrim( wp_normalize_path( (string) $path ), '/' );
                if ( '' === $path || str_starts_with( $path, 'wp-content/' ) ) {
                    continue;
                }
                $items[] = array( 'path' => $path, 'status' => $kind, 'label' => $label );
            }
        }
        return array_slice( $items, 0, 250 );
    }

    private function core_checksums(): array {
        if ( ! function_exists( 'get_core_checksums' ) ) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }
        $version = function_exists( 'wp_get_wp_version' ) ? wp_get_wp_version() : (string) ( $GLOBALS['wp_version'] ?? '' );
        $locale = function_exists( 'get_locale' ) ? get_locale() : 'en_US';
        $checksums = get_core_checksums( $version, $locale );
        if ( ! is_array( $checksums ) && 'en_US' !== $locale ) {
            $checksums = get_core_checksums( $version, 'en_US' );
            $locale = 'en_US';
        }
        if ( ! is_array( $checksums ) ) {
            throw new RuntimeException( 'Official WordPress core checksums are unavailable.' );
        }
        return array( 'version' => $version, 'locale' => $locale, 'checksums' => $checksums );
    }

    private function current_core_package_url( string $version, string $locale ): string {
        if ( ! function_exists( 'wp_version_check' ) ) {
            require_once ABSPATH . 'wp-includes/update.php';
        }
        wp_version_check( array(), false );
        $transient = get_site_transient( 'update_core' );
        if ( is_object( $transient ) && is_array( $transient->updates ?? null ) ) {
            foreach ( $transient->updates as $offer ) {
                if ( ! is_object( $offer ) || (string) ( $offer->current ?? '' ) !== $version ) {
                    continue;
                }
                $offer_locale = (string) ( $offer->locale ?? '' );
                if ( '' !== $offer_locale && $offer_locale !== $locale && !( 'en_US' === $locale && 'en_US' === $offer_locale ) ) {
                    continue;
                }
                $packages = $offer->packages ?? null;
                $full = is_object( $packages ) ? (string) ( $packages->full ?? '' ) : ( is_array( $packages ) ? (string) ( $packages['full'] ?? '' ) : '' );
                $url = '' !== $full ? $full : (string) ( $offer->package ?? '' );
                if ( '' !== $url && 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) && in_array( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ), array( 'downloads.wordpress.org', 'wordpress.org' ), true ) ) {
                    return $url;
                }
            }
        }
        $suffix = 'en_US' === $locale ? '' : '-' . rawurlencode( $locale );
        return 'https://downloads.wordpress.org/release/wordpress-' . rawurlencode( $version ) . $suffix . '.zip';
    }

    private function download_verified_package( string $url ): string {
        if ( ! function_exists( 'download_url' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) || ! in_array( $host, array( 'downloads.wordpress.org', 'wordpress.org' ), true ) ) {
            throw new RuntimeException( 'Repair package host was rejected.' );
        }
        $tmp = download_url( $url, 300, true );
        if ( is_wp_error( $tmp ) ) {
            throw new RuntimeException( 'Unable to download the official package: ' . $tmp->get_error_message() );
        }
        return wp_normalize_path( $tmp );
    }

    private function extract_zip_private( string $zip, string $dir ): void {
        $this->ensure_private_dir( $dir );
        if ( class_exists( 'ZipArchive' ) ) {
            $archive = new ZipArchive();
            if ( true !== $archive->open( $zip ) ) {
                throw new RuntimeException( 'Unable to open the downloaded package.' );
            }
            if ( $archive->numFiles > self::MAX_PACKAGE_FILES ) {
                $archive->close();
                throw new RuntimeException( 'Package contains too many files for the guarded verifier.' );
            }
            for ( $i = 0; $i < $archive->numFiles; $i++ ) {
                $name = wp_normalize_path( (string) $archive->getNameIndex( $i ) );
                if ( '' === $name || str_starts_with( $name, '/' ) || preg_match( '#(^|/)\.\.(/|$)#', $name ) || preg_match( '/^[A-Za-z]:\//', $name ) ) {
                    $archive->close();
                    throw new RuntimeException( 'Downloaded package contained an unsafe archive path.' );
                }
                $stat = $archive->statIndex( $i );
                if ( is_array( $stat ) && isset( $stat['external_attributes'] ) ) {
                    $mode = ( (int) $stat['external_attributes'] >> 16 ) & 0170000;
                    if ( 0120000 === $mode ) {
                        $archive->close();
                        throw new RuntimeException( 'Downloaded package contained a symbolic link.' );
                    }
                }
            }
            if ( ! $archive->extractTo( $dir ) ) {
                $archive->close();
                throw new RuntimeException( 'Unable to extract the official package into private storage.' );
            }
            $archive->close();
            return;
        }

        // Shared hosting often omits the PHP Zip extension. WordPress bundles PclZip,
        // which can safely provide a list before extraction so we can reject traversal.
        if ( ! class_exists( 'PclZip' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
        }
        if ( ! class_exists( 'PclZip' ) ) {
            throw new RuntimeException( 'No supported ZIP reader is available for guarded package verification.' );
        }
        $archive = new PclZip( $zip );
        $list = $archive->listContent();
        if ( 0 === $list || ! is_array( $list ) ) {
            throw new RuntimeException( 'Unable to inspect the downloaded package.' );
        }
        if ( count( $list ) > self::MAX_PACKAGE_FILES ) {
            throw new RuntimeException( 'Package contains too many files for the guarded verifier.' );
        }
        foreach ( $list as $entry ) {
            $name = wp_normalize_path( (string) ( $entry['filename'] ?? '' ) );
            if ( '' === $name || str_starts_with( $name, '/' ) || preg_match( '#(^|/)\.\.(/|$)#', $name ) || preg_match( '/^[A-Za-z]:\//', $name ) ) {
                throw new RuntimeException( 'Downloaded package contained an unsafe archive path.' );
            }
        }
        $result = $archive->extract( PCLZIP_OPT_PATH, $dir, PCLZIP_OPT_STOP_ON_ERROR );
        if ( 0 === $result ) {
            throw new RuntimeException( 'Unable to extract the official package into private storage.' );
        }
    }

    private function atomic_copy_verified( string $source, string $target, string $sha256 = '' ): string {
        $parent = dirname( $target );
        if ( ! is_dir( $parent ) || ! is_writable( $parent ) ) {
            throw new RuntimeException( 'Repair destination is not writable: ' . basename( $target ) );
        }
        $tmp = $parent . '/.nobash-files-repair-' . wp_generate_password( 12, false, false ) . '.tmp';
        if ( ! @copy( $source, $tmp ) ) {
            throw new RuntimeException( 'Unable to prepare repaired file.' );
        }
        if ( '' !== $sha256 ) {
            $actual = @hash_file( 'sha256', $tmp );
            if ( false === $actual || ! hash_equals( $sha256, $actual ) ) {
                @unlink( $tmp );
                throw new RuntimeException( 'Prepared repair file failed integrity verification.' );
            }
        }
        if ( ! @rename( $tmp, $target ) ) {
            @unlink( $tmp );
            throw new RuntimeException( 'Unable to atomically install repaired file.' );
        }
        return (string) @hash_file( 'sha256', $target );
    }

    public function repair_core( array $paths ): array {
        $paths = array_values( array_unique( array_filter( array_map( fn( $p ) => $this->fs->normalize_relative( (string) $p ), $paths ) ) ) );
        if ( empty( $paths ) || count( $paths ) > self::MAX_REPAIR_FILES ) {
            throw new InvalidArgumentException( 'Choose between 1 and ' . self::MAX_REPAIR_FILES . ' core files per repair.' );
        }
        $official = $this->core_checksums();
        foreach ( $paths as $path ) {
            if ( str_starts_with( $path, 'wp-content/' ) || ! isset( $official['checksums'][ $path ] ) ) {
                throw new RuntimeException( 'Repair refused a path that is not in the official core checksum manifest: ' . $path );
            }
        }
        $url = $this->current_core_package_url( $official['version'], $official['locale'] );
        $zip = $this->download_verified_package( $url );
        $id = $this->new_id( 'r' );
        $job = $this->private . '/' . self::REPAIR_DIR . '/' . $id;
        $extract = $job . '/package';
        $backup = $job . '/backup';
        $this->ensure_private_dir( $job );
        $this->ensure_private_dir( $backup );
        $installed = array();
        try {
            $this->extract_zip_private( $zip, $extract );
            foreach ( $paths as $path ) {
                $source = wp_normalize_path( $extract . '/wordpress/' . $path );
                if ( ! is_file( $source ) || is_link( $source ) ) {
                    throw new RuntimeException( 'Official package does not contain expected core file: ' . $path );
                }
                $md5 = @md5_file( $source );
                if ( false === $md5 || ! hash_equals( strtolower( (string) $official['checksums'][ $path ] ), strtolower( $md5 ) ) ) {
                    throw new RuntimeException( 'Official package file did not match WordPress checksum: ' . $path );
                }
                $target = wp_normalize_path( $this->root . '/' . $path );
                $target_parent = dirname( $target );
                if ( ! is_dir( $target_parent ) ) {
                    if ( ! wp_mkdir_p( $target_parent ) ) {
                        throw new RuntimeException( 'Unable to create missing core directory for ' . $path );
                    }
                }
                $existing = is_file( $target );
                $backup_name = hash( 'sha256', $path ) . '.blob';
                $before_hash = '';
                $before_mode = 0;
                if ( $existing ) {
                    if ( is_link( $target ) ) {
                        throw new RuntimeException( 'Core repair refuses symbolic links: ' . $path );
                    }
                    $before_hash = (string) @hash_file( 'sha256', $target );
                    $before_mode = (int) ( @fileperms( $target ) ?: 0 ) & 0777;
                    if ( ! @copy( $target, $backup . '/' . $backup_name ) ) {
                        throw new RuntimeException( 'Unable to create rollback copy for ' . $path );
                    }
                    @chmod( $backup . '/' . $backup_name, 0600 );
                }
                $after_hash = $this->atomic_copy_verified( $source, $target, (string) @hash_file( 'sha256', $source ) );
                if ( ! hash_equals( strtolower( (string) $official['checksums'][ $path ] ), strtolower( (string) @md5_file( $target ) ) ) ) {
                    throw new RuntimeException( 'Repaired core file failed final official checksum: ' . $path );
                }
                if ( $before_mode > 0 ) {
                    @chmod( $target, $before_mode );
                }
                $installed[] = array( 'path' => $path, 'existed' => $existing, 'backup' => $existing ? $backup_name : '', 'before_sha256' => $before_hash, 'after_sha256' => $after_hash, 'mode' => $before_mode );
            }
            $meta = array(
                'id' => $id,
                'type' => 'core_repair',
                'wordpress_version' => $official['version'],
                'locale' => $official['locale'],
                'package_url' => $url,
                'created_at' => current_time( 'mysql' ),
                'created_by' => get_current_user_id(),
                'items' => $installed,
            );
            $this->write_json_atomic( $job . '/meta.json', $meta );
            Nobash_Files_DB::log( 'security_core_repaired', '', array( 'repair_id' => $id, 'files' => array_column( $installed, 'path' ), 'version' => $official['version'] ) );
            return array( 'repair_id' => $id, 'files' => $installed, 'message' => count( $installed ) . ' WordPress core file(s) repaired and verified.' );
        } catch ( Throwable $e ) {
            // Best-effort rollback only for files already changed in this transaction.
            foreach ( array_reverse( $installed ) as $item ) {
                $target = $this->root . '/' . $item['path'];
                if ( ! empty( $item['existed'] ) && ! empty( $item['backup'] ) && is_file( $backup . '/' . $item['backup'] ) ) {
                    @copy( $backup . '/' . $item['backup'], $target );
                } elseif ( empty( $item['existed'] ) ) {
                    @unlink( $target );
                }
            }
            $this->remove_tree( $job );
            throw $e;
        } finally {
            @unlink( $zip );
            $this->remove_tree( $extract );
        }
    }

    public function repair_history(): array {
        $base = $this->private . '/' . self::REPAIR_DIR;
        $dirs = @scandir( $base );
        $out = array();
        if ( false === $dirs ) {
            return $out;
        }
        foreach ( $dirs as $id ) {
            if ( '.' === $id || '..' === $id || ! preg_match( '/^r-[a-z0-9-]+$/', $id ) ) {
                continue;
            }
            $meta = $this->read_json( $base . '/' . $id . '/meta.json' );
            if ( ! empty( $meta['id'] ) ) {
                unset( $meta['package_url'] );
                $out[] = $meta;
            }
        }
        usort( $out, static fn( array $a, array $b ): int => strcmp( (string) ( $b['created_at'] ?? '' ), (string) ( $a['created_at'] ?? '' ) ) );
        return array_slice( $out, 0, 20 );
    }

    public function rollback_core_repair( string $id ): array {
        if ( ! preg_match( '/^r-[a-z0-9-]+$/', $id ) ) {
            throw new InvalidArgumentException( 'Invalid repair snapshot.' );
        }
        $dir = $this->private . '/' . self::REPAIR_DIR . '/' . $id;
        $meta = $this->read_json( $dir . '/meta.json' );
        if ( empty( $meta['items'] ) || ! is_array( $meta['items'] ) ) {
            throw new RuntimeException( 'Repair snapshot is unavailable.' );
        }
        foreach ( $meta['items'] as $item ) {
            $path = $this->fs->normalize_relative( (string) ( $item['path'] ?? '' ) );
            $target = $this->root . '/' . $path;
            $expected_current = (string) ( $item['after_sha256'] ?? '' );
            if ( is_file( $target ) && '' !== $expected_current ) {
                $current = (string) @hash_file( 'sha256', $target );
                if ( ! hash_equals( $expected_current, $current ) ) {
                    throw new RuntimeException( 'Rollback stopped because a repaired file changed again after repair: ' . $path );
                }
            }
        }
        $restored = array();
        foreach ( array_reverse( $meta['items'] ) as $item ) {
            $path = $this->fs->normalize_relative( (string) $item['path'] );
            $target = $this->root . '/' . $path;
            if ( ! empty( $item['existed'] ) ) {
                $backup = $dir . '/backup/' . (string) $item['backup'];
                if ( ! is_file( $backup ) ) {
                    throw new RuntimeException( 'Rollback backup is missing for ' . $path );
                }
                $this->atomic_copy_verified( $backup, $target, (string) ( $item['before_sha256'] ?? '' ) );
                if ( ! empty( $item['mode'] ) ) {
                    @chmod( $target, (int) $item['mode'] );
                }
            } else {
                if ( is_file( $target ) && ! @unlink( $target ) ) {
                    throw new RuntimeException( 'Unable to remove core file that was created by repair: ' . $path );
                }
            }
            $restored[] = $path;
        }
        $this->remove_tree( $dir );
        Nobash_Files_DB::log( 'security_core_repair_rolled_back', '', array( 'repair_id' => $id, 'files' => $restored ) );
        return array( 'restored' => $restored );
    }

    public function verify_official_component( string $type, string $slug ): array {
        $type = sanitize_key( $type );
        $slug = sanitize_key( $slug );
        if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) || '' === $slug ) {
            throw new InvalidArgumentException( 'Choose a valid plugin or theme.' );
        }
        if ( 'plugin' === $type ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            $plugins = get_plugins();
            $plugin_file = '';
            $data = array();
            foreach ( $plugins as $file => $plugin_data ) {
                $candidate = str_contains( $file, '/' ) ? dirname( $file ) : pathinfo( $file, PATHINFO_FILENAME );
                if ( $candidate === $slug ) {
                    $plugin_file = $file;
                    $data = $plugin_data;
                    break;
                }
            }
            if ( '' === $plugin_file ) {
                throw new RuntimeException( 'Installed plugin was not found.' );
            }
            $version = sanitize_text_field( (string) ( $data['Version'] ?? '' ) );
            $installed_root = str_contains( $plugin_file, '/' ) ? wp_normalize_path( WP_PLUGIN_DIR . '/' . dirname( $plugin_file ) ) : wp_normalize_path( WP_PLUGIN_DIR );
            $single_file = ! str_contains( $plugin_file, '/' );
            $url = 'https://downloads.wordpress.org/plugin/' . rawurlencode( $slug ) . '.' . rawurlencode( $version ) . '.zip';
        } else {
            $theme = wp_get_theme( $slug );
            if ( ! $theme->exists() ) {
                throw new RuntimeException( 'Installed theme was not found.' );
            }
            $version = sanitize_text_field( (string) $theme->get( 'Version' ) );
            $installed_root = wp_normalize_path( $theme->get_stylesheet_directory() );
            $single_file = false;
            $url = 'https://downloads.wordpress.org/theme/' . rawurlencode( $slug ) . '.' . rawurlencode( $version ) . '.zip';
        }
        if ( '' === $version ) {
            throw new RuntimeException( 'Installed component version is unavailable.' );
        }
        $zip = $this->download_verified_package( $url );
        $tmp_id = $this->new_id( 'verify' );
        $extract = $this->private . '/' . self::REPAIR_DIR . '/' . $tmp_id;
        try {
            $this->extract_zip_private( $zip, $extract );
            $package_root = $extract . '/' . $slug;
            if ( ! is_dir( $package_root ) ) {
                throw new RuntimeException( 'Official package root did not match the component slug.' );
            }
            $package = $this->manifest_tree( $package_root );
            if ( $single_file ) {
                $installed = array();
                $main = basename( $plugin_file );
                $path = wp_normalize_path( WP_PLUGIN_DIR . '/' . $main );
                if ( is_file( $path ) && isset( $package[ $main ] ) ) {
                    $installed[ $main ] = (string) @hash_file( 'sha256', $path );
                }
            } else {
                $installed = $this->manifest_tree( $installed_root );
            }
            $modified = array();
            $missing = array();
            $unexpected = array();
            $verified = 0;
            foreach ( $package as $path => $hash ) {
                if ( ! isset( $installed[ $path ] ) ) {
                    $missing[] = $path;
                } elseif ( ! hash_equals( $hash, (string) $installed[ $path ] ) ) {
                    $modified[] = $path;
                } else {
                    $verified++;
                }
            }
            foreach ( $installed as $path => $hash ) {
                if ( ! isset( $package[ $path ] ) ) {
                    $unexpected[] = $path;
                }
            }
            $result = array(
                'type' => $type,
                'slug' => $slug,
                'version' => $version,
                'official_package' => true,
                'verified' => $verified,
                'modified' => array_slice( $modified, 0, 250 ),
                'missing' => array_slice( $missing, 0, 250 ),
                'unexpected' => array_slice( $unexpected, 0, 250 ),
                'clean' => empty( $modified ) && empty( $missing ) && empty( $unexpected ),
            );
            Nobash_Files_DB::log( 'security_component_verified', '', array( 'type' => $type, 'slug' => $slug, 'version' => $version, 'clean' => $result['clean'], 'modified' => count( $modified ), 'missing' => count( $missing ), 'unexpected' => count( $unexpected ) ) );
            return $result;
        } finally {
            @unlink( $zip );
            $this->remove_tree( $extract );
        }
    }

    private function manifest_tree( string $root ): array {
        $root = rtrim( wp_normalize_path( $root ), '/' );
        if ( ! is_dir( $root ) ) {
            return array();
        }
        $manifest = array();
        $stack = array( $root );
        $count = 0;
        while ( $stack ) {
            $dir = array_pop( $stack );
            $entries = @scandir( $dir );
            if ( false === $entries ) {
                throw new RuntimeException( 'Unable to read component files.' );
            }
            foreach ( $entries as $name ) {
                if ( '.' === $name || '..' === $name ) {
                    continue;
                }
                $path = wp_normalize_path( $dir . '/' . $name );
                if ( is_link( $path ) ) {
                    throw new RuntimeException( 'Component contains a symbolic link; verification stopped.' );
                }
                if ( is_dir( $path ) ) {
                    $stack[] = $path;
                    continue;
                }
                if ( ! is_file( $path ) ) {
                    continue;
                }
                $count++;
                if ( $count > self::MAX_PACKAGE_FILES ) {
                    throw new RuntimeException( 'Component contains too many files for a single verification request.' );
                }
                $rel = ltrim( substr( $path, strlen( $root ) ), '/' );
                $hash = @hash_file( 'sha256', $path );
                if ( false === $hash ) {
                    throw new RuntimeException( 'Unable to hash component file: ' . $rel );
                }
                $manifest[ $rel ] = $hash;
            }
        }
        ksort( $manifest, SORT_STRING );
        return $manifest;
    }
}
