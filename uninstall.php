<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

/** Delete Nobash-specific data only when the administrator explicitly chose the remove policy. */
function nobash_files_uninstall_site_data(): void {
    global $wpdb;
    if ( 'remove' !== (string) get_option( 'nobash_files_uninstall_policy', 'keep' ) ) { return; }

    foreach ( array( $wpdb->prefix . 'nobash_files_versions', $wpdb->prefix . 'nobash_files_activity', $wpdb->prefix . 'nobash_files_operations' ) as $table ) {
        $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- uninstall cleanup requires dropping plugin-owned tables.
    }

    $private_path = (string) get_option( 'nobash_files_private_storage_path', '' );

    $options = array(
        'nobash_files_db_version','nobash_files_safe_mode','nobash_files_max_edit_bytes','nobash_files_chunk_bytes','nobash_files_version_limit','nobash_files_default_workspace',
        'nobash_files_directory_page_size','nobash_files_uninstall_policy','nobash_files_private_storage_path','nobash_files_integrity_max_files','nobash_files_integrity_max_seconds',
        'nobash_files_integrity_baseline_v1','nobash_files_integrity_history_v1','nobash_files_integrity_last_scan_v1','nobash_files_access_role_policies','nobash_files_access_user_policies',
        'nobash_files_onboarding_pending','nobash_files_onboarding_complete_version','nobash_files_onboarding_completed_at','nobash_files_monitor_alerts_v1','nobash_files_monitor_settings_v1',
        'nobash_files_monitor_state_v1','nobash_files_monitor_updates_v1','nobash_files_remote_destinations_v1','nobash_files_remote_jobs_v1','nobash_files_remote_settings_v1','nobash_files_remote_certifications_v1',
        'nobash_files_security_permission_scan_v1','nobash_files_agency_templates_v1'
    );
    foreach ( $options as $option ) { delete_option( $option ); }

    delete_metadata( 'user', 0, 'nobash_files_favorites', '', true );
    delete_metadata( 'user', 0, 'nobash_files_recent', '', true );

    $path = $private_path;
    if ( '' !== $path && '.nobash-files' === basename( wp_normalize_path( $path ) ) && is_dir( $path ) ) {
        nobash_files_uninstall_remove_tree( $path, true );
    }
}

function nobash_files_uninstall_remove_tree( string $dir, bool $is_root = false ): void {
    $dir = rtrim( wp_normalize_path( $dir ), '/' );
    if ( ! is_dir( $dir ) || is_link( $dir ) ) { return; }
    if ( $is_root && '.nobash-files' !== basename( $dir ) ) { return; }
    $items = @scandir( $dir );
    if ( ! is_array( $items ) ) { return; }
    foreach ( $items as $name ) {
        if ( '.' === $name || '..' === $name ) { continue; }
        $path = $dir . '/' . $name;
        if ( is_link( $path ) ) { @unlink( $path ); continue; }
        if ( is_dir( $path ) ) { nobash_files_uninstall_remove_tree( $path, false ); }
        else { @unlink( $path ); }
    }
    @rmdir( $dir );
}

if ( is_multisite() && function_exists( 'get_sites' ) ) {
    $site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
    foreach ( $site_ids as $site_id ) {
        switch_to_blog( (int) $site_id );
        try { nobash_files_uninstall_site_data(); } finally { restore_current_blog(); }
    }
} else {
    nobash_files_uninstall_site_data();
}
