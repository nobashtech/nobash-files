<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** WordPress privacy-tools integration for Nobash-owned user-linked metadata. */
final class Nobash_Files_Privacy {
    private const PAGE_SIZE = 50;

    public static function init(): void {
        add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
        add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
    }

    public static function register_exporter( array $exporters ): array {
        $exporters['nobash-files'] = array(
            'exporter_friendly_name' => __( 'Nobash Files', 'nobash-files' ),
            'callback'               => array( __CLASS__, 'export' ),
        );
        return $exporters;
    }

    public static function register_eraser( array $erasers ): array {
        $erasers['nobash-files'] = array(
            'eraser_friendly_name' => __( 'Nobash Files', 'nobash-files' ),
            'callback'             => array( __CLASS__, 'erase' ),
        );
        return $erasers;
    }

    private static function user_id_for_email( string $email ): int {
        $user = get_user_by( 'email', sanitize_email( $email ) );
        return $user instanceof WP_User ? (int) $user->ID : 0;
    }

    public static function export( string $email_address, int $page = 1 ): array {
        global $wpdb;
        $user_id = self::user_id_for_email( $email_address );
        if ( $user_id <= 0 ) { return array( 'data' => array(), 'done' => true ); }
        $page   = max( 1, $page );
        $offset = ( $page - 1 ) * self::PAGE_SIZE;
        $data   = array();
        $done   = true;
        $sources = array(
            'activity' => array( 'table' => Nobash_Files_DB::table_activity(), 'fields' => 'id,action,relative_path,details,created_at' ),
            'operation' => array( 'table' => Nobash_Files_DB::table_operations(), 'fields' => 'id,action,label,relative_path,created_at,undone_at' ),
            'version' => array( 'table' => Nobash_Files_DB::table_versions(), 'fields' => 'id,relative_path,checksum,file_size,created_at,action' ),
        );
        foreach ( $sources as $type => $source ) {
            // Both field selections are hardcoded above; the table name is
            // escaped as an identifier rather than concatenated into SQL.
            $fields = $source['fields'];
            $sql = $wpdb->prepare(
                'SELECT ' . $fields . ' FROM %i WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d',
                $source['table'], $user_id, self::PAGE_SIZE, $offset
            );
            $rows = (array) $wpdb->get_results( $sql, ARRAY_A );
            if ( count( $rows ) === self::PAGE_SIZE ) { $done = false; }
            foreach ( $rows as $row ) {
                $item = array();
                foreach ( $row as $key => $value ) {
                    if ( 'details' === $key ) {
                        $decoded = json_decode( (string) $value, true );
                        $value = is_array( $decoded ) ? wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES ) : (string) $value;
                    }
                    $item[] = array( 'name' => ucwords( str_replace( '_', ' ', (string) $key ) ), 'value' => (string) $value );
                }
                $data[] = array(
                    'group_id'    => 'nobash-files',
                    'group_label' => __( 'Nobash Files activity and recovery metadata', 'nobash-files' ),
                    'item_id'     => 'nobash-files-' . $type . '-' . absint( $row['id'] ?? 0 ),
                    'data'        => $item,
                );
            }
        }
        if ( 1 === $page ) {
            foreach ( array( 'nobash_files_favorites' => 'Favorite paths', 'nobash_files_recent' => 'Recent paths' ) as $key => $label ) {
                $value = get_user_meta( $user_id, $key, true );
                if ( is_array( $value ) && $value ) {
                    $data[] = array(
                        'group_id'    => 'nobash-files-preferences',
                        'group_label' => __( 'Nobash Files preferences', 'nobash-files' ),
                        'item_id'     => 'nobash-files-pref-' . sanitize_key( $key ),
                        'data'        => array( array( 'name' => $label, 'value' => implode( "\n", array_map( 'strval', $value ) ) ) ),
                    );
                }
            }
        }
        return array( 'data' => $data, 'done' => $done );
    }

    public static function erase( string $email_address, int $page = 1 ): array {
        global $wpdb;
        $user_id = self::user_id_for_email( $email_address );
        if ( $user_id <= 0 ) {
            return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
        }
        // User association is personal data; operational rows/recovery history are retained but anonymized.
        $removed = false;
        foreach ( array( Nobash_Files_DB::table_activity(), Nobash_Files_DB::table_operations(), Nobash_Files_DB::table_versions() ) as $table ) {
            $changed = $wpdb->update( $table, array( 'user_id' => 0 ), array( 'user_id' => $user_id ), array( '%d' ), array( '%d' ) );
            if ( false !== $changed && $changed > 0 ) { $removed = true; }
        }
        if ( delete_user_meta( $user_id, 'nobash_files_favorites' ) ) { $removed = true; }
        if ( delete_user_meta( $user_id, 'nobash_files_recent' ) ) { $removed = true; }
        return array(
            'items_removed'  => $removed,
            'items_retained' => true,
            'messages'       => array( __( 'Nobash user associations were anonymized. Operational recovery/version records may be retained for site safety until their normal retention or administrator cleanup.', 'nobash-files' ) ),
            'done'           => true,
        );
    }
}
