<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }
delete_option( 'nobash_file_integrity_latest_report' );
if ( is_multisite() ) {
    foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $nobash_file_integrity_site_id ) {
        switch_to_blog( (int) $nobash_file_integrity_site_id );
        delete_option( 'nobash_file_integrity_latest_report' );
        restore_current_blog();
    }
}
