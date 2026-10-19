<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Nobash_Files_Filesystem {
    private string $root;
    private string $private_dir;
    private ?string $private_relative = null;

    public function __construct() {
        $root = realpath( ABSPATH );
        if ( false === $root ) {
            throw new RuntimeException( 'Unable to resolve WordPress root.' );
        }
        $this->root = rtrim( wp_normalize_path( $root ), '/' );
        $this->private_dir = $this->bootstrap_private_storage();
        if ( str_starts_with( $this->private_dir . '/', $this->root . '/' ) ) {
            $this->private_relative = ltrim( substr( $this->private_dir, strlen( $this->root ) ), '/' );
        }
    }

    public function root(): string {
        return $this->root;
    }

    public function private_dir(): string {
        return $this->private_dir;
    }

    private function bootstrap_private_storage(): string {
        // NEVER store historical PHP, config, and database-adjacent recovery content
        // below a publicly served directory. Apache/IIS deny files do not protect
        // an Nginx installation. Fail closed rather than silently falling back
        // to a web-accessible wp-content directory.
        $candidates = array(
            wp_normalize_path( dirname( $this->root ) . '/.nobash-files' ),
        );
        $document_root = isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] )
            ? realpath( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) : false;
        $document_root = $document_root ? rtrim( wp_normalize_path( $document_root ), '/' ) : '';

        foreach ( array_unique( $candidates ) as $candidate ) {
            $candidate = rtrim( $candidate, '/' );
            // The candidate must not be inside WordPress or the detected web root.
            if ( '' === $candidate || $candidate === $this->root || str_starts_with( $candidate . '/', $this->root . '/' ) ) {
                continue;
            }
            if ( '' !== $document_root && ( $candidate === $document_root || str_starts_with( $candidate . '/', $document_root . '/' ) ) ) {
                continue;
            }
            // Never follow a planted storage symlink, including a symlinked parent.
            if ( is_link( $candidate ) || ( file_exists( $candidate ) && ! is_dir( $candidate ) ) ) {
                continue;
            }
            if ( is_dir( $candidate ) || wp_mkdir_p( $candidate ) ) {
                $real = realpath( $candidate );
                if ( false === $real || rtrim( wp_normalize_path( $real ), '/' ) !== rtrim( $candidate, '/' ) ) {
                    continue;
                }
                if ( is_writable( $candidate ) ) {
                    foreach ( array( 'chunks', 'snapshots', 'downloads', 'archive-jobs', 'locks', 'quarantine', 'security-repairs' ) as $folder ) {
                        $child = $candidate . '/' . $folder;
                        if ( is_link( $child ) || ( file_exists( $child ) && ! is_dir( $child ) ) ) {
                            throw new RuntimeException( 'Private storage contains an unsafe directory.' );
                        }
                        if ( ! is_dir( $child ) && ! wp_mkdir_p( $child ) ) {
                            throw new RuntimeException( 'Unable to prepare private recovery storage.' );
                        }
                        if ( ( fileperms( $child ) & 0777 ) !== 0700 ) { @chmod( $child, 0700 ); }
                    }
                    if ( ( fileperms( $candidate ) & 0777 ) !== 0700 ) { @chmod( $candidate, 0700 ); }
                    $this->harden_private_storage( $candidate );
                    update_option( 'nobash_files_private_storage_path', rtrim( wp_normalize_path( $candidate ), '/' ), false );
                    return rtrim( $candidate, '/' );
                }
            }
        }
        throw new RuntimeException( 'Nobash Files requires a writable private storage directory outside the public web root. Ask your host to grant access to the WordPress parent directory.' );
    }

    private function harden_private_storage( string $dir ): void {
        $files = array(
            'index.php'   => "<?php\nhttp_response_code(404);\nexit;\n",
            '.htaccess'   => "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
            'web.config'  => "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></security></system.webServer></configuration>",
        );
        foreach ( $files as $name => $content ) {
            $path = $dir . '/' . $name;
            if ( is_link( $path ) ) {
                throw new RuntimeException( 'Private storage contains an unsafe protection file.' );
            }
            if ( ! file_exists( $path ) ) {
                if ( strlen( $content ) !== @file_put_contents( $path, $content, LOCK_EX ) ) {
                    throw new RuntimeException( 'Unable to protect private recovery storage.' );
                }
            }
        }
    }

    /**
     * Acquire a local filesystem lock in Nobash private storage.
     *
     * flock() gives us an atomic per-installation mutex without relying on
     * non-atomic get/set transient sequences. The lock file never contains
     * user data and is kept outside the browsable WordPress tree whenever
     * the host permits that layout.
     *
     * @return resource
     */
    private function acquire_private_lock( string $name, bool $blocking = false ) {
        $name = preg_replace( '/[^A-Za-z0-9_.-]/', '-', $name );
        $dir  = $this->private_dir . '/locks';
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            throw new RuntimeException( 'Unable to prepare Nobash operation locks.' );
        }
        $handle = @fopen( $dir . '/' . $name . '.lock', 'c+' );
        if ( false === $handle ) {
            throw new RuntimeException( 'Unable to open the Nobash operation lock.' );
        }
        $mode = LOCK_EX | ( $blocking ? 0 : LOCK_NB );
        if ( ! @flock( $handle, $mode ) ) {
            fclose( $handle );
            throw new DomainException( 'This operation is already running in another request. Please retry.' );
        }
        @ftruncate( $handle, 0 );
        @fwrite( $handle, (string) getmypid() . ':' . time() );
        @fflush( $handle );
        return $handle;
    }

    /** @param resource|null $handle */
    private function release_private_lock( $handle ): void {
        if ( is_resource( $handle ) ) {
            @flock( $handle, LOCK_UN );
            @fclose( $handle );
        }
    }

    private function write_json_atomic( string $path, array $value ): void {
        $dir = dirname( $path );
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            throw new RuntimeException( 'Unable to prepare private job storage.' );
        }
        $tmp = $path . '.tmp-' . wp_generate_password( 10, false, false );
        $json = wp_json_encode( $value );
        if ( false === $json || strlen( $json ) !== @file_put_contents( $tmp, $json, LOCK_EX ) ) {
            @unlink( $tmp );
            throw new RuntimeException( 'Unable to write private job state.' );
        }
        @chmod( $tmp, 0600 );
        if ( ! @rename( $tmp, $path ) ) {
            @unlink( $tmp );
            throw new RuntimeException( 'Unable to commit private job state.' );
        }
    }

    /**
     * Remove abandoned temporary work without touching version/trash recovery.
     * Only known transient directories are considered and every deletion stays
     * inside Nobash private storage.
     */
    public function cleanup_stale_work( int $max_age = 86400 ): array {
        $max_age = max( HOUR_IN_SECONDS, min( WEEK_IN_SECONDS, $max_age ) );
        $cutoff  = time() - $max_age;
        $removed = array( 'chunk_sessions' => 0, 'archive_jobs' => 0, 'downloads' => 0 );
        foreach ( array( 'chunks' => 'chunk_sessions', 'archive-jobs' => 'archive_jobs' ) as $folder => $counter ) {
            $root = $this->private_dir . '/' . $folder;
            if ( ! is_dir( $root ) ) { continue; }
            foreach ( scandir( $root ) ?: array() as $name ) {
                if ( '.' === $name || '..' === $name ) { continue; }
                $path = $root . '/' . $name;
                if ( is_link( $path ) ) { continue; }
                $newest = (int) @filemtime( $path );
                if ( is_dir( $path ) ) {
                    foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) ) as $entry ) {
                        $newest = max( $newest, (int) $entry->getMTime() );
                    }
                }
                if ( $newest > 0 && $newest < $cutoff ) {
                    $this->remove_private_directory( $path );
                    $removed[ $counter ]++;
                }
            }
        }
        $downloads = $this->private_dir . '/downloads';
        if ( is_dir( $downloads ) ) {
            foreach ( scandir( $downloads ) ?: array() as $name ) {
                if ( '.' === $name || '..' === $name ) { continue; }
                $path = $downloads . '/' . $name;
                if ( is_file( $path ) && ! is_link( $path ) && (int) @filemtime( $path ) < $cutoff ) {
                    if ( @unlink( $path ) ) { $removed['downloads']++; }
                }
            }
        }
        return $removed;
    }

    public function normalize_relative( string $path ): string {
        // HTTP parameters are already decoded by the transport. Decoding again
        // changes the path after authorization and breaks literal percent names.
        return Nobash_Files_Access::normalize_path( $path );
    }

    private function validate_name( string $name ): string {
        $name = trim( str_replace( "\0", '', $name ) );
        if ( '' === $name || '.' === $name || '..' === $name || str_contains( $name, '/' ) || str_contains( $name, '\\' ) ) {
            throw new InvalidArgumentException( 'Invalid file or folder name.' );
        }
        if ( preg_match( '/[\x00-\x1F\x7F]/u', $name ) ) {
            throw new InvalidArgumentException( 'The name contains unsupported control characters.' );
        }
        if ( strlen( $name ) > 240 ) {
            throw new InvalidArgumentException( 'The name is too long.' );
        }
        return $name;
    }


    /**
     * Normalize a browser-provided relative folder-upload path without allowing
     * traversal, separators inside names, control characters or oversized names.
     */
    public function normalize_upload_relative_path( string $relative_path ): string {
        $relative_path = str_replace( '\\', '/', $relative_path );
        $relative_path = $this->normalize_relative( $relative_path );
        if ( '' === $relative_path ) {
            throw new InvalidArgumentException( 'The upload path is empty.' );
        }
        $parts = array();
        foreach ( explode( '/', $relative_path ) as $part ) {
            $parts[] = $this->validate_name( $part );
        }
        return implode( '/', $parts );
    }

    /** Return the final WordPress-relative target path for a folder upload. */
    public function upload_target_path( string $base_relative, string $relative_path ): string {
        $base_relative = $this->normalize_relative( $base_relative );
        $relative_path = $this->normalize_upload_relative_path( $relative_path );
        $target = trim( $base_relative . '/' . $relative_path, '/' );
        $absolute = wp_normalize_path( $this->root . '/' . $target );
        $this->assert_inside_root( $absolute );
        $this->assert_not_private_storage( $absolute );
        return $target;
    }

    /**
     * Create only the missing directory chain required by a folder upload.
     * Existing folders are reused; symlinks and non-directory collisions are rejected.
     * Returns [destination_directory_relative, filename].
     */
    private function ensure_upload_parent_unlocked( string $base_relative, string $relative_path, bool $confirm_protected ): array {
        $relative_path = $this->normalize_upload_relative_path( $relative_path );
        $parts = explode( '/', $relative_path );
        $filename = array_pop( $parts );
        $current = $this->resolve_existing( $base_relative );
        if ( ! is_dir( $current ) || ! is_writable( $current ) ) {
            throw new RuntimeException( 'Upload destination is not a writable directory.' );
        }

        foreach ( $parts as $segment ) {
            $segment = $this->validate_name( $segment );
            $candidate = wp_normalize_path( $current . '/' . $segment );
            $this->assert_inside_root( $candidate );
            $this->assert_not_private_storage( $candidate );
            if ( is_link( $candidate ) ) {
                throw new RuntimeException( 'Folder uploads cannot pass through symbolic links.' );
            }
            if ( file_exists( $candidate ) ) {
                if ( ! is_dir( $candidate ) ) {
                    throw new RuntimeException( 'A file blocks the folder upload path: ' . $segment );
                }
                $this->assert_no_symlink_components( $candidate );
                $current = $candidate;
                continue;
            }

            $parent_rel = $this->relative_from_absolute( $current );
            if ( $this->is_protected_path( $parent_rel ) && ! $confirm_protected ) {
                throw new DomainException( 'PROTECTED_CONFIRM_REQUIRED' );
            }
            if ( ! is_writable( $current ) || ! @mkdir( $candidate, 0755 ) ) {
                throw new RuntimeException( 'Unable to create upload folder: ' . $segment );
            }
            Nobash_Files_DB::log( 'created_upload_folder', $this->relative_from_absolute( $candidate ) );
            $current = $candidate;
        }

        return array( $this->relative_from_absolute( $current ), $filename );
    }

    public function resolve_existing( string $relative ): string {
        $relative = $this->normalize_relative( $relative );
        $candidate = $relative ? $this->root . '/' . $relative : $this->root;
        $this->assert_no_symlink_components( $candidate );
        $real = realpath( $candidate );
        if ( false === $real ) {
            throw new RuntimeException( 'File or directory not found.' );
        }
        $real = wp_normalize_path( $real );
        $this->assert_inside_root( $real );
        $this->assert_not_private_storage( $real );
        return $real;
    }

    private function resolve_parent_for_new( string $parent_relative, string $name ): array {
        $parent = $this->resolve_existing( $parent_relative );
        if ( ! is_dir( $parent ) ) {
            throw new RuntimeException( 'Destination is not a directory.' );
        }
        if ( ! is_writable( $parent ) ) {
            throw new RuntimeException( 'Destination directory is not writable.' );
        }
        $name = $this->validate_name( $name );
        $candidate = wp_normalize_path( $parent . '/' . $name );
        $this->assert_inside_root( $candidate );
        $this->assert_not_private_storage( $candidate );
        return array( $parent, $candidate, $name );
    }

    private function assert_no_symlink_components( string $candidate ): void {
        $candidate = wp_normalize_path( $candidate );
        $this->assert_inside_root( $candidate );
        if ( $candidate === $this->root ) {
            return;
        }
        $relative = ltrim( substr( $candidate, strlen( $this->root ) ), '/' );
        $cursor = $this->root;
        foreach ( explode( '/', $relative ) as $part ) {
            if ( '' === $part ) {
                continue;
            }
            $cursor .= '/' . $part;
            if ( is_link( $cursor ) ) {
                throw new RuntimeException( 'Symbolic-link paths are blocked for safety.' );
            }
        }
    }

    private function assert_inside_root( string $absolute ): void {
        $absolute = rtrim( wp_normalize_path( $absolute ), '/' );
        if ( $absolute !== $this->root && ! str_starts_with( $absolute . '/', $this->root . '/' ) ) {
            throw new RuntimeException( 'Access outside the WordPress root is blocked.' );
        }
    }

    private function assert_not_private_storage( string $absolute ): void {
        $absolute = rtrim( wp_normalize_path( $absolute ), '/' );
        $private  = rtrim( $this->private_dir, '/' );
        if ( $absolute === $private || str_starts_with( $absolute . '/', $private . '/' ) ) {
            throw new RuntimeException( 'Nobash recovery storage is private and cannot be browsed.' );
        }
    }

    public function relative_from_absolute( string $absolute ): string {
        $absolute = wp_normalize_path( $absolute );
        $this->assert_inside_root( $absolute );
        if ( $absolute === $this->root ) {
            return '';
        }
        return ltrim( substr( $absolute, strlen( $this->root ) ), '/' );
    }

    private function parent_path( string $relative ): ?string {
        if ( '' === $relative ) {
            return null;
        }
        $parent = dirname( $relative );
        return '.' === $parent ? '' : wp_normalize_path( $parent );
    }

    private function should_hide( string $absolute ): bool {
        if ( null !== $this->private_relative ) {
            $rel = $this->relative_from_absolute( $absolute );
            return $rel === $this->private_relative || str_starts_with( $rel . '/', $this->private_relative . '/' );
        }
        return false;
    }

    public function list_directory( string $relative, $cursor = 0, int $limit = 0 ): array {
        $absolute = $this->resolve_existing( $relative );
        if ( ! is_dir( $absolute ) ) {
            throw new RuntimeException( 'Path is not a directory.' );
        }
        if ( ! is_readable( $absolute ) ) {
            throw new RuntimeException( 'Directory is not readable.' );
        }

        $configured = (int) get_option( 'nobash_files_directory_page_size', 250 );
        $limit = $limit > 0 ? $limit : $configured;
        $limit = max( 50, min( 500, $limit ) );
        $items = array();
        $compare = static function ( array $a, array $b ): int {
            if ( $a['type'] !== $b['type'] ) {
                return 'directory' === $a['type'] ? -1 : 1;
            }
            $natural = strnatcasecmp( (string) $a['name'], (string) $b['name'] );
            return 0 !== $natural ? $natural : strcmp( (string) $a['name'], (string) $b['name'] );
        };
        $start = 0;
        $after = null;
        $cursor_value = is_scalar( $cursor ) ? trim( (string) $cursor ) : '';
        if ( '' !== $cursor_value && '0' !== $cursor_value ) {
            if ( ctype_digit( $cursor_value ) ) {
                // Backward compatibility for clients using the former offset cursor.
                $start = max( 0, (int) $cursor_value );
                if ( $start > 100000 ) {
                    throw new InvalidArgumentException( 'Legacy directory cursor is too large. Refresh this folder to continue.' );
                }
            } else {
                $padding = strlen( $cursor_value ) % 4;
                $encoded = strtr( $cursor_value, '-_', '+/' ) . ( $padding ? str_repeat( '=', 4 - $padding ) : '' );
                $decoded = base64_decode( $encoded, true );
                $after = false === $decoded ? null : json_decode( $decoded, true );
                if ( ! is_array( $after ) || ! isset( $after['type'], $after['name'] ) || ! in_array( $after['type'], array( 'directory', 'file' ), true ) ) {
                    throw new InvalidArgumentException( 'Invalid directory cursor.' );
                }
            }
        }
        $capacity = $start + $limit + 1;
        $discarded = false;

        try {
            $iterator = new DirectoryIterator( $absolute );
            foreach ( $iterator as $entry ) {
                if ( $entry->isDot() ) {
                    continue;
                }
                $full = wp_normalize_path( $entry->getPathname() );
                if ( $this->should_hide( $full ) || $entry->isLink() ) {
                    continue;
                }
                $real = $entry->getRealPath();
                if ( false === $real ) {
                    continue;
                }
                $real = wp_normalize_path( $real );
                try {
                    $this->assert_inside_root( $real );
                    $this->assert_not_private_storage( $real );
                } catch ( Throwable $e ) {
                    continue;
                }

                $name    = $entry->getFilename();
                $is_dir  = is_dir( $real );
                $rel     = $this->relative_from_absolute( $real );
                $size    = $is_dir ? null : @filesize( $real );
                $mtime   = @filemtime( $real );
                $item = array(
                    'name'       => $name,
                    'path'       => $rel,
                    'type'       => $is_dir ? 'directory' : 'file',
                    'size'       => $is_dir || false === $size ? null : (int) $size,
                    'modified'   => false === $mtime ? 0 : (int) $mtime,
                    'extension'  => $is_dir ? '' : strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ),
                    'readable'   => is_readable( $real ),
                    'writable'   => is_writable( $real ),
                    'protected'  => $this->is_protected_path( $rel ),
                    'editable'   => ! $is_dir && $this->is_editable_file( $rel ),
                );
                if ( $after && $compare( $item, $after ) <= 0 ) {
                    continue;
                }

                $low = 0;
                $high = count( $items );
                while ( $low < $high ) {
                    $middle = intdiv( $low + $high, 2 );
                    if ( $compare( $items[ $middle ], $item ) <= 0 ) {
                        $low = $middle + 1;
                    } else {
                        $high = $middle;
                    }
                }
                if ( $low < $capacity ) {
                    array_splice( $items, $low, 0, array( $item ) );
                    if ( count( $items ) > $capacity ) {
                        array_pop( $items );
                        $discarded = true;
                    }
                } else {
                    $discarded = true;
                }
            }
        } catch ( UnexpectedValueException $e ) {
            throw new RuntimeException( 'Unable to read directory.' );
        }

        $page_items = array_slice( $items, $start, $limit );
        $has_more = $discarded || count( $items ) > $start + count( $page_items );
        $next_cursor = null;
        if ( $has_more && ! empty( $page_items ) ) {
            $last = $page_items[ count( $page_items ) - 1 ];
            $token = wp_json_encode( array( 'type' => $last['type'], 'name' => $last['name'] ) );
            $next_cursor = rtrim( strtr( base64_encode( (string) $token ), '+/', '-_' ), '=' );
        }

        $rel = $this->relative_from_absolute( $absolute );
        return array(
            'path'        => $rel,
            'parent'      => $this->parent_path( $rel ),
            'items'       => $page_items,
            'truncated'   => $has_more,
            'has_more'    => $has_more,
            'next_cursor' => $next_cursor,
            'page_size'   => $limit,
            'cursor'      => '' === $cursor_value ? '0' : $cursor_value,
            'writable'    => is_writable( $absolute ),
        );
    }

    public function read_file( string $relative ): array {
        $absolute = $this->resolve_existing( $relative );
        if ( ! is_file( $absolute ) ) {
            throw new RuntimeException( 'Path is not a file.' );
        }
        if ( ! is_readable( $absolute ) ) {
            throw new RuntimeException( 'File is not readable.' );
        }
        $size = (int) filesize( $absolute );
        $max  = max( 65536, (int) get_option( 'nobash_files_max_edit_bytes', 1048576 ) );
        if ( $size > $max ) {
            throw new RuntimeException( 'This file is larger than the current safe editor limit.' );
        }
        if ( ! $this->is_editable_file( $relative ) ) {
            throw new RuntimeException( 'This file type is not editable in the code editor.' );
        }
        $content = file_get_contents( $absolute );
        if ( false === $content ) {
            throw new RuntimeException( 'Unable to read file.' );
        }
        if ( str_contains( $content, "\0" ) ) {
            throw new RuntimeException( 'Binary files cannot be opened in the code editor.' );
        }
        $owner_id = @fileowner( $absolute );
        $group_id = @filegroup( $absolute );
        $owner_name = '';
        $group_name = '';
        if ( false !== $owner_id && function_exists( 'posix_getpwuid' ) ) {
            $owner_row = @posix_getpwuid( (int) $owner_id );
            $owner_name = is_array( $owner_row ) ? (string) ( $owner_row['name'] ?? '' ) : '';
        }
        if ( false !== $group_id && function_exists( 'posix_getgrgid' ) ) {
            $group_row = @posix_getgrgid( (int) $group_id );
            $group_name = is_array( $group_row ) ? (string) ( $group_row['name'] ?? '' ) : '';
        }
        return array(
            'path'        => $this->relative_from_absolute( $absolute ),
            'name'        => basename( $absolute ),
            'content'     => $content,
            'size'        => $size,
            'modified'    => (int) filemtime( $absolute ),
            'permissions' => substr( sprintf( '%o', fileperms( $absolute ) ), -4 ),
            'writable'    => is_writable( $absolute ),
            'protected'   => $this->is_protected_path( $relative ),
            'language'    => $this->language_for( $relative ),
            'extension'   => strtolower( pathinfo( $absolute, PATHINFO_EXTENSION ) ),
            'checksum'    => hash( 'sha256', $content ),
            'owner_id'    => false === $owner_id ? null : (int) $owner_id,
            'owner_name'  => $owner_name,
            'group_id'    => false === $group_id ? null : (int) $group_id,
            'group_name'  => $group_name,
        );
    }

    public function save_file( string $relative, string $content, bool $confirm_protected = false, string $expected_checksum = '', bool $force = false ): array {
        $lock = $this->acquire_private_lock( 'filesystem-mutation' );
        try { return $this->save_file_unlocked( $relative, $content, $confirm_protected, $expected_checksum, $force ); }
        finally { $this->release_private_lock( $lock ); }
    }

    private function save_file_unlocked( string $relative, string $content, bool $confirm_protected = false, string $expected_checksum = '', bool $force = false ): array {
        $normalized = $this->normalize_relative( $relative );
        if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT
            && ( str_starts_with( $normalized, 'wp-content/plugins/' ) || str_starts_with( $normalized, 'wp-content/themes/' ) ) ) {
            throw new RuntimeException( 'WordPress has disabled plugin and theme file editing (DISALLOW_FILE_EDIT).' );
        }
        if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS
            && ( str_starts_with( $normalized, 'wp-content/plugins/' ) || str_starts_with( $normalized, 'wp-content/themes/' ) || str_starts_with( $normalized, 'wp-admin/' ) || str_starts_with( $normalized, 'wp-includes/' ) ) ) {
            throw new RuntimeException( 'WordPress has disabled plugin, theme, and core modifications (DISALLOW_FILE_MODS).' );
        }
        $absolute = $this->resolve_existing( $relative );
        if ( ! is_file( $absolute ) || ! is_writable( $absolute ) ) {
            throw new RuntimeException( 'File is not writable.' );
        }
        if ( ! $this->is_editable_file( $relative ) ) {
            throw new RuntimeException( 'This file type is not editable.' );
        }
        $max = max( 65536, (int) get_option( 'nobash_files_max_edit_bytes', 1048576 ) );
        if ( strlen( $content ) > $max ) {
            throw new RuntimeException( 'The new content exceeds the safe editor limit.' );
        }
        if ( $this->is_protected_path( $relative ) && ! $confirm_protected ) {
            throw new DomainException( 'PROTECTED_CONFIRM_REQUIRED' );
        }
        $old = file_get_contents( $absolute );
        if ( false === $old ) {
            throw new RuntimeException( 'Unable to create a safety version.' );
        }
        $disk_checksum = hash( 'sha256', $old );
        $expected_checksum = strtolower( trim( $expected_checksum ) );
        if ( ! $force && '' !== $expected_checksum && ! hash_equals( $disk_checksum, $expected_checksum ) ) {
            throw new DomainException( 'FILE_CHANGED_ON_DISK' );
        }
        $relative   = $this->relative_from_absolute( $absolute );
        $safe_mode  = '1' === get_option( 'nobash_files_safe_mode', '1' );
        $version_id = $safe_mode ? Nobash_Files_DB::add_version( $relative, $old, 'save' ) : 0;

        $validation = $this->validate_content( $relative, $content );
        if ( ! $validation['ok'] ) {
            throw new RuntimeException( $validation['message'] );
        }

        $this->atomic_write( $absolute, $content, fileperms( $absolute ) & 0777 );
        clearstatcache( true, $absolute );
        Nobash_Files_DB::log( 'edited_file', $relative, array( 'version_id' => $version_id, 'bytes' => strlen( $content ), 'safe_mode' => $safe_mode, 'forced_conflict_overwrite' => $force, 'previous_checksum' => $disk_checksum ) );

        return array(
            'path'       => $relative,
            'size'       => strlen( $content ),
            'modified'   => (int) filemtime( $absolute ),
            'checksum'   => hash( 'sha256', $content ),
            'version_id' => $version_id,
            'validation' => $validation,
            'previous_checksum' => $disk_checksum,
            'forced_conflict_overwrite' => $force,
        );
    }

    private function atomic_write( string $absolute, string $content, int $mode = 0644 ): void {
        $tmp = dirname( $absolute ) . '/.' . basename( $absolute ) . '.nobash-files-' . wp_generate_password( 12, false, false ) . '.tmp';
        $written = file_put_contents( $tmp, $content, LOCK_EX );
        if ( false === $written || $written !== strlen( $content ) ) {
            @unlink( $tmp );
            throw new RuntimeException( 'Unable to write the temporary file.' );
        }
        @chmod( $tmp, $mode );
        if ( ! @rename( $tmp, $absolute ) ) {
            @unlink( $tmp );
            throw new RuntimeException( 'Atomic save failed; the live file was not changed.' );
        }
    }

    public function restore_version( int $version_id ): array {
        $lock = $this->acquire_private_lock( 'filesystem-mutation' );
        try {
            return $this->restore_version_unlocked( $version_id );
        } finally {
            $this->release_private_lock( $lock );
        }
    }

    private function restore_version_unlocked( int $version_id ): array {
        $version = Nobash_Files_DB::get_version( $version_id );
        if ( ! $version ) {
            throw new RuntimeException( 'Version not found.' );
        }
        $relative = (string) $version['relative_path'];
        $absolute = $this->resolve_existing( $relative );
        if ( ! is_file( $absolute ) || ! is_writable( $absolute ) ) {
            throw new RuntimeException( 'Live file is not writable.' );
        }
        $current = file_get_contents( $absolute );
        if ( false === $current ) {
            throw new RuntimeException( 'Unable to snapshot the current file.' );
        }
        Nobash_Files_DB::add_version( $relative, $current, 'restore' );
        $content = (string) $version['content'];
        $this->atomic_write( $absolute, $content, fileperms( $absolute ) & 0777 );
        Nobash_Files_DB::log( 'restored_version', $relative, array( 'version_id' => $version_id ) );
        return $this->read_file( $relative );
    }

    public function validate_content( string $relative, string $content ): array {
        if ( str_contains( $content, "\0" ) ) {
            return array( 'ok' => false, 'message' => 'Binary content is not allowed.' );
        }
        $ext = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );
        if ( 'json' === $ext ) {
            json_decode( $content, true );
            if ( JSON_ERROR_NONE !== json_last_error() ) {
                return array( 'ok' => false, 'message' => 'JSON validation failed: ' . json_last_error_msg() );
            }
        }
        if ( 'php' === $ext ) {
            try {
                token_get_all( $content, TOKEN_PARSE );
            } catch ( ParseError $e ) {
                return array( 'ok' => false, 'message' => 'PHP syntax error: ' . $e->getMessage() );
            }
        }
        return array( 'ok' => true, 'message' => 'Validation passed.' );
    }

    public function snapshot_file( string $relative ): array {
        $lock = $this->acquire_private_lock( 'filesystem-mutation' );
        try {
            $absolute = $this->resolve_existing( $relative );
            if ( ! is_file( $absolute ) || ! is_readable( $absolute ) ) {
                throw new RuntimeException( 'File is not readable.' );
            }
            if ( ! $this->is_editable_file( $relative ) ) {
                throw new RuntimeException( 'This file type cannot be backed up from the code editor.' );
            }
            $size = (int) filesize( $absolute );
            $max  = max( 65536, (int) get_option( 'nobash_files_max_edit_bytes', 1048576 ) );
            if ( $size > $max ) {
                throw new RuntimeException( 'This file is larger than the current safe editor limit.' );
            }
            $content = file_get_contents( $absolute );
            if ( false === $content || str_contains( $content, "\0" ) ) {
                throw new RuntimeException( 'Unable to create a safety backup for this file.' );
            }
            $relative = $this->relative_from_absolute( $absolute );
            $version_id = Nobash_Files_DB::add_version( $relative, $content, 'manual' );
            Nobash_Files_DB::log( 'created_manual_version', $relative, array( 'version_id' => $version_id, 'bytes' => strlen( $content ) ) );
            return array(
                'id'       => $version_id,
                'path'     => $relative,
                'checksum' => hash( 'sha256', $content ),
                'size'     => strlen( $content ),
            );
        } finally {
            $this->release_private_lock( $lock );
        }
    }

    public function versions( string $relative ): array {
        $absolute = $this->resolve_existing( $relative );
        return Nobash_Files_DB::get_versions( $this->relative_from_absolute( $absolute ) );
    }

    public function version( int $version_id ): array {
        $version = Nobash_Files_DB::get_version( $version_id );
        if ( ! $version ) {
            throw new RuntimeException( 'Version not found.' );
        }
        // Re-resolve the path so an old database row cannot expose content for a path
        // that is no longer inside the active WordPress filesystem sandbox.
        $absolute = $this->resolve_existing( (string) $version['relative_path'] );
        $relative = $this->relative_from_absolute( $absolute );
        if ( $relative !== (string) $version['relative_path'] ) {
            throw new RuntimeException( 'Version path is no longer valid.' );
        }
        return array(
            'id'            => (int) $version['id'],
            'relative_path' => $relative,
            'checksum'      => (string) $version['checksum'],
            'file_size'     => (int) $version['file_size'],
            'content'       => (string) $version['content'],
            'created_at'    => (string) $version['created_at'],
            'action'        => (string) $version['action'],
        );
    }

    public function file_info( string $relative ): array {
        $absolute = $this->resolve_existing( $relative );
        $is_dir   = is_dir( $absolute );
        $preview = $is_dir ? array( 'previewable' => false, 'kind' => 'none', 'mime' => '' ) : $this->preview_meta( $this->relative_from_absolute( $absolute ) );
        return array(
            'path'        => $this->relative_from_absolute( $absolute ),
            'name'        => '' === $relative ? '/ (root)' : basename( $absolute ),
            'type'        => $is_dir ? 'directory' : 'file',
            'size'        => $is_dir ? null : (int) filesize( $absolute ),
            'modified'    => (int) filemtime( $absolute ),
            'permissions' => substr( sprintf( '%o', fileperms( $absolute ) ), -4 ),
            'readable'    => is_readable( $absolute ),
            'writable'    => is_writable( $absolute ),
            'protected'   => $this->is_protected_path( $relative ),
            'editable'    => ! $is_dir && $this->is_editable_file( $relative ),
            'extension'   => $is_dir ? '' : strtolower( pathinfo( $absolute, PATHINFO_EXTENSION ) ),
            'preview'     => $preview,
        );
    }

    public function create_folder( string $parent_relative, string $name, bool $confirm_protected = false ): array {
        $lock = $this->acquire_private_lock( 'filesystem-mutation' );
        try { return $this->create_folder_unlocked( $parent_relative, $name, $confirm_protected ); }
        finally { $this->release_private_lock( $lock ); }
    }

    private function create_folder_unlocked( string $parent_relative, string $name, bool $confirm_protected = false ): array {
        [ $parent, $target, $name ] = $this->resolve_parent_for_new( $parent_relative, $name );
        $target_rel = $this->relative_from_absolute( $target );
        if ( $this->is_protected_path( $this->relative_from_absolute( $parent ) ) && ! $confirm_protected ) {
            throw new DomainException( 'PROTECTED_CONFIRM_REQUIRED' );
        }
        if ( file_exists( $target ) || is_link( $target ) ) {
            throw new RuntimeException( 'A file or folder with that name already exists.' );
        }
        if ( ! @mkdir( $target, 0755 ) ) {
            throw new RuntimeException( 'Unable to create the folder.' );
        }
        Nobash_Files_DB::add_operation( 'create_folder', 'Created folder ' . $name, $target_rel, array( 'type' => 'remove_empty_dir', 'path' => $target_rel ) );
        Nobash_Files_DB::log( 'created_folder', $target_rel );
        return $this->file_info( $target_rel );
    }

    public function rename_item( string $relative, string $new_name, bool $confirm_protected = false ): array {
        $lock = $this->acquire_private_lock( 'filesystem-mutation' );
        try { return $this->rename_item_unlocked( $relative, $new_name, $confirm_protected ); }
        finally { $this->release_private_lock( $lock ); }
    }

    private function rename_item_unlocked( string $relative, string $new_name, bool $confirm_protected = false ): array {
        $source = $this->resolve_existing( $relative );
        $source_rel = $this->relative_from_absolute( $source );
        $this->assert_mutable_source( $source, $source_rel );
        [ $parent, $target, $new_name ] = $this->resolve_parent_for_new( $this->parent_path( $source_rel ) ?? '', $new_name );
        unset( $parent );
        if ( ( $this->is_protected_path( $source_rel ) || $this->is_protected_path( $this->relative_from_absolute( $target ) ) ) && ! $confirm_protected ) {
            throw new DomainException( 'PROTECTED_CONFIRM_REQUIRED' );
        }
        if ( file_exists( $target ) || is_link( $target ) ) {
            throw new RuntimeException( 'A file or folder with that name already exists.' );
        }
        if ( ! @rename( $source, $target ) ) {
            throw new RuntimeException( 'Rename failed.' );
        }
        $target_rel = $this->relative_from_absolute( $target );
        Nobash_Files_DB::add_operation( 'rename', 'Renamed ' . basename( $source ) . ' to ' . $new_name, $target_rel, array( 'type' => 'move_back', 'moves' => array( array( 'from' => $target_rel, 'to' => $source_rel ) ) ) );
        Nobash_Files_DB::log( 'renamed_item', $target_rel, array( 'from' => $source_rel ) );
        return $this->file_info( $target_rel );
    }

    public function duplicate_item( string $relative, bool $confirm_protected = false ): array {
        $lock = $this->acquire_private_lock( 'filesystem-mutation' );
        try { return $this->duplicate_item_unlocked( $relative, $confirm_protected ); }
        finally { $this->release_private_lock( $lock ); }
    }

    private function duplicate_item_unlocked( string $relative, bool $confirm_protected = false ): array {
        $source = $this->resolve_existing( $relative );
        $source_rel = $this->relative_from_absolute( $source );
        $this->assert_mutable_source( $source, $source_rel, false );
        $parent_rel = $this->parent_path( $source_rel ) ?? '';
        $parent = $this->resolve_existing( $parent_rel );
        if ( $this->is_protected_path( $source_rel ) && ! $confirm_protected ) {
            throw new DomainException( 'PROTECTED_CONFIRM_REQUIRED' );
        }
        $target = $this->unique_copy_path( $parent, basename( $source ), is_dir( $source ) );
        $this->copy_atomic( $source, $target );
        $target_rel = $this->relative_from_absolute( $target );
        Nobash_Files_DB::add_operation( 'duplicate', 'Duplicated ' . basename( $source ), $target_rel, array( 'type' => 'delete_paths', 'items' => array( $this->deletion_undo_item( $target_rel ) ) ) );
        Nobash_Files_DB::log( 'duplicated_item', $target_rel, array( 'from' => $source_rel ) );
        return $this->file_info( $target_rel );
    }

    public function copy_items( array $paths, string $target_dir_relative, bool $confirm_protected = false ): array {
        $lock = $this->acquire_private_lock( 'filesystem-mutation' );
        try { return $this->copy_items_unlocked( $paths, $target_dir_relative, $confirm_protected ); }
        finally { $this->release_private_lock( $lock ); }
    }

    private function copy_items_unlocked( array $paths, string $target_dir_relative, bool $confirm_protected = false ): array {
        return $this->transfer_items( $paths, $target_dir_relative, false, $confirm_protected );
    }

    public function move_items( array $paths, string $target_dir_relative, bool $confirm_protected = false ): array {
        $lock = $this->acquire_private_lock( 'filesystem-mutation' );
        try { return $this->move_items_unlocked( $paths, $target_dir_relative, $confirm_protected ); }
        finally { $this->release_private_lock( $lock ); }
    }

    private function move_items_unlocked( array $paths, string $target_dir_relative, bool $confirm_protected = false ): array {
        return $this->transfer_items( $paths, $target_dir_relative, true, $confirm_protected );
    }

    private function transfer_items( array $paths, string $target_dir_relative, bool $move, bool $confirm_protected ): array {
        $paths = $this->normalize_path_list( $paths );
        if ( empty( $paths ) ) {
            throw new InvalidArgumentException( 'Select at least one file or folder.' );
        }
        $target_dir = $this->resolve_existing( $target_dir_relative );
        if ( ! is_dir( $target_dir ) || ! is_writable( $target_dir ) ) {
            throw new RuntimeException( 'Destination directory is not writable.' );
        }
        $target_dir_rel = $this->relative_from_absolute( $target_dir );

        $plan = array();
        foreach ( $paths as $relative ) {
            $source = $this->resolve_existing( $relative );
            $source_rel = $this->relative_from_absolute( $source );
            $this->assert_mutable_source( $source, $source_rel, ! $move );
            if ( ( $this->is_protected_path( $source_rel ) || $this->is_protected_path( $target_dir_rel ) ) && ! $confirm_protected ) {
                throw new DomainException( 'PROTECTED_CONFIRM_REQUIRED' );
            }
            if ( is_dir( $source ) && ( $target_dir === $source || str_starts_with( $target_dir . '/', rtrim( $source, '/' ) . '/' ) ) ) {
                throw new RuntimeException( 'A folder cannot be copied or moved into itself.' );
            }
            $target = wp_normalize_path( $target_dir . '/' . basename( $source ) );
            if ( file_exists( $target ) || is_link( $target ) ) {
                throw new RuntimeException( basename( $source ) . ' already exists in the destination.' );
            }
            $plan[] = array( 'source' => $source, 'source_rel' => $source_rel, 'target' => $target, 'target_rel' => $this->relative_from_absolute( $target ) );
        }

        $completed = array();
        try {
            foreach ( $plan as $item ) {
                if ( $move ) {
                    if ( ! @rename( $item['source'], $item['target'] ) ) {
                        throw new RuntimeException( 'Move failed for ' . basename( $item['source'] ) . '. Moving across filesystems is not supported yet.' );
                    }
                } else {
                    $this->copy_atomic( $item['source'], $item['target'] );
                }
                $completed[] = $item;
            }
        } catch ( Throwable $e ) {
            foreach ( array_reverse( $completed ) as $item ) {
                if ( $move ) {
                    @rename( $item['target'], $item['source'] );
                } else {
                    $this->remove_absolute( $item['target'] );
                }
            }
            throw $e;
        }

        if ( $move ) {
            $moves = array_map( static fn( array $item ): array => array( 'from' => $item['target_rel'], 'to' => $item['source_rel'] ), $completed );
            Nobash_Files_DB::add_operation( 'move', 'Moved ' . count( $completed ) . ' item(s)', $target_dir_rel, array( 'type' => 'move_back', 'moves' => $moves ) );
            Nobash_Files_DB::log( 'moved_items', $target_dir_rel, array( 'paths' => array_column( $completed, 'source_rel' ) ) );
        } else {
            $created = array_column( $completed, 'target_rel' );
            $undo_items = array_map( fn( string $path ): array => $this->deletion_undo_item( $path ), $created );
            Nobash_Files_DB::add_operation( 'copy', 'Copied ' . count( $completed ) . ' item(s)', $target_dir_rel, array( 'type' => 'delete_paths', 'items' => $undo_items ) );
            Nobash_Files_DB::log( 'copied_items', $target_dir_rel, array( 'paths' => array_column( $completed, 'source_rel' ) ) );
        }

        return array_map( fn( array $item ): array => $this->file_info( $item['target_rel'] ), $completed );
    }

    public function trash_items( array $paths, bool $confirm_protected = false ): array {
        $lock = $this->acquire_private_lock( 'filesystem-mutation' );
        try { return $this->trash_items_unlocked( $paths, $confirm_protected ); }
        finally { $this->release_private_lock( $lock ); }
    }

    private function trash_items_unlocked( array $paths, bool $confirm_protected = false ): array {
        $paths = $this->normalize_path_list( $paths );
        if ( empty( $paths ) ) {
            throw new InvalidArgumentException( 'Select at least one file or folder.' );
        }
        $items = array();
        foreach ( $paths as $relative ) {
            $absolute = $this->resolve_existing( $relative );
            $rel = $this->relative_from_absolute( $absolute );
            $this->assert_mutable_source( $absolute, $rel );
            if ( $this->is_protected_path( $rel ) && ! $confirm_protected ) {
                throw new DomainException( 'PROTECTED_CONFIRM_REQUIRED' );
            }
            $items[] = array( 'absolute' => $absolute, 'path' => $rel, 'snapshot' => $this->create_snapshot( $absolute, $rel ) );
        }

        $removed = array();
        try {
            foreach ( $items as $item ) {
                if ( ! $this->remove_absolute( $item['absolute'] ) ) {
                    throw new RuntimeException( 'Unable to move ' . basename( $item['absolute'] ) . ' to Trash.' );
                }
                $removed[] = $item;
            }
        } catch ( Throwable $e ) {
            foreach ( $items as $item ) {
                try {
                    if ( file_exists( $item['absolute'] ) || is_link( $item['absolute'] ) ) {
                        $this->remove_absolute( $item['absolute'] );
                    }
                    $this->restore_snapshot( $item['snapshot'], $item['path'] );
                } catch ( Throwable $ignored ) {
                    // Best-effort rollback; original exception remains the primary failure.
                }
            }
            throw $e;
        }

        $snapshots = array_map( static fn( array $item ): array => array( 'snapshot' => $item['snapshot'], 'path' => $item['path'] ), $items );
        Nobash_Files_DB::add_operation( 'trash', 'Moved ' . count( $items ) . ' item(s) to Trash', implode( ', ', array_column( $items, 'path' ) ), array( 'type' => 'restore_snapshots', 'items' => $snapshots ) );
        Nobash_Files_DB::log( 'trashed_items', implode( ', ', array_column( $items, 'path' ) ), array( 'count' => count( $items ) ) );
        return array( 'count' => count( $items ) );
    }

    public function upload_file( string $dir_relative, array $file, bool $confirm_protected = false ): array {
        $lock = $this->acquire_private_lock( 'filesystem-mutation' );
        try { return $this->upload_file_unlocked( $dir_relative, $file, $confirm_protected ); }
        finally { $this->release_private_lock( $lock ); }
    }


    /** Upload a file while preserving a browser folder-selection relative path. */
    public function upload_file_relative( string $base_relative, string $relative_path, array $file, bool $confirm_protected = false ): array {
        $lock = $this->acquire_private_lock( 'filesystem-mutation' );
        try {
            [ $dir_relative, $filename ] = $this->ensure_upload_parent_unlocked( $base_relative, $relative_path, $confirm_protected );
            $file['name'] = $filename;
            return $this->upload_file_unlocked( $dir_relative, $file, $confirm_protected );
        } finally {
            $this->release_private_lock( $lock );
        }
    }

    private function upload_file_unlocked( string $dir_relative, array $file, bool $confirm_protected = false ): array {
        if ( ! isset( $file['tmp_name'], $file['name'], $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
            throw new RuntimeException( 'Upload failed before the file reached Nobash Files.' );
        }
        $name = $this->validate_name( (string) $file['name'] );
        [ $dir, $target ] = $this->resolve_parent_for_new( $dir_relative, $name );
        $dir_rel = $this->relative_from_absolute( $dir );
        if ( $this->is_protected_path( $dir_rel ) && ! $confirm_protected ) {
            throw new DomainException( 'PROTECTED_CONFIRM_REQUIRED' );
        }
        if ( file_exists( $target ) || is_link( $target ) ) {
            throw new RuntimeException( $name . ' already exists.' );
        }
        $tmp_target = $dir . '/.' . $name . '.nobash-files-upload-' . wp_generate_password( 10, false, false ) . '.tmp';
        $moved = is_uploaded_file( (string) $file['tmp_name'] )
            ? move_uploaded_file( (string) $file['tmp_name'], $tmp_target )
            : @rename( (string) $file['tmp_name'], $tmp_target );
        if ( ! $moved ) {
            throw new RuntimeException( 'Unable to move the uploaded file into the destination.' );
        }
        @chmod( $tmp_target, 0644 );
        if ( ! @rename( $tmp_target, $target ) ) {
            @unlink( $tmp_target );
            throw new RuntimeException( 'Unable to finalize the upload.' );
        }
        $rel = $this->relative_from_absolute( $target );
        Nobash_Files_DB::add_operation( 'upload', 'Uploaded ' . $name, $rel, array( 'type' => 'delete_paths', 'items' => array( $this->deletion_undo_item( $rel ) ) ) );
        Nobash_Files_DB::log( 'uploaded_file', $rel, array( 'bytes' => (int) filesize( $target ) ) );
        return $this->file_info( $rel );
    }

    public function upload_chunk( string $dir_relative, string $filename, string $upload_id, int $chunk_index, int $total_chunks, array $file, bool $confirm_protected = false, string $relative_path = '' ): array {
        $filename = $this->validate_name( $filename );
        if ( '' !== trim( $relative_path ) ) {
            $mutation_lock = $this->acquire_private_lock( 'filesystem-mutation' );
            try {
                [ $dir_relative, $relative_filename ] = $this->ensure_upload_parent_unlocked( $dir_relative, $relative_path, $confirm_protected );
                $filename = $relative_filename;
            } finally {
                $this->release_private_lock( $mutation_lock );
            }
        }
        if ( ! preg_match( '/^[A-Za-z0-9_-]{16,96}$/', $upload_id ) ) {
            throw new InvalidArgumentException( 'Invalid upload session.' );
        }
        if ( $total_chunks < 1 || $total_chunks > 20000 || $chunk_index < 0 || $chunk_index >= $total_chunks ) {
            throw new InvalidArgumentException( 'Invalid upload chunk information.' );
        }
        if ( ! isset( $file['tmp_name'], $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
            throw new RuntimeException( 'Chunk upload failed.' );
        }
        $incoming = (string) $file['tmp_name'];
        $chunk_size = isset( $file['size'] ) ? (int) $file['size'] : ( is_file( $incoming ) ? (int) filesize( $incoming ) : 0 );
        $chunk_cap  = class_exists( 'Nobash_Files_Runtime' ) ? Nobash_Files_Runtime::effective_chunk_bytes() : 8388608;
        if ( $chunk_size < 1 || $chunk_size > $chunk_cap ) {
            throw new RuntimeException( 'Upload chunk exceeds the safe runtime limit for this server.' );
        }
        $incoming_hash = is_file( $incoming ) ? hash_file( 'sha256', $incoming ) : false;
        if ( false === $incoming_hash ) {
            throw new RuntimeException( 'Unable to fingerprint the upload chunk.' );
        }
        [ $dir, $target ] = $this->resolve_parent_for_new( $dir_relative, $filename );
        $dir_rel = $this->relative_from_absolute( $dir );
        if ( $this->is_protected_path( $dir_rel ) && ! $confirm_protected ) {
            throw new DomainException( 'PROTECTED_CONFIRM_REQUIRED' );
        }

        $session = $this->private_dir . '/chunks/' . $upload_id;
        if ( ! is_dir( $session ) && ! wp_mkdir_p( $session ) ) {
            throw new RuntimeException( 'Unable to create upload session.' );
        }
        $lock = $this->acquire_private_lock( 'chunk-' . $upload_id );
        try {
            $meta_path      = $session . '/meta.json';
            $complete_path  = $session . '/complete.json';
            $meta = array( 'dir' => $dir_rel, 'filename' => $filename, 'total' => $total_chunks, 'created' => time() );
            if ( file_exists( $meta_path ) ) {
                $stored = json_decode( (string) file_get_contents( $meta_path ), true );
                if ( ! is_array( $stored ) || (string) ( $stored['dir'] ?? '' ) !== $dir_rel || (string) ( $stored['filename'] ?? '' ) !== $filename || (int) ( $stored['total'] ?? 0 ) !== $total_chunks ) {
                    throw new RuntimeException( 'Upload session metadata does not match.' );
                }
            } else {
                $this->write_json_atomic( $meta_path, $meta );
            }
            if ( file_exists( $complete_path ) ) {
                $completed = json_decode( (string) file_get_contents( $complete_path ), true );
                if ( is_array( $completed ) && ! empty( $completed['path'] ) ) {
                    try {
                        $info = $this->file_info( (string) $completed['path'] );
                        if ( isset( $completed['size'] ) && (int) $completed['size'] !== (int) ( $info['size'] ?? -1 ) ) {
                            throw new RuntimeException( 'Completed upload fingerprint no longer matches.' );
                        }
                        @unlink( $incoming );
                        return array( 'complete' => true, 'received' => $total_chunks, 'total' => $total_chunks, 'progress' => 100, 'file' => $info, 'replayed' => true );
                    } catch ( Throwable $ignored ) {
                        @unlink( $complete_path );
                    }
                }
            }

            $part = $session . '/' . str_pad( (string) $chunk_index, 6, '0', STR_PAD_LEFT ) . '.part';
            $sidecar = $part . '.sha256';
            if ( is_file( $part ) ) {
                $existing_size = (int) filesize( $part );
                $existing_hash = hash_file( 'sha256', $part );
                if ( $existing_size !== $chunk_size || false === $existing_hash || ! hash_equals( $existing_hash, $incoming_hash ) ) {
                    @unlink( $incoming );
                    throw new RuntimeException( 'This upload chunk was replayed with different content. Start a new upload session.' );
                }
                @unlink( $incoming );
                if ( ! is_file( $sidecar ) ) {
                    @file_put_contents( $sidecar, $existing_size . ':' . $existing_hash, LOCK_EX );
                }
            } else {
                $moved = is_uploaded_file( $incoming ) ? move_uploaded_file( $incoming, $part ) : @rename( $incoming, $part );
                if ( ! $moved ) {
                    throw new RuntimeException( 'Unable to store upload chunk.' );
                }
                @chmod( $part, 0600 );
                if ( false === @file_put_contents( $sidecar, $chunk_size . ':' . $incoming_hash, LOCK_EX ) ) {
                    @unlink( $part );
                    throw new RuntimeException( 'Unable to checkpoint upload chunk integrity.' );
                }
            }

            $received = 0;
            $assembled_size = 0;
            $max_upload = max( 1073741824, (int) apply_filters( 'nobash_files_chunk_upload_max_bytes', 107374182400 ) ); // 100 GiB default ceiling.
            for ( $i = 0; $i < $total_chunks; $i++ ) {
                $part_path = $session . '/' . str_pad( (string) $i, 6, '0', STR_PAD_LEFT ) . '.part';
                if ( file_exists( $part_path ) ) {
                    $received++;
                    $assembled_size += (int) filesize( $part_path );
                    if ( $assembled_size > $max_upload ) {
                        throw new RuntimeException( 'Chunked upload exceeds the configured maximum assembled file size.' );
                    }
                }
            }
            if ( $received < $total_chunks ) {
                return array( 'complete' => false, 'received' => $received, 'total' => $total_chunks, 'progress' => (int) floor( ( $received / $total_chunks ) * 100 ) );
            }
            $mutation_lock = $this->acquire_private_lock( 'filesystem-mutation' );
            try {
            if ( file_exists( $target ) || is_link( $target ) ) {
                throw new RuntimeException( $filename . ' already exists.' );
            }

            $tmp_target = $dir . '/.' . $filename . '.nobash-files-upload-' . wp_generate_password( 10, false, false ) . '.tmp';
            $out = @fopen( $tmp_target, 'wb' );
            if ( false === $out ) {
                throw new RuntimeException( 'Unable to assemble uploaded chunks.' );
            }
            $written_total = 0;
            try {
                for ( $i = 0; $i < $total_chunks; $i++ ) {
                    $part_path = $session . '/' . str_pad( (string) $i, 6, '0', STR_PAD_LEFT ) . '.part';
                    $checkpoint = trim( (string) @file_get_contents( $part_path . '.sha256' ) );
                    $actual_hash = is_file( $part_path ) ? hash_file( 'sha256', $part_path ) : false;
                    $actual_size = is_file( $part_path ) ? (int) filesize( $part_path ) : -1;
                    $expected = $actual_size . ':' . ( false === $actual_hash ? '' : $actual_hash );
                    if ( false === $actual_hash || '' === $checkpoint || ! hash_equals( $checkpoint, $expected ) ) {
                        throw new RuntimeException( 'An upload chunk failed integrity verification before assembly.' );
                    }
                    $in = @fopen( $part_path, 'rb' );
                    if ( false === $in ) {
                        throw new RuntimeException( 'An upload chunk is missing.' );
                    }
                    try {
                        $copied = stream_copy_to_stream( $in, $out );
                        if ( false === $copied || $copied !== $actual_size ) {
                            throw new RuntimeException( 'An upload chunk could not be assembled completely.' );
                        }
                        $written_total += $copied;
                    } finally {
                        fclose( $in );
                    }
                }
                @fflush( $out );
                if ( function_exists( 'fsync' ) ) { @fsync( $out ); }
            } catch ( Throwable $e ) {
                fclose( $out );
                @unlink( $tmp_target );
                throw $e;
            }
            fclose( $out );
            if ( $written_total !== $assembled_size || (int) filesize( $tmp_target ) !== $assembled_size ) {
                @unlink( $tmp_target );
                throw new RuntimeException( 'Assembled upload size verification failed.' );
            }
            @chmod( $tmp_target, 0644 );
            if ( ! @rename( $tmp_target, $target ) ) {
                @unlink( $tmp_target );
                throw new RuntimeException( 'Unable to finalize the chunked upload.' );
            }
            $rel = $this->relative_from_absolute( $target );
            $final_hash = hash_file( 'sha256', $target );
            Nobash_Files_DB::add_operation( 'upload', 'Uploaded ' . $filename, $rel, array( 'type' => 'delete_paths', 'items' => array( $this->deletion_undo_item( $rel ) ) ) );
            Nobash_Files_DB::log( 'uploaded_file', $rel, array( 'bytes' => (int) filesize( $target ), 'chunked' => true, 'chunks' => $total_chunks ) );
            for ( $i = 0; $i < $total_chunks; $i++ ) {
                $part_path = $session . '/' . str_pad( (string) $i, 6, '0', STR_PAD_LEFT ) . '.part';
                @unlink( $part_path );
                @unlink( $part_path . '.sha256' );
            }
            $this->write_json_atomic( $complete_path, array( 'path' => $rel, 'size' => (int) filesize( $target ), 'sha256' => false === $final_hash ? '' : $final_hash, 'completed' => time() ) );
            return array( 'complete' => true, 'received' => $total_chunks, 'total' => $total_chunks, 'progress' => 100, 'file' => $this->file_info( $rel ) );
            } finally {
                $this->release_private_lock( $mutation_lock );
            }
        } finally {
            $this->release_private_lock( $lock );
        }
    }

    /** Remove abandoned chunk sessions after a user cancels a local upload. */
    public function cancel_upload_sessions( array $upload_ids ): array {
        $removed = 0;
        foreach ( array_unique( array_map( 'strval', $upload_ids ) ) as $upload_id ) {
            if ( ! preg_match( '/^[A-Za-z0-9_-]{16,96}$/', $upload_id ) ) {
                continue;
            }
            $lock = $this->acquire_private_lock( 'chunk-' . $upload_id );
            try {
                $session = wp_normalize_path( $this->private_dir . '/chunks/' . $upload_id );
                if ( is_dir( $session ) ) {
                    $this->remove_private_directory( $session );
                    if ( ! is_dir( $session ) ) { $removed++; }
                }
            } finally {
                $this->release_private_lock( $lock );
            }
        }
        if ( $removed > 0 ) {
            Nobash_Files_DB::log( 'cancelled_upload_sessions', '', array( 'count' => $removed ) );
        }
        return array( 'removed' => $removed );
    }

    public function undo_last_operation(): array {
        $lock = $this->acquire_private_lock( 'operation-user-' . ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 ) );
        try {
            $mutation_lock = $this->acquire_private_lock( 'filesystem-mutation' );
            try { return $this->undo_last_operation_unlocked(); }
            finally { $this->release_private_lock( $mutation_lock ); }
        } finally { $this->release_private_lock( $lock ); }
    }

    private function undo_last_operation_unlocked(): array {
        $operation = Nobash_Files_DB::last_undoable();
        if ( ! $operation ) {
            throw new RuntimeException( 'There is nothing to undo.' );
        }
        $payload = $operation['undo_payload'];
        $type = isset( $payload['type'] ) ? (string) $payload['type'] : '';

        if ( 'delete_paths' === $type ) {
            $items = (array) ( $payload['items'] ?? array() );
            if ( empty( $items ) && ! empty( $payload['paths'] ) ) {
                $items = array_map( static fn( $path ): array => array( 'path' => (string) $path, 'fingerprint' => '' ), (array) $payload['paths'] );
            }
            foreach ( array_reverse( $items ) as $item ) {
                $path = (string) ( $item['path'] ?? '' );
                $absolute = $this->resolve_existing( $path );
                $expected = (string) ( $item['fingerprint'] ?? '' );
                if ( '' !== $expected && ! hash_equals( $expected, $this->fingerprint_absolute( $absolute ) ) ) {
                    throw new RuntimeException( 'Undo stopped because ' . basename( $absolute ) . ' changed after the original operation.' );
                }
                if ( ! $this->remove_absolute( $absolute ) ) {
                    throw new RuntimeException( 'Undo could not remove ' . basename( $absolute ) . '.' );
                }
            }
        } elseif ( 'remove_empty_dir' === $type ) {
            $absolute = $this->resolve_existing( (string) ( $payload['path'] ?? '' ) );
            if ( ! is_dir( $absolute ) ) {
                throw new RuntimeException( 'Undo target is no longer a folder.' );
            }
            $entries = array_diff( scandir( $absolute ) ?: array(), array( '.', '..' ) );
            if ( ! empty( $entries ) ) {
                throw new RuntimeException( 'The folder is no longer empty, so undo was stopped to protect newer files.' );
            }
            if ( ! @rmdir( $absolute ) ) {
                throw new RuntimeException( 'Unable to remove the created folder.' );
            }
        } elseif ( 'move_back' === $type ) {
            $moves = (array) ( $payload['moves'] ?? array() );
            foreach ( $moves as $move ) {
                $from = $this->resolve_existing( (string) $move['from'] );
                $to_rel = $this->normalize_relative( (string) $move['to'] );
                $to_parent_rel = $this->parent_path( $to_rel ) ?? '';
                $to_parent = $this->resolve_existing( $to_parent_rel );
                $to = wp_normalize_path( $to_parent . '/' . basename( $to_rel ) );
                if ( file_exists( $to ) || is_link( $to ) ) {
                    throw new RuntimeException( 'Undo destination already exists: ' . $to_rel );
                }
                if ( ! @rename( $from, $to ) ) {
                    throw new RuntimeException( 'Unable to move ' . basename( $from ) . ' back.' );
                }
            }
        } elseif ( 'restore_extraction' === $type ) {
            foreach ( array_reverse( (array) ( $payload['created'] ?? array() ) ) as $item ) {
                $path = (string) ( $item['path'] ?? '' );
                $absolute = $this->resolve_existing( $path );
                $expected = (string) ( $item['fingerprint'] ?? '' );
                if ( '' !== $expected && ! hash_equals( $expected, $this->fingerprint_absolute( $absolute ) ) ) {
                    throw new RuntimeException( 'Undo stopped because ' . basename( $absolute ) . ' changed after extraction.' );
                }
                if ( ! $this->remove_absolute( $absolute ) ) throw new RuntimeException( 'Unable to remove extracted item during Undo.' );
            }
            foreach ( (array) ( $payload['overwritten'] ?? array() ) as $item ) {
                $path = $this->normalize_relative( (string) ( $item['path'] ?? '' ) );
                $current = $this->resolve_existing( $path );
                $expected = (string) ( $item['fingerprint'] ?? '' );
                if ( '' !== $expected && ! hash_equals( $expected, $this->fingerprint_absolute( $current ) ) ) {
                    throw new RuntimeException( 'Undo stopped because ' . basename( $current ) . ' changed after extraction.' );
                }
                if ( ! $this->remove_absolute( $current ) ) throw new RuntimeException( 'Unable to remove overwritten extraction target during Undo.' );
                $this->restore_snapshot( (string) ( $item['snapshot'] ?? '' ), $path );
            }
        } elseif ( 'restore_snapshots' === $type ) {
            foreach ( (array) ( $payload['items'] ?? array() ) as $item ) {
                $path = $this->normalize_relative( (string) $item['path'] );
                $parent_rel = $this->parent_path( $path ) ?? '';
                $parent = $this->resolve_existing( $parent_rel );
                $target = wp_normalize_path( $parent . '/' . basename( $path ) );
                if ( file_exists( $target ) || is_link( $target ) ) {
                    throw new RuntimeException( 'Cannot restore ' . $path . ' because a new item now exists there.' );
                }
                $this->restore_snapshot( (string) $item['snapshot'], $path );
            }
        } else {
            throw new RuntimeException( 'This operation does not have a supported undo action.' );
        }

        Nobash_Files_DB::mark_undone( (int) $operation['id'] );
        Nobash_Files_DB::log( 'undid_operation', (string) $operation['relative_path'], array( 'operation_id' => (int) $operation['id'], 'action' => $operation['action'] ) );
        $this->cleanup_undo_payload( $payload );
        return array( 'undone' => true, 'label' => $operation['label'], 'operation_id' => (int) $operation['id'] );
    }

    public function undo_state(): array {
        $last = Nobash_Files_DB::last_undoable();
        return array(
            'can_undo' => (bool) $last,
            'last' => $last ? array(
                'id' => (int) $last['id'],
                'action' => $last['action'],
                'label' => $last['label'],
                'path' => $last['relative_path'],
                'created_at' => $last['created_at'],
            ) : null,
            'recent' => Nobash_Files_DB::recent_operations( 12 ),
        );
    }

    private function cleanup_undo_payload( array $payload ): void {
        $type = (string) ( $payload['type'] ?? '' );
        if ( 'restore_snapshots' === $type ) {
            $snapshot_items = (array) ( $payload['items'] ?? array() );
        } elseif ( 'restore_extraction' === $type ) {
            $snapshot_items = (array) ( $payload['overwritten'] ?? array() );
        } else {
            return;
        }
        foreach ( $snapshot_items as $item ) {
            if ( ! empty( $item['snapshot'] ) ) {
                $dir = $this->snapshot_dir( (string) $item['snapshot'] );
                if ( is_dir( $dir ) ) {
                    $this->remove_private_directory( $dir );
                }
            }
        }
    }

    private function create_snapshot( string $absolute, string $relative ): string {
        $token = gmdate( 'YmdHis' ) . '-' . wp_generate_password( 20, false, false );
        $dir = $this->snapshot_dir( $token );
        if ( ! wp_mkdir_p( $dir . '/files' ) ) {
            throw new RuntimeException( 'Unable to create recovery snapshot.' );
        }
        $manifest = array(
            'version' => 1,
            'source_path' => $relative,
            'created' => time(),
            'entries' => array(),
        );
        $counter = 0;
        $base_parent = dirname( $absolute );
        try {
            $this->snapshot_walk( $absolute, $base_parent, $dir, $manifest['entries'], $counter );
            if ( false === file_put_contents( $dir . '/manifest.json', wp_json_encode( $manifest ), LOCK_EX ) ) {
                throw new RuntimeException( 'Unable to finalize recovery snapshot.' );
            }
        } catch ( Throwable $e ) {
            $this->remove_private_directory( $dir );
            throw $e;
        }
        return $token;
    }

    private function snapshot_walk( string $absolute, string $base_parent, string $snapshot_dir, array &$entries, int &$counter ): void {
        if ( is_link( $absolute ) ) {
            throw new RuntimeException( 'Symbolic links are not supported in recoverable Trash operations.' );
        }
        $relative_inside = ltrim( substr( wp_normalize_path( $absolute ), strlen( wp_normalize_path( $base_parent ) ) ), '/' );
        if ( is_dir( $absolute ) ) {
            $entries[] = array( 'type' => 'dir', 'path' => $relative_inside, 'mode' => fileperms( $absolute ) & 0777 );
            $names = scandir( $absolute );
            if ( false === $names ) {
                throw new RuntimeException( 'Unable to snapshot directory.' );
            }
            foreach ( $names as $name ) {
                if ( '.' === $name || '..' === $name ) {
                    continue;
                }
                $this->snapshot_walk( $absolute . '/' . $name, $base_parent, $snapshot_dir, $entries, $counter );
            }
            return;
        }
        if ( ! is_file( $absolute ) ) {
            throw new RuntimeException( 'Unsupported filesystem item in recovery snapshot.' );
        }
        $counter++;
        $blob = 'files/' . str_pad( (string) $counter, 8, '0', STR_PAD_LEFT ) . '.nfmdata';
        if ( ! @copy( $absolute, $snapshot_dir . '/' . $blob ) ) {
            throw new RuntimeException( 'Unable to snapshot ' . basename( $absolute ) . '.' );
        }
        $entries[] = array(
            'type' => 'file',
            'path' => $relative_inside,
            'blob' => $blob,
            'mode' => fileperms( $absolute ) & 0777,
            'size' => (int) filesize( $absolute ),
            'sha256' => hash_file( 'sha256', $absolute ),
        );
    }

    private function restore_snapshot( string $token, string $target_relative ): void {
        if ( ! preg_match( '/^[A-Za-z0-9_-]{10,80}$/', $token ) ) {
            throw new RuntimeException( 'Invalid recovery snapshot.' );
        }
        $dir = $this->snapshot_dir( $token );
        $manifest_path = $dir . '/manifest.json';
        if ( ! is_file( $manifest_path ) ) {
            throw new RuntimeException( 'Recovery snapshot is no longer available.' );
        }
        $manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
        if ( ! is_array( $manifest ) || empty( $manifest['entries'] ) ) {
            throw new RuntimeException( 'Recovery snapshot is invalid.' );
        }
        $target_relative = $this->normalize_relative( $target_relative );
        $parent_rel = $this->parent_path( $target_relative ) ?? '';
        $parent = $this->resolve_existing( $parent_rel );
        $top = basename( $target_relative );

        foreach ( $manifest['entries'] as $entry ) {
            if ( ! is_array( $entry ) || empty( $entry['path'] ) ) {
                throw new RuntimeException( 'Recovery manifest contains an invalid entry.' );
            }
            $inside = $this->normalize_relative( (string) $entry['path'] );
            $parts = explode( '/', $inside );
            if ( $parts[0] !== $top ) {
                throw new RuntimeException( 'Recovery snapshot path mismatch.' );
            }
            $dest = wp_normalize_path( $parent . '/' . $inside );
            $this->assert_inside_root( $dest );
            $this->assert_not_private_storage( $dest );
            if ( 'dir' === $entry['type'] ) {
                if ( ! is_dir( $dest ) && ! wp_mkdir_p( $dest ) ) {
                    throw new RuntimeException( 'Unable to restore directory.' );
                }
                @chmod( $dest, (int) ( $entry['mode'] ?? 0755 ) );
            } elseif ( 'file' === $entry['type'] ) {
                $blob = $dir . '/' . (string) $entry['blob'];
                if ( ! is_file( $blob ) ) {
                    throw new RuntimeException( 'Recovery data is incomplete.' );
                }
                $parent_dest = dirname( $dest );
                if ( ! is_dir( $parent_dest ) && ! wp_mkdir_p( $parent_dest ) ) {
                    throw new RuntimeException( 'Unable to restore file parent directory.' );
                }
                if ( ! @copy( $blob, $dest ) ) {
                    throw new RuntimeException( 'Unable to restore ' . basename( $dest ) . '.' );
                }
                @chmod( $dest, (int) ( $entry['mode'] ?? 0644 ) );
                if ( ! empty( $entry['sha256'] ) && hash_file( 'sha256', $dest ) !== $entry['sha256'] ) {
                    @unlink( $dest );
                    throw new RuntimeException( 'Recovery verification failed for ' . basename( $dest ) . '.' );
                }
            }
        }
    }

    private function snapshot_dir( string $token ): string {
        return wp_normalize_path( $this->private_dir . '/snapshots/' . $token );
    }

    private function remove_private_directory( string $dir ): void {
        $dir = wp_normalize_path( $dir );
        if ( ! str_starts_with( $dir . '/', rtrim( $this->private_dir, '/' ) . '/' ) || ! is_dir( $dir ) ) {
            return;
        }
        $items = scandir( $dir );
        if ( false === $items ) {
            return;
        }
        foreach ( $items as $item ) {
            if ( '.' === $item || '..' === $item ) {
                continue;
            }
            $path = $dir . '/' . $item;
            if ( is_dir( $path ) && ! is_link( $path ) ) {
                $this->remove_private_directory( $path );
            } else {
                @unlink( $path );
            }
        }
        @rmdir( $dir );
    }

    private function normalize_path_list( array $paths ): array {
        $normalized = array();
        foreach ( $paths as $path ) {
            $rel = $this->normalize_relative( (string) $path );
            if ( '' === $rel ) {
                throw new RuntimeException( 'The WordPress root itself cannot be changed.' );
            }
            $normalized[$rel] = $rel;
        }
        return array_values( $normalized );
    }

    private function assert_mutable_source( string $absolute, string $relative, bool $allow_read_only = false ): void {
        if ( '' === $relative || $absolute === $this->root ) {
            throw new RuntimeException( 'The WordPress root itself cannot be changed.' );
        }
        if ( is_link( $absolute ) ) {
            throw new RuntimeException( 'Symbolic links cannot be changed from Nobash Files.' );
        }
        if ( ! $allow_read_only && ! is_writable( $absolute ) && ! is_writable( dirname( $absolute ) ) ) {
            throw new RuntimeException( 'The selected item is not writable.' );
        }
    }

    private function copy_atomic( string $source, string $target ): void {
        $parent = dirname( $target );
        if ( ! is_writable( $parent ) ) {
            throw new RuntimeException( 'Destination directory is not writable.' );
        }
        $temp = $parent . '/.nobash-files-copy-' . wp_generate_password( 16, false, false ) . '.tmp';
        if ( is_dir( $source ) ) {
            if ( ! @mkdir( $temp, 0755 ) ) {
                throw new RuntimeException( 'Unable to prepare folder copy.' );
            }
            try {
                $this->copy_directory_contents( $source, $temp );
            } catch ( Throwable $e ) {
                $this->remove_absolute( $temp );
                throw $e;
            }
        } else {
            if ( ! @copy( $source, $temp ) ) {
                throw new RuntimeException( 'Unable to copy ' . basename( $source ) . '.' );
            }
            @chmod( $temp, fileperms( $source ) & 0777 );
        }
        if ( ! @rename( $temp, $target ) ) {
            $this->remove_absolute( $temp );
            throw new RuntimeException( 'Unable to finalize copy.' );
        }
    }

    private function copy_directory_contents( string $source, string $target ): void {
        $names = scandir( $source );
        if ( false === $names ) {
            throw new RuntimeException( 'Unable to read source directory.' );
        }
        foreach ( $names as $name ) {
            if ( '.' === $name || '..' === $name ) {
                continue;
            }
            $src = $source . '/' . $name;
            $dst = $target . '/' . $name;
            if ( is_link( $src ) ) {
                throw new RuntimeException( 'Folder contains a symbolic link, which is not copied for safety.' );
            }
            if ( is_dir( $src ) ) {
                if ( ! @mkdir( $dst, fileperms( $src ) & 0777 ) ) {
                    throw new RuntimeException( 'Unable to create copied directory.' );
                }
                $this->copy_directory_contents( $src, $dst );
            } elseif ( is_file( $src ) ) {
                if ( ! @copy( $src, $dst ) ) {
                    throw new RuntimeException( 'Unable to copy ' . basename( $src ) . '.' );
                }
                @chmod( $dst, fileperms( $src ) & 0777 );
            }
        }
    }

    private function remove_absolute( string $absolute ): bool {
        if ( is_link( $absolute ) ) {
            return @unlink( $absolute );
        }
        if ( is_file( $absolute ) ) {
            return @unlink( $absolute );
        }
        if ( ! is_dir( $absolute ) ) {
            return true;
        }
        $names = scandir( $absolute );
        if ( false === $names ) {
            return false;
        }
        foreach ( $names as $name ) {
            if ( '.' === $name || '..' === $name ) {
                continue;
            }
            if ( ! $this->remove_absolute( $absolute . '/' . $name ) ) {
                return false;
            }
        }
        return @rmdir( $absolute );
    }

    private function deletion_undo_item( string $relative ): array {
        $absolute = $this->resolve_existing( $relative );
        return array( 'path' => $relative, 'fingerprint' => $this->fingerprint_absolute( $absolute ) );
    }

    private function fingerprint_absolute( string $absolute ): string {
        $ctx = hash_init( 'sha256' );
        $count = 0;
        $base = is_dir( $absolute ) ? rtrim( wp_normalize_path( $absolute ), '/' ) : dirname( wp_normalize_path( $absolute ) );
        $this->fingerprint_walk( $absolute, $base, $ctx, $count );
        return hash_final( $ctx );
    }

    private function fingerprint_walk( string $absolute, string $base, $ctx, int &$count ): void {
        $count++;
        if ( $count > 10000 ) {
            throw new RuntimeException( 'This item is too large for a safe immediate Undo fingerprint.' );
        }
        $absolute = wp_normalize_path( $absolute );
        $relative = ltrim( substr( $absolute, strlen( $base ) ), '/' );
        if ( is_link( $absolute ) ) {
            hash_update( $ctx, 'link|' . $relative );
            return;
        }
        if ( is_dir( $absolute ) ) {
            // Directory mtimes change during safe rename/trash round-trips even when
            // the directory tree is byte-for-byte identical. Fingerprint structure
            // and child content instead so Undo does not produce false conflicts.
            hash_update( $ctx, 'dir|' . $relative );
            $names = scandir( $absolute );
            if ( false === $names ) {
                throw new RuntimeException( 'Unable to fingerprint directory for Undo.' );
            }
            foreach ( $names as $name ) {
                if ( '.' === $name || '..' === $name ) {
                    continue;
                }
                $this->fingerprint_walk( $absolute . '/' . $name, $base, $ctx, $count );
            }
            return;
        }
        if ( is_file( $absolute ) ) {
            $size = (int) filesize( $absolute );
            hash_update( $ctx, 'file|' . $relative . '|' . $size . '|' );
            if ( $size <= 8388608 ) {
                // Full content hash for normal files. This is stable across rename/
                // Trash restore and stronger than relying on filesystem timestamps.
                $full = hash_file( 'sha256', $absolute );
                if ( false === $full ) {
                    throw new RuntimeException( 'Unable to fingerprint file for Undo.' );
                }
                hash_update( $ctx, 'full|' . $full );
                return;
            }
            // Keep immediate Undo bounded for very large files while sampling three
            // distant regions so same-size middle edits are not silently missed.
            $fh = @fopen( $absolute, 'rb' );
            if ( false === $fh ) {
                throw new RuntimeException( 'Unable to fingerprint file for Undo.' );
            }
            foreach ( array( 0, max( 0, (int) floor( $size / 2 ) - 32768 ), max( 0, $size - 65536 ) ) as $offset ) {
                if ( 0 !== fseek( $fh, $offset ) ) { fclose( $fh ); throw new RuntimeException( 'Unable to fingerprint file for Undo.' ); }
                $chunk = fread( $fh, min( 65536, $size - $offset ) );
                if ( false === $chunk ) { fclose( $fh ); throw new RuntimeException( 'Unable to fingerprint file for Undo.' ); }
                hash_update( $ctx, $offset . '|' . $chunk );
            }
            fclose( $fh );
            return;
        }
        hash_update( $ctx, 'other|' . $relative );
    }

    private function unique_copy_path( string $parent, string $basename, bool $is_dir ): string {
        if ( $is_dir ) {
            $stem = $basename;
            $ext = '';
        } else {
            $ext = pathinfo( $basename, PATHINFO_EXTENSION );
            $stem = $ext ? substr( $basename, 0, -1 * ( strlen( $ext ) + 1 ) ) : $basename;
            $ext = $ext ? '.' . $ext : '';
        }
        $candidate = $parent . '/' . $stem . '-copy' . $ext;
        $i = 2;
        while ( file_exists( $candidate ) || is_link( $candidate ) ) {
            $candidate = $parent . '/' . $stem . '-copy-' . $i . $ext;
            $i++;
            if ( $i > 9999 ) {
                throw new RuntimeException( 'Unable to find a unique duplicate name.' );
            }
        }
        return wp_normalize_path( $candidate );
    }

    public function create_download_package( array $paths ): array {
        $paths = $this->normalize_path_list( $paths );
        if ( empty( $paths ) ) {
            throw new InvalidArgumentException( 'Select at least one file or folder.' );
        }
        if ( 1 === count( $paths ) ) {
            $absolute = $this->resolve_existing( $paths[0] );
            if ( is_file( $absolute ) ) {
                return array( 'path' => $absolute, 'name' => basename( $absolute ), 'mime' => 'application/octet-stream', 'temporary' => false );
            }
        }

        $token = 'nobash-files-download-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 10, false, false );
        if ( class_exists( 'ZipArchive' ) ) {
            $archive_path = $this->private_dir . '/downloads/' . $token . '.zip';
            $zip = new ZipArchive();
            if ( true !== $zip->open( $archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
                throw new RuntimeException( 'Unable to create download archive.' );
            }
            try {
                foreach ( $paths as $relative ) {
                    $absolute = $this->resolve_existing( $relative );
                    $this->add_to_zip( $zip, $absolute, basename( $absolute ) );
                }
            } catch ( Throwable $e ) {
                $zip->close();
                @unlink( $archive_path );
                throw $e;
            }
            $zip->close();
            return array( 'path' => $archive_path, 'name' => 'nobash-files-' . gmdate( 'Ymd-His' ) . '.zip', 'mime' => 'application/zip', 'temporary' => true );
        }

        if ( class_exists( 'PharData' ) ) {
            $archive_path = $this->private_dir . '/downloads/' . $token . '.tar';
            try {
                $tar = new PharData( $archive_path );
                foreach ( $paths as $relative ) {
                    $absolute = $this->resolve_existing( $relative );
                    $this->add_to_phar( $tar, $absolute, basename( $absolute ) );
                }
            } catch ( Throwable $e ) {
                @unlink( $archive_path );
                throw new RuntimeException( 'Unable to create download archive: ' . $e->getMessage() );
            }
            return array( 'path' => $archive_path, 'name' => 'nobash-files-' . gmdate( 'Ymd-His' ) . '.tar', 'mime' => 'application/x-tar', 'temporary' => true );
        }

        throw new RuntimeException( 'Folder or multi-file downloads require ZipArchive or PharData on this server.' );
    }

    private function add_to_zip( ZipArchive $zip, string $absolute, string $archive_path, string $job_id = '' ): void {
        $absolute = wp_normalize_path( $absolute );
        if ( '' !== $job_id ) $this->archive_job_assert_not_cancelled( $job_id );
        if ( is_link( $absolute ) || $this->should_hide( $absolute ) ) {
            return;
        }
        if ( is_dir( $absolute ) ) {
            $zip->addEmptyDir( $archive_path );
            $names = scandir( $absolute );
            if ( false === $names ) {
                throw new RuntimeException( 'Unable to read a folder for download.' );
            }
            foreach ( $names as $name ) {
                if ( '.' === $name || '..' === $name ) {
                    continue;
                }
                $this->add_to_zip( $zip, $absolute . '/' . $name, $archive_path . '/' . $name, $job_id );
            }
        } elseif ( is_file( $absolute ) ) {
            if ( ! $zip->addFile( $absolute, $archive_path ) ) {
                throw new RuntimeException( 'Unable to add ' . basename( $absolute ) . ' to the download.' );
            }
        }
    }

    private function add_to_phar( PharData $tar, string $absolute, string $archive_path, string $job_id = '' ): void {
        $absolute = wp_normalize_path( $absolute );
        if ( '' !== $job_id ) $this->archive_job_assert_not_cancelled( $job_id );
        if ( is_link( $absolute ) || $this->should_hide( $absolute ) ) {
            return;
        }
        if ( is_dir( $absolute ) ) {
            $tar->addEmptyDir( $archive_path );
            $names = scandir( $absolute );
            if ( false === $names ) {
                throw new RuntimeException( 'Unable to read a folder for download.' );
            }
            foreach ( $names as $name ) {
                if ( '.' === $name || '..' === $name ) {
                    continue;
                }
                $this->add_to_phar( $tar, $absolute . '/' . $name, $archive_path . '/' . $name, $job_id );
            }
        } elseif ( is_file( $absolute ) ) {
            $tar->addFile( $absolute, $archive_path );
        }
    }


    public function search( string $base_relative, string $query, bool $search_content = false, ?callable $path_filter = null ): array {
        $base_relative = $this->normalize_relative( $base_relative );
        $base          = $this->resolve_existing( $base_relative );
        if ( ! is_dir( $base ) ) {
            throw new InvalidArgumentException( 'Search scope must be a directory.' );
        }

        $query = trim( wp_strip_all_tags( $query ) );
        if ( strlen( $query ) < 2 ) {
            throw new InvalidArgumentException( 'Enter at least 2 characters to search.' );
        }
        if ( strlen( $query ) > 128 ) {
            throw new InvalidArgumentException( 'Search query is too long.' );
        }

        $needle        = function_exists( 'mb_strtolower' ) ? mb_strtolower( $query ) : strtolower( $query );
        $results       = array();
        $queue         = array( array( $base, 0 ) );
        $queue_index   = 0;
        $visited       = 0;
        $content_files = 0;
        $started       = microtime( true );
        $max_results   = 150;
        $max_visited   = 5000;
        $max_depth     = 20;
        $max_seconds   = 5.0;

        while ( isset( $queue[ $queue_index ] ) && count( $results ) < $max_results && $visited < $max_visited ) {
            [ $dir, $depth ] = $queue[ $queue_index++ ];
            if ( microtime( true ) - $started > $max_seconds ) {
                break;
            }
            try {
                $entries = new DirectoryIterator( $dir );
            } catch ( UnexpectedValueException $e ) {
                continue;
            }

            foreach ( $entries as $entry ) {
                $name = $entry->getFilename();
                if ( '.' === $name || '..' === $name ) {
                    continue;
                }
                if ( count( $results ) >= $max_results || $visited >= $max_visited || microtime( true ) - $started > $max_seconds ) {
                    break 2;
                }
                $visited++;
                $full = wp_normalize_path( $dir . '/' . $name );
                if ( is_link( $full ) || $this->should_hide( $full ) ) {
                    continue;
                }
                $real = realpath( $full );
                if ( false === $real ) {
                    continue;
                }
                $real = wp_normalize_path( $real );
                try {
                    $this->assert_inside_root( $real );
                    $this->assert_not_private_storage( $real );
                } catch ( Throwable $e ) {
                    continue;
                }

                $is_dir = is_dir( $real );
                $rel    = $this->relative_from_absolute( $real );
                if ( null !== $path_filter && ! $path_filter( $rel ) ) {
                    continue;
                }
                $hay    = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name );
                $matched_name = false !== strpos( $hay, $needle );
                $matched_content = false;
                $snippet = '';

                if ( ! $is_dir && ! $matched_name && $search_content && $this->is_editable_file( $rel ) ) {
                    $size = (int) @filesize( $real );
                    if ( $size >= 0 && $size <= 524288 && $content_files < 500 ) {
                        $content_files++;
                        $content = @file_get_contents( $real );
                        if ( is_string( $content ) && ! str_contains( $content, "\0" ) ) {
                            $content_hay = function_exists( 'mb_strtolower' ) ? mb_strtolower( $content ) : strtolower( $content );
                            $pos = strpos( $content_hay, $needle );
                            if ( false !== $pos ) {
                                $matched_content = true;
                                $start = max( 0, $pos - 70 );
                                $snippet = trim( preg_replace( '/\s+/', ' ', substr( $content, $start, 180 ) ) );
                            }
                        }
                    }
                }

                if ( $matched_name || $matched_content ) {
                    $results[] = array(
                        'name'       => $name,
                        'path'       => $rel,
                        'type'       => $is_dir ? 'directory' : 'file',
                        'size'       => $is_dir ? null : (int) @filesize( $real ),
                        'modified'   => (int) @filemtime( $real ),
                        'extension'  => $is_dir ? '' : strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ),
                        'protected'  => $this->is_protected_path( $rel ),
                        'editable'   => ! $is_dir && $this->is_editable_file( $rel ),
                        'match'      => $matched_content ? 'content' : 'name',
                        'snippet'    => $snippet,
                    );
                }

                if ( $is_dir && $depth < $max_depth ) {
                    $queue[] = array( $real, $depth + 1 );
                }
            }
        }

        return array(
            'query'        => $query,
            'base'         => $base_relative,
            'results'      => $results,
            'truncated'    => count( $results ) >= $max_results || $visited >= $max_visited || microtime( true ) - $started > $max_seconds,
            'visited'      => $visited,
            'content_files'=> $content_files,
        );
    }

    public function preview_file( string $relative ): array {
        $absolute = $this->resolve_existing( $relative );
        if ( ! is_file( $absolute ) || ! is_readable( $absolute ) ) {
            throw new RuntimeException( 'Preview file is unavailable.' );
        }

        $size = (int) filesize( $absolute );
        if ( $size > 104857600 ) {
            throw new RuntimeException( 'Files larger than 100 MB are not streamed in the inline preview.' );
        }

        $extension = strtolower( pathinfo( $absolute, PATHINFO_EXTENSION ) );
        if ( 'svg' === $extension || 'html' === $extension || 'htm' === $extension ) {
            throw new RuntimeException( 'This file type is not rendered inline for security reasons.' );
        }

        $mime = $this->detect_mime_type( $absolute );
        $allowed = array(
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif',
            'application/pdf',
            'audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/ogg', 'audio/mp4', 'audio/aac',
            'video/mp4', 'video/webm', 'video/quicktime', 'video/ogg',
        );
        if ( ! in_array( $mime, $allowed, true ) ) {
            throw new RuntimeException( 'This file type does not support secure inline preview.' );
        }

        return array(
            'path' => $absolute,
            'mime' => $mime,
            'size' => $size,
        );
    }

    public function preview_meta( string $relative ): array {
        $absolute = $this->resolve_existing( $relative );
        if ( ! is_file( $absolute ) ) {
            return array( 'previewable' => false, 'kind' => 'none', 'mime' => '' );
        }
        $extension = strtolower( pathinfo( $absolute, PATHINFO_EXTENSION ) );
        $mime = $this->detect_mime_type( $absolute );
        $kind = 'none';
        if ( in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' ), true ) ) {
            $kind = 'image';
        } elseif ( 'application/pdf' === $mime ) {
            $kind = 'pdf';
        } elseif ( str_starts_with( $mime, 'audio/' ) ) {
            $kind = 'audio';
        } elseif ( str_starts_with( $mime, 'video/' ) ) {
            $kind = 'video';
        } elseif ( $this->is_editable_file( $relative ) && 'svg' !== $extension ) {
            $kind = 'text';
        }
        return array(
            'previewable' => 'none' !== $kind,
            'kind'        => $kind,
            'mime'        => $mime,
        );
    }

    private function detect_mime_type( string $absolute ): string {
        $mime = '';
        if ( class_exists( 'finfo' ) ) {
            try {
                $finfo = new finfo( FILEINFO_MIME_TYPE );
                $found = $finfo->file( $absolute );
                if ( is_string( $found ) ) {
                    $mime = strtolower( trim( $found ) );
                }
            } catch ( Throwable $e ) {
                $mime = '';
            }
        }

        if ( ( '' === $mime || 'application/octet-stream' === $mime ) && function_exists( 'wp_check_filetype' ) ) {
            $checked = wp_check_filetype( basename( $absolute ) );
            if ( ! empty( $checked['type'] ) ) {
                $mime = strtolower( (string) $checked['type'] );
            }
        }

        $ext_map = array(
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
            'webp' => 'image/webp', 'avif' => 'image/avif', 'pdf' => 'application/pdf',
            'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4',
            'aac' => 'audio/aac', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
            'txt' => 'text/plain', 'css' => 'text/css', 'js' => 'text/javascript',
            'json' => 'application/json', 'php' => 'text/x-php', 'md' => 'text/markdown',
            'xml' => 'application/xml', 'yml' => 'text/yaml', 'yaml' => 'text/yaml',
        );
        $ext = strtolower( pathinfo( $absolute, PATHINFO_EXTENSION ) );
        if ( '' === $mime || 'application/octet-stream' === $mime ) {
            $mime = $ext_map[ $ext ] ?? 'application/octet-stream';
        }

        return $mime;
    }



    private function archive_job_dir( string $job_id ): string {
        $job_id = preg_replace( '/[^A-Za-z0-9_-]/', '', $job_id );
        if ( ! is_string( $job_id ) || strlen( $job_id ) < 8 || strlen( $job_id ) > 80 ) {
            throw new InvalidArgumentException( 'Invalid archive job id.' );
        }
        return $this->private_dir . '/archive-jobs/' . $job_id;
    }

    private function archive_job_update( string $job_id, array $changes ): void {
        $dir = $this->archive_job_dir( $job_id );
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            throw new RuntimeException( 'Unable to create archive job state.' );
        }
        $path = $dir . '/state.json';
        $state = array();
        if ( is_file( $path ) ) {
            $decoded = json_decode( (string) file_get_contents( $path ), true );
            if ( is_array( $decoded ) ) {
                $state = $decoded;
            }
        }
        $state = array_merge( $state, $changes, array( 'updated_at' => time() ) );
        if ( false === file_put_contents( $path, wp_json_encode( $state ), LOCK_EX ) ) {
            throw new RuntimeException( 'Unable to write archive job state.' );
        }
    }

    public function archive_job_state( string $job_id ): array {
        $dir = $this->archive_job_dir( $job_id );
        $path = $dir . '/state.json';
        if ( ! is_file( $path ) ) {
            return array( 'job_id' => $job_id, 'status' => 'pending', 'progress' => 0 );
        }
        $state = json_decode( (string) file_get_contents( $path ), true );
        if ( ! is_array( $state ) ) {
            throw new RuntimeException( 'Archive job state is invalid.' );
        }
        unset( $state['private_path'] );
        return $state;
    }

    public function cancel_archive_job( string $job_id ): array {
        $dir = $this->archive_job_dir( $job_id );
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            throw new RuntimeException( 'Unable to create archive job state.' );
        }
        file_put_contents( $dir . '/cancel', '1', LOCK_EX );
        $this->archive_job_update( $job_id, array( 'cancel_requested' => true ) );
        return array( 'job_id' => $job_id, 'cancel_requested' => true );
    }

    private function archive_job_assert_not_cancelled( string $job_id ): void {
        if ( is_file( $this->archive_job_dir( $job_id ) . '/cancel' ) ) {
            throw new RuntimeException( 'Archive operation cancelled.' );
        }
    }

    private function archive_format( string $format ): string {
        $format = strtolower( trim( $format ) );
        if ( ! in_array( $format, array( 'zip', 'tar', 'tar.gz' ), true ) ) {
            throw new InvalidArgumentException( 'Unsupported archive format.' );
        }
        if ( 'zip' === $format && ! class_exists( 'ZipArchive' ) ) {
            throw new RuntimeException( 'ZIP archives require the PHP ZipArchive extension.' );
        }
        if ( in_array( $format, array( 'tar', 'tar.gz' ), true ) && ! class_exists( 'PharData' ) ) {
            throw new RuntimeException( 'TAR archives require PHP PharData support.' );
        }
        return $format;
    }

    private function archive_safe_entry_name( string $name ): string {
        $name = str_replace( "\\", '/', trim( $name ) );
        $name = preg_replace( '#/+#', '/', $name );
        $name = ltrim( (string) $name, '/' );
        if ( '' === $name ) {
            return '';
        }
        if ( preg_match( '#^[A-Za-z]:/#', $name ) ) {
            throw new RuntimeException( 'Archive contains an absolute Windows path.' );
        }
        $parts = array();
        foreach ( explode( '/', $name ) as $part ) {
            if ( '' === $part || '.' === $part ) {
                continue;
            }
            if ( '..' === $part || str_contains( $part, "\0" ) ) {
                throw new RuntimeException( 'Archive contains an unsafe path.' );
            }
            $parts[] = $part;
        }
        return implode( '/', $parts );
    }

    private function unique_conflict_target( string $absolute ): string {
        if ( ! file_exists( $absolute ) && ! is_link( $absolute ) ) {
            return $absolute;
        }
        $dir = dirname( $absolute );
        $base = basename( $absolute );
        $ext = pathinfo( $base, PATHINFO_EXTENSION );
        $stem = $ext ? substr( $base, 0, -strlen( $ext ) - 1 ) : $base;
        for ( $i = 1; $i <= 9999; $i++ ) {
            $candidate = $dir . '/' . $stem . ' (' . $i . ')' . ( $ext ? '.' . $ext : '' );
            if ( ! file_exists( $candidate ) && ! is_link( $candidate ) ) {
                return wp_normalize_path( $candidate );
            }
        }
        throw new RuntimeException( 'Unable to find a unique conflict name.' );
    }

    public function create_archive( array $paths, string $destination_relative, string $name, string $format, string $job_id, bool $confirm_protected = false ): array {
        $lock = $this->acquire_private_lock( 'archive-' . $job_id );
        try { return $this->create_archive_unlocked( $paths, $destination_relative, $name, $format, $job_id, $confirm_protected ); }
        finally { $this->release_private_lock( $lock ); }
    }

    private function create_archive_unlocked( array $paths, string $destination_relative, string $name, string $format, string $job_id, bool $confirm_protected = false ): array {
        $paths = $this->normalize_path_list( $paths );
        if ( empty( $paths ) ) {
            throw new InvalidArgumentException( 'Select at least one file or folder to archive.' );
        }
        $format = $this->archive_format( $format );
        $destination = $this->resolve_existing( $destination_relative );
        if ( ! is_dir( $destination ) || ! is_writable( $destination ) ) {
            throw new RuntimeException( 'Archive destination is not writable.' );
        }
        $destination_relative = $this->relative_from_absolute( $destination );
        if ( $this->is_protected_path( $destination_relative ) && ! $confirm_protected ) {
            throw new DomainException( 'PROTECTED_CONFIRM_REQUIRED' );
        }
        $name = $this->validate_name( $name );
        $expected_ext = 'tar.gz' === $format ? '.tar.gz' : '.' . $format;
        if ( ! str_ends_with( strtolower( $name ), $expected_ext ) ) {
            $name .= $expected_ext;
        }
        $target = wp_normalize_path( $destination . '/' . $name );
        $this->assert_inside_root( $target );
        $this->assert_not_private_storage( $target );
        if ( file_exists( $target ) || is_link( $target ) ) {
            throw new RuntimeException( 'An archive with that name already exists.' );
        }

        $this->archive_job_update( $job_id, array( 'job_id' => $job_id, 'type' => 'create', 'status' => 'running', 'progress' => 1, 'label' => 'Preparing archive…' ) );
        $tmp_base = $this->private_dir . '/archive-jobs/' . preg_replace( '/[^A-Za-z0-9_-]/', '', $job_id ) . '/output';
        $created = '';
        try {
            if ( 'zip' === $format ) {
                $created = $tmp_base . '.zip';
                $zip = new ZipArchive();
                if ( true !== $zip->open( $created, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
                    throw new RuntimeException( 'Unable to create ZIP archive.' );
                }
                $total = count( $paths );
                foreach ( $paths as $index => $relative ) {
                    $this->archive_job_assert_not_cancelled( $job_id );
                    $absolute = $this->resolve_existing( $relative );
                    $this->add_to_zip( $zip, $absolute, basename( $absolute ), $job_id );
                    $this->archive_job_update( $job_id, array( 'progress' => max( 2, (int) floor( ( ( $index + 1 ) / $total ) * 88 ) ), 'label' => 'Adding ' . basename( $absolute ) ) );
                }
                if ( ! $zip->close() ) {
                    throw new RuntimeException( 'Unable to finalize ZIP archive.' );
                }
            } else {
                $tar_path = $tmp_base . '.tar';
                @unlink( $tar_path );
                $tar = new PharData( $tar_path );
                $total = count( $paths );
                foreach ( $paths as $index => $relative ) {
                    $this->archive_job_assert_not_cancelled( $job_id );
                    $absolute = $this->resolve_existing( $relative );
                    $this->add_to_phar( $tar, $absolute, basename( $absolute ), $job_id );
                    $this->archive_job_update( $job_id, array( 'progress' => max( 2, (int) floor( ( ( $index + 1 ) / $total ) * 82 ) ), 'label' => 'Adding ' . basename( $absolute ) ) );
                }
                unset( $tar );
                if ( 'tar.gz' === $format ) {
                    $this->archive_job_assert_not_cancelled( $job_id );
                    $tar = new PharData( $tar_path );
                    $gz = $tar->compress( Phar::GZ );
                    unset( $gz, $tar );
                    $created = $tar_path . '.gz';
                    @unlink( $tar_path );
                } else {
                    $created = $tar_path;
                }
            }
            $this->archive_job_assert_not_cancelled( $job_id );
            if ( ! is_file( $created ) ) {
                throw new RuntimeException( 'Archive output was not created.' );
            }
            if ( ! @rename( $created, $target ) ) {
                throw new RuntimeException( 'Unable to place the completed archive in the destination folder.' );
            }
            @chmod( $target, 0644 );
            $rel = $this->relative_from_absolute( $target );
            Nobash_Files_DB::add_operation( 'archive_create', 'Created archive ' . basename( $target ), $rel, array( 'type' => 'delete_paths', 'items' => array( $this->deletion_undo_item( $rel ) ) ) );
            Nobash_Files_DB::log( 'created_archive', $rel, array( 'format' => $format, 'sources' => $paths ) );
            $this->archive_job_update( $job_id, array( 'status' => 'complete', 'progress' => 100, 'label' => 'Archive created', 'result_path' => $rel ) );
            return array( 'job_id' => $job_id, 'file' => $this->file_info( $rel ) );
        } catch ( Throwable $e ) {
            if ( $created && is_file( $created ) ) {
                @unlink( $created );
            }
            @unlink( $tmp_base . '.tar' );
            @unlink( $tmp_base . '.tar.gz' );
            @unlink( $tmp_base . '.zip' );
            $status = 'Archive operation cancelled.' === $e->getMessage() ? 'cancelled' : 'failed';
            $this->archive_job_update( $job_id, array( 'status' => $status, 'label' => $e->getMessage(), 'error' => $e->getMessage() ) );
            throw $e;
        }
    }


    private function validate_tar_structure( string $absolute ): array {
        $lower = strtolower( basename( $absolute ) );
        $gzip = str_ends_with( $lower, '.tar.gz' ) || str_ends_with( $lower, '.tgz' );
        $handle = $gzip ? @gzopen( $absolute, 'rb' ) : @fopen( $absolute, 'rb' );
        if ( false === $handle ) {
            throw new RuntimeException( 'Unable to open TAR archive.' );
        }
        $read = static function ( $handle, int $length, bool $gzip ): string {
            $data = '';
            while ( strlen( $data ) < $length ) {
                $chunk = $gzip ? gzread( $handle, $length - strlen( $data ) ) : fread( $handle, $length - strlen( $data ) );
                if ( false === $chunk || '' === $chunk ) break;
                $data .= $chunk;
            }
            return $data;
        };
        $skip = static function ( $handle, int $length, bool $gzip, callable $reader ): void {
            while ( $length > 0 ) {
                $chunk = $reader( $handle, min( 1048576, $length ), $gzip );
                if ( '' === $chunk ) throw new RuntimeException( 'TAR archive ended unexpectedly.' );
                $length -= strlen( $chunk );
            }
        };
        $parse_pax = static function ( string $payload ): array {
            $out = array(); $offset = 0; $len = strlen( $payload );
            while ( $offset < $len ) {
                $space = strpos( $payload, ' ', $offset );
                if ( false === $space ) break;
                $record_len = (int) substr( $payload, $offset, $space - $offset );
                if ( $record_len <= 0 || $offset + $record_len > $len ) break;
                $record = substr( $payload, $space + 1, $record_len - ( $space - $offset ) - 1 );
                $record = rtrim( $record, "\n" );
                $eq = strpos( $record, '=' );
                if ( false !== $eq ) $out[ substr( $record, 0, $eq ) ] = substr( $record, $eq + 1 );
                $offset += $record_len;
            }
            return $out;
        };

        $entries = array(); $total = 0; $count = 0; $long_name = null; $pax = array();
        try {
            while ( true ) {
                $header = $read( $handle, 512, $gzip );
                if ( '' === $header ) break;
                if ( 512 !== strlen( $header ) ) throw new RuntimeException( 'TAR archive has a truncated header.' );
                if ( "" === trim( $header, "\0" ) ) break;
                $name = rtrim( substr( $header, 0, 100 ), "\0 " );
                $prefix = rtrim( substr( $header, 345, 155 ), "\0 " );
                if ( '' !== $prefix ) $name = $prefix . '/' . $name;
                $type = substr( $header, 156, 1 );
                $size_field = trim( substr( $header, 124, 12 ), "\0 " );
                $size = '' === $size_field ? 0 : intval( $size_field, 8 );
                if ( $size < 0 ) throw new RuntimeException( 'TAR archive contains an invalid entry size.' );
                $padded = (int) ( ceil( $size / 512 ) * 512 );

                if ( 'L' === $type ) {
                    $payload = $read( $handle, $padded, $gzip );
                    if ( strlen( $payload ) !== $padded ) throw new RuntimeException( 'TAR long-name record is truncated.' );
                    $long_name = rtrim( substr( $payload, 0, $size ), "\0\n" );
                    $this->archive_safe_entry_name( $long_name );
                    continue;
                }
                if ( 'K' === $type ) {
                    throw new RuntimeException( 'Archive contains link metadata, which is blocked.' );
                }
                if ( 'x' === $type || 'g' === $type ) {
                    $payload = $read( $handle, $padded, $gzip );
                    if ( strlen( $payload ) !== $padded ) throw new RuntimeException( 'TAR PAX metadata is truncated.' );
                    $parsed = $parse_pax( substr( $payload, 0, $size ) );
                    if ( isset( $parsed['path'] ) ) $this->archive_safe_entry_name( (string) $parsed['path'] );
                    if ( isset( $parsed['linkpath'] ) ) throw new RuntimeException( 'Archive contains link metadata, which is blocked.' );
                    if ( 'x' === $type ) $pax = $parsed;
                    continue;
                }
                if ( in_array( $type, array( '1', '2', '3', '4', '6' ), true ) ) {
                    throw new RuntimeException( 'Archive contains a symbolic-link, hard-link, device, or special entry, which is blocked.' );
                }
                if ( ! in_array( $type, array( "\0", '', '0', '5', '7' ), true ) ) {
                    throw new RuntimeException( 'Archive contains an unsupported TAR entry type.' );
                }
                $effective = isset( $pax['path'] ) ? (string) $pax['path'] : ( null !== $long_name ? $long_name : $name );
                $pax = array(); $long_name = null;
                $effective = $this->archive_safe_entry_name( $effective );
                if ( '' === $effective ) {
                    $skip( $handle, $padded, $gzip, $read );
                    continue;
                }
                $count++;
                if ( $count > 5000 ) throw new RuntimeException( 'Archive contains too many entries.' );
                $is_dir = '5' === $type || str_ends_with( $effective, '/' );
                if ( ! $is_dir ) {
                    $total += $size;
                    if ( $total > 2147483648 ) throw new RuntimeException( 'Archive expands beyond the 2 GB safety ceiling.' );
                }
                $entries[] = array( 'name' => rtrim( $effective, '/' ), 'size' => $is_dir ? 0 : $size, 'directory' => $is_dir );
                $skip( $handle, $padded, $gzip, $read );
            }
        } finally {
            $gzip ? gzclose( $handle ) : fclose( $handle );
        }
        return array( 'entries' => $entries, 'total_uncompressed' => $total, 'truncated' => false );
    }

    public function inspect_archive( string $relative ): array {
        $absolute = $this->resolve_existing( $relative );
        if ( ! is_file( $absolute ) ) {
            throw new InvalidArgumentException( 'Archive path must be a file.' );
        }
        $lower = strtolower( basename( $absolute ) );
        $entries = array();
        $total_size = 0;
        $compressed_size = 0;
        if ( str_ends_with( $lower, '.zip' ) ) {
            if ( ! class_exists( 'ZipArchive' ) ) {
                throw new RuntimeException( 'ZIP archives require the PHP ZipArchive extension.' );
            }
            $zip = new ZipArchive();
            if ( true !== $zip->open( $absolute ) ) {
                throw new RuntimeException( 'Unable to open ZIP archive.' );
            }
            try {
                $limit = min( $zip->numFiles, 5000 );
                for ( $i = 0; $i < $limit; $i++ ) {
                    $stat = $zip->statIndex( $i );
                    if ( ! is_array( $stat ) ) continue;
                    $name = $this->archive_safe_entry_name( (string) $stat['name'] );
                    if ( '' === $name ) continue;
                    $size = (int) ( $stat['size'] ?? 0 );
                    $comp = max( 0, (int) ( $stat['comp_size'] ?? $size ) );
                    $total_size += $size;
                    $compressed_size += $comp;
                    $entries[] = array( 'name' => $name, 'size' => $size, 'directory' => str_ends_with( (string) $stat['name'], '/' ) );
                }
                $truncated = $zip->numFiles > $limit;
            } finally {
                $zip->close();
            }
        } elseif ( str_ends_with( $lower, '.tar' ) || str_ends_with( $lower, '.tar.gz' ) || str_ends_with( $lower, '.tgz' ) ) {
            if ( ! class_exists( 'PharData' ) ) {
                throw new RuntimeException( 'TAR archives require PHP PharData support.' );
            }
            $validated = $this->validate_tar_structure( $absolute );
            $entries = $validated['entries'];
            $total_size = (int) $validated['total_uncompressed'];
            $truncated = (bool) $validated['truncated'];
            $compressed_size = max( 1, (int) filesize( $absolute ) );
        } else {
            throw new InvalidArgumentException( 'Supported archive types are ZIP, TAR, TAR.GZ, and TGZ.' );
        }
        $ratio = $compressed_size > 0 ? ( $total_size / max( 1, $compressed_size ) ) : ( $total_size > 0 ? INF : 1.0 );
        return array( 'path' => $this->relative_from_absolute( $absolute ), 'name' => basename( $absolute ), 'entries' => $entries, 'entry_count' => count( $entries ), 'total_uncompressed' => $total_size, 'compressed_bytes' => $compressed_size, 'compression_ratio' => is_finite( $ratio ) ? round( $ratio, 2 ) : 999999.0, 'truncated' => (bool) $truncated );
    }

    private function extract_target_for_entry( string $destination, string $entry_name, string $conflict, bool $entry_is_dir, array &$overwritten ): ?string {
        $target = wp_normalize_path( $destination . '/' . $entry_name );
        $this->assert_inside_root( $target );
        $this->assert_not_private_storage( $target );
        $this->assert_no_symlink_components( $target );
        if ( ! file_exists( $target ) && ! is_link( $target ) ) {
            return $target;
        }
        if ( 'skip' === $conflict ) {
            return null;
        }
        if ( 'rename' === $conflict ) {
            $target = $this->unique_conflict_target( $target );
            $this->assert_no_symlink_components( $target );
            return $target;
        }
        if ( 'overwrite' !== $conflict ) {
            throw new InvalidArgumentException( 'Unsupported archive conflict policy.' );
        }
        if ( $entry_is_dir && is_dir( $target ) && ! is_link( $target ) ) {
            return $target; // Safely merge into an existing directory; child conflicts are handled individually.
        }
        $rel = $this->relative_from_absolute( $target );
        $snapshot = $this->create_snapshot( $target, $rel );
        if ( ! $this->remove_absolute( $target ) ) {
            $this->remove_private_directory( $this->snapshot_dir( $snapshot ) );
            throw new RuntimeException( 'Unable to replace an existing extraction target.' );
        }
        $overwritten[] = array( 'path' => $rel, 'snapshot' => $snapshot, 'fingerprint' => '' );
        return $target;
    }


    private function ensure_extraction_parent( string $destination, string $target, array &$created ): void {
        $destination = rtrim( wp_normalize_path( $destination ), '/' );
        $parent = rtrim( wp_normalize_path( dirname( $target ) ), '/' );
        $this->assert_no_symlink_components( $destination );
        $this->assert_no_symlink_components( $parent );
        if ( $parent === $destination || is_dir( $parent ) ) {
            return;
        }
        $relative = ltrim( substr( $parent, strlen( $destination ) ), '/' );
        $cursor = $destination;
        foreach ( explode( '/', $relative ) as $part ) {
            if ( '' === $part ) continue;
            $cursor .= '/' . $part;
            if ( is_link( $cursor ) ) {
                throw new RuntimeException( 'Symbolic-link paths are blocked for archive extraction.' );
            }
            if ( ! file_exists( $cursor ) ) {
                if ( ! @mkdir( $cursor, 0755 ) ) throw new RuntimeException( 'Unable to create archive extraction folder.' );
                $created[] = $this->relative_from_absolute( $cursor );
            } elseif ( ! is_dir( $cursor ) ) {
                throw new RuntimeException( 'Archive extraction parent conflicts with an existing file.' );
            }
        }
        $this->assert_no_symlink_components( $parent );
    }

    private function write_archive_stream( $stream, string $target, int &$expanded_written, int $max_expanded ): void {
        $parent = dirname( $target );
        $this->assert_no_symlink_components( $target );
        if ( ! is_dir( $parent ) && ! wp_mkdir_p( $parent ) ) {
            throw new RuntimeException( 'Unable to create archive extraction folder.' );
        }
        $tmp = $parent . '/.' . basename( $target ) . '.nobash-files-extract-' . wp_generate_password( 8, false, false ) . '.tmp';
        $out = fopen( $tmp, 'wb' );
        if ( false === $out ) throw new RuntimeException( 'Unable to create extracted file.' );
        try {
            while ( ! feof( $stream ) ) {
                $chunk = fread( $stream, 1048576 );
                if ( false === $chunk ) { throw new RuntimeException( 'Unable to read archive entry.' ); }
                if ( '' === $chunk ) { break; }
                $expanded_written += strlen( $chunk );
                if ( $expanded_written > $max_expanded ) {
                    throw new RuntimeException( 'Archive extraction exceeded the expanded-size safety ceiling while streaming.' );
                }
                $written = fwrite( $out, $chunk );
                if ( false === $written || $written !== strlen( $chunk ) ) {
                    throw new RuntimeException( 'Unable to extract archive entry completely.' );
                }
            }
            @fflush( $out );
            if ( function_exists( 'fsync' ) ) { @fsync( $out ); }
        } catch ( Throwable $e ) {
            fclose( $out );
            @unlink( $tmp );
            throw $e;
        }
        fclose( $out );
        @chmod( $tmp, 0644 );
        try {
            $this->assert_no_symlink_components( $target );
        } catch ( Throwable $e ) {
            @unlink( $tmp );
            throw $e;
        }
        if ( ! @rename( $tmp, $target ) ) {
            @unlink( $tmp );
            throw new RuntimeException( 'Unable to finalize extracted file.' );
        }
    }

    public function extract_archive( string $relative, string $destination_relative, string $conflict, string $job_id, bool $confirm_protected = false ): array {
        $job_lock = $this->acquire_private_lock( 'archive-' . $job_id );
        try {
            $mutation_lock = $this->acquire_private_lock( 'filesystem-mutation' );
            try { return $this->extract_archive_unlocked( $relative, $destination_relative, $conflict, $job_id, $confirm_protected ); }
            finally { $this->release_private_lock( $mutation_lock ); }
        } finally { $this->release_private_lock( $job_lock ); }
    }

    private function extract_archive_unlocked( string $relative, string $destination_relative, string $conflict, string $job_id, bool $confirm_protected = false ): array {
        $archive = $this->resolve_existing( $relative );
        if ( ! is_file( $archive ) ) throw new InvalidArgumentException( 'Archive path must be a file.' );
        $destination = $this->resolve_existing( $destination_relative );
        if ( ! is_dir( $destination ) || ! is_writable( $destination ) ) throw new RuntimeException( 'Extraction destination is not writable.' );
        $destination_relative = $this->relative_from_absolute( $destination );
        if ( $this->is_protected_path( $destination_relative ) && ! $confirm_protected ) throw new DomainException( 'PROTECTED_CONFIRM_REQUIRED' );
        $conflict = strtolower( $conflict );
        if ( ! in_array( $conflict, array( 'skip', 'rename', 'overwrite' ), true ) ) throw new InvalidArgumentException( 'Unsupported archive conflict policy.' );

        $meta = $this->inspect_archive( $relative );
        if ( $meta['truncated'] || $meta['entry_count'] > 5000 ) throw new RuntimeException( 'Archive contains too many entries to extract safely.' );
        $max_expanded = max( 268435456, (int) apply_filters( 'nobash_files_archive_max_expanded_bytes', 2147483648 ) );
        if ( (int) $meta['total_uncompressed'] > $max_expanded ) throw new RuntimeException( 'Archive expands beyond the configured 2 GB safety ceiling.' );
        $max_ratio = max( 50.0, (float) apply_filters( 'nobash_files_archive_max_compression_ratio', 500.0 ) );
        if ( (int) $meta['total_uncompressed'] >= 67108864 && (float) ( $meta['compression_ratio'] ?? 1.0 ) > $max_ratio ) {
            throw new RuntimeException( 'Archive compression ratio is unusually high and was blocked as a decompression-bomb precaution.' );
        }
        $expanded_written = 0;
        $this->archive_job_update( $job_id, array( 'job_id' => $job_id, 'type' => 'extract', 'status' => 'running', 'progress' => 1, 'label' => 'Inspecting archive…' ) );

        $created = array();
        $overwritten = array();
        $skipped = 0;
        $lower = strtolower( basename( $archive ) );
        try {
            if ( str_ends_with( $lower, '.zip' ) ) {
                $zip = new ZipArchive();
                if ( true !== $zip->open( $archive ) ) throw new RuntimeException( 'Unable to open ZIP archive.' );
                try {
                    $total = max( 1, $zip->numFiles );
                    for ( $i = 0; $i < $zip->numFiles; $i++ ) {
                        $this->archive_job_assert_not_cancelled( $job_id );
                        $stat = $zip->statIndex( $i );
                        if ( ! is_array( $stat ) ) continue;
                        $raw = (string) $stat['name'];
                        $name = $this->archive_safe_entry_name( $raw );
                        if ( '' === $name ) continue;
                        if ( method_exists( $zip, 'getExternalAttributesIndex' ) ) {
                            $opsys = 0; $attr = 0;
                            if ( $zip->getExternalAttributesIndex( $i, $opsys, $attr ) && 3 === $opsys && ( ( $attr >> 16 ) & 0170000 ) === 0120000 ) {
                                throw new RuntimeException( 'Archive contains a symbolic-link entry, which is blocked.' );
                            }
                        }
                        $is_dir = str_ends_with( $raw, '/' );
                        $existed_before = file_exists( wp_normalize_path( $destination . '/' . $name ) ) || is_link( wp_normalize_path( $destination . '/' . $name ) );
                        $target = $this->extract_target_for_entry( $destination, $name, $conflict, $is_dir, $overwritten );
                        if ( null === $target ) { $skipped++; continue; }
                        if ( $is_dir ) {
                            if ( ! is_dir( $target ) && ! wp_mkdir_p( $target ) ) throw new RuntimeException( 'Unable to create extracted folder.' );
                            if ( ! $existed_before ) $created[] = $this->relative_from_absolute( $target );
                        } else {
                            $this->ensure_extraction_parent( $destination, $target, $created );
                            $stream = $zip->getStream( $raw );
                            if ( false === $stream ) throw new RuntimeException( 'Unable to read archive entry.' );
                            try { $this->write_archive_stream( $stream, $target, $expanded_written, $max_expanded ); } finally { fclose( $stream ); }
                            $target_rel = $this->relative_from_absolute( $target );
                            $overwritten_index = null;
                            foreach ( $overwritten as $oi => $ov ) { if ( $ov['path'] === $target_rel && '' === $ov['fingerprint'] ) { $overwritten_index = $oi; break; } }
                            if ( null !== $overwritten_index ) $overwritten[ $overwritten_index ]['fingerprint'] = $this->fingerprint_absolute( $target );
                            elseif ( ! $existed_before || 'rename' === $conflict ) $created[] = $target_rel;
                        }
                        $this->archive_job_update( $job_id, array( 'progress' => max( 2, (int) floor( ( ( $i + 1 ) / $total ) * 96 ) ), 'label' => 'Extracting ' . basename( $name ) ) );
                    }
                } finally { $zip->close(); }
            } else {
                if ( ! class_exists( 'PharData' ) ) throw new RuntimeException( 'TAR archives require PHP PharData support.' );
                $phar = new PharData( $archive );
                $prefix = 'phar://' . wp_normalize_path( $archive ) . '/';
                $entries = iterator_to_array( new RecursiveIteratorIterator( $phar, RecursiveIteratorIterator::SELF_FIRST ), false );
                $total = max( 1, count( $entries ) );
                foreach ( $entries as $i => $entry ) {
                    $this->archive_job_assert_not_cancelled( $job_id );
                    if ( method_exists( $entry, 'isLink' ) && $entry->isLink() ) throw new RuntimeException( 'Archive contains a symbolic-link entry, which is blocked.' );
                    $pathname = wp_normalize_path( $entry->getPathname() );
                    $name = str_starts_with( $pathname, $prefix ) ? substr( $pathname, strlen( $prefix ) ) : basename( $pathname );
                    $name = $this->archive_safe_entry_name( $name );
                    if ( '' === $name ) continue;
                    $entry_is_dir = $entry->isDir();
                    $existed_before = file_exists( wp_normalize_path( $destination . '/' . $name ) ) || is_link( wp_normalize_path( $destination . '/' . $name ) );
                    $target = $this->extract_target_for_entry( $destination, $name, $conflict, $entry_is_dir, $overwritten );
                    if ( null === $target ) { $skipped++; continue; }
                    if ( $entry_is_dir ) {
                        if ( ! is_dir( $target ) && ! wp_mkdir_p( $target ) ) throw new RuntimeException( 'Unable to create extracted folder.' );
                        if ( ! $existed_before ) $created[] = $this->relative_from_absolute( $target );
                    } else {
                        $this->ensure_extraction_parent( $destination, $target, $created );
                        $stream = fopen( $entry->getPathname(), 'rb' );
                        if ( false === $stream ) throw new RuntimeException( 'Unable to read TAR entry.' );
                        try { $this->write_archive_stream( $stream, $target, $expanded_written, $max_expanded ); } finally { fclose( $stream ); }
                        $target_rel = $this->relative_from_absolute( $target );
                        $overwritten_index = null;
                        foreach ( $overwritten as $oi => $ov ) { if ( $ov['path'] === $target_rel && '' === $ov['fingerprint'] ) { $overwritten_index = $oi; break; } }
                        if ( null !== $overwritten_index ) $overwritten[ $overwritten_index ]['fingerprint'] = $this->fingerprint_absolute( $target );
                        elseif ( ! $existed_before || 'rename' === $conflict ) $created[] = $target_rel;
                    }
                    $this->archive_job_update( $job_id, array( 'progress' => max( 2, (int) floor( ( ( $i + 1 ) / $total ) * 96 ) ), 'label' => 'Extracting ' . basename( $name ) ) );
                }
            }
            if ( ! empty( $created ) || ! empty( $overwritten ) ) {
                $created = array_values( array_unique( $created ) );
                // Keep only top-most created paths; deleting a created parent also removes its extracted children.
                usort( $created, static fn( string $a, string $b ): int => substr_count( $a, '/' ) <=> substr_count( $b, '/' ) );
                $top_created = array();
                foreach ( $created as $candidate ) {
                    $nested = false;
                    foreach ( $top_created as $parent_created ) {
                        if ( str_starts_with( $candidate . '/', rtrim( $parent_created, '/' ) . '/' ) ) { $nested = true; break; }
                    }
                    if ( ! $nested ) $top_created[] = $candidate;
                }
                $undo_items = array_map( fn( string $path ): array => $this->deletion_undo_item( $path ), $top_created );
                Nobash_Files_DB::add_operation( 'archive_extract', 'Extracted ' . basename( $archive ), $destination_relative, array( 'type' => 'restore_extraction', 'created' => $undo_items, 'overwritten' => $overwritten ) );
            }
            Nobash_Files_DB::log( 'extracted_archive', $this->relative_from_absolute( $archive ), array( 'destination' => $destination_relative, 'created' => count( $created ), 'overwritten' => count( $overwritten ), 'skipped' => $skipped, 'conflict' => $conflict ) );
            $this->archive_job_update( $job_id, array( 'status' => 'complete', 'progress' => 100, 'label' => 'Archive extracted', 'created' => count( $created ), 'overwritten' => count( $overwritten ), 'skipped' => $skipped ) );
            return array( 'job_id' => $job_id, 'created' => count( $created ), 'overwritten' => count( $overwritten ), 'skipped' => $skipped, 'destination' => $destination_relative );
        } catch ( Throwable $e ) {
            // Failed/cancelled extraction must not leave a half-applied filesystem state.
            $rollback_errors = array();
            $rollback_created = array_values( array_unique( $created ) );
            usort( $rollback_created, static fn( string $a, string $b ): int => substr_count( $b, '/' ) <=> substr_count( $a, '/' ) );
            foreach ( $rollback_created as $path ) {
                try {
                    $absolute = $this->resolve_existing( $path );
                    if ( ! $this->remove_absolute( $absolute ) ) $rollback_errors[] = $path;
                } catch ( Throwable $ignored ) {
                    // It may already have disappeared as part of removing a created parent.
                }
            }
            foreach ( array_reverse( $overwritten ) as $item ) {
                try {
                    $path = $this->normalize_relative( (string) ( $item['path'] ?? '' ) );
                    try {
                        $current = $this->resolve_existing( $path );
                        $this->remove_absolute( $current );
                    } catch ( Throwable $ignored ) {
                        // Target may not have been recreated yet.
                    }
                    $this->restore_snapshot( (string) ( $item['snapshot'] ?? '' ), $path );
                    $dir = $this->snapshot_dir( (string) ( $item['snapshot'] ?? '' ) );
                    if ( is_dir( $dir ) ) $this->remove_private_directory( $dir );
                } catch ( Throwable $rollback_error ) {
                    $rollback_errors[] = (string) ( $item['path'] ?? 'unknown' );
                }
            }
            $status = 'Archive operation cancelled.' === $e->getMessage() ? 'cancelled' : 'failed';
            $label = $e->getMessage();
            if ( ! empty( $rollback_errors ) ) {
                $label .= ' Rollback needs attention for: ' . implode( ', ', array_slice( $rollback_errors, 0, 5 ) );
            }
            $this->archive_job_update( $job_id, array( 'status' => $status, 'label' => $label, 'error' => $label, 'created' => count( $created ), 'overwritten' => count( $overwritten ), 'skipped' => $skipped ) );
            throw new RuntimeException( $label, 0, $e );
        }
    }

    public function trash_entries(): array {
        return Nobash_Files_DB::trash_entries( 100 );
    }

    public function restore_trash_operation( int $operation_id ): array {
        $lock = $this->acquire_private_lock( 'operation-user-' . ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 ) );
        try {
            $mutation_lock = $this->acquire_private_lock( 'filesystem-mutation' );
            try { return $this->restore_trash_operation_unlocked( $operation_id ); }
            finally { $this->release_private_lock( $mutation_lock ); }
        } finally { $this->release_private_lock( $lock ); }
    }

    private function restore_trash_operation_unlocked( int $operation_id ): array {
        $operation = Nobash_Files_DB::get_operation( $operation_id );
        if ( ! $operation || 'trash' !== $operation['action'] || ! empty( $operation['undone_at'] ) ) {
            throw new RuntimeException( 'Trash item is no longer available.' );
        }
        $payload = $operation['undo_payload'];
        if ( 'restore_snapshots' !== ( $payload['type'] ?? '' ) ) throw new RuntimeException( 'Trash recovery data is invalid.' );
        foreach ( (array) ( $payload['items'] ?? array() ) as $item ) {
            $path = $this->normalize_relative( (string) ( $item['path'] ?? '' ) );
            $parent_rel = $this->parent_path( $path ) ?? '';
            $parent = $this->resolve_existing( $parent_rel );
            $target = wp_normalize_path( $parent . '/' . basename( $path ) );
            if ( file_exists( $target ) || is_link( $target ) ) throw new RuntimeException( 'Cannot restore ' . $path . ' because a new item exists there.' );
            $this->restore_snapshot( (string) ( $item['snapshot'] ?? '' ), $path );
        }
        Nobash_Files_DB::mark_undone( $operation_id );
        Nobash_Files_DB::log( 'restored_from_trash', (string) $operation['relative_path'], array( 'operation_id' => $operation_id ) );
        $this->cleanup_undo_payload( $payload );
        return array( 'restored' => true, 'operation_id' => $operation_id );
    }

    public function purge_trash_operation( int $operation_id ): array {
        $lock = $this->acquire_private_lock( 'operation-user-' . ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 ) );
        try {
            $mutation_lock = $this->acquire_private_lock( 'filesystem-mutation' );
            try { return $this->purge_trash_operation_unlocked( $operation_id ); }
            finally { $this->release_private_lock( $mutation_lock ); }
        } finally { $this->release_private_lock( $lock ); }
    }

    private function purge_trash_operation_unlocked( int $operation_id ): array {
        $operation = Nobash_Files_DB::get_operation( $operation_id );
        if ( ! $operation || 'trash' !== $operation['action'] || ! empty( $operation['undone_at'] ) ) throw new RuntimeException( 'Trash item is no longer available.' );
        $payload = $operation['undo_payload'];
        $this->cleanup_undo_payload( is_array( $payload ) ? $payload : array() );
        Nobash_Files_DB::mark_undone( $operation_id );
        Nobash_Files_DB::log( 'permanently_deleted_trash', (string) $operation['relative_path'], array( 'operation_id' => $operation_id ) );
        return array( 'purged' => true, 'operation_id' => $operation_id );
    }

    public function empty_trash(): array {
        $entries = Nobash_Files_DB::trash_entries( 500 );
        $count = 0;
        foreach ( $entries as $entry ) {
            try {
                $this->purge_trash_operation( (int) $entry['id'] );
                $count++;
            } catch ( Throwable $ignored ) {
                continue;
            }
        }
        return array( 'purged' => $count );
    }

    public function is_protected_path( string $relative ): bool {
        $relative = $this->normalize_relative( $relative );
        if ( '' === $relative ) {
            return true;
        }
        $name = strtolower( basename( $relative ) );
        if ( in_array( $name, array( 'wp-config.php', '.htaccess', '.user.ini', 'web.config' ), true ) ) {
            return true;
        }
        if ( str_starts_with( $relative . '/', 'wp-admin/' ) || str_starts_with( $relative . '/', 'wp-includes/' ) ) {
            return true;
        }
        if ( ! str_contains( $relative, '/' ) && ( str_starts_with( strtolower( $name ), 'wp-' ) || in_array( $name, array( 'index.php', 'xmlrpc.php' ), true ) ) ) {
            return true;
        }
        $plugin_real = realpath( NOBASH_FILES_DIR );
        if ( false !== $plugin_real ) {
            $plugin_real = rtrim( wp_normalize_path( $plugin_real ), '/' );
            if ( str_starts_with( $plugin_real . '/', $this->root . '/' ) ) {
                $plugin_rel = ltrim( substr( $plugin_real, strlen( $this->root ) ), '/' );
                if ( $relative === $plugin_rel || str_starts_with( $relative . '/', $plugin_rel . '/' ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    private function is_editable_file( string $relative ): bool {
        $name = strtolower( basename( $relative ) );
        if ( in_array( $name, array( '.htaccess', '.user.ini', 'web.config' ), true ) ) {
            return true;
        }
        $ext = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );
        return in_array( $ext, array( 'php', 'css', 'js', 'json', 'html', 'htm', 'txt', 'md', 'xml', 'svg', 'yml', 'yaml', 'ini', 'conf', 'twig' ), true );
    }

    private function language_for( string $relative ): string {
        $name = strtolower( basename( $relative ) );
        if ( '.htaccess' === $name ) {
            return 'apache';
        }
        $ext = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );
        return $ext ?: 'text';
    }
}
