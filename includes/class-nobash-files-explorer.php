<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Deterministic Explorer transport.
 *
 * The File Manager no longer depends on REST or admin-ajax for its first render.
 * Initial directory data is created server-side while the admin page is built.
 * Later directory navigation uses one dedicated admin-post endpoint with a framed
 * payload so unrelated notices/HTML cannot be mistaken for directory JSON.
 */
final class Nobash_Files_Explorer {
    private const FRAME_START = '<<<NOBASH_FILES_EXPLORER_V1>>>';
    private const FRAME_END   = '<<<END_NOBASH_FILES_EXPLORER_V1>>>';

    public static function init(): void {
        add_action( 'admin_post_nobash_files_explorer_list', array( __CLASS__, 'handle_list' ) );
    }

    private static function fs(): Nobash_Files_Filesystem {
        return new Nobash_Files_Filesystem();
    }

    public static function payload( string $path, $cursor = 0, int $limit = 0 ): array {
        if ( ! current_user_can( 'manage_options' ) && '' === Nobash_Files_Access::normalize_path( $path ) ) {
            $path = Nobash_Files_Access::home_root();
        }

        Nobash_Files_Access::assert_permission( 'browse', array( $path ) );
        $data = self::fs()->list_directory( $path, $cursor, max( 0, $limit ) );

        if ( current_user_can( 'manage_options' ) ) {
            $total = @disk_total_space( ABSPATH );
            $free  = @disk_free_space( ABSPATH );
            if ( false !== $total && false !== $free && $total > 0 ) {
                $data['storage'] = array(
                    'total'   => (int) $total,
                    'free'    => (int) $free,
                    'used'    => max( 0, (int) $total - (int) $free ),
                    'percent' => max( 0, min( 100, round( ( ( $total - $free ) / $total ) * 100, 1 ) ) ),
                );
            }
        }

        if ( ! current_user_can( 'manage_options' ) && ! empty( $data['items'] ) && is_array( $data['items'] ) ) {
            $data['items'] = array_values(
                array_filter(
                    $data['items'],
                    static fn( $item ) => is_array( $item ) && Nobash_Files_Access::is_path_allowed( (string) ( $item['path'] ?? '' ) )
                )
            );
        }

        return $data;
    }

    public static function bootstrap( string $path, int $limit = 0 ): array {
        try {
            return array(
                'ok'   => true,
                'data' => self::payload( $path, 0, $limit ),
            );
        } catch ( Throwable $e ) {
            return array(
                'ok'    => false,
                'error' => self::error_array( $e ),
            );
        }
    }

    public static function clean_path( string $path ): string {
        try {
            return Nobash_Files_Access::normalize_path( $path );
        } catch ( Throwable $e ) {
            return '';
        }
    }

    private static function error_array( Throwable $e ): array {
        $code    = 'nobash_files_explorer_error';
        $status  = 400;
        $message = $e->getMessage();

        if ( $e instanceof Nobash_Files_Access_Denied ) {
            $status = 403;
            if ( 'ACCESS_PATH_DENIED' === $e->getMessage() ) {
                $code = 'nobash_files_access_path_denied';
                $message = 'Your file access profile does not allow this folder or file.';
            } elseif ( 'ACCESS_PROTECTED_DENIED' === $e->getMessage() ) {
                $code = 'nobash_files_access_protected_denied';
                $message = 'Your access profile does not allow changes to protected WordPress files.';
            } else {
                $code = 'nobash_files_access_permission_denied';
                $message = 'Your file access profile does not allow this action.';
            }
        } elseif ( $e instanceof InvalidArgumentException ) {
            $code   = 'nobash_files_invalid_request';
            $status = 422;
        }

        return array(
            'code'    => $code,
            'message' => $message ?: 'Unable to load this folder.',
            'status'  => $status,
        );
    }

    private static function send_frame( array $payload, int $status = 200 ): void {
        // Remove buffered notices/markup before the framed response when possible.
        while ( ob_get_level() > 0 ) {
            @ob_end_clean();
        }

        if ( ! headers_sent() ) {
            status_header( $status );
            nocache_headers();
            header( 'Content-Type: text/plain; charset=UTF-8' );
            header( 'X-Content-Type-Options: nosniff' );
            header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
            header( 'Pragma: no-cache' );
            header( 'Vary: Cookie' );
        }

        $json = wp_json_encode( $payload );
        if ( false === $json ) {
            $json = '{"ok":false,"error":{"code":"nobash_files_encode_error","message":"Unable to encode Explorer response.","status":500}}';
            $status = 500;
        }

        echo esc_html( self::FRAME_START . base64_encode( $json ) . self::FRAME_END ); // Framed base64 transport contains only safe ASCII characters.
        exit;
    }

    public static function handle_list(): void {
        $nonce = isset( $_POST['nonce'] ) && is_string( $_POST['nonce'] )
            ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'nobash_files_explorer' ) ) {
            self::send_frame(
                array(
                    'ok'    => false,
                    'error' => array(
                        'code'    => 'nobash_files_bad_nonce',
                        'message' => 'Your Nobash session expired. Reload this admin page and try again.',
                        'status'  => 403,
                    ),
                ),
                403
            );
        }

        if ( ! Nobash_Files_Access::can_use() ) {
            self::send_frame(
                array(
                    'ok'    => false,
                    'error' => array(
                        'code'    => 'nobash_files_access_denied',
                        'message' => 'You do not have permission to use Nobash Files.',
                        'status'  => 403,
                    ),
                ),
                403
            );
        }

        try {
            $path   = isset( $_POST['path'] ) && is_string( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '';
            $cursor = isset( $_POST['cursor'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['cursor'] ) ) : '0';
            $limit  = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 0;
            self::send_frame(
                array(
                    'ok'   => true,
                    'data' => self::payload( $path, $cursor, $limit ),
                ),
                200
            );
        } catch ( Throwable $e ) {
            $error = self::error_array( $e );
            self::send_frame(
                array(
                    'ok'    => false,
                    'error' => $error,
                ),
                (int) $error['status']
            );
        }
    }
}
