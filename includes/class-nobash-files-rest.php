<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Nobash_Files_REST {
    public static function init(): void {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
    }

    public static function routes(): void {
        $user    = array( 'permission_callback' => array( __CLASS__, 'can_use' ) );
        $admin   = array( 'permission_callback' => array( __CLASS__, 'can_admin' ) );

        register_rest_route( 'nobash-files/v1', '/list', $user + array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'list_dir' ) ) );
        register_rest_route( 'nobash-files/v1', '/file', $user + array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'read_file' ) ) );
        register_rest_route( 'nobash-files/v1', '/file', $user + array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_file' ) ) );
        register_rest_route( 'nobash-files/v1', '/validate', $user + array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'validate_file' ) ) );
        register_rest_route( 'nobash-files/v1', '/info', $user + array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'info' ) ) );
        register_rest_route( 'nobash-files/v1', '/versions', $user + array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'versions' ) ) );
        register_rest_route( 'nobash-files/v1', '/snapshot', $user + array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'snapshot' ) ) );
        register_rest_route( 'nobash-files/v1', '/version', $user + array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'version' ) ) );
        register_rest_route( 'nobash-files/v1', '/restore', $user + array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'restore' ) ) );
        register_rest_route( 'nobash-files/v1', '/operation', $user + array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'operation' ) ) );
        register_rest_route( 'nobash-files/v1', '/upload', $user + array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'upload' ) ) );
        register_rest_route( 'nobash-files/v1', '/upload-chunk', $user + array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'upload_chunk' ) ) );
        register_rest_route( 'nobash-files/v1', '/upload-cancel', $user + array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'upload_cancel' ) ) );
        register_rest_route( 'nobash-files/v1', '/undo', $user + array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'undo' ) ) );
        register_rest_route( 'nobash-files/v1', '/undo-state', $user + array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'undo_state' ) ) );
        register_rest_route( 'nobash-files/v1', '/activity', $user + array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'activity' ) ) );
        register_rest_route( 'nobash-files/v1', '/search', $user + array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'search' ) ) );
        register_rest_route( 'nobash-files/v1', '/preferences', $user + array( 'methods' => array( 'GET', 'POST' ), 'callback' => array( __CLASS__, 'preferences' ) ) );
        register_rest_route( 'nobash-files/v1', '/archive/create', $user + array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'archive_create' ) ) );
        register_rest_route( 'nobash-files/v1', '/archive/inspect', $user + array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'archive_inspect' ) ) );
        register_rest_route( 'nobash-files/v1', '/archive/extract', $user + array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'archive_extract' ) ) );
        register_rest_route( 'nobash-files/v1', '/archive/job', $user + array( 'methods' => array( 'GET', 'POST' ), 'callback' => array( __CLASS__, 'archive_job' ) ) );
        register_rest_route( 'nobash-files/v1', '/trash', $user + array( 'methods' => array( 'GET', 'POST' ), 'callback' => array( __CLASS__, 'trash' ) ) );
        register_rest_route( 'nobash-files/v1', '/settings', $admin + array( 'methods' => array( 'GET', 'POST' ), 'callback' => array( __CLASS__, 'settings' ) ) );
        register_rest_route( 'nobash-files/v1', '/integrity', $admin + array( 'methods' => array( 'GET', 'POST', 'DELETE' ), 'callback' => array( __CLASS__, 'integrity' ) ) );
    }

    public static function can_use(): bool {
        return Nobash_Files_Access::can_use();
    }

    public static function can_admin(): bool {
        return current_user_can( 'manage_options' );
    }


    private static function fs(): Nobash_Files_Filesystem {
        return new Nobash_Files_Filesystem();
    }

    private static function bool_param( WP_REST_Request $request, string $key ): bool {
        $value = $request->get_param( $key );
        return null === $value ? false : rest_sanitize_boolean( $value );
    }

    private static function error( Throwable $e ) {
        $code    = 'nobash_files_error';
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
        } elseif ( $e instanceof DomainException && 'PROTECTED_CONFIRM_REQUIRED' === $e->getMessage() ) {
            $code   = 'protected_confirm_required';
            $status = 409;
            $message = 'This protected WordPress area requires explicit confirmation.';
        } elseif ( $e instanceof DomainException && 'FILE_CHANGED_ON_DISK' === $e->getMessage() ) {
            $code   = 'file_changed_on_disk';
            $status = 409;
            $message = 'The live file changed after it was opened. Review the latest version before saving.';
        } elseif ( $e instanceof InvalidArgumentException ) {
            $code   = 'nobash_files_invalid_request';
            $status = 422;
        }
        return new WP_Error( $code, $message, array( 'status' => $status ) );
    }

    private static function version_row( int $id ): array {
        $row = Nobash_Files_DB::get_version( $id );
        if ( ! $row ) {
            throw new InvalidArgumentException( 'Version not found.' );
        }
        return $row;
    }

    private static function directory_payload( string $path, $cursor = 0, int $limit = 0 ): array {
        return Nobash_Files_Explorer::payload( $path, $cursor, $limit );
    }

    public static function list_dir( WP_REST_Request $request ) {
        try {
            $data = self::directory_payload(
                (string) $request->get_param( 'path' ),
                sanitize_text_field( (string) $request->get_param( 'cursor' ) ),
                absint( $request->get_param( 'limit' ) )
            );
            $response = rest_ensure_response( $data );
            if ( $response instanceof WP_REST_Response ) {
                $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
                $response->header( 'Pragma', 'no-cache' );
                $response->header( 'Expires', 'Wed, 11 Jan 1984 05:00:00 GMT' );
                $response->header( 'Vary', 'Cookie' );
            }
            return $response;
        } catch ( Throwable $e ) {
            return self::error( $e );
        }
    }


    public static function read_file( WP_REST_Request $request ) {
        try {
            $path = (string) $request->get_param( 'path' );
            $fs = self::fs();
            Nobash_Files_Access::assert_paths( 'read', array( $path ), $fs );
            return rest_ensure_response( $fs->read_file( $path ) );
        } catch ( Throwable $e ) {
            return self::error( $e );
        }
    }

    public static function save_file( WP_REST_Request $request ) {
        try {
            $path = (string) $request->get_param( 'path' );
            $fs = self::fs();
            Nobash_Files_Access::assert_paths( 'edit', array( $path ), $fs );
            return rest_ensure_response( $fs->save_file(
                $path,
                (string) $request->get_param( 'content' ),
                self::bool_param( $request, 'confirm_protected' ),
                (string) $request->get_param( 'expected_checksum' ),
                self::bool_param( $request, 'force' )
            ) );
        } catch ( Throwable $e ) {
            return self::error( $e );
        }
    }

    public static function validate_file( WP_REST_Request $request ) {
        try {
            $path = (string) $request->get_param( 'path' );
            $fs = self::fs();
            Nobash_Files_Access::assert_paths( 'read', array( $path ), $fs );
            $fs->file_info( $path );
            return rest_ensure_response( $fs->validate_content( $path, (string) $request->get_param( 'content' ) ) );
        } catch ( Throwable $e ) {
            return self::error( $e );
        }
    }

    public static function info( WP_REST_Request $request ) {
        try {
            $path = (string) $request->get_param( 'path' );
            $fs = self::fs();
            Nobash_Files_Access::assert_paths( 'read', array( $path ), $fs );
            return rest_ensure_response( $fs->file_info( $path ) );
        } catch ( Throwable $e ) {
            return self::error( $e );
        }
    }

    public static function snapshot( WP_REST_Request $request ) {
        try {
            $path = (string) $request->get_param( 'path' );
            $fs = self::fs();
            Nobash_Files_Access::assert_paths( 'versions', array( $path ), $fs );
            Nobash_Files_Access::assert_paths( 'read', array( $path ), $fs );
            return rest_ensure_response( $fs->snapshot_file( $path ) );
        } catch ( Throwable $e ) {
            return self::error( $e );
        }
    }

    public static function versions( WP_REST_Request $request ) {
        try {
            $path = (string) $request->get_param( 'path' );
            $fs = self::fs();
            Nobash_Files_Access::assert_paths( 'versions', array( $path ), $fs );
            return rest_ensure_response( $fs->versions( $path ) );
        } catch ( Throwable $e ) {
            return self::error( $e );
        }
    }

    public static function version( WP_REST_Request $request ) {
        try {
            $id = (int) $request->get_param( 'id' );
            $row = self::version_row( $id );
            $fs = self::fs();
            Nobash_Files_Access::assert_paths( 'versions', array( (string) $row['relative_path'] ), $fs );
            return rest_ensure_response( $fs->version( $id ) );
        } catch ( Throwable $e ) {
            return self::error( $e );
        }
    }

    public static function restore( WP_REST_Request $request ) {
        try {
            $id = (int) $request->get_param( 'version_id' );
            $row = self::version_row( $id );
            $fs = self::fs();
            Nobash_Files_Access::assert_paths( 'restore', array( (string) $row['relative_path'] ), $fs );
            return rest_ensure_response( $fs->restore_version( $id ) );
        } catch ( Throwable $e ) {
            return self::error( $e );
        }
    }

    public static function operation( WP_REST_Request $request ) {
        try {
            $fs      = self::fs();
            $action  = sanitize_key( (string) $request->get_param( 'action' ) );
            $confirm = self::bool_param( $request, 'confirm_protected' );
            $paths   = $request->get_param( 'paths' );
            $paths   = is_array( $paths ) ? array_values( array_map( 'strval', $paths ) ) : array();
            $path    = (string) $request->get_param( 'path' );
            $target  = (string) $request->get_param( 'target' );

            switch ( $action ) {
                case 'create_folder':
                    $name = sanitize_file_name( (string) $request->get_param( 'name' ) );
                    $new_path = trim( $path . '/' . $name, '/' );
                    Nobash_Files_Access::assert_paths( 'create', array( $path, $new_path ), $fs );
                    $result = $fs->create_folder( $path, $name, $confirm );
                    break;
                case 'rename':
                    $name = sanitize_file_name( (string) $request->get_param( 'name' ) );
                    $parent = str_contains( $path, '/' ) ? substr( $path, 0, strrpos( $path, '/' ) ) : '';
                    $new_path = trim( $parent . '/' . $name, '/' );
                    Nobash_Files_Access::assert_paths( 'rename', array( $path, $new_path ), $fs );
                    Nobash_Files_Access::assert_recursive_safe( array( $path ), 'rename', $fs );
                    $result = $fs->rename_item( $path, $name, $confirm );
                    break;
                case 'duplicate':
                    $source_abs = $fs->resolve_existing( $path );
                    $base_name = basename( $source_abs );
                    if ( is_dir( $source_abs ) ) {
                        $stem = $base_name;
                        $ext = '';
                    } else {
                        $raw_ext = pathinfo( $base_name, PATHINFO_EXTENSION );
                        $stem = $raw_ext ? substr( $base_name, 0, -1 * ( strlen( $raw_ext ) + 1 ) ) : $base_name;
                        $ext = $raw_ext ? '.' . $raw_ext : '';
                    }
                    $candidate_abs = dirname( $source_abs ) . '/' . $stem . '-copy' . $ext;
                    $copy_index = 2;
                    while ( file_exists( $candidate_abs ) || is_link( $candidate_abs ) ) {
                        $candidate_abs = dirname( $source_abs ) . '/' . $stem . '-copy-' . $copy_index . $ext;
                        $copy_index++;
                        if ( $copy_index > 9999 ) {
                            throw new RuntimeException( 'Unable to find a unique duplicate name.' );
                        }
                    }
                    $duplicate_target = $fs->relative_from_absolute( wp_normalize_path( $candidate_abs ) );
                    Nobash_Files_Access::assert_paths( 'copy', array( $path, $duplicate_target ), $fs );
                    Nobash_Files_Access::assert_recursive_safe( array( $path ), 'copy', $fs );
                    $result = $fs->duplicate_item( $path, $confirm );
                    break;
                case 'copy':
                    $targets = array_map( static fn( $source ) => trim( $target . '/' . basename( (string) $source ), '/' ), $paths );
                    Nobash_Files_Access::assert_paths( 'copy', array_merge( $paths, array( $target ), $targets ), $fs );
                    Nobash_Files_Access::assert_recursive_safe( $paths, 'copy', $fs );
                    $result = $fs->copy_items( $paths, $target, $confirm );
                    break;
                case 'move':
                    $targets = array_map( static fn( $source ) => trim( $target . '/' . basename( (string) $source ), '/' ), $paths );
                    Nobash_Files_Access::assert_paths( 'move', array_merge( $paths, array( $target ), $targets ), $fs );
                    Nobash_Files_Access::assert_recursive_safe( $paths, 'move', $fs );
                    $result = $fs->move_items( $paths, $target, $confirm );
                    break;
                case 'trash':
                    Nobash_Files_Access::assert_paths( 'delete', $paths, $fs );
                    Nobash_Files_Access::assert_recursive_safe( $paths, 'delete', $fs );
                    $result = $fs->trash_items( $paths, $confirm );
                    break;
                default:
                    throw new InvalidArgumentException( 'Unsupported file operation.' );
            }
            return rest_ensure_response( $result );
        } catch ( Throwable $e ) {
            return self::error( $e );
        }
    }

    public static function upload( WP_REST_Request $request ) {
        try {
            $path = (string) $request->get_param( 'path' );
            $fs = self::fs();
            Nobash_Files_Access::assert_paths( 'upload', array( $path ), $fs );
            $files = $request->get_file_params();
            if ( empty( $files['file'] ) || ! is_array( $files['file'] ) ) {
                throw new InvalidArgumentException( 'No upload file was received.' );
            }
            $filename = sanitize_file_name( (string) ( $files['file']['name'] ?? '' ) );
            $relative_path = trim( (string) $request->get_param( 'relative_path' ) );
            if ( '' !== $relative_path ) {
                $relative_path = $fs->normalize_upload_relative_path( $relative_path );
                $target_path = $fs->upload_target_path( $path, $relative_path );
                $target_parent = str_contains( $target_path, '/' ) ? substr( $target_path, 0, strrpos( $target_path, '/' ) ) : $path;
                Nobash_Files_Access::assert_paths( 'upload', array( $path, $target_path ), $fs );
                Nobash_Files_Access::assert_paths( 'create', array( $path, $target_parent ), $fs );
                return rest_ensure_response( $fs->upload_file_relative( $path, $relative_path, $files['file'], self::bool_param( $request, 'confirm_protected' ) ) );
            }
            Nobash_Files_Access::assert_paths( 'upload', array( trim( $path . '/' . $filename, '/' ) ), $fs );
            return rest_ensure_response( $fs->upload_file( $path, $files['file'], self::bool_param( $request, 'confirm_protected' ) ) );
        } catch ( Throwable $e ) {
            return self::error( $e );
        }
    }

    public static function upload_chunk( WP_REST_Request $request ) {
        try {
            $path = (string) $request->get_param( 'path' );
            $fs = self::fs();
            Nobash_Files_Access::assert_paths( 'upload', array( $path ), $fs );
            $files = $request->get_file_params();
            if ( empty( $files['chunk'] ) || ! is_array( $files['chunk'] ) ) {
                throw new InvalidArgumentException( 'No upload chunk was received.' );
            }
            $filename = sanitize_file_name( (string) $request->get_param( 'filename' ) );
            $relative_path = trim( (string) $request->get_param( 'relative_path' ) );
            $target_path = '' !== $relative_path
                ? $fs->upload_target_path( $path, $fs->normalize_upload_relative_path( $relative_path ) )
                : trim( $path . '/' . $filename, '/' );
            Nobash_Files_Access::assert_paths( 'upload', array( $path, $target_path ), $fs );
            if ( '' !== $relative_path ) {
                $target_parent = str_contains( $target_path, '/' ) ? substr( $target_path, 0, strrpos( $target_path, '/' ) ) : $path;
                Nobash_Files_Access::assert_paths( 'create', array( $path, $target_parent ), $fs );
            }
            return rest_ensure_response( $fs->upload_chunk(
                $path,
                $filename,
                (string) $request->get_param( 'upload_id' ),
                (int) $request->get_param( 'chunk_index' ),
                (int) $request->get_param( 'total_chunks' ),
                $files['chunk'],
                self::bool_param( $request, 'confirm_protected' ),
                $relative_path
            ) );
        } catch ( Throwable $e ) {
            return self::error( $e );
        }
    }

    public static function upload_cancel( WP_REST_Request $request ) {
        try {
            Nobash_Files_Access::assert_permission( 'upload' );
            $ids = $request->get_param( 'upload_ids' );
            $ids = is_array( $ids ) ? $ids : array();
            return rest_ensure_response( self::fs()->cancel_upload_sessions( $ids ) );
        } catch ( Throwable $e ) {
            return self::error( $e );
        }
    }

    public static function undo() {
        try {
            Nobash_Files_Access::assert_permission( 'undo' );
            $last = Nobash_Files_DB::last_undoable();
            $fs = self::fs();
            if ( $last ) {
                Nobash_Files_Access::assert_operation_paths( 'undo', $last, $fs );
            }
            return rest_ensure_response( $fs->undo_last_operation() );
        } catch ( Throwable $e ) {
            return self::error( $e );
        }
    }

    public static function undo_state() {
        try {
            Nobash_Files_Access::assert_permission( 'undo' );
            $state = self::fs()->undo_state();
            if ( ! current_user_can( 'manage_options' ) ) {
                $operation = Nobash_Files_DB::last_undoable();
                if ( $operation && ! Nobash_Files_Access::can_paths( 'undo', Nobash_Files_Access::operation_paths( $operation ), self::fs() ) ) {
                    $state['can_undo'] = false;
                    $state['last'] = null;
                }
            }
            return rest_ensure_response( $state );
        } catch ( Throwable $e ) {
            return self::error( $e );
        }
    }

    public static function activity() {
        try {
            Nobash_Files_Access::assert_permission( 'activity' );
            $rows = Nobash_Files_DB::activity( 100 );
            if ( current_user_can( 'manage_options' ) ) {
                return rest_ensure_response( array_slice( $rows, 0, 50 ) );
            }
            $user_id = get_current_user_id();
            $rows = array_values( array_filter( $rows, static function( $row ) use ( $user_id ) {
                return (int) ( $row['user_id'] ?? 0 ) === $user_id && Nobash_Files_Access::is_path_allowed( (string) ( $row['relative_path'] ?? '' ) );
            } ) );
            return rest_ensure_response( array_slice( $rows, 0, 50 ) );
        } catch ( Throwable $e ) {
            return self::error( $e );
        }
    }

    public static function search( WP_REST_Request $request ) {
        try {
            Nobash_Files_Access::assert_permission( 'search' );
            $fs      = self::fs();
            $base    = (string) $request->get_param( 'base' );
            $query   = (string) $request->get_param( 'q' );
            $content = self::bool_param( $request, 'content' );

            if ( current_user_can( 'manage_options' ) || '' !== Nobash_Files_Access::normalize_path( $base ) ) {
                Nobash_Files_Access::assert_paths( 'search', array( $base ), $fs );
                return rest_ensure_response( $fs->search( $base, $query, $content, current_user_can( 'manage_options' ) ? null : static fn( $candidate ) => Nobash_Files_Access::is_path_allowed( (string) $candidate ) ) );
            }

            $combined = array( 'query' => $query, 'base' => '', 'results' => array(), 'truncated' => false, 'visited' => 0, 'content_files' => 0 );
            foreach ( Nobash_Files_Access::roots() as $root ) {
                Nobash_Files_Access::assert_paths( 'search', array( $root ), $fs );
                $part = $fs->search( $root, $query, $content, static fn( $candidate ) => Nobash_Files_Access::is_path_allowed( (string) $candidate ) );
                $combined['results'] = array_merge( $combined['results'], $part['results'] ?? array() );
                $combined['visited'] += (int) ( $part['visited'] ?? 0 );
                $combined['content_files'] += (int) ( $part['content_files'] ?? 0 );
                $combined['truncated'] = $combined['truncated'] || ! empty( $part['truncated'] );
                if ( count( $combined['results'] ) >= 150 ) {
                    $combined['results'] = array_slice( $combined['results'], 0, 150 );
                    $combined['truncated'] = true;
                    break;
                }
            }
            return rest_ensure_response( $combined );
        } catch ( Throwable $e ) {
            return self::error( $e );
        }
    }

    private static function get_path_meta( string $key ): array {
        $value = get_user_meta( get_current_user_id(), $key, true );
        if ( ! is_array( $value ) ) {
            return array();
        }
        $clean = array();
        foreach ( $value as $path ) {
            if ( ! is_string( $path ) ) {
                continue;
            }
            try {
                $path = Nobash_Files_Access::normalize_path( $path );
            } catch ( Throwable $e ) {
                continue;
            }
            if ( ! in_array( $path, $clean, true ) ) {
                $clean[] = $path;
            }
        }
        return $clean;
    }

    private static function hydrate_paths( array $paths, int $limit = 40 ): array {
        $out = array();
        $fs  = self::fs();
        foreach ( array_slice( $paths, 0, $limit ) as $path ) {
            try {
                if ( ! current_user_can( 'manage_options' ) && ! Nobash_Files_Access::is_path_allowed( (string) $path ) ) {
                    continue;
                }
                $out[] = $fs->file_info( (string) $path );
            } catch ( Throwable $e ) {
                continue;
            }
        }
        return $out;
    }

    public static function preferences( WP_REST_Request $request ) {
        $user_id   = get_current_user_id();
        $favorites = self::get_path_meta( 'nobash_files_favorites' );
        $recent    = self::get_path_meta( 'nobash_files_recent' );

        if ( 'POST' === $request->get_method() ) {
            try {
                $action = sanitize_key( (string) $request->get_param( 'action' ) );
                $path   = (string) $request->get_param( 'path' );

                if ( in_array( $action, array( 'toggle_favorite', 'add_recent' ), true ) ) {
                    $fs = self::fs();
                    Nobash_Files_Access::assert_paths( 'read', array( $path ), $fs );
                    $info = $fs->file_info( $path );
                    $path = (string) $info['path'];
                }

                if ( 'toggle_favorite' === $action ) {
                    $index = array_search( $path, $favorites, true );
                    if ( false === $index ) {
                        array_unshift( $favorites, $path );
                        $favorites = array_slice( array_values( array_unique( $favorites ) ), 0, 40 );
                    } else {
                        unset( $favorites[ $index ] );
                        $favorites = array_values( $favorites );
                    }
                    update_user_meta( $user_id, 'nobash_files_favorites', $favorites );
                } elseif ( 'add_recent' === $action ) {
                    $recent = array_values( array_diff( $recent, array( $path ) ) );
                    array_unshift( $recent, $path );
                    $recent = array_slice( $recent, 0, 30 );
                    update_user_meta( $user_id, 'nobash_files_recent', $recent );
                } elseif ( 'clear_recent' === $action ) {
                    $recent = array();
                    update_user_meta( $user_id, 'nobash_files_recent', $recent );
                } else {
                    throw new InvalidArgumentException( 'Unsupported preference action.' );
                }
            } catch ( Throwable $e ) {
                return self::error( $e );
            }
        }

        return rest_ensure_response( array(
            'favorites'      => self::hydrate_paths( $favorites, 40 ),
            'recent'         => self::hydrate_paths( $recent, 30 ),
            'favorite_paths' => array_values( array_filter( $favorites, static fn( $path ) => current_user_can( 'manage_options' ) || Nobash_Files_Access::is_path_allowed( (string) $path ) ) ),
        ) );
    }

    public static function archive_create( WP_REST_Request $request ) {
        try {
            $fs = self::fs();
            $paths = $request->get_param( 'paths' );
            $paths = is_array( $paths ) ? array_values( array_map( 'strval', $paths ) ) : array();
            $destination = (string) $request->get_param( 'destination' );
            Nobash_Files_Access::assert_paths( 'archive', array_merge( $paths, array( $destination ) ), $fs );
            Nobash_Files_Access::assert_recursive_safe( $paths, 'archive', $fs );
            return rest_ensure_response( $fs->create_archive(
                $paths,
                $destination,
                (string) $request->get_param( 'name' ),
                (string) $request->get_param( 'format' ),
                (string) $request->get_param( 'job_id' ),
                self::bool_param( $request, 'confirm_protected' )
            ) );
        } catch ( Throwable $e ) {
            return self::error( $e );
        }
    }

    public static function archive_inspect( WP_REST_Request $request ) {
        try {
            $path = (string) $request->get_param( 'path' );
            $fs = self::fs();
            Nobash_Files_Access::assert_paths( 'read', array( $path ), $fs );
            return rest_ensure_response( $fs->inspect_archive( $path ) );
        } catch ( Throwable $e ) {
            return self::error( $e );
        }
    }

    public static function archive_extract( WP_REST_Request $request ) {
        try {
            $fs = self::fs();
            $path = (string) $request->get_param( 'path' );
            $destination = (string) $request->get_param( 'destination' );
            Nobash_Files_Access::assert_paths( 'extract', array( $path, $destination ), $fs );
            if ( ! current_user_can( 'manage_options' ) ) {
                $meta = $fs->inspect_archive( $path );
                $targets = array();
                foreach ( (array) ( $meta['entries'] ?? array() ) as $entry ) {
                    if ( ! is_array( $entry ) || empty( $entry['name'] ) ) {
                        continue;
                    }
                    $targets[] = trim( $destination . '/' . ltrim( (string) $entry['name'], '/' ), '/' );
                }
                if ( $targets ) {
                    Nobash_Files_Access::assert_paths( 'extract', $targets, $fs );
                }
            }
            return rest_ensure_response( $fs->extract_archive(
                $path,
                $destination,
                (string) $request->get_param( 'conflict' ),
                (string) $request->get_param( 'job_id' ),
                self::bool_param( $request, 'confirm_protected' )
            ) );
        } catch ( Throwable $e ) {
            return self::error( $e );
        }
    }

    public static function archive_job( WP_REST_Request $request ) {
        try {
            Nobash_Files_Access::assert_permission( 'archive' );
            $fs = self::fs();
            $job_id = (string) $request->get_param( 'job_id' );
            if ( 'POST' === $request->get_method() ) {
                if ( 'cancel' !== sanitize_key( (string) $request->get_param( 'action' ) ) ) {
                    throw new InvalidArgumentException( 'Unsupported archive job action.' );
                }
                return rest_ensure_response( $fs->cancel_archive_job( $job_id ) );
            }
            return rest_ensure_response( $fs->archive_job_state( $job_id ) );
        } catch ( Throwable $e ) {
            return self::error( $e );
        }
    }

    public static function trash( WP_REST_Request $request ) {
        try {
            Nobash_Files_Access::assert_permission( 'trash' );
            $fs = self::fs();
            if ( 'GET' === $request->get_method() ) {
                $items = $fs->trash_entries();
                if ( ! current_user_can( 'manage_options' ) ) {
                    $items = array_values( array_filter( $items, static fn( $operation ): bool => Nobash_Files_Access::can_paths( 'trash', Nobash_Files_Access::operation_paths( $operation ), $fs ) ) );
                }
                return rest_ensure_response( array( 'items' => $items ) );
            }
            $action = sanitize_key( (string) $request->get_param( 'action' ) );
            $operation_id = (int) $request->get_param( 'operation_id' );
            if ( in_array( $action, array( 'restore', 'purge' ), true ) ) {
                $op = Nobash_Files_DB::get_operation( $operation_id );
                if ( ! $op ) {
                    throw new InvalidArgumentException( 'Trash recovery entry not found.' );
                }
                $permission = 'restore' === $action ? 'restore' : 'delete';
                Nobash_Files_Access::assert_operation_paths( $permission, $op, $fs );
            }
            if ( 'restore' === $action ) {
                return rest_ensure_response( $fs->restore_trash_operation( $operation_id ) );
            }
            if ( 'purge' === $action ) {
                return rest_ensure_response( $fs->purge_trash_operation( $operation_id ) );
            }
            if ( 'empty' === $action ) {
                Nobash_Files_Access::assert_permission( 'delete' );
                if ( ! current_user_can( 'manage_options' ) ) {
                    foreach ( $fs->trash_entries() as $operation ) {
                        Nobash_Files_Access::assert_operation_paths( 'delete', $operation, $fs );
                    }
                }
                return rest_ensure_response( $fs->empty_trash() );
            }
            throw new InvalidArgumentException( 'Unsupported Trash action.' );
        } catch ( Throwable $e ) {
            return self::error( $e );
        }
    }


    public static function integrity( WP_REST_Request $request ) {
        try {
            $scanner = new Nobash_Files_Integrity();
            if ( 'GET' === $request->get_method() ) {
                return rest_ensure_response( array(
                    'baseline' => Nobash_Files_Integrity::baseline_meta(),
                    'last_scan' => Nobash_Files_Integrity::last_scan(),
                    'history' => Nobash_Files_Integrity::history(),
                    'settings' => Nobash_Files_Integrity::settings(),
                ) );
            }
            if ( 'DELETE' === $request->get_method() ) {
                Nobash_Files_Integrity::delete_baseline();
                return rest_ensure_response( array( 'deleted' => true, 'baseline' => Nobash_Files_Integrity::baseline_meta() ) );
            }
            $action = sanitize_key( (string) $request->get_param( 'action' ) );
            if ( 'scan' === $action ) {
                $scan = $scanner->scan();
                $response = array( 'scan' => $scan, 'history' => Nobash_Files_Integrity::history() );
                if ( class_exists( 'Nobash_Files_Monitor' ) && (bool) apply_filters( 'nobash_files_integrity_advanced_enabled', true ) ) {
                    $response['awareness'] = Nobash_Files_Monitor::after_scan( $scan, 'manual' );
                    $response['monitoring'] = Nobash_Files_Monitor::admin_payload();
                }
                return rest_ensure_response( $response );
            }
            if ( 'baseline' === $action ) {
                return rest_ensure_response( array( 'baseline' => $scanner->create_baseline() ) );
            }
            if ( 'settings' === $action ) {
                $settings = $request->get_param( 'settings' );
                if ( ! is_array( $settings ) ) {
                    throw new InvalidArgumentException( 'Integrity settings are required.' );
                }
                return rest_ensure_response( array( 'settings' => Nobash_Files_Integrity::update_settings( $settings ) ) );
            }
            throw new InvalidArgumentException( 'Unsupported Integrity action.' );
        } catch ( Throwable $e ) {
            return self::error( $e );
        }
    }


    public static function settings( WP_REST_Request $request ) {
        if ( 'POST' === $request->get_method() ) {
            $safe = $request->get_param( 'safe_mode' );
            if ( null !== $safe ) {
                update_option( 'nobash_files_safe_mode', rest_sanitize_boolean( $safe ) ? '1' : '0' );
            }
            $max = $request->get_param( 'max_edit_bytes' );
            if ( null !== $max ) {
                update_option( 'nobash_files_max_edit_bytes', max( 65536, min( 5242880, absint( $max ) ) ) );
            }
            $version_limit = $request->get_param( 'version_limit' );
            if ( null !== $version_limit ) {
                $max_versions = max( 5, (int) apply_filters( 'nobash_files_version_history_max', 100 ) );
                update_option( 'nobash_files_version_limit', max( 5, min( $max_versions, absint( $version_limit ) ) ) );
            }
            $workspace = $request->get_param( 'default_workspace' );
            if ( null !== $workspace && class_exists( 'Nobash_Files_Onboarding' ) ) {
                $workspace = sanitize_text_field( (string) $workspace );
                $choices   = Nobash_Files_Onboarding::workspace_choices();
                if ( array_key_exists( $workspace, $choices ) ) {
                    update_option( 'nobash_files_default_workspace', $workspace, false );
                }
            }
            $chunk = $request->get_param( 'chunk_bytes' );
            if ( null !== $chunk ) {
                $allowed_chunks = array( 1048576, 2097152, 4194304, 8388608 );
                $chunk = absint( $chunk );
                if ( in_array( $chunk, $allowed_chunks, true ) ) {
                    update_option( 'nobash_files_chunk_bytes', $chunk, false );
                }
            }
            $page_size = $request->get_param( 'directory_page_size' );
            if ( null !== $page_size ) {
                $page_size = absint( $page_size );
                if ( in_array( $page_size, array( 100, 250, 500 ), true ) ) {
                    update_option( 'nobash_files_directory_page_size', $page_size, false );
                }
            }
            $word_wrap = $request->get_param( 'editor_word_wrap' );
            if ( null !== $word_wrap ) {
                update_option( 'nobash_files_editor_word_wrap', rest_sanitize_boolean( $word_wrap ) ? '1' : '0', false );
            }
            $uninstall_policy = $request->get_param( 'uninstall_policy' );
            if ( null !== $uninstall_policy ) {
                $uninstall_policy = sanitize_key( (string) $uninstall_policy );
                if ( in_array( $uninstall_policy, array( 'keep', 'remove' ), true ) ) {
                    update_option( 'nobash_files_uninstall_policy', $uninstall_policy, false );
                }
            }
        }
        $workspace_choices = array();
        if ( class_exists( 'Nobash_Files_Onboarding' ) ) {
            foreach ( Nobash_Files_Onboarding::workspace_choices() as $value => $meta ) {
                $workspace_choices[] = array( 'value' => $value, 'label' => (string) $meta['label'], 'description' => (string) $meta['description'] );
            }
        }
        return rest_ensure_response( array(
            'safe_mode'      => '1' === get_option( 'nobash_files_safe_mode', '1' ),
            'max_edit_bytes' => (int) get_option( 'nobash_files_max_edit_bytes', 1048576 ),
            'chunk_bytes'    => (int) get_option( 'nobash_files_chunk_bytes', 2097152 ),
            'chunk_bytes_effective' => class_exists( 'Nobash_Files_Runtime' ) ? Nobash_Files_Runtime::effective_chunk_bytes() : (int) get_option( 'nobash_files_chunk_bytes', 2097152 ),
            'directory_page_size' => (int) get_option( 'nobash_files_directory_page_size', 250 ),
            'editor_word_wrap' => '1' === get_option( 'nobash_files_editor_word_wrap', '0' ),
            'uninstall_policy' => in_array( (string) get_option( 'nobash_files_uninstall_policy', 'keep' ), array( 'keep', 'remove' ), true ) ? (string) get_option( 'nobash_files_uninstall_policy', 'keep' ) : 'keep',
            'version_limit'  => max( 5, min( max( 5, (int) apply_filters( 'nobash_files_version_history_max', 100 ) ), (int) get_option( 'nobash_files_version_limit', 5 ) ) ),
            'version_limit_max' => max( 5, (int) apply_filters( 'nobash_files_version_history_max', 100 ) ),
            'default_workspace' => class_exists( 'Nobash_Files_Onboarding' ) ? Nobash_Files_Onboarding::default_workspace() : '',
            'workspace_choices' => $workspace_choices,
            'onboarding_complete' => class_exists( 'Nobash_Files_Onboarding' ) ? Nobash_Files_Onboarding::complete() : true,
            'runtime' => class_exists( 'Nobash_Files_Runtime' ) ? Nobash_Files_Runtime::profile() : array(),
        ) );
    }
}
