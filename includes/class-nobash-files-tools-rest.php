<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Administrator tools included in the free plugin. */
final class Nobash_Files_Tools_REST {
    public static function init(): void {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ), 20 );
    }

    public static function routes(): void {
        foreach ( array( 'access', 'monitoring', 'security', 'multisite', 'agency' ) as $tool ) {
            $permission = 'multisite' === $tool ? 'can_network' : ( 'agency' === $tool ? 'can_agency' : 'can_admin' );
            register_rest_route( 'nobash-files/v1', '/' . $tool, array(
                'methods'             => in_array( $tool, array( 'monitoring', 'multisite' ), true ) ? array( 'GET', 'POST' ) : array( 'GET', 'POST', 'DELETE' ),
                'permission_callback' => array( __CLASS__, $permission ),
                'callback'            => static function ( WP_REST_Request $request ) use ( $tool ) {
                    try {
                        return rest_ensure_response( self::$tool( $request ) );
                    } catch ( Throwable $e ) {
                        $status = $e instanceof Nobash_Files_Access_Denied ? 403 : ( $e instanceof InvalidArgumentException ? 422 : 400 );
                        return new WP_Error( 'nobash_files_tools_error', $e->getMessage(), array( 'status' => $status ) );
                    }
                },
            ) );
        }
    }

    public static function can_admin(): bool {
        return current_user_can( 'manage_options' );
    }

    public static function can_network(): bool {
        return Nobash_Files_Multisite::can_network_admin();
    }

    public static function can_agency(): bool {
        return Nobash_Files_Agency::can_manage();
    }

    private static function action( WP_REST_Request $request ): string {
        return sanitize_key( (string) $request->get_param( 'action' ) );
    }

    private static function text( WP_REST_Request $request, string $key ): string {
        return sanitize_text_field( (string) $request->get_param( $key ) );
    }

    private static function flag( WP_REST_Request $request, string $key ): bool {
        return rest_sanitize_boolean( $request->get_param( $key ) ?? false );
    }

    private static function data( WP_REST_Request $request, string $key ): array {
        $value = $request->get_param( $key );
        if ( ! is_array( $value ) ) {
            throw new InvalidArgumentException( 'A valid ' . $key . ' object or list is required.' );
        }
        return $value;
    }

    private static function access( WP_REST_Request $request ): array {
        if ( 'GET' === $request->get_method() ) {
            return Nobash_Files_Access::admin_payload();
        }
        $type = sanitize_key( (string) $request->get_param( 'type' ) );
        $id = self::text( $request, 'id' );
        if ( 'DELETE' === $request->get_method() || 'delete' === self::action( $request ) ) {
            Nobash_Files_Access::delete_policy( $type, $id );
            return array( 'deleted' => true );
        }
        return array( 'policy' => Nobash_Files_Access::save_policy( $type, $id, self::data( $request, 'policy' ) ) );
    }

    private static function monitoring( WP_REST_Request $request ): array {
        if ( 'GET' === $request->get_method() ) {
            return Nobash_Files_Monitor::admin_payload();
        }
        switch ( self::action( $request ) ) {
            case 'settings':
                return array( 'settings' => Nobash_Files_Monitor::update_settings( self::data( $request, 'settings' ) ), 'state' => Nobash_Files_Monitor::schedule_state() );
            case 'dismiss':
                $id = self::text( $request, 'id' );
                if ( '' === $id ) { throw new InvalidArgumentException( 'Alert ID is required.' ); }
                return array( 'dismissed' => Nobash_Files_Monitor::dismiss_alert( $id ), 'alerts' => Nobash_Files_Monitor::alerts() );
            case 'mark_all_read':
                Nobash_Files_Monitor::mark_all_read();
                return array( 'alerts' => Nobash_Files_Monitor::alerts() );
            case 'scan_now':
                $scan = ( new Nobash_Files_Integrity() )->scan();
                return array( 'scan' => $scan, 'awareness' => Nobash_Files_Monitor::after_scan( $scan, 'manual' ), 'monitoring' => Nobash_Files_Monitor::admin_payload() );
        }
        throw new InvalidArgumentException( 'Unsupported monitoring action.' );
    }

    private static function security( WP_REST_Request $request ): array {
        $security = new Nobash_Files_Security();
        if ( 'GET' === $request->get_method() ) {
            return $security->payload();
        }
        $id = self::text( $request, 'id' );
        if ( 'DELETE' === $request->get_method() || 'delete_quarantine' === self::action( $request ) ) {
            $security->delete_quarantine( $id );
            return array( 'deleted' => true );
        }
        return match ( self::action( $request ) ) {
            'quarantine' => $security->quarantine_file( (string) $request->get_param( 'path' ), self::text( $request, 'reason' ) ?: 'Manual security quarantine' ),
            'restore_quarantine' => $security->restore_quarantine( $id, null === $request->get_param( 'keep_both' ) || self::flag( $request, 'keep_both' ) ),
            'permissions', 'scan_permissions' => $security->permission_diagnostics( true ),
            'repair_permission' => $security->repair_world_write( (string) $request->get_param( 'path' ) ),
            'repair_core' => $security->repair_core( self::data( $request, 'paths' ) ),
            'rollback_core' => $security->rollback_core_repair( self::text( $request, 'repair_id' ) ),
            'verify_component' => $security->verify_official_component( self::text( $request, 'type' ), self::text( $request, 'slug' ) ),
            default => throw new InvalidArgumentException( 'Unsupported security action.' ),
        };
    }

    private static function multisite( WP_REST_Request $request ): array {
        if ( ! is_multisite() ) {
            throw new RuntimeException( 'WordPress Multisite is not enabled.' );
        }
        return 'POST' === $request->get_method() && 'site_summary' === self::action( $request )
            ? Nobash_Files_Multisite::site_summary( absint( $request->get_param( 'site_id' ) ) )
            : Nobash_Files_Multisite::admin_payload();
    }

    private static function agency( WP_REST_Request $request ): array {
        $action = self::action( $request );
        $site = absint( $request->get_param( 'site_id' ) );
        if ( 'GET' === $request->get_method() ) {
            return match ( $action ) {
                'subjects' => Nobash_Files_Agency::subjects( $site ),
                'audit' => array( 'rows' => Nobash_Files_Agency::audit_rows( array( 'site_id' => $site, 'action' => self::text( $request, 'audit_action' ), 'user_id' => absint( $request->get_param( 'user_id' ) ) ), min( 500, max( 1, absint( $request->get_param( 'limit' ) ) ?: 100 ) ) ) ),
                default => Nobash_Files_Agency::payload(),
            };
        }
        $template = sanitize_key( (string) $request->get_param( 'template_id' ) );
        if ( 'DELETE' === $request->get_method() || 'delete_template' === $action ) {
            Nobash_Files_Agency::delete_template( $template );
            return Nobash_Files_Agency::payload();
        }
        return match ( $action ) {
            'save_template' => array( 'template' => Nobash_Files_Agency::save_template( self::data( $request, 'template' ) ), 'agency' => Nobash_Files_Agency::payload() ),
            'apply_template' => array( 'policy' => Nobash_Files_Agency::apply_template( $template, self::text( $request, 'type' ), self::text( $request, 'subject_id' ), $site ) ),
            default => throw new InvalidArgumentException( 'Unsupported agency action.' ),
        };
    }
}
