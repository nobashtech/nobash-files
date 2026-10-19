<?php
/**
 * Plugin Name: Nobash Files
 * Description: Safe, beginner-friendly file management and code editing for WordPress.
 * Version: 1.0.0.1
 * Author: Nobash
 * Author URI: https://nobash.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Text Domain: nobash-files
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Explorer navigation uses a framed admin-post response. When WP_DEBUG_DISPLAY is
// enabled, PHP notices from this plugin or later-loaded code must not corrupt the
// transport. Start a request-local buffer as early as possible; the Explorer
// handler discards it before emitting its framed payload. Stray output emitted
// before this plugin loads is also harmless because the client extracts only the
// explicit frame markers.
if ( 'nobash_files_explorer_list' === filter_input( INPUT_POST, 'action', FILTER_UNSAFE_RAW ) && 0 === ob_get_level() ) {
    ob_start();
}

define( 'NOBASH_FILES_VERSION', '1.0.0.1' );
define( 'NOBASH_FILES_FILE', __FILE__ );
define( 'NOBASH_FILES_DIR', plugin_dir_path( __FILE__ ) );
define( 'NOBASH_FILES_URL', plugin_dir_url( __FILE__ ) );

require_once NOBASH_FILES_DIR . 'includes/class-nobash-files-runtime.php';
require_once NOBASH_FILES_DIR . 'includes/class-nobash-files-db.php';
require_once NOBASH_FILES_DIR . 'includes/class-nobash-files-access.php';
require_once NOBASH_FILES_DIR . 'includes/class-nobash-files-integrity.php';
require_once NOBASH_FILES_DIR . 'includes/class-nobash-files-filesystem.php';
require_once NOBASH_FILES_DIR . 'includes/class-nobash-files-explorer.php';
require_once NOBASH_FILES_DIR . 'includes/class-nobash-files-rest.php';
require_once NOBASH_FILES_DIR . 'includes/class-nobash-files-admin.php';
require_once NOBASH_FILES_DIR . 'includes/class-nobash-files-onboarding.php';
require_once NOBASH_FILES_DIR . 'includes/class-nobash-files-health.php';
require_once NOBASH_FILES_DIR . 'includes/class-nobash-files-privacy.php';
require_once NOBASH_FILES_DIR . 'includes/class-nobash-files-monitor.php';
require_once NOBASH_FILES_DIR . 'includes/class-nobash-files-security.php';
require_once NOBASH_FILES_DIR . 'includes/class-nobash-files-multisite.php';
require_once NOBASH_FILES_DIR . 'includes/class-nobash-files-agency.php';
require_once NOBASH_FILES_DIR . 'includes/class-nobash-files-tools-rest.php';

register_activation_hook( __FILE__, array( 'Nobash_Files_DB', 'activate' ) );
register_activation_hook( __FILE__, array( 'Nobash_Files_Onboarding', 'activate' ) );
register_activation_hook( __FILE__, array( 'Nobash_Files_Runtime', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Nobash_Files_Runtime', 'deactivate' ) );
register_deactivation_hook( __FILE__, array( 'Nobash_Files_Multisite', 'deactivate' ) );

add_action( 'plugins_loaded', static function () {
    Nobash_Files_Runtime::init();
    Nobash_Files_DB::maybe_upgrade();
    Nobash_Files_Access::init();
    Nobash_Files_Health::init();
    Nobash_Files_Privacy::init();
    Nobash_Files_Explorer::init();
    Nobash_Files_REST::init();
    Nobash_Files_Multisite::init();
    Nobash_Files_Monitor::init();
    Nobash_Files_Tools_REST::init();
    if ( is_admin() ) { Nobash_Files_Onboarding::init(); }
    if ( is_admin() ) { Nobash_Files_Admin::init(); }
    do_action( 'nobash_files_loaded', NOBASH_FILES_VERSION );
}, 10 );
