<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WordPress Multisite integration.
 *
 * Important: WordPress Multisite shares core/plugins/themes across the network.
 * Per-site filesystem workspaces therefore intentionally point at the site's
 * real uploads directory. Shared code remains a network-level concern.
 */
final class Nobash_Files_Multisite {
    private const SITE_LIMIT = 250;

    public static function init(): void {
        if ( ! is_multisite() ) {
            return;
        }
        add_action( 'wp_initialize_site', array( __CLASS__, 'initialize_site' ), 20, 2 );
    }

    public static function deactivate( bool $network_wide = false ): void {
        if ( is_multisite() && $network_wide ) {
            $offset = 0;
            $batch  = 100;
            do {
                $site_ids = get_sites( array( 'fields' => 'ids', 'number' => $batch, 'offset' => $offset, 'orderby' => 'id', 'order' => 'ASC' ) );
                foreach ( $site_ids as $site_id ) {
                    switch_to_blog( (int) $site_id );
                    try {
                        Nobash_Files_Monitor::deactivate();
                    } finally {
                        restore_current_blog();
                    }
                }
                $offset += count( $site_ids );
            } while ( count( $site_ids ) === $batch );
            return;
        }
        Nobash_Files_Monitor::deactivate();
    }

    public static function initialize_site( WP_Site $site, array $args = array() ): void {
        if ( ! is_multisite() || empty( $site->blog_id ) ) {
            return;
        }
        switch_to_blog( (int) $site->blog_id );
        try {
            Nobash_Files_DB::activate( false );
        } finally {
            restore_current_blog();
        }
    }

    public static function can_network_admin(): bool {
        return is_multisite() && is_super_admin() && current_user_can( 'manage_network_options' );
    }

    public static function admin_payload(): array {
        if ( ! self::can_network_admin() ) {
            throw new RuntimeException( 'Network administrator access is required.' );
        }

        $total = (int) get_sites( array( 'count' => true ) );
        $sites = get_sites( array(
            'number'  => self::SITE_LIMIT,
            'orderby' => 'id',
            'order'   => 'ASC',
        ) );

        $rows = array();
        foreach ( $sites as $site ) {
            if ( ! $site instanceof WP_Site ) {
                continue;
            }
            $rows[] = self::site_summary( (int) $site->blog_id );
        }

        $healthy = 0;
        $review  = 0;
        $unknown = 0;
        foreach ( $rows as $row ) {
            $status = (string) ( $row['integrity']['status'] ?? 'unknown' );
            if ( 'clean' === $status ) {
                $healthy++;
            } elseif ( in_array( $status, array( 'warning', 'critical', 'incomplete' ), true ) ) {
                $review++;
            } else {
                $unknown++;
            }
        }

        $network_id = function_exists( 'get_current_network_id' ) ? (int) get_current_network_id() : 1;
        return array(
            'enabled'       => true,
            'network_id'    => $network_id,
            'network_name'  => (string) get_network_option( $network_id, 'site_name', get_bloginfo( 'name' ) ),
            'main_site_id'  => function_exists( 'get_main_site_id' ) ? (int) get_main_site_id() : 1,
            'total_sites'   => $total,
            'listed_sites'  => count( $rows ),
            'truncated'     => $total > count( $rows ),
            'site_limit'    => self::SITE_LIMIT,
            'summary'       => array( 'verified' => $healthy, 'review' => $review, 'unknown' => $unknown ),
            'sites'         => $rows,
            'shared_paths'  => self::shared_paths(),
        );
    }

    public static function site_summary( int $blog_id ): array {
        $site = get_site( $blog_id );
        if ( ! $site ) {
            throw new InvalidArgumentException( 'Network site not found.' );
        }

        switch_to_blog( $blog_id );
        try {
            $uploads   = wp_upload_dir( null, false );
            $upload_dir = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
            $relative  = self::relative_to_root( $upload_dir );
            $theme     = wp_get_theme();
            $scan      = Nobash_Files_Integrity::last_scan();
            $monitor   = Nobash_Files_Monitor::settings();
            $alerts    = Nobash_Files_Monitor::alerts();
            $active_plugins = (array) get_option( 'active_plugins', array() );
            $network_plugins = (array) get_site_option( 'active_sitewide_plugins', array() );
            $critical_alerts = 0;
            $open_alerts = 0;
            foreach ( $alerts as $alert ) {
                if ( ! is_array( $alert ) || ! empty( $alert['resolved_at'] ) || ! empty( $alert['dismissed_at'] ) ) {
                    continue;
                }
                $open_alerts++;
                if ( 'critical' === ( $alert['severity'] ?? '' ) ) {
                    $critical_alerts++;
                }
            }

            $inside_root = '' !== $relative || untrailingslashit( wp_normalize_path( $upload_dir ) ) === untrailingslashit( wp_normalize_path( ABSPATH ) );
            return array(
                'id'               => $blog_id,
                'name'             => (string) get_bloginfo( 'name' ),
                'url'              => (string) home_url( '/' ),
                'admin_url'        => $inside_root ? (string) admin_url( 'admin.php?page=nobash-files&nobash_files_workspace=site' ) : '',
                'integrity_url'    => (string) admin_url( 'admin.php?page=nobash-files-integrity' ),
                'security_url'     => (string) admin_url( 'admin.php?page=nobash-files-security' ),
                'access_url'       => (string) admin_url( 'admin.php?page=nobash-files-access' ),
                'uploads'          => array(
                    'path'       => $relative,
                    'inside_root'=> $inside_root,
                    'writable'   => $upload_dir ? wp_is_writable( $upload_dir ) : false,
                    'error'      => (string) ( $uploads['error'] ?? '' ),
                ),
                'theme'            => array(
                    'name'    => (string) $theme->get( 'Name' ),
                    'version' => (string) $theme->get( 'Version' ),
                    'slug'    => (string) $theme->get_stylesheet(),
                ),
                'plugins'          => array(
                    'site_active'    => count( $active_plugins ),
                    'network_active' => count( $network_plugins ),
                ),
                'integrity'        => self::scan_summary( $scan ),
                'monitoring'       => array(
                    'enabled' => ! empty( $monitor['enabled'] ),
                    'schedule'=> (string) ( $monitor['schedule'] ?? '' ),
                ),
                'alerts'           => array( 'open' => $open_alerts, 'critical' => $critical_alerts ),
            );
        } finally {
            restore_current_blog();
        }
    }

    private static function scan_summary( array $scan ): array {
        if ( empty( $scan ) ) {
            return array( 'status' => 'unknown', 'message' => 'Not scanned', 'scanned_at' => '' );
        }
        $summary = isset( $scan['summary'] ) && is_array( $scan['summary'] ) ? $scan['summary'] : array();
        return array(
            'status'     => sanitize_key( (string) ( $summary['status'] ?? ( ! empty( $scan['complete'] ) ? 'unknown' : 'incomplete' ) ) ),
            'message'    => sanitize_text_field( (string) ( $summary['message'] ?? '' ) ),
            'scanned_at' => sanitize_text_field( (string) ( $scan['scanned_at'] ?? '' ) ),
            'issues'     => absint( $summary['issues'] ?? 0 ),
            'complete'   => isset( $scan['complete'] ) ? (bool) $scan['complete'] : null,
        );
    }

    public static function shared_paths(): array {
        $paths = array();
        foreach ( array(
            'WordPress Core' => ABSPATH,
            'Plugins'        => WP_PLUGIN_DIR,
            'Themes'         => get_theme_root(),
        ) as $label => $absolute ) {
            $paths[] = array(
                'label' => $label,
                'path'  => self::relative_to_root( (string) $absolute ),
                'shared'=> true,
            );
        }
        return $paths;
    }

    public static function site_workspace_path( ?int $blog_id = null ): string {
        if ( ! is_multisite() ) {
            return 'wp-content/uploads';
        }
        $blog_id = $blog_id ?: (int) get_current_blog_id();
        if ( $blog_id !== (int) get_current_blog_id() ) {
            switch_to_blog( $blog_id );
            try {
                $uploads = wp_upload_dir( null, false );
                return self::relative_to_root( (string) ( $uploads['basedir'] ?? '' ) );
            } finally {
                restore_current_blog();
            }
        }
        $uploads = wp_upload_dir( null, false );
        return self::relative_to_root( (string) ( $uploads['basedir'] ?? '' ) );
    }

    public static function relative_to_root( string $absolute ): string {
        $root = trailingslashit( wp_normalize_path( ABSPATH ) );
        $path = wp_normalize_path( $absolute );
        if ( '' === $path ) {
            return '';
        }
        if ( untrailingslashit( $path ) === untrailingslashit( $root ) ) {
            return '';
        }
        if ( ! str_starts_with( trailingslashit( $path ), $root ) ) {
            return '';
        }
        return Nobash_Files_Access::normalize_path( substr( $path, strlen( $root ) ) );
    }
}
