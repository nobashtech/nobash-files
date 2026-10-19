<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Nobash_Files_DB {
    private const DB_VERSION = '3';

    public static function table_versions(): string {
        global $wpdb;
        return $wpdb->prefix . 'nobash_files_versions';
    }

    public static function table_activity(): string {
        global $wpdb;
        return $wpdb->prefix . 'nobash_files_activity';
    }

    public static function table_operations(): string {
        global $wpdb;
        return $wpdb->prefix . 'nobash_files_operations';
    }

    public static function activate( bool $network_wide = false ): void {
        if ( is_multisite() && $network_wide && function_exists( 'get_sites' ) ) {
            $offset = 0;
            $batch  = 100;
            do {
                $site_ids = get_sites( array( 'fields' => 'ids', 'number' => $batch, 'offset' => $offset, 'orderby' => 'id', 'order' => 'ASC' ) );
                foreach ( $site_ids as $site_id ) {
                    switch_to_blog( (int) $site_id );
                    try {
                        self::activate_site();
                    } finally {
                        restore_current_blog();
                    }
                }
                $offset += count( $site_ids );
            } while ( count( $site_ids ) === $batch );
            return;
        }
        self::activate_site();
    }

    private static function activate_site(): void {
        self::create_tables();
        update_option( 'nobash_files_db_version', self::DB_VERSION );
        add_option( 'nobash_files_safe_mode', '1' );
        add_option( 'nobash_files_max_edit_bytes', 1048576 );
        add_option( 'nobash_files_chunk_bytes', 2097152 );
        add_option( 'nobash_files_version_limit', 5 );
        add_option( 'nobash_files_default_workspace', class_exists( 'Nobash_Files_Onboarding' ) ? Nobash_Files_Onboarding::recommended_workspace() : '' );
        add_option( 'nobash_files_directory_page_size', 250 );
        add_option( 'nobash_files_uninstall_policy', 'keep' );
        add_option( 'nobash_files_integrity_max_files', 50000 );
        add_option( 'nobash_files_integrity_max_seconds', 18 );
    }

    public static function maybe_upgrade(): void {
        if ( get_option( 'nobash_files_db_version' ) !== self::DB_VERSION ) {
            self::create_tables();
            update_option( 'nobash_files_db_version', self::DB_VERSION );
        }
        // Options added after activation must also exist on upgraded installations.
        add_option( 'nobash_files_version_limit', 5 );
        add_option( 'nobash_files_integrity_max_files', 50000 );
        add_option( 'nobash_files_integrity_max_seconds', 18 );
        add_option( 'nobash_files_default_workspace', class_exists( 'Nobash_Files_Onboarding' ) ? Nobash_Files_Onboarding::recommended_workspace() : '' );
        add_option( 'nobash_files_directory_page_size', 250 );
        add_option( 'nobash_files_uninstall_policy', 'keep' );
    }

    private static function create_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $versions   = self::table_versions();
        $activity   = self::table_activity();
        $operations = self::table_operations();

        dbDelta( "CREATE TABLE {$versions} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            relative_path text NOT NULL,
            checksum char(64) NOT NULL,
            file_size bigint(20) unsigned NOT NULL DEFAULT 0,
            content longtext NOT NULL,
            created_at datetime NOT NULL,
            action varchar(30) NOT NULL DEFAULT 'save',
            PRIMARY KEY  (id),
            KEY path_lookup (relative_path(191)),
            KEY created_at (created_at)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$activity} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            action varchar(40) NOT NULL,
            relative_path text NOT NULL,
            details longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY action (action)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$operations} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            action varchar(40) NOT NULL,
            label varchar(191) NOT NULL DEFAULT '',
            relative_path text NOT NULL,
            undo_payload longtext NOT NULL,
            created_at datetime NOT NULL,
            undone_at datetime NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY undone_at (undone_at),
            KEY user_undo (user_id,undone_at)
        ) {$charset};" );
    }

    public static function add_version( string $relative_path, string $content, string $action = 'save' ): int {
        global $wpdb;
        $inserted = $wpdb->insert(
            self::table_versions(),
            array(
                'user_id'      => get_current_user_id(),
                'relative_path' => $relative_path,
                'checksum'      => hash( 'sha256', $content ),
                'file_size'     => strlen( $content ),
                'content'       => $content,
                'created_at'    => current_time( 'mysql' ),
                'action'        => sanitize_key( $action ),
            ),
            array( '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
        );
        if ( false === $inserted || (int) $wpdb->insert_id <= 0 ) {
            throw new RuntimeException( 'Unable to persist the safety version. The file was not changed.' );
        }
        $id = (int) $wpdb->insert_id;
        self::prune_versions( $relative_path );
        return $id;
    }

    private static function prune_versions( string $relative_path ): void {
        global $wpdb;
        $max_limit = max( 5, (int) apply_filters( 'nobash_files_version_history_max', 100 ) );
        $limit = max( 5, min( $max_limit, (int) get_option( 'nobash_files_version_limit', 5 ) ) );
        $ids = (array) $wpdb->get_col(
            $wpdb->prepare(
                'SELECT id FROM %i WHERE relative_path = %s ORDER BY id DESC LIMIT 1000 OFFSET %d',
                self::table_versions(),
                $relative_path,
                $limit
            )
        );
        if ( empty( $ids ) ) {
            return;
        }
        $ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
        if ( empty( $ids ) ) {
            return;
        }
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id IN ({$placeholders})", self::table_versions(), ...$ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders are generated internally from an integer array.
    }

    public static function get_versions( string $relative_path, int $limit = 20 ): array {
        global $wpdb;
        $max_limit = max( 5, (int) apply_filters( 'nobash_files_version_history_max', 100 ) );
        $limit = max( 1, min( $max_limit, $limit ) );
        $sql = $wpdb->prepare(
            'SELECT id,user_id,relative_path,checksum,file_size,created_at,action FROM %i WHERE relative_path = %s ORDER BY id DESC LIMIT %d',
            self::table_versions(),
            $relative_path,
            $limit
        );
        return (array) $wpdb->get_results( $sql, ARRAY_A );
    }

    public static function get_version( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table_versions(), $id ), ARRAY_A );
        return $row ?: null;
    }

    public static function log( string $action, string $relative_path, array $details = array() ): void {
        global $wpdb;
        $wpdb->insert(
            self::table_activity(),
            array(
                'user_id'      => get_current_user_id(),
                'action'        => sanitize_key( $action ),
                'relative_path' => $relative_path,
                'details'       => wp_json_encode( $details ),
                'created_at'    => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%s', '%s', '%s' )
        );
    }

    public static function activity( int $limit = 30 ): array {
        global $wpdb;
        $limit = max( 1, min( 100, $limit ) );
        $sql = $wpdb->prepare( 'SELECT id,user_id,action,relative_path,details,created_at FROM %i ORDER BY id DESC LIMIT %d', self::table_activity(), $limit );
        return (array) $wpdb->get_results( $sql, ARRAY_A );
    }

    public static function add_operation( string $action, string $label, string $relative_path, array $undo_payload ): int {
        global $wpdb;
        $json = wp_json_encode( $undo_payload );
        if ( false === $json ) {
            throw new RuntimeException( 'Unable to encode operation recovery data.' );
        }
        $inserted = $wpdb->insert(
            self::table_operations(),
            array(
                'user_id'      => get_current_user_id(),
                'action'        => sanitize_key( $action ),
                'label'         => sanitize_text_field( $label ),
                'relative_path' => $relative_path,
                'undo_payload'  => $json,
                'created_at'    => current_time( 'mysql' ),
                'undone_at'     => null,
            )
        );
        if ( false === $inserted || (int) $wpdb->insert_id <= 0 ) {
            throw new RuntimeException( 'Unable to persist operation recovery data.' );
        }
        return (int) $wpdb->insert_id;
    }

    public static function last_undoable(): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE user_id = %d AND undone_at IS NULL ORDER BY id DESC LIMIT 1',
                self::table_operations(),
                get_current_user_id()
            ),
            ARRAY_A
        );
        if ( ! $row ) {
            return null;
        }
        $payload = json_decode( (string) $row['undo_payload'], true );
        $row['undo_payload'] = is_array( $payload ) ? $payload : array();
        return $row;
    }

    public static function mark_undone( int $id ): void {
        global $wpdb;
        $wpdb->update(
            self::table_operations(),
            array( 'undone_at' => current_time( 'mysql' ) ),
            array( 'id' => $id, 'user_id' => get_current_user_id() ),
            array( '%s' ),
            array( '%d', '%d' )
        );
    }


    public static function get_operation( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE id = %d AND user_id = %d LIMIT 1',
                self::table_operations(),
                $id,
                get_current_user_id()
            ),
            ARRAY_A
        );
        if ( ! $row ) {
            return null;
        }
        $payload = json_decode( (string) $row['undo_payload'], true );
        $row['undo_payload'] = is_array( $payload ) ? $payload : array();
        return $row;
    }

    public static function trash_entries( int $limit = 100 ): array {
        global $wpdb;
        $limit = max( 1, min( 500, $limit ) );
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE user_id = %d AND action = 'trash' AND undone_at IS NULL ORDER BY id DESC LIMIT %d",
                self::table_operations(),
                get_current_user_id(),
                $limit
            ),
            ARRAY_A
        );
        $out = array();
        foreach ( $rows as $row ) {
            $payload = json_decode( (string) $row['undo_payload'], true );
            $items = array();
            if ( is_array( $payload ) ) {
                foreach ( (array) ( $payload['items'] ?? array() ) as $item ) {
                    $items[] = array( 'path' => (string) ( $item['path'] ?? '' ) );
                }
            }
            $out[] = array(
                'id'            => (int) $row['id'],
                'label'         => (string) $row['label'],
                'relative_path' => (string) $row['relative_path'],
                'created_at'    => (string) $row['created_at'],
                'items'         => $items,
                'item_count'    => count( $items ),
            );
        }
        return $out;
    }

    public static function recent_operations( int $limit = 20 ): array {
        global $wpdb;
        $limit = max( 1, min( 100, $limit ) );
        $sql = $wpdb->prepare(
            'SELECT id,user_id,action,label,relative_path,created_at,undone_at FROM %i WHERE user_id = %d ORDER BY id DESC LIMIT %d',
            self::table_operations(),
            get_current_user_id(),
            $limit
        );
        return (array) $wpdb->get_results( $sql, ARRAY_A );
    }

    public static function version_history_rows( int $limit = 1000 ): array {
        global $wpdb;
        $limit = max( 1, min( 2000, $limit ) );
        $sql = $wpdb->prepare(
            'SELECT id,user_id,relative_path,checksum,file_size,created_at,action FROM %i ORDER BY id DESC LIMIT %d',
            self::table_versions(),
            $limit
        );
        return (array) $wpdb->get_results( $sql, ARRAY_A );
    }

    public static function version_history_stats(): array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT COUNT(*) AS snapshots, COALESCE(SUM(file_size),0) AS bytes, MAX(created_at) AS latest_at FROM %i', self::table_versions() ),
            ARRAY_A
        );
        return array(
            'snapshots' => (int) ( $row['snapshots'] ?? 0 ),
            'bytes'     => (int) ( $row['bytes'] ?? 0 ),
            'latest_at' => (string) ( $row['latest_at'] ?? '' ),
        );
    }

    public static function undoable_count(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE user_id = %d AND undone_at IS NULL',
                self::table_operations(),
                get_current_user_id()
            )
        );
    }

}
