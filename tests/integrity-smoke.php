<?php
/** Lightweight read-only scanner regression harness using WordPress function stubs. */
error_reporting( E_ALL );
$root = sys_get_temp_dir() . '/nobash-integrity-' . getmypid();
mkdir( $root . '/wp-admin/includes', 0700, true );
mkdir( $root . '/wp-content/uploads', 0700, true );
file_put_contents( $root . '/wp-admin/includes/update.php', '<?php // Test stub' );
file_put_contents( $root . '/wp-admin/readme.html', 'changed' );
file_put_contents( $root . '/wp-content/uploads/good.jpg', 'media' );
file_put_contents( $root . '/wp-content/uploads/evil.php', '<?php echo 1;' );
file_put_contents( $root . '/wp-content/uploads/.htaccess', 'Options -Indexes' );
define( 'ABSPATH', $root . '/' );
function add_action( $a, $b ) {}
function current_user_can( $cap ) { return true; }
function current_time( $format ) { return '2026-10-16 12:00:00'; }
function get_bloginfo( $kind ) { return '7.1.3'; }
function get_locale() { return 'en_US'; }
function get_core_checksums( $version, $locale ) { return array( 'wp-admin/readme.html' => md5( 'original' ), 'missing.php' => md5( 'gone' ) ); }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
function wp_get_upload_dir() { return array( 'error' => false, 'basedir' => ABSPATH . 'wp-content/uploads' ); }
$GLOBALS['report'] = null;
function update_option( $key, $value, $autoload = true ) { $GLOBALS['report'] = $value; return true; }
require __DIR__ . '/../distribution/wporg-file-integrity/nobash-file-integrity.php';
$report = Nobash_File_Integrity::scan();
foreach ( array( 'core_changed' => 1, 'core_missing' => 1, 'upload_executable' => 2 ) as $key => $expected ) {
    if ( $report['counts'][$key] !== $expected ) { throw new RuntimeException( "$key failed: expected $expected, got {$report['counts'][$key]}" ); }
}
if ( 'findings' !== $report['status'] || ! $report['complete'] ) { throw new RuntimeException( 'Expected complete scan with findings.' ); }
if ( ! isset( $GLOBALS['report'] ) ) { throw new RuntimeException( 'Scan was not persisted.' ); }
echo "PASS core mismatch, missing core file, executable uploads, scan status.\n";
foreach ( array( 'wp-admin/readme.html', 'wp-admin/includes/update.php', 'wp-content/uploads/good.jpg', 'wp-content/uploads/evil.php', 'wp-content/uploads/.htaccess' ) as $rel ) { unlink( $root . '/' . $rel ); }
foreach ( array( 'wp-admin/includes','wp-admin', 'wp-content/uploads','wp-content' ) as $rel ) { rmdir( $root . '/' . $rel ); }
rmdir( $root );
