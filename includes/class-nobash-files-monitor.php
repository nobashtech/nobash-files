<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Scheduled integrity monitoring, update awareness, and notification delivery.
 *
 * Security/reliability rules:
 * - Scheduling is opt-in and bounded by the Integrity scanner safety budget.
 * - A successful WordPress update is context only; it never refreshes/trusts a baseline automatically.
 * - Duplicate alerts are fingerprint-throttled to avoid mail/admin-notice floods.
 * - Incomplete scans can never emit an "all clear" notification.
 */
final class Nobash_Files_Monitor {
    private const SETTINGS_OPTION = 'nobash_files_monitor_settings_v1';
    private const ALERTS_OPTION   = 'nobash_files_monitor_alerts_v1';
    private const UPDATES_OPTION  = 'nobash_files_monitor_updates_v1';
    private const STATE_OPTION    = 'nobash_files_monitor_state_v1';
    private const CRON_HOOK       = 'nobash_files_integrity_scheduled_scan';
    private const POST_UPDATE_HOOK = 'nobash_files_integrity_post_update_scan';
    private const LOCK_OPTION     = 'nobash_files_monitor_scan_lock_v1';
    private const MAX_ALERTS      = 50;
    private const MAX_UPDATES     = 30;
    private const UPDATE_WINDOW   = 259200; // 72 hours.

    public static function deactivate(): void {
        wp_clear_scheduled_hook( self::CRON_HOOK );
        wp_clear_scheduled_hook( self::POST_UPDATE_HOOK );
        delete_option( self::LOCK_OPTION );
    }

    public static function init(): void {
        add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
        add_action( self::CRON_HOOK, array( __CLASS__, 'run_scheduled_scan' ) );
        add_action( self::POST_UPDATE_HOOK, array( __CLASS__, 'run_post_update_scan' ) );
        add_action( 'upgrader_process_complete', array( __CLASS__, 'record_update' ), 10, 2 );
        add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
        add_action( 'admin_init', array( __CLASS__, 'ensure_schedule' ) );
    }

    public static function defaults(): array {
        $admin_email = (string) get_option( 'admin_email', '' );
        return array(
            'enabled'              => false,
            'frequency'            => 'daily',
            'email_enabled'        => false,
            'email_to'             => sanitize_email( $admin_email ),
            'notify_warning'       => true,
            'notify_critical'      => true,
            'notify_incomplete'    => true,
            'notify_update_related'=> true,
            'throttle_hours'       => 12,
        );
    }

    public static function settings(): array {
        $stored = get_option( self::SETTINGS_OPTION, array() );
        $stored = is_array( $stored ) ? $stored : array();
        $settings = array_merge( self::defaults(), $stored );
        $settings['enabled'] = ! empty( $settings['enabled'] );
        $settings['email_enabled'] = ! empty( $settings['email_enabled'] );
        $settings['notify_warning'] = ! empty( $settings['notify_warning'] );
        $settings['notify_critical'] = ! empty( $settings['notify_critical'] );
        $settings['notify_incomplete'] = ! empty( $settings['notify_incomplete'] );
        $settings['notify_update_related'] = ! empty( $settings['notify_update_related'] );
        $settings['frequency'] = in_array( $settings['frequency'], array( 'twicedaily', 'daily', 'weekly' ), true ) ? $settings['frequency'] : 'daily';
        $settings['email_to'] = sanitize_email( (string) $settings['email_to'] );
        $settings['throttle_hours'] = max( 1, min( 72, absint( $settings['throttle_hours'] ) ) );
        return $settings;
    }

    public static function update_settings( array $input ): array {
        $settings = self::settings();
        foreach ( array( 'enabled', 'email_enabled', 'notify_warning', 'notify_critical', 'notify_incomplete', 'notify_update_related' ) as $key ) {
            if ( array_key_exists( $key, $input ) ) {
                $settings[ $key ] = rest_sanitize_boolean( $input[ $key ] );
            }
        }
        if ( array_key_exists( 'frequency', $input ) ) {
            $frequency = sanitize_key( (string) $input['frequency'] );
            if ( in_array( $frequency, array( 'twicedaily', 'daily', 'weekly' ), true ) ) {
                $settings['frequency'] = $frequency;
            }
        }
        if ( array_key_exists( 'email_to', $input ) ) {
            $email = sanitize_email( (string) $input['email_to'] );
            if ( '' !== (string) $input['email_to'] && '' === $email ) {
                throw new InvalidArgumentException( 'Enter a valid notification email address.' );
            }
            $settings['email_to'] = $email;
        }
        if ( array_key_exists( 'throttle_hours', $input ) ) {
            $settings['throttle_hours'] = max( 1, min( 72, absint( $input['throttle_hours'] ) ) );
        }
        if ( false === get_option( self::SETTINGS_OPTION, false ) ) {
            add_option( self::SETTINGS_OPTION, $settings, '', false );
        } else {
            update_option( self::SETTINGS_OPTION, $settings, false );
        }
        self::reschedule();
        Nobash_Files_DB::log( 'monitor_settings', '', array( 'enabled' => $settings['enabled'], 'frequency' => $settings['frequency'], 'email_enabled' => $settings['email_enabled'] ) );
        return self::settings();
    }

    public static function cron_schedules( array $schedules ): array {
        if ( ! isset( $schedules['weekly'] ) ) {
            $schedules['weekly'] = array( 'interval' => WEEK_IN_SECONDS, 'display' => 'Once Weekly' );
        }
        return $schedules;
    }

    public static function ensure_schedule(): void {
        $settings = self::settings();
        $next = wp_next_scheduled( self::CRON_HOOK );
        if ( ! $settings['enabled'] ) {
            if ( $next ) {
                wp_clear_scheduled_hook( self::CRON_HOOK );
            }
            return;
        }
        if ( ! $next ) {
            wp_schedule_event( time() + 300, $settings['frequency'], self::CRON_HOOK );
        }
    }

    private static function reschedule(): void {
        wp_clear_scheduled_hook( self::CRON_HOOK );
        $settings = self::settings();
        if ( $settings['enabled'] ) {
            wp_schedule_event( time() + 300, $settings['frequency'], self::CRON_HOOK );
        }
    }

    public static function schedule_state(): array {
        $state = get_option( self::STATE_OPTION, array() );
        $state = is_array( $state ) ? $state : array();
        return array(
            'next_run'     => (int) ( wp_next_scheduled( self::CRON_HOOK ) ?: 0 ),
            'last_run'     => (string) ( $state['last_run'] ?? '' ),
            'last_status'  => (string) ( $state['last_status'] ?? '' ),
            'last_source'  => (string) ( $state['last_source'] ?? '' ),
            'last_error'   => (string) ( $state['last_error'] ?? '' ),
        );
    }

    public static function run_scheduled_scan(): void {
        self::run_automatic_scan( 'scheduled' );
    }

    public static function run_post_update_scan(): void {
        self::run_automatic_scan( 'post_update' );
    }

    private static function run_automatic_scan( string $source ): void {
        $settings = self::settings();
        if ( ! $settings['enabled'] || ! self::acquire_lock() ) {
            return;
        }
        try {
            $scan = ( new Nobash_Files_Integrity() )->scan();
            self::after_scan( $scan, $source );
            self::save_state( array( 'last_run' => current_time( 'mysql' ), 'last_status' => (string) ( $scan['summary']['status'] ?? '' ), 'last_source' => $source, 'last_error' => '' ) );
        } catch ( Throwable $e ) {
            self::save_state( array( 'last_run' => current_time( 'mysql' ), 'last_status' => 'error', 'last_source' => $source, 'last_error' => sanitize_text_field( $e->getMessage() ) ) );
            self::create_system_alert( 'critical', 'Automatic integrity scan failed', 'Nobash Files could not complete the automatic integrity scan: ' . $e->getMessage(), $source . '_error:' . hash( 'sha256', $e->getMessage() ) );
            Nobash_Files_DB::log( 'integrity_automatic_error', '', array( 'source' => $source, 'message' => $e->getMessage() ) );
        } finally {
            self::release_lock();
        }
    }

    private static function acquire_lock(): bool {
        $now = time();
        $current = (int) get_option( self::LOCK_OPTION, 0 );
        if ( $current > $now - 600 ) {
            return false;
        }
        update_option( self::LOCK_OPTION, $now, false );
        return true;
    }

    private static function release_lock(): void {
        delete_option( self::LOCK_OPTION );
    }

    private static function save_state( array $patch ): void {
        $state = get_option( self::STATE_OPTION, array() );
        $state = is_array( $state ) ? $state : array();
        $state = array_merge( $state, $patch );
        if ( false === get_option( self::STATE_OPTION, false ) ) {
            add_option( self::STATE_OPTION, $state, '', false );
        } else {
            update_option( self::STATE_OPTION, $state, false );
        }
    }

    /**
     * Called after both manual and scheduled scans. Returns update-correlation metadata and
     * creates a deduplicated alert when policy requires one.
     */
    public static function after_scan( array $scan, string $source = 'manual' ): array {
        $awareness = self::correlate_scan( $scan );
        if ( 'clean' === (string) ( $scan['summary']['status'] ?? '' ) ) {
            self::resolve_open_scan_alerts();
        } else {
            self::maybe_alert_for_scan( $scan, $awareness, $source );
        }
        if ( 'manual' === $source ) {
            self::save_state( array( 'last_run' => current_time( 'mysql' ), 'last_status' => (string) ( $scan['summary']['status'] ?? '' ), 'last_source' => 'manual', 'last_error' => '' ) );
        }
        return $awareness;
    }

    public static function correlate_scan( array $scan ): array {
        $updates = self::recent_updates();
        $recent = array_values( array_filter( $updates, static fn( $row ) => (int) ( $row['timestamp'] ?? 0 ) >= time() - self::UPDATE_WINDOW ) );
        $component_map = array();
        foreach ( $recent as $row ) {
            $type = self::normalize_component_type( (string) ( $row['type'] ?? '' ) );
            foreach ( (array) ( $row['components'] ?? array() ) as $component ) {
                $component_map[ $type . ':' . self::normalize_component( $type, (string) $component ) ] = $row;
            }
        }
        $related = array();
        $unexpected = array();
        foreach ( array( 'modified', 'added', 'removed' ) as $kind ) {
            foreach ( (array) ( $scan['content'][ $kind ] ?? array() ) as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                $type = self::normalize_component_type( (string) ( $item['type'] ?? '' ) );
                $component = (string) ( $item['component'] ?? '' );
                $key = $type . ':' . self::normalize_component( $type, $component );
                $entry = array( 'kind' => $kind, 'path' => (string) ( $item['path'] ?? '' ), 'type' => $type, 'component' => $component );
                if ( isset( $component_map[ $key ] ) ) {
                    $entry['update'] = $component_map[ $key ];
                    $related[] = $entry;
                } else {
                    $unexpected[] = $entry;
                }
            }
        }
        return array(
            'window_hours' => (int) ( self::UPDATE_WINDOW / HOUR_IN_SECONDS ),
            'recent_updates' => array_slice( $recent, 0, 10 ),
            'related_changes' => array_slice( $related, 0, 250 ),
            'unexpected_changes' => array_slice( $unexpected, 0, 250 ),
            'related_count' => count( $related ),
            'unexpected_count' => count( $unexpected ),
            'message' => count( $related ) ? 'Some plugin/theme changes line up with recent successful WordPress updates. Review them before refreshing the trusted baseline.' : 'No current plugin/theme changes were correlated with a recent recorded update.',
        );
    }

    public static function record_update( $upgrader, array $hook_extra ): void {
        if ( is_object( $upgrader ) && isset( $upgrader->result ) && ( false === $upgrader->result || ( function_exists( 'is_wp_error' ) && is_wp_error( $upgrader->result ) ) ) ) {
            return;
        }
        $action = sanitize_key( (string) ( $hook_extra['action'] ?? '' ) );
        if ( 'update' !== $action ) {
            return;
        }
        $type = sanitize_key( (string) ( $hook_extra['type'] ?? '' ) );
        if ( ! in_array( $type, array( 'plugin', 'theme', 'core' ), true ) ) {
            return;
        }
        $components = array();
        if ( 'plugin' === $type ) {
            $raw = array();
            if ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
                $raw = $hook_extra['plugins'];
            } elseif ( ! empty( $hook_extra['plugin'] ) ) {
                $raw = array( $hook_extra['plugin'] );
            }
            foreach ( $raw as $plugin_file ) {
                $plugin_file = wp_normalize_path( (string) $plugin_file );
                $parts = explode( '/', ltrim( $plugin_file, '/' ) );
                $components[] = self::normalize_component( 'plugin', $parts[0] ?: basename( $plugin_file ) );
            }
        } elseif ( 'theme' === $type ) {
            $raw = array();
            if ( ! empty( $hook_extra['themes'] ) && is_array( $hook_extra['themes'] ) ) {
                $raw = $hook_extra['themes'];
            } elseif ( ! empty( $hook_extra['theme'] ) ) {
                $raw = array( $hook_extra['theme'] );
            }
            foreach ( $raw as $theme ) {
                $components[] = self::normalize_component( 'theme', (string) $theme );
            }
        } else {
            $components[] = 'wordpress-core';
        }
        $components = array_values( array_unique( array_filter( $components ) ) );
        $updates = self::recent_updates();
        array_unshift( $updates, array(
            'id'         => wp_generate_uuid4(),
            'timestamp'  => time(),
            'recorded_at'=> current_time( 'mysql' ),
            'type'       => $type,
            'action'     => $action,
            'components' => $components,
        ) );
        $updates = array_slice( $updates, 0, self::MAX_UPDATES );
        if ( false === get_option( self::UPDATES_OPTION, false ) ) {
            add_option( self::UPDATES_OPTION, $updates, '', false );
        } else {
            update_option( self::UPDATES_OPTION, $updates, false );
        }
        Nobash_Files_DB::log( 'trusted_update_recorded', '', array( 'type' => $type, 'components' => $components ) );
        if ( self::settings()['enabled'] && ! wp_next_scheduled( self::POST_UPDATE_HOOK ) ) {
            wp_schedule_single_event( time() + 300, self::POST_UPDATE_HOOK );
        }
    }

    private static function normalize_component_type( string $type ): string {
        $type = sanitize_key( $type );
        return array( 'plugin' => 'plugins', 'plugins' => 'plugins', 'theme' => 'themes', 'themes' => 'themes', 'mu-plugin' => 'mu-plugins', 'mu-plugins' => 'mu-plugins' )[ $type ] ?? $type;
    }

    private static function normalize_component( string $type, string $component ): string {
        $component = trim( wp_normalize_path( $component ), '/' );
        if ( in_array( $type, array( 'plugin', 'plugins' ), true ) && str_ends_with( strtolower( $component ), '.php' ) ) {
            $component = pathinfo( $component, PATHINFO_FILENAME );
        }
        return sanitize_key( $component );
    }

    public static function recent_updates(): array {
        $updates = get_option( self::UPDATES_OPTION, array() );
        return is_array( $updates ) ? array_slice( $updates, 0, self::MAX_UPDATES ) : array();
    }

    private static function maybe_alert_for_scan( array $scan, array $awareness, string $source ): void {
        $settings = self::settings();
        $status = (string) ( $scan['summary']['status'] ?? '' );
        $severity = '';
        if ( 'critical' === $status && $settings['notify_critical'] ) {
            $severity = 'critical';
        } elseif ( 'warning' === $status && $settings['notify_warning'] ) {
            $severity = 'warning';
        } elseif ( 'incomplete' === $status && $settings['notify_incomplete'] ) {
            $severity = 'incomplete';
        }
        if ( '' === $severity ) {
            return;
        }

        $summary = (array) ( $scan['summary'] ?? array() );
        $unexpected = (int) ( $awareness['unexpected_count'] ?? 0 );
        $related = (int) ( $awareness['related_count'] ?? 0 );
        $only_update_related = 'warning' === $status && 0 === $unexpected && $related > 0 && 0 === (int) ( $summary['core_modified'] ?? 0 ) && 0 === (int) ( $summary['core_missing'] ?? 0 ) && 0 === (int) ( $summary['core_unexpected'] ?? 0 ) && 0 === (int) ( $summary['suspicious_high'] ?? 0 ) && 0 === (int) ( $summary['suspicious_medium'] ?? 0 );
        if ( $only_update_related ) {
            if ( ! $settings['notify_update_related'] ) {
                return;
            }
            $severity = 'info';
        }

        $title = 'Nobash integrity: ' . self::severity_label( $severity );
        $message = (string) ( $summary['message'] ?? 'Integrity changes need review.' );
        if ( $only_update_related ) {
            $message = sprintf( '%d change(s) align with recent recorded plugin/theme updates. Review them before refreshing the trusted baseline.', $related );
        }
        $fingerprint = self::scan_fingerprint( $scan, $awareness, $severity );
        self::create_alert( $severity, $title, $message, $fingerprint, $source, $scan );
    }

    private static function scan_fingerprint( array $scan, array $awareness, string $severity ): string {
        $paths = array();
        foreach ( array( 'modified', 'missing', 'unexpected' ) as $key ) {
            foreach ( (array) ( $scan['core'][ $key ] ?? array() ) as $path ) {
                $paths[] = 'core:' . $key . ':' . (string) $path;
            }
        }
        foreach ( array( 'modified', 'added', 'removed' ) as $key ) {
            foreach ( (array) ( $scan['content'][ $key ] ?? array() ) as $item ) {
                if ( is_array( $item ) ) {
                    $paths[] = 'content:' . $key . ':' . (string) ( $item['path'] ?? '' );
                }
            }
        }
        foreach ( (array) ( $scan['suspicious'] ?? array() ) as $item ) {
            if ( is_array( $item ) ) {
                $paths[] = 'suspicious:' . (string) ( $item['rule'] ?? '' ) . ':' . (string) ( $item['path'] ?? '' );
            }
        }
        sort( $paths, SORT_STRING );
        return hash( 'sha256', $severity . '|' . implode( "\n", $paths ) . '|related:' . (int) ( $awareness['related_count'] ?? 0 ) );
    }

    private static function create_system_alert( string $severity, string $title, string $message, string $fingerprint ): void {
        self::create_alert( $severity, $title, $message, $fingerprint, 'system', array() );
    }

    private static function create_alert( string $severity, string $title, string $message, string $fingerprint, string $source, array $scan ): void {
        $settings = self::settings();
        $alerts = self::alerts();
        $cutoff = time() - ( (int) $settings['throttle_hours'] * HOUR_IN_SECONDS );
        foreach ( $alerts as $alert ) {
            if ( hash_equals( (string) ( $alert['fingerprint'] ?? '' ), $fingerprint ) && (int) ( $alert['timestamp'] ?? 0 ) >= $cutoff ) {
                return;
            }
        }
        $alert = array(
            'id'          => wp_generate_uuid4(),
            'timestamp'   => time(),
            'created_at'  => current_time( 'mysql' ),
            'severity'    => $severity,
            'title'       => sanitize_text_field( $title ),
            'message'     => sanitize_text_field( $message ),
            'fingerprint' => $fingerprint,
            'source'      => sanitize_key( $source ),
            'read'        => false,
            'issues'      => (int) ( $scan['summary']['issues'] ?? 0 ),
        );
        array_unshift( $alerts, $alert );
        $alerts = array_slice( $alerts, 0, self::MAX_ALERTS );
        if ( false === get_option( self::ALERTS_OPTION, false ) ) {
            add_option( self::ALERTS_OPTION, $alerts, '', false );
        } else {
            update_option( self::ALERTS_OPTION, $alerts, false );
        }
        Nobash_Files_DB::log( 'integrity_alert', '', array( 'severity' => $severity, 'source' => $source, 'issues' => $alert['issues'] ) );

        if ( $settings['email_enabled'] && '' !== $settings['email_to'] ) {
            self::send_email( $alert, $settings['email_to'] );
        }
    }

    private static function send_email( array $alert, string $to ): void {
        $site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
        $subject = sprintf( '[%s] %s', $site_name ?: 'WordPress', (string) $alert['title'] );
        $url = admin_url( 'admin.php?page=nobash-files-monitoring' );
        $body = (string) $alert['message'] . "\n\n";
        $body .= 'Severity: ' . self::severity_label( (string) $alert['severity'] ) . "\n";
        $body .= 'Detected: ' . (string) $alert['created_at'] . "\n";
        $body .= 'Review: ' . $url . "\n\n";
        $body .= 'This alert does not automatically trust or repair changed files.';
        wp_mail( $to, $subject, $body );
    }

    private static function severity_label( string $severity ): string {
        return array(
            'critical'   => 'Action recommended',
            'warning'    => 'Review changes',
            'incomplete' => 'Scan incomplete',
            'info'       => 'Update review',
        )[ $severity ] ?? 'Review';
    }

    public static function alerts(): array {
        $alerts = get_option( self::ALERTS_OPTION, array() );
        return is_array( $alerts ) ? array_slice( $alerts, 0, self::MAX_ALERTS ) : array();
    }

    public static function dismiss_alert( string $id ): bool {
        $alerts = self::alerts();
        $changed = false;
        foreach ( $alerts as &$alert ) {
            if ( hash_equals( (string) ( $alert['id'] ?? '' ), $id ) ) {
                $alert['read'] = true;
                $changed = true;
                break;
            }
        }
        unset( $alert );
        if ( $changed ) {
            update_option( self::ALERTS_OPTION, $alerts, false );
        }
        return $changed;
    }

    public static function mark_all_read(): void {
        $alerts = self::alerts();
        foreach ( $alerts as &$alert ) {
            $alert['read'] = true;
        }
        unset( $alert );
        update_option( self::ALERTS_OPTION, $alerts, false );
    }

    private static function resolve_open_scan_alerts(): void {
        $alerts = self::alerts();
        $changed = false;
        foreach ( $alerts as &$alert ) {
            if ( empty( $alert['resolved_at'] ) && in_array( (string) ( $alert['source'] ?? '' ), array( 'manual', 'scheduled', 'post_update' ), true ) ) {
                $alert['resolved_at'] = current_time( 'mysql' );
                $alert['read'] = true;
                $changed = true;
            }
        }
        unset( $alert );
        if ( $changed ) {
            update_option( self::ALERTS_OPTION, $alerts, false );
        }
    }

    public static function admin_payload(): array {
        $last_scan = Nobash_Files_Integrity::last_scan();
        return array(
            'settings' => self::settings(),
            'state' => self::schedule_state(),
            'alerts' => self::alerts(),
            'updates' => self::recent_updates(),
            'awareness' => $last_scan ? self::correlate_scan( $last_scan ) : array( 'recent_updates' => array(), 'related_changes' => array(), 'unexpected_changes' => array(), 'related_count' => 0, 'unexpected_count' => 0, 'message' => 'Run an integrity scan to correlate changes with recent updates.' ),
        );
    }

    public static function admin_notice(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( $screen ) {
            $id = (string) $screen->id;
            // Keep high-priority monitoring visible without polluting every wp-admin screen.
            if ( ! in_array( $id, array( 'dashboard', 'plugins' ), true ) ) { return; }
        }
        $alert = null;
        foreach ( self::alerts() as $candidate ) {
            if ( empty( $candidate['read'] ) && empty( $candidate['resolved_at'] ) && in_array( (string) ( $candidate['severity'] ?? '' ), array( 'critical', 'warning', 'incomplete' ), true ) ) {
                $alert = $candidate;
                break;
            }
        }
        if ( ! $alert ) {
            return;
        }
        $class = 'critical' === $alert['severity'] ? 'notice notice-error' : 'notice notice-warning';
        $url = admin_url( 'admin.php?page=nobash-files-monitoring' );
        echo '<div class="' . esc_attr( $class ) . '"><p><strong>' . esc_html( $alert['title'] ) . '</strong> ' . esc_html( $alert['message'] ) . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Review monitoring', 'nobash-files' ) . '</a></p></div>';
    }
}
