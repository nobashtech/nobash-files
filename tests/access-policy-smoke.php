<?php
/** CLI-only access-policy regression test; WordPress itself is not required. */
error_reporting( E_ALL );
class WP_User {
    public int $ID = 8;
    public array $roles = array( 'editor' );
    public function exists(): bool { return true; }
}
function current_user_can( $cap ) { return false; }
function user_can( $user, $cap ) { return false; }
function wp_get_current_user() { return new WP_User(); }
function apply_filters( $name, $value ) { return $value; }
function get_option( $name, $default = false ) {
    if ( 'nobash_files_access_role_policies' === $name ) {
        return array( 'editor' => array( 'enabled' => true, 'roots' => array( 'wp-content/uploads' ), 'permissions' => array( 'upload' => true, 'edit' => true, 'archive' => true ), 'allow_protected' => true ) );
    }
    return $default;
}
function wp_upload_dir() { return array( 'error' => false, 'basedir' => ABSPATH . 'wp-content/uploads' ); }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_generate_password( $n = 16 ) { return str_repeat('a', $n); }
function wp_unslash( $value ) { return stripslashes($value); }
$root = sys_get_temp_dir() . '/nbh-files-access-' . getmypid();
mkdir( $root . '/wp-content/uploads', 0700, true );
define( 'ABSPATH', $root . '/' );
define( 'NOBASH_FILES_DIR', __DIR__ . '/../' );
require __DIR__ . '/../includes/class-nobash-files-access.php';
function should_deny( $label, $fn ) {
    try { $fn(); throw new RuntimeException( $label . ' unexpectedly ALLOWED' ); }
    catch ( Nobash_Files_Access_Denied $e ) { echo 'PASS denied: ' . $label . "\n"; }
}
function should_allow( $label, $fn ) { $fn(); echo 'PASS allowed: ' . $label . "\n"; }
should_allow( 'media file', fn() => Nobash_Files_Access::assert_permission( 'upload', array( 'wp-content/uploads/photo.jpg' ) ) );
should_allow( 'media folder', fn() => Nobash_Files_Access::assert_permission( 'upload', array( 'wp-content/uploads' ) ) );
should_deny( 'php upload', fn() => Nobash_Files_Access::assert_permission( 'upload', array( 'wp-content/uploads/shell.php' ) ) );
should_deny( 'multi-extension php upload', fn() => Nobash_Files_Access::assert_permission( 'upload', array( 'wp-content/uploads/shell.php.jpg' ) ) );
should_deny( 'webserver file', fn() => Nobash_Files_Access::assert_permission( 'edit', array( 'wp-content/uploads/.htaccess' ) ) );
should_deny( 'plugin tree', fn() => Nobash_Files_Access::assert_permission( 'upload', array( 'wp-content/plugins/code.php' ) ) );
should_deny( 'archive extraction', fn() => Nobash_Files_Access::assert_permission( 'extract', array( 'wp-content/uploads/archive.zip' ) ) );
try { Nobash_Files_Access::normalize_path( '../wp-config.php' ); throw new RuntimeException( 'Traversal unexpectedly allowed' ); }
catch ( InvalidArgumentException $e ) { echo "PASS denied: traversal\n"; }
rmdir( $root . '/wp-content/uploads' ); rmdir( $root . '/wp-content' ); rmdir( $root );
echo "Access-policy smoke tests passed.\n";
