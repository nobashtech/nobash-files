<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Nobash_Files_Access {
    private const ROLE_OPTION = 'nobash_files_access_role_policies';
    private const USER_OPTION = 'nobash_files_access_user_policies';
    private const CAPABILITY  = 'nobash_files_use_file_manager';

    private const PERMISSIONS = array(
        'browse', 'read', 'download', 'upload', 'create', 'edit', 'rename', 'copy', 'move', 'delete',
        'archive', 'extract', 'search', 'versions', 'restore', 'undo', 'trash', 'activity'
    );

    public static function init(): void {
        add_filter( 'user_has_cap', array( __CLASS__, 'virtual_capability' ), 20, 4 );
    }

    public static function capability(): string {
        return self::CAPABILITY;
    }

    public static function virtual_capability( array $allcaps, array $caps, array $args, WP_User $user ): array {
        if ( ! in_array( self::CAPABILITY, $caps, true ) && self::CAPABILITY !== ( $args[0] ?? '' ) ) {
            return $allcaps;
        }
        if ( ! empty( $allcaps['manage_options'] ) || self::has_enabled_policy( $user ) ) {
            $allcaps[ self::CAPABILITY ] = true;
        }
        return $allcaps;
    }

    public static function can_use( ?WP_User $user = null ): bool {
        $user = $user ?: wp_get_current_user();
        if ( ! $user || ! $user->exists() ) {
            return false;
        }
        if ( user_can( $user, 'manage_options' ) ) {
            return true;
        }
        return self::has_enabled_policy( $user );
    }

    private static function has_enabled_policy( WP_User $user ): bool {
        if ( ! (bool) apply_filters( 'nobash_files_delegated_access_enabled', true, $user ) ) {
            return false;
        }
        $direct = self::user_policies();
        if ( isset( $direct[ (string) $user->ID ] ) ) {
            return ! empty( $direct[ (string) $user->ID ]['enabled'] );
        }
        $roles = self::role_policies();
        foreach ( (array) $user->roles as $role ) {
            if ( ! empty( $roles[ $role ]['enabled'] ) ) {
                return true;
            }
        }
        return false;
    }

    public static function permission_keys(): array {
        return self::PERMISSIONS;
    }

    public static function full_policy(): array {
        return array(
            'enabled'         => true,
            'roots'           => array( '' ),
            'denied_paths'    => array(),
            'allow_protected' => true,
            'permissions'     => array_fill_keys( self::PERMISSIONS, true ),
            'source'          => 'administrator',
        );
    }

    public static function effective_policy( ?WP_User $user = null ): array {
        $user = $user ?: wp_get_current_user();
        $enabled = self::policies_for_user( $user );
        if ( empty( $enabled ) ) {
            return self::empty_policy();
        }
        if ( 1 === count( $enabled ) ) {
            return $enabled[0];
        }

        $merged = self::empty_policy();
        $merged['enabled'] = true;
        $merged['source']  = 'role';
        foreach ( $enabled as $policy ) {
            $merged['roots'] = array_merge( $merged['roots'], $policy['roots'] );
            $merged['denied_paths'] = array_merge( $merged['denied_paths'], $policy['denied_paths'] );
            $merged['allow_protected'] = $merged['allow_protected'] || $policy['allow_protected'];
            foreach ( self::PERMISSIONS as $permission ) {
                $merged['permissions'][ $permission ] = $merged['permissions'][ $permission ] || ! empty( $policy['permissions'][ $permission ] );
            }
        }
        $merged['roots'] = self::compact_roots( array_values( array_unique( $merged['roots'] ) ) );
        $merged['denied_paths'] = array_values( array_unique( $merged['denied_paths'] ) );
        return $merged;
    }

    /**
     * Return atomic policies for authorization decisions.
     *
     * The flattened effective policy is useful for presenting a combined UI,
     * but it must never be used to combine a permission from one role with a
     * filesystem root (or protected-file grant) from another role.
     *
     * @return array<int,array>
     */
    private static function policies_for_user( ?WP_User $user = null ): array {
        $user = $user ?: wp_get_current_user();
        if ( ! $user || ! $user->exists() ) {
            return array();
        }
        if ( user_can( $user, 'manage_options' ) ) {
            return array( self::full_policy() );
        }
        if ( ! (bool) apply_filters( 'nobash_files_delegated_access_enabled', true, $user ) ) {
            return array();
        }

        $direct = self::user_policies();
        $key = (string) $user->ID;
        if ( isset( $direct[ $key ] ) ) {
            $policy = self::sanitize_policy( $direct[ $key ] );
            if ( empty( $policy['enabled'] ) ) {
                return array();
            }
            $policy['source'] = 'user';
            return array( $policy );
        }

        $role_policies = self::role_policies();
        $enabled = array();
        foreach ( (array) $user->roles as $role ) {
            if ( ! isset( $role_policies[ $role ] ) ) {
                continue;
            }
            $policy = self::sanitize_policy( $role_policies[ $role ] );
            if ( ! empty( $policy['enabled'] ) ) {
                $policy['source'] = 'role';
                $enabled[] = $policy;
            }
        }
        return $enabled;
    }

    private static function empty_policy(): array {
        return array(
            'enabled'         => false,
            'roots'           => array(),
            'denied_paths'    => array(),
            'allow_protected' => false,
            'permissions'     => array_fill_keys( self::PERMISSIONS, false ),
            'source'          => 'none',
        );
    }

    public static function role_policies(): array {
        $raw = get_option( self::ROLE_OPTION, array() );
        return is_array( $raw ) ? $raw : array();
    }

    public static function user_policies(): array {
        $raw = get_option( self::USER_OPTION, array() );
        return is_array( $raw ) ? $raw : array();
    }

    public static function save_policy( string $type, string $id, array $policy ): array {
        if ( ! current_user_can( 'manage_options' ) ) {
            throw new RuntimeException( 'Only administrators can change file access policies.' );
        }
        $policy = self::sanitize_policy( $policy );
        if ( 'role' === $type ) {
            $roles = wp_roles()->roles;
            if ( ! isset( $roles[ $id ] ) || 'administrator' === $id ) {
                throw new InvalidArgumentException( 'Choose a valid non-administrator role.' );
            }
            $all = self::role_policies();
            $all[ $id ] = $policy;
            update_option( self::ROLE_OPTION, $all, false );
        } elseif ( 'user' === $type ) {
            $user_id = absint( $id );
            $user = get_user_by( 'id', $user_id );
            if ( ! $user || user_can( $user, 'manage_options' ) ) {
                throw new InvalidArgumentException( 'Choose a valid non-administrator user.' );
            }
            $all = self::user_policies();
            $all[ (string) $user_id ] = $policy;
            update_option( self::USER_OPTION, $all, false );
        } else {
            throw new InvalidArgumentException( 'Unsupported access policy type.' );
        }
        Nobash_Files_DB::log( 'access_policy_saved', $type . ':' . $id, array( 'enabled' => $policy['enabled'], 'roots' => $policy['roots'] ) );
        return $policy;
    }

    public static function delete_policy( string $type, string $id ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            throw new RuntimeException( 'Only administrators can change file access policies.' );
        }
        if ( 'role' === $type ) {
            $all = self::role_policies();
            unset( $all[ $id ] );
            update_option( self::ROLE_OPTION, $all, false );
        } elseif ( 'user' === $type ) {
            $all = self::user_policies();
            unset( $all[ (string) absint( $id ) ] );
            update_option( self::USER_OPTION, $all, false );
        } else {
            throw new InvalidArgumentException( 'Unsupported access policy type.' );
        }
        Nobash_Files_DB::log( 'access_policy_deleted', $type . ':' . $id );
    }

    public static function sanitize_policy( array $raw ): array {
        $permissions = array_fill_keys( self::PERMISSIONS, false );
        $incoming = isset( $raw['permissions'] ) && is_array( $raw['permissions'] ) ? $raw['permissions'] : array();
        foreach ( self::PERMISSIONS as $permission ) {
            $permissions[ $permission ] = ! empty( $incoming[ $permission ] );
        }
        // Browsing is required for any useful profile.
        if ( ! empty( $raw['enabled'] ) ) {
            $permissions['browse'] = true;
        }

        $roots = self::sanitize_paths( $raw['roots'] ?? array(), true );
        if ( ! empty( $raw['enabled'] ) && empty( $roots ) ) {
            $roots = array( 'wp-content/uploads' );
        }

        return array(
            'enabled'         => ! empty( $raw['enabled'] ),
            'roots'           => self::compact_roots( $roots ),
            'denied_paths'    => self::sanitize_paths( $raw['denied_paths'] ?? array(), false ),
            'allow_protected' => ! empty( $raw['allow_protected'] ),
            'permissions'     => $permissions,
        );
    }

    private static function sanitize_paths( $paths, bool $allow_root ): array {
        if ( is_string( $paths ) ) {
            $paths = preg_split( '/[\r\n,]+/', $paths );
        }
        if ( ! is_array( $paths ) ) {
            return array();
        }
        $out = array();
        foreach ( $paths as $path ) {
            if ( ! is_string( $path ) ) {
                continue;
            }
            $path = self::normalize_path( $path );
            if ( '' === $path && ! $allow_root ) {
                continue;
            }
            if ( ! in_array( $path, $out, true ) ) {
                $out[] = $path;
            }
        }
        return $out;
    }

    private static function compact_roots( array $roots ): array {
        sort( $roots, SORT_STRING );
        $out = array();
        foreach ( $roots as $root ) {
            $covered = false;
            foreach ( $out as $parent ) {
                if ( self::path_within( $root, $parent ) ) {
                    $covered = true;
                    break;
                }
            }
            if ( ! $covered ) {
                $out[] = $root;
            }
        }
        return $out;
    }

    public static function normalize_path( string $path ): string {
        if ( preg_match( '/[\x00-\x1F\x7F]/', $path ) ) {
            throw new InvalidArgumentException( 'Path contains unsupported control characters.' );
        }
        $path = str_replace( '\\', '/', trim( $path ) );
        $path = preg_replace( '#/+#', '/', $path );
        $path = ltrim( (string) $path, '/' );
        $parts = array();
        foreach ( explode( '/', $path ) as $part ) {
            if ( '' === $part || '.' === $part ) {
                continue;
            }
            if ( '..' === $part || str_contains( $part, "\0" ) ) {
                throw new InvalidArgumentException( 'Unsafe path.' );
            }
            $parts[] = $part;
        }
        return implode( '/', $parts );
    }

    private static function path_within( string $path, string $root ): bool {
        if ( '' === $root ) {
            return true;
        }
        return $path === $root || str_starts_with( $path . '/', $root . '/' );
    }

    private static function policy_allows_path( string $path, array $policy ): bool {
        if ( empty( $policy['enabled'] ) ) {
            return false;
        }
        $path = self::normalize_path( $path );
        $inside = false;
        foreach ( (array) ( $policy['roots'] ?? array() ) as $root ) {
            if ( self::path_within( $path, (string) $root ) ) {
                $inside = true;
                break;
            }
        }
        if ( ! $inside ) {
            return false;
        }
        foreach ( (array) ( $policy['denied_paths'] ?? array() ) as $deny ) {
            if ( self::path_within( $path, (string) $deny ) ) {
                return false;
            }
        }
        return true;
    }

    public static function is_path_allowed( string $path, ?array $policy = null ): bool {
        if ( null !== $policy ) {
            return self::policy_allows_path( $path, $policy );
        }
        foreach ( self::policies_for_user() as $candidate ) {
            if ( self::policy_allows_path( $path, $candidate ) ) {
                return true;
            }
        }
        return false;
    }

    public static function assert_permission( string $permission, array $paths = array(), bool $protected = false ): void {
        if ( current_user_can( 'manage_options' ) ) {
            return;
        }
        // A delegated file permission must never turn a lesser role into a
        // plugin/theme administrator or arbitrary PHP execution capability.
        // Archive extraction, history restoration, and undo can write files not
        // represented by the caller-supplied target path: administrator only.
        $mutating = array( 'upload', 'create', 'edit', 'rename', 'copy', 'move', 'delete', 'archive', 'extract', 'restore', 'undo', 'trash' );
        if ( in_array( $permission, array( 'archive', 'extract', 'restore', 'undo', 'trash' ), true ) ) {
            throw new Nobash_Files_Access_Denied( 'ACCESS_PERMISSION_DENIED' );
        }
        if ( in_array( $permission, $mutating, true ) ) {
            $upload_root = trim( Nobash_Files_Access::normalize_path( 'wp-content/uploads' ), '/' );
            // Sites with customized upload directories: only permit a folder
            // inside ABSPATH, never grant writes in a plugin/theme directory.
            $dirs = wp_upload_dir( null, false );
            if ( empty( $dirs['error'] ) && ! empty( $dirs['basedir'] ) ) {
                $wp_root = rtrim( wp_normalize_path( realpath( ABSPATH ) ?: ABSPATH ), '/' );
                $basedir = rtrim( wp_normalize_path( (string) $dirs['basedir'] ), '/' );
                if ( str_starts_with( $basedir . '/', $wp_root . '/' ) ) {
                    $upload_root = ltrim( substr( $basedir, strlen( $wp_root ) ), '/' );
                }
            }
            if ( empty( $paths ) ) {
                throw new Nobash_Files_Access_Denied( 'ACCESS_PATH_DENIED' );
            }
            foreach ( $paths as $candidate ) {
                $candidate = self::normalize_path( (string) $candidate );
                if ( '' === $candidate || ! self::path_within( $candidate, $upload_root ) ) {
                    throw new Nobash_Files_Access_Denied( 'ACCESS_PATH_DENIED' );
                }
                // Even when delegated users may upload media, never let them
                // change executable code or webserver configuration files.
                $filename = strtolower( basename( $candidate ) );
                if ( preg_match( '/(?:^|\.)(?:php[0-9]*|phtml|pht|phtm|phar|cgi|pl|py|rb|sh|js|html?|shtml|svg|exe|bat)(?:\.|$)/i', $filename )
                    || in_array( $filename, array( '.htaccess', '.user.ini', 'web.config', 'wp-config.php' ), true ) ) {
                    throw new Nobash_Files_Access_Denied( 'ACCESS_PROTECTED_DENIED' );
                }
            }
        }
        $policies = self::policies_for_user();
        $has_permission = false;
        $has_paths = false;
        foreach ( $policies as $policy ) {
            if ( empty( $policy['permissions'][ $permission ] ) ) {
                continue;
            }
            $has_permission = true;
            $allowed = true;
            foreach ( $paths as $path ) {
                if ( ! self::policy_allows_path( (string) $path, $policy ) ) {
                    $allowed = false;
                    break;
                }
            }
            if ( ! $allowed ) {
                continue;
            }
            $has_paths = true;
            if ( $protected && empty( $policy['allow_protected'] ) ) {
                continue;
            }
            return;
        }

        $reason = ! $has_permission ? 'permission' : ( ! $has_paths ? 'path' : 'protected' );
        Nobash_Files_DB::log( 'access_denied', self::normalize_path( (string) ( $paths[0] ?? '' ) ), array( 'permission' => $permission, 'reason' => $reason ) );
        $code = 'permission' === $reason ? 'ACCESS_PERMISSION_DENIED' : ( 'path' === $reason ? 'ACCESS_PATH_DENIED' : 'ACCESS_PROTECTED_DENIED' );
        throw new Nobash_Files_Access_Denied( $code );
    }

    public static function assert_paths( string $permission, array $paths, Nobash_Files_Filesystem $fs ): void {
        $protected = false;
        foreach ( $paths as $path ) {
            $normalized = self::normalize_path( (string) $path );
            if ( $fs->is_protected_path( $normalized ) ) {
                $protected = true;
            }
        }
        self::assert_permission( $permission, $paths, $protected );
    }

    /** Non-logging authorization probe for filtering server-rendered datasets. */
    public static function can_paths( string $permission, array $paths, Nobash_Files_Filesystem $fs ): bool {
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }
        $protected = false;
        foreach ( $paths as $path ) {
            if ( $fs->is_protected_path( self::normalize_path( (string) $path ) ) ) {
                $protected = true;
                break;
            }
        }
        foreach ( self::policies_for_user() as $policy ) {
            if ( empty( $policy['permissions'][ $permission ] ) || ( $protected && empty( $policy['allow_protected'] ) ) ) {
                continue;
            }
            $allowed = true;
            foreach ( $paths as $path ) {
                if ( ! self::policy_allows_path( (string) $path, $policy ) ) {
                    $allowed = false;
                    break;
                }
            }
            if ( $allowed ) {
                return true;
            }
        }
        return false;
    }

    public static function assert_recursive_safe( array $paths, string $permission = 'read', ?Nobash_Files_Filesystem $fs = null ): void {
        if ( current_user_can( 'manage_options' ) ) {
            return;
        }
        $normalized = array_map( static fn( $path ) => self::normalize_path( (string) $path ), $paths );
        $protected = false;
        if ( $fs ) {
            foreach ( $normalized as $path ) {
                if ( $fs->is_protected_path( $path ) ) {
                    $protected = true;
                    break;
                }
            }
        }
        foreach ( self::policies_for_user() as $policy ) {
            if ( empty( $policy['permissions'][ $permission ] ) || ( $protected && empty( $policy['allow_protected'] ) ) ) {
                continue;
            }
            $safe = true;
            foreach ( $normalized as $path ) {
                if ( ! self::policy_allows_path( $path, $policy ) ) {
                    $safe = false;
                    break;
                }
                foreach ( (array) $policy['denied_paths'] as $deny ) {
                    if ( self::path_within( (string) $deny, $path ) ) {
                        $safe = false;
                        break 2;
                    }
                }
            }
            if ( $safe ) {
                return;
            }
        }
        $path = (string) ( $normalized[0] ?? '' );
        Nobash_Files_DB::log( 'access_denied', $path, array( 'permission' => $permission, 'reason' => 'denied_descendant' ) );
        throw new Nobash_Files_Access_Denied( 'ACCESS_PATH_DENIED' );
    }

    /** Return every filesystem path encoded in a persisted undo/trash operation. */
    public static function operation_paths( array $operation ): array {
        $payload = isset( $operation['undo_payload'] ) && is_array( $operation['undo_payload'] ) ? $operation['undo_payload'] : array();
        $paths = array();
        foreach ( array( $payload, $operation ) as $source ) {
            foreach ( array( 'path' ) as $key ) {
                if ( isset( $source[ $key ] ) && is_string( $source[ $key ] ) ) {
                    $paths[] = $source[ $key ];
                }
            }
            foreach ( array( 'paths', 'items', 'created', 'overwritten', 'moves' ) as $key ) {
                foreach ( (array) ( $source[ $key ] ?? array() ) as $item ) {
                    if ( is_string( $item ) ) {
                        $paths[] = $item;
                        continue;
                    }
                    if ( ! is_array( $item ) ) {
                        continue;
                    }
                    foreach ( array( 'path', 'from', 'to' ) as $path_key ) {
                        if ( isset( $item[ $path_key ] ) && is_string( $item[ $path_key ] ) ) {
                            $paths[] = $item[ $path_key ];
                        }
                    }
                }
            }
        }
        $paths = array_values( array_unique( array_map( static fn( $path ) => self::normalize_path( (string) $path ), $paths ) ) );
        if ( empty( $paths ) && ! empty( $operation['relative_path'] ) ) {
            $paths[] = self::normalize_path( (string) $operation['relative_path'] );
        }
        return $paths;
    }

    /** Validate every filesystem path encoded in a persisted undo/trash operation. */
    public static function assert_operation_paths( string $permission, array $operation, Nobash_Files_Filesystem $fs ): void {
        $paths = self::operation_paths( $operation );
        if ( empty( $paths ) ) {
            self::assert_permission( $permission );
            return;
        }
        self::assert_paths( $permission, $paths, $fs );
    }

    public static function roots(): array {
        return self::effective_policy()['roots'];
    }

    public static function home_root(): string {
        $roots = self::roots();
        return $roots[0] ?? '';
    }

    public static function client_summary(): array {
        $policy = self::effective_policy();
        $permission_scopes = array_fill_keys( self::PERMISSIONS, array() );
        foreach ( self::policies_for_user() as $atomic_policy ) {
            foreach ( self::PERMISSIONS as $permission ) {
                if ( empty( $atomic_policy['permissions'][ $permission ] ) ) {
                    continue;
                }
                $permission_scopes[ $permission ][] = array(
                    'roots'          => array_values( $atomic_policy['roots'] ),
                    'deniedPaths'    => array_values( $atomic_policy['denied_paths'] ),
                    'allowProtected' => ! empty( $atomic_policy['allow_protected'] ),
                );
            }
        }
        return array(
            'enabled'         => ! empty( $policy['enabled'] ),
            'roots'           => array_values( $policy['roots'] ),
            'home'            => $policy['roots'][0] ?? '',
            'deniedPaths'     => array_values( $policy['denied_paths'] ),
            'allowProtected'  => ! empty( $policy['allow_protected'] ),
            'permissions'     => $policy['permissions'],
            'permissionScopes' => $permission_scopes,
            'source'          => $policy['source'] ?? 'none',
            'isAdministrator' => current_user_can( 'manage_options' ),
        );
    }

    public static function admin_payload(): array {
        if ( ! current_user_can( 'manage_options' ) ) {
            throw new RuntimeException( 'Only administrators can manage access policies.' );
        }
        $roles = array();
        foreach ( wp_roles()->roles as $key => $role ) {
            if ( 'administrator' === $key ) {
                continue;
            }
            $roles[] = array( 'id' => $key, 'name' => translate_user_role( $role['name'] ) );
        }
        $users = get_users( array(
            'number'  => 200,
            'orderby' => 'display_name',
            'order'   => 'ASC',
            'fields'  => 'all',
        ) );
        $user_rows = array();
        foreach ( $users as $user ) {
            if ( user_can( $user, 'manage_options' ) ) {
                continue;
            }
            $user_rows[] = array(
                'id'    => (int) $user->ID,
                'name'  => $user->display_name ?: $user->user_login,
                'login' => $user->user_login,
                'email' => $user->user_email,
            );
        }
        return array(
            'roles'       => $roles,
            'users'       => $user_rows,
            'rolePolicies'=> self::role_policies(),
            'userPolicies'=> self::user_policies(),
            'permissions' => self::PERMISSIONS,
            'defaults'    => self::sanitize_policy( array(
                'enabled' => true,
                'roots' => array( 'wp-content/uploads' ),
                'permissions' => array_fill_keys( self::PERMISSIONS, true ),
            ) ),
        );
    }
}

final class Nobash_Files_Access_Denied extends RuntimeException {}
