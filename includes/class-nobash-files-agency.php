<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Nobash_Files_Agency {
    private const OPTION = 'nobash_files_agency_templates_v1';
    private const MAX_TEMPLATES = 50;

    public static function can_manage(): bool {
        return is_multisite() ? ( is_super_admin() && current_user_can( 'manage_network_options' ) ) : current_user_can( 'manage_options' );
    }

    private static function get_store(): array {
        $raw = is_multisite() ? get_site_option( self::OPTION, array() ) : get_option( self::OPTION, array() );
        return is_array( $raw ) ? $raw : array();
    }

    private static function save_store( array $templates ): void {
        if ( is_multisite() ) {
            update_site_option( self::OPTION, $templates );
        } else {
            update_option( self::OPTION, $templates, false );
        }
    }

    public static function payload(): array {
        if ( ! self::can_manage() ) {
            throw new RuntimeException( 'Administrator access is required.' );
        }
        $templates = array_values( self::get_store() );
        usort( $templates, static fn( $a, $b ) => strcmp( (string) ( $b['updated_at'] ?? '' ), (string) ( $a['updated_at'] ?? '' ) ) );
        return array(
            'templates'  => $templates,
            'permissions'=> Nobash_Files_Access::permission_keys(),
            'is_multisite'=> is_multisite(),
            'sites'      => is_multisite() && Nobash_Files_Multisite::can_network_admin() ? array_map( static function ( $site ) {
                return array( 'id' => (int) $site->blog_id, 'name' => (string) get_blog_option( (int) $site->blog_id, 'blogname', 'Site #' . (int) $site->blog_id ) );
            }, get_sites( array( 'number' => 250, 'orderby' => 'id', 'order' => 'ASC' ) ) ) : array(),
        );
    }

    public static function subjects( int $blog_id = 0 ): array {
        if ( ! self::can_manage() ) {
            throw new RuntimeException( 'Administrator access is required.' );
        }
        $switched = false;
        if ( is_multisite() ) {
            if ( $blog_id <= 0 || ! get_site( $blog_id ) ) {
                throw new InvalidArgumentException( 'Choose a valid network site.' );
            }
            switch_to_blog( $blog_id );
            $switched = true;
        }
        try {
            $roles = array();
            foreach ( (array) wp_roles()->roles as $id => $role ) {
                if ( 'administrator' === $id ) {
                    continue;
                }
                $roles[] = array( 'id' => (string) $id, 'name' => translate_user_role( (string) ( $role['name'] ?? $id ) ) );
            }
            $users = array();
            foreach ( get_users( array( 'number' => 300, 'orderby' => 'display_name', 'order' => 'ASC' ) ) as $user ) {
                if ( user_can( $user, 'manage_options' ) ) {
                    continue;
                }
                $users[] = array( 'id' => (int) $user->ID, 'name' => (string) $user->display_name, 'login' => (string) $user->user_login );
            }
            return array( 'roles' => $roles, 'users' => $users );
        } finally {
            if ( $switched ) {
                restore_current_blog();
            }
        }
    }

    public static function save_template( array $raw ): array {
        if ( ! self::can_manage() ) {
            throw new RuntimeException( 'Administrator access is required.' );
        }
        $all = self::get_store();
        $id  = sanitize_key( (string) ( $raw['id'] ?? '' ) );
        if ( '' === $id ) {
            $id = 'policy_' . strtolower( wp_generate_password( 10, false, false ) );
        }
        if ( ! isset( $all[ $id ] ) && count( $all ) >= self::MAX_TEMPLATES ) {
            throw new RuntimeException( 'Agency policy template limit reached.' );
        }
        $name = sanitize_text_field( (string) ( $raw['name'] ?? '' ) );
        if ( '' === $name ) {
            throw new InvalidArgumentException( 'Template name is required.' );
        }
        $policy = isset( $raw['policy'] ) && is_array( $raw['policy'] ) ? Nobash_Files_Access::sanitize_policy( $raw['policy'] ) : Nobash_Files_Access::sanitize_policy( array() );
        $template = array(
            'id'          => $id,
            'name'        => $name,
            'description' => sanitize_textarea_field( (string) ( $raw['description'] ?? '' ) ),
            'policy'      => $policy,
            'created_at'  => isset( $all[ $id ]['created_at'] ) ? (string) $all[ $id ]['created_at'] : current_time( 'mysql', true ),
            'updated_at'  => current_time( 'mysql', true ),
        );
        $all[ $id ] = $template;
        self::save_store( $all );
        Nobash_Files_DB::log( 'agency_template_saved', $id, array( 'name' => $name ) );
        return $template;
    }

    public static function delete_template( string $id ): void {
        if ( ! self::can_manage() ) {
            throw new RuntimeException( 'Administrator access is required.' );
        }
        $id = sanitize_key( $id );
        $all = self::get_store();
        if ( ! isset( $all[ $id ] ) ) {
            throw new InvalidArgumentException( 'Agency template not found.' );
        }
        unset( $all[ $id ] );
        self::save_store( $all );
        Nobash_Files_DB::log( 'agency_template_deleted', $id );
    }

    public static function apply_template( string $template_id, string $type, string $subject_id, int $blog_id = 0 ): array {
        if ( ! self::can_manage() ) {
            throw new RuntimeException( 'Administrator access is required.' );
        }
        $all = self::get_store();
        $template_id = sanitize_key( $template_id );
        if ( ! isset( $all[ $template_id ] ) || ! is_array( $all[ $template_id ] ) ) {
            throw new InvalidArgumentException( 'Agency template not found.' );
        }
        $policy = (array) ( $all[ $template_id ]['policy'] ?? array() );

        if ( is_multisite() ) {
            if ( $blog_id <= 0 || ! get_site( $blog_id ) ) {
                throw new InvalidArgumentException( 'Choose a valid network site.' );
            }
            switch_to_blog( $blog_id );
            try {
                if ( 'user' === $type && function_exists( 'is_user_member_of_blog' ) && ! is_user_member_of_blog( absint( $subject_id ), $blog_id ) ) {
                    throw new InvalidArgumentException( 'Choose a user who belongs to the selected site.' );
                }
                $policy = self::expand_policy_for_site( $policy, $blog_id );
                $saved = Nobash_Files_Access::save_policy( $type, $subject_id, $policy );
            } finally {
                restore_current_blog();
            }
        } else {
            $saved = Nobash_Files_Access::save_policy( $type, $subject_id, $policy );
        }

        Nobash_Files_DB::log( 'agency_template_applied', $template_id, array(
            'type' => sanitize_key( $type ),
            'subject_id' => sanitize_text_field( $subject_id ),
            'blog_id' => $blog_id,
        ) );
        return $saved;
    }

    private static function expand_policy_for_site( array $policy, int $blog_id ): array {
        $site_uploads = Nobash_Files_Multisite::site_workspace_path( $blog_id );
        foreach ( array( 'roots', 'denied_paths' ) as $key ) {
            if ( empty( $policy[ $key ] ) || ! is_array( $policy[ $key ] ) ) {
                continue;
            }
            $policy[ $key ] = array_map( static function ( $path ) use ( $site_uploads ) {
                return str_replace( '{site_uploads}', $site_uploads, (string) $path );
            }, $policy[ $key ] );
        }
        return $policy;
    }

    public static function audit_rows( array $filters = array(), int $limit = 2000 ): array {
        if ( ! self::can_manage() ) {
            throw new RuntimeException( 'Administrator access is required.' );
        }
        $limit = max( 1, min( 5000, $limit ) );
        $site_filter = absint( $filters['site_id'] ?? 0 );
        $action_filter = sanitize_key( (string) ( $filters['action'] ?? '' ) );
        $user_filter = absint( $filters['user_id'] ?? 0 );
        $sites = is_multisite() ? get_sites( array( 'number' => 250, 'orderby' => 'id', 'order' => 'ASC' ) ) : array( (object) array( 'blog_id' => get_current_blog_id() ) );
        if ( $site_filter ) {
            $sites = array_values( array_filter( $sites, static fn( $site ) => (int) $site->blog_id === $site_filter ) );
        }
        $rows = array();
        $site_count = max( 1, count( $sites ) );
        $per_site_limit = 1 === $site_count ? $limit : max( 20, min( $limit, (int) ceil( $limit / $site_count ) * 2 ) );

        foreach ( $sites as $site ) {
            $blog_id = (int) $site->blog_id;
            if ( is_multisite() ) {
                switch_to_blog( $blog_id );
            }
            try {
                global $wpdb;
                $table = Nobash_Files_DB::table_activity();
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is generated internally.
                $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
                if ( $exists !== $table ) {
                    continue;
                }
                $where = array( '1=1' );
                $args  = array();
                if ( $action_filter ) {
                    $where[] = 'action = %s';
                    $args[]  = $action_filter;
                }
                if ( $user_filter ) {
                    $where[] = 'user_id = %d';
                    $args[]  = $user_filter;
                }
                // Only fixed query predicates are constructed above; escape
                // the per-site table as an identifier using WP 6.5+ %i.
                $sql = 'SELECT id,user_id,action,relative_path,details,created_at FROM %i WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d';
                array_unshift( $args, $table );
                $args[] = $per_site_limit;
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WHERE consists of fixed strings; values and table identifier are prepared.
                $prepared = $wpdb->prepare( $sql, ...$args );
                $site_rows = (array) $wpdb->get_results( $prepared, ARRAY_A );
                $site_name = (string) get_bloginfo( 'name' );
                foreach ( $site_rows as $row ) {
                    $user = ! empty( $row['user_id'] ) ? get_user_by( 'id', (int) $row['user_id'] ) : false;
                    $details = json_decode( (string) ( $row['details'] ?? '' ), true );
                    $rows[] = array(
                        'site_id' => $blog_id,
                        'site_name' => $site_name,
                        'id' => (int) $row['id'],
                        'user_id' => (int) $row['user_id'],
                        'user' => $user ? (string) $user->user_login : '',
                        'action' => (string) $row['action'],
                        'path' => (string) $row['relative_path'],
                        'details' => is_array( $details ) ? $details : array(),
                        'created_at' => (string) $row['created_at'],
                    );
                }
            } finally {
                if ( is_multisite() ) {
                    restore_current_blog();
                }
            }
        }
        usort( $rows, static fn( $a, $b ) => strcmp( (string) $b['created_at'], (string) $a['created_at'] ) );
        return array_slice( $rows, 0, $limit );
    }
}
