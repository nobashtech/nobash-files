<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Nobash_Files_Admin {
    private static array $pages = array();

    public static function init(): void {
        add_action( 'network_admin_menu', array( __CLASS__, 'network_menu' ) );
        add_action( 'admin_post_nobash_files_audit_export', array( __CLASS__, 'audit_export' ) );
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
        add_filter( 'parent_file', array( __CLASS__, 'tool_navigation_parent' ) );
        add_filter( 'submenu_file', array( __CLASS__, 'tool_navigation_item' ) );
        add_action( 'admin_post_nobash_files_download', array( __CLASS__, 'download' ) );
        add_action( 'admin_post_nobash_files_preview', array( __CLASS__, 'preview' ) );
        add_action( 'admin_post_nobash_files_history_restore', array( __CLASS__, 'history_restore' ) );
        add_action( 'admin_post_nobash_files_history_download', array( __CLASS__, 'history_download' ) );
        add_action( 'admin_post_nobash_files_history_undo', array( __CLASS__, 'history_undo' ) );
        add_action( 'admin_post_nobash_files_integrity_scan', array( __CLASS__, 'integrity_scan' ) );
        add_action( 'admin_post_nobash_files_trash_bulk', array( __CLASS__, 'trash_bulk' ) );
        add_action( 'admin_post_nobash_files_trash_empty', array( __CLASS__, 'trash_empty' ) );
        add_action( 'admin_post_nobash_files_settings_save', array( __CLASS__, 'settings_save' ) );
        add_action( 'admin_post_nobash_files_settings_reset', array( __CLASS__, 'settings_reset' ) );
        add_action( 'admin_post_nobash_files_settings_export', array( __CLASS__, 'settings_export' ) );
        add_action( 'admin_post_nobash_files_toggle_safe_mode', array( __CLASS__, 'toggle_safe_mode' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( NOBASH_FILES_FILE ), array( __CLASS__, 'action_links' ) );
    }

    public static function menu(): void {
        $cap = Nobash_Files_Access::capability();
        self::$pages[] = add_menu_page( 'Nobash Files', 'Nobash Files', $cap, 'nobash-files', array( __CLASS__, 'render_app' ), 'dashicons-open-folder', 58 );
        foreach ( array(
            array( 'Files', 'nobash-files', 'render_app', $cap ),
            array( 'Editor', 'nobash-files-editor', 'render_editor', $cap ),
            array( 'Trash', 'nobash-files-trash', 'render_trash', $cap ),
            array( 'Tools', 'nobash-files-tools', 'render_tools', $cap ),
            array( 'Settings', 'nobash-files-settings', 'render_settings', 'manage_options' ),
        ) as $item ) {
            self::$pages[] = add_submenu_page( 'nobash-files', $item[0], $item[0], $item[3], $item[1], array( __CLASS__, $item[2] ) );
        }
        // These pages remain registered and capability checked, but are reached
        // from the Tools page instead of crowding the WordPress sidebar.
        foreach ( self::tool_pages() as $slug => $tool ) {
            self::$pages[] = add_submenu_page( null, $tool[0], $tool[0], $tool[2], $slug, array( __CLASS__, $tool[1] ) );
        }
    }

    private static function tool_pages(): array {
        $cap = Nobash_Files_Access::capability();
        return array(
            'nobash-files-versions' => array( 'File history', 'render_versions', $cap ),
            'nobash-files-activity' => array( 'Recent activity', 'render_activity', $cap ),
            'nobash-files-integrity' => array( 'Check site files', 'render_integrity', 'manage_options' ),
            'nobash-files-security' => array( 'Security & repair', 'render_security', 'manage_options' ),
            'nobash-files-monitoring' => array( 'Monitoring', 'render_monitoring', 'manage_options' ),
            'nobash-files-access' => array( 'Team access', 'render_access', 'manage_options' ),
            'nobash-files-agency' => array( 'Access templates', 'render_agency', is_multisite() ? 'manage_network_options' : 'manage_options' ),
        );
    }

    public static function tool_navigation_parent( $parent ) {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( is_network_admin() && 'nobash-files-agency' === $page ) { return 'nobash-files-network'; }
        return isset( self::tool_pages()[ $page ] ) ? 'nobash-files' : $parent;
    }

    public static function tool_navigation_item( $item ) {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( is_network_admin() && 'nobash-files-agency' === $page ) { return 'nobash-files-agency'; }
        return isset( self::tool_pages()[ $page ] ) ? 'nobash-files-tools' : $item;
    }

    public static function render_tools(): void {
        if ( ! Nobash_Files_Access::can_use() ) {
            wp_die( esc_html__( 'You do not have permission to use these tools.', 'nobash-files' ), '', array( 'response' => 403 ) );
        }
        self::shell( 'tools' );
        $groups = array(
            'Files & recovery' => array(
                array( 'versions', 'File history', 'View backups and restore earlier file versions.', 'backup' ),
                array( 'activity', 'Recent activity', 'See what changed and who changed it.', 'clock' ),
            ),
            'Site protection' => array(
                array( 'integrity', 'Check site files', 'Find changes to WordPress, plugin and theme files.', 'shield' ),
                array( 'security', 'Security & repair', 'Review findings, quarantine files and repair safely.', 'admin-tools' ),
                array( 'monitoring', 'Monitoring', 'Set up scheduled checks and optional alerts.', 'visibility' ),
            ),
            'Team & policies' => array(
                array( 'access', 'Team access', 'Choose who can access each folder.', 'groups' ),
                array( 'agency', 'Access templates', 'Save access settings and reuse them for your team.', 'clipboard' ),
            ),
        );
        echo '<main class="nobash-files-tools-page"><header><h2>Tools</h2><p>Choose a tool when you need it.</p></header><div class="nobash-files-tool-groups">';
        foreach ( $groups as $title => $items ) {
            $allowed = array_filter( $items, static function ( $item ) {
                $tool = self::tool_pages()[ 'nobash-files-' . $item[0] ];
                return current_user_can( $tool[2] );
            } );
            if ( empty( $allowed ) ) { continue; }
            echo '<section class="nobash-files-tool-group"><h3>' . esc_html( $title ) . '</h3>';
            foreach ( $allowed as $item ) {
                echo '<a class="nobash-files-tool-link" href="' . esc_url( admin_url( 'admin.php?page=nobash-files-' . $item[0] ) ) . '"><span class="dashicons dashicons-' . esc_attr( $item[3] ) . '" aria-hidden="true"></span><span><strong>' . esc_html( $item[1] ) . '</strong><small>' . esc_html( $item[2] ) . '</small></span><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a>';
            }
            echo '</section>';
        }
        echo '</div>';
        if ( current_user_can( 'manage_options' ) ) {
            echo '<a class="nobash-files-setup-link" href="' . esc_url( admin_url( 'admin.php?page=nobash-files-setup' ) ) . '">Need help getting started? Open the setup guide.</a>';
        }
        echo '</main>';
        self::shell_end();
    }

    private static function editor_start_path(): string {
        if ( ! function_exists( 'get_stylesheet_directory' ) ) {
            return 'wp-content/themes';
        }
        $root  = rtrim( wp_normalize_path( ABSPATH ), '/' );
        $theme = rtrim( wp_normalize_path( get_stylesheet_directory() ), '/' );
        if ( $theme !== $root && str_starts_with( $theme . '/', $root . '/' ) ) {
            return ltrim( substr( $theme, strlen( $root ) ), '/' );
        }
        return 'wp-content/themes';
    }

    public static function action_links( array $links ): array {
        $primary = array(
            '<a href="' . esc_url( admin_url( 'admin.php?page=nobash-files' ) ) . '">' . esc_html__( 'File Manager', 'nobash-files' ) . '</a>',
            '<a href="' . esc_url( admin_url( 'admin.php?page=nobash-files-settings' ) ) . '">' . esc_html__( 'Settings', 'nobash-files' ) . '</a>',
            '<a href="' . esc_url( admin_url( 'admin.php?page=nobash-files-setup' ) ) . '">' . esc_html__( 'Setup Guide', 'nobash-files' ) . '</a>',
        );
        return array_merge( $primary, $links );
    }

    public static function register_page_hook( string $hook ): void {
        if ( '' !== $hook ) { self::$pages[] = $hook; }
    }

    public static function network_menu(): void {
        if ( ! class_exists( 'Nobash_Files_Multisite' ) || ! Nobash_Files_Multisite::can_network_admin() ) {
            return;
        }
        $cap = 'manage_network_options';
        self::$pages[] = add_menu_page( 'Nobash Files Network', 'Nobash Files', $cap, 'nobash-files-network', array( __CLASS__, 'render_multisite' ), 'dashicons-networking', 58 );
        self::$pages[] = add_submenu_page( 'nobash-files-network', 'Network Overview', 'Network Overview', $cap, 'nobash-files-network', array( __CLASS__, 'render_multisite' ) );
        self::$pages[] = add_submenu_page( 'nobash-files-network', 'Agency & Audit', 'Agency & Audit', $cap, 'nobash-files-agency', array( __CLASS__, 'render_agency' ) );
    }

    public static function assets( string $hook ): void {
        if ( ! in_array( $hook, self::$pages, true ) ) {
            return;
        }
        wp_enqueue_style( 'nobash-files-admin', NOBASH_FILES_URL . 'assets/css/admin.css', array(), NOBASH_FILES_VERSION );
        $workspace_style = 'assets/css/workspace.css';
        wp_enqueue_style( 'nobash-files-workspace', NOBASH_FILES_URL . $workspace_style, array( 'nobash-files-admin' ), NOBASH_FILES_VERSION . '.' . filemtime( NOBASH_FILES_DIR . $workspace_style ) );

        $page            = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( 'nobash-files' === $page ) {
            $manager_style = 'assets/css/manager.css';
            wp_enqueue_style( 'nobash-files-manager', NOBASH_FILES_URL . $manager_style, array( 'nobash-files-workspace' ), NOBASH_FILES_VERSION . '.' . filemtime( NOBASH_FILES_DIR . $manager_style ) );
        }
        $simple_style = 'assets/css/simple.css';
        wp_enqueue_style( 'nobash-files-simple', NOBASH_FILES_URL . $simple_style, array( 'nobash-files' === $page ? 'nobash-files-manager' : 'nobash-files-workspace' ), NOBASH_FILES_VERSION . '.' . filemtime( NOBASH_FILES_DIR . $simple_style ) );
        $script_deps     = array();
        $editor_settings = false;
        if ( 'nobash-files-editor' === $page && function_exists( 'wp_enqueue_code_editor' ) ) {
            // Use WordPress' bundled CodeMirror stack instead of a remote editor dependency.
            // wp_enqueue_code_editor() returns false when the current user disabled syntax highlighting;
            // in that case Nobash intentionally keeps the accessible textarea fallback.
            $editor_settings = wp_enqueue_code_editor( array( 'type' => 'text/x-php' ) );
            if ( false !== $editor_settings ) {
                wp_enqueue_code_editor( array( 'type' => 'text/css' ) );
                wp_enqueue_code_editor( array( 'type' => 'text/javascript' ) );
                $script_deps[] = 'code-editor';
            }
        }

        $workspace_root = ( class_exists( 'Nobash_Files_Multisite' ) && is_multisite() && isset( $_GET['nobash_files_workspace'] ) && 'site' === sanitize_key( wp_unslash( $_GET['nobash_files_workspace'] ) ) ) ? Nobash_Files_Multisite::site_workspace_path() : '';
        $default_workspace = class_exists( 'Nobash_Files_Onboarding' ) ? Nobash_Files_Onboarding::default_workspace() : '';
        $editor_start = self::editor_start_path();
        $requested_path = isset( $_GET['nobash_files_path'] ) ? Nobash_Files_Explorer::clean_path( (string) wp_unslash( $_GET['nobash_files_path'] ) ) : '';
        $initial_path = '';
        if ( 'nobash-files-editor' === $page ) {
            $initial_path = '' !== $requested_path ? $requested_path : $editor_start;
        } elseif ( 'nobash-files' === $page ) {
            if ( '' !== $requested_path ) {
                $initial_path = $requested_path;
            } elseif ( '' !== $workspace_root ) {
                $initial_path = $workspace_root;
            } elseif ( ! current_user_can( 'manage_options' ) ) {
                $initial_path = Nobash_Files_Access::home_root();
            } else {
                $initial_path = $default_workspace;
            }
        }
        $bootstrap_directory = in_array( $page, array( 'nobash-files', 'nobash-files-editor' ), true )
            ? Nobash_Files_Explorer::bootstrap( $initial_path, (int) get_option( 'nobash_files_directory_page_size', 250 ) )
            : null;
        if ( is_array( $bootstrap_directory ) && empty( $bootstrap_directory['ok'] ) && '' !== $initial_path ) {
            $fallback = ! current_user_can( 'manage_options' ) ? Nobash_Files_Access::home_root() : '';
            $bootstrap_directory = Nobash_Files_Explorer::bootstrap( $fallback, (int) get_option( 'nobash_files_directory_page_size', 250 ) );
            $initial_path = $fallback;
        }

        wp_enqueue_script( 'nobash-files-admin', NOBASH_FILES_URL . 'assets/js/admin.js', $script_deps, NOBASH_FILES_VERSION . '.' . filemtime( NOBASH_FILES_DIR . 'assets/js/admin.js' ), true );
        wp_localize_script( 'nobash-files-admin', 'NobashFiles', array(
            'rest'        => esc_url_raw( rest_url( 'nobash-files/v1/' ) ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'explorerUrl' => admin_url( 'admin-post.php' ),
            'explorerNonce' => wp_create_nonce( 'nobash_files_explorer' ),
            'managerUrl'  => admin_url( 'admin.php?page=nobash-files' ),
            'editorUrl'   => admin_url( 'admin.php?page=nobash-files-editor' ),
            'bootstrapDirectory' => $bootstrap_directory,
            'initialPath' => $initial_path,
            'downloadUrl' => admin_url( 'admin-post.php' ),
            'downloadNonce' => wp_create_nonce( 'nobash_files_download' ),
            'previewNonce'  => wp_create_nonce( 'nobash_files_preview' ),
            'page'        => isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'nobash-files',
            'safeMode'    => '1' === get_option( 'nobash_files_safe_mode', '1' ),
            'chunkBytes'  => class_exists( 'Nobash_Files_Runtime' ) ? Nobash_Files_Runtime::effective_chunk_bytes() : (int) get_option( 'nobash_files_chunk_bytes', 2097152 ),
            'configuredChunkBytes' => (int) get_option( 'nobash_files_chunk_bytes', 2097152 ),
            'directoryPageSize' => (int) get_option( 'nobash_files_directory_page_size', 250 ),
            'zipAvailable' => class_exists( 'ZipArchive' ),
            'tarAvailable' => class_exists( 'PharData' ),
            'access'       => Nobash_Files_Access::client_summary(),
            'isMultisite'  => is_multisite(),
            'isNetworkAdmin' => is_multisite() && is_network_admin(),
            'workspaceRoot' => $workspace_root,
            'auditExportUrl' => class_exists( 'Nobash_Files_Agency' ) ? wp_nonce_url( admin_url( 'admin-post.php?action=nobash_files_audit_export' ), 'nobash_files_audit_export' ) : '',
            'defaultWorkspace' => $default_workspace,
            'setupUrl' => admin_url( 'admin.php?page=nobash-files-setup' ),
            'onboardingComplete' => class_exists( 'Nobash_Files_Onboarding' ) ? Nobash_Files_Onboarding::complete() : true,
            'runtime' => class_exists( 'Nobash_Files_Runtime' ) ? Nobash_Files_Runtime::profile() : array(),
            'editorSettings' => false !== $editor_settings ? $editor_settings : null,
            'editorEnhanced' => false !== $editor_settings,
            'editorWordWrap' => '1' === get_option( 'nobash_files_editor_word_wrap', '0' ),
            'editorStartPath' => $editor_start,
        ) );
    }

    private static function shell( string $view ): void {
        $access    = Nobash_Files_Access::client_summary();
        $file_view = in_array( $view, array( 'manager', 'editor' ), true );
        $subtitle  = 'manager' === $view ? 'The easiest way to manage your WordPress files.' : 'Safe file management for WordPress';
        ?>
        <div class="nobash-files-wrap" data-view="<?php echo esc_attr( $view ); ?>">
            <header class="nobash-files-header">
                <div class="nobash-files-brand">
                    <span class="nobash-files-logo dashicons dashicons-open-folder"></span>
                    <div><div class="nobash-files-brand-title"><h1>Nobash Files</h1><span class="nobash-files-edition-pill">FREE</span></div><p><?php echo esc_html( $subtitle ); ?></p></div>
                </div>
                <div class="nobash-files-head-actions">
                    <?php if ( $file_view ) : ?>
                        <button class="nobash-files-search" type="button" id="nobash-files-search-placeholder" aria-label="Search files and folders"><span class="dashicons dashicons-search" aria-hidden="true"></span><span>Search files…</span><kbd>⌘ K</kbd></button>
                        <div class="nobash-files-upload-picker-wrap">
                            <button class="button button-primary nobash-files-btn nobash-files-primary-action" id="nobash-files-upload" type="button" aria-expanded="false"><span class="dashicons dashicons-upload nobash-files-btn-icon" aria-hidden="true"></span><span class="nobash-files-btn-label">Upload</span><span class="dashicons dashicons-arrow-down-alt2 nobash-files-btn-caret" aria-hidden="true"></span></button>
                            <div class="nobash-files-upload-picker" id="nobash-files-upload-picker" hidden>
                                <button type="button" data-upload-mode="single"><span class="nobash-files-upload-choice-icon dashicons dashicons-media-default"></span><span><strong>Single file</strong><small>Choose one file</small></span></button>
                                <button type="button" data-upload-mode="multiple"><span class="nobash-files-upload-choice-icon dashicons dashicons-images-alt2"></span><span><strong>Multiple files</strong><small>Choose several files at once</small></span></button>
                                <button type="button" data-upload-mode="folder"><span class="nobash-files-upload-choice-icon dashicons dashicons-category"></span><span><strong>Full folder</strong><small>Keep folders and subfolders</small></span></button>
                            </div>
                        </div>
                        <button class="button nobash-files-btn nobash-files-btn-secondary" id="nobash-files-new-folder" type="button"><span class="dashicons dashicons-plus-alt2 nobash-files-btn-icon" aria-hidden="true"></span><span class="nobash-files-btn-label">New Folder</span></button>
                        <div class="nobash-files-header-overflow">
                            <button class="nobash-files-overflow-button" id="nobash-files-header-more" type="button" aria-label="More actions" aria-expanded="false"><span class="dashicons dashicons-ellipsis"></span></button>
                            <div class="nobash-files-header-overflow-menu" id="nobash-files-header-more-menu" hidden>
                                <button id="nobash-files-undo" type="button" disabled><span class="dashicons dashicons-undo"></span><span id="nobash-files-undo-label">Undo</span></button>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=nobash-files-trash' ) ); ?>"><span class="dashicons dashicons-trash"></span>Trash</a>
                                <?php if ( current_user_can( 'manage_options' ) ) : ?><a href="<?php echo esc_url( admin_url( 'admin.php?page=nobash-files-settings' ) ); ?>"><span class="dashicons dashicons-admin-generic"></span>Settings</a><?php endif; ?>
                            </div>
                        </div>
                    <?php else : ?>
                        <a class="button button-primary nobash-files-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=nobash-files' ) ); ?>"><span class="dashicons dashicons-open-folder"></span> File Manager</a>
                        <a class="button nobash-files-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=nobash-files-tools' ) ); ?>"><span class="dashicons dashicons-admin-tools" aria-hidden="true"></span> Tools</a>
                    <?php endif; ?>
                </div>
            </header>
            <details class="nobash-files-safety-summary <?php echo '1' === get_option( 'nobash_files_safe_mode', '1' ) ? 'is-on' : 'is-off'; ?>">
                <summary><span><span class="dashicons dashicons-shield-alt" aria-hidden="true"></span> Safe Mode: <?php echo '1' === get_option( 'nobash_files_safe_mode', '1' ) ? 'On' : 'Off'; ?></span><span>Safety settings</span></summary>
                <div class="nobash-files-safety-options">
                <div><strong>Safe Mode: <?php echo '1' === get_option( 'nobash_files_safe_mode', '1' ) ? 'On' : 'Off'; ?></strong><p><?php echo '1' === get_option( 'nobash_files_safe_mode', '1' ) ? 'Protected changes use confirmation, recovery snapshots, and safe editor validation.' : 'Turn Safe Mode on for stronger protection before sensitive changes.'; ?></p></div>
                <?php if ( current_user_can( 'manage_options' ) ) : ?>
                    <form class="nobash-files-safe-toggle-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="nobash_files_toggle_safe_mode">
                        <input type="hidden" name="redirect_to" value="<?php echo esc_attr( self::current_nobash_files_admin_url() ); ?>">
                        <?php wp_nonce_field( 'nobash_files_toggle_safe_mode' ); ?>
                        <button class="button" type="submit"><?php echo '1' === get_option( 'nobash_files_safe_mode', '1' ) ? 'Turn Safe Mode Off' : 'Turn Safe Mode On'; ?></button>
                    </form>
                <?php else : ?>
                    <span class="nobash-files-badge ready">Managed by administrator</span>
                <?php endif; ?>
                </div>
            </details>
            <?php if ( empty( $access['isAdministrator'] ) && ! empty( $access['roots'] ) ) : ?>
                <section class="nobash-files-access-scope">
                    <div><span class="dashicons dashicons-lock"></span><strong>Your workspace</strong><small>Only assigned folders and actions are available.</small></div>
                    <div class="nobash-files-access-root-list">
                        <?php foreach ( $access['roots'] as $root_path ) : ?>
                            <button type="button" class="nobash-files-access-root" data-access-root="<?php echo esc_attr( $root_path ); ?>"><span class="dashicons dashicons-category"></span><?php echo esc_html( '/' . ( $root_path ?: '' ) ); ?></button>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
            <?php if ( is_multisite() && isset( $_GET['nobash_files_workspace'] ) && 'site' === sanitize_key( wp_unslash( $_GET['nobash_files_workspace'] ) ) ) : ?>
                <section class="nobash-files-network-workspace"><span class="dashicons dashicons-admin-site-alt3"></span><div><strong>Site uploads workspace</strong><p>This workspace opens the current network site's real uploads directory. Plugins, themes and WordPress core are shared network files.</p></div><a class="button" href="<?php echo esc_url( network_admin_url( 'admin.php?page=nobash-files-network' ) ); ?>">Network Overview</a></section>
            <?php endif; ?>
            <div id="nobash-files-notice" class="nobash-files-notice" role="status" aria-live="polite" aria-atomic="true" hidden></div>
            <input id="nobash-files-upload-single-input" type="file" hidden>
            <input id="nobash-files-upload-multi-input" type="file" multiple hidden>
            <input id="nobash-files-upload-folder-input" type="file" multiple webkitdirectory directory hidden>
            <section id="nobash-files-upload-panel" class="nobash-files-upload-panel" hidden>
                <div class="nobash-files-upload-head"><div><strong id="nobash-files-upload-title">Uploading</strong><p id="nobash-files-upload-status" aria-live="polite">Preparing upload…</p></div><div class="nobash-files-upload-head-actions"><span id="nobash-files-upload-percent">0%</span><button class="nobash-files-upload-cancel" id="nobash-files-upload-cancel" type="button">Cancel</button></div></div>
                <div class="nobash-files-progress"><span id="nobash-files-upload-progress"></span></div>
            </section>
            <div class="nobash-files-drop-overlay" id="nobash-files-drop-overlay" hidden><div><span class="dashicons dashicons-upload"></span><strong>Drop files or a folder here</strong><small>Folder structure will be preserved.</small></div></div>
            <section id="nobash-files-archive-job-panel" class="nobash-files-upload-panel nobash-files-archive-job-panel" hidden>
                <div class="nobash-files-upload-head"><div><strong id="nobash-files-archive-job-title">Archive operation</strong><p id="nobash-files-archive-job-status" aria-live="polite">Preparing…</p></div><div class="nobash-files-job-actions"><span id="nobash-files-archive-job-percent">0%</span><button class="button button-small" id="nobash-files-archive-cancel" type="button">Cancel</button></div></div>
                <div class="nobash-files-progress"><span id="nobash-files-archive-job-progress"></span></div>
            </section>
            <section id="nobash-files-search-panel" class="nobash-files-search-panel" hidden>
                <div class="nobash-files-search-panel-head">
                    <div class="nobash-files-search-field"><span class="dashicons dashicons-search"></span><input id="nobash-files-search-input" type="search" placeholder="Search by file or folder name…" autocomplete="off"><kbd>Esc</kbd></div>
                    <button class="nobash-files-icon-btn" id="nobash-files-search-close" type="button" aria-label="Close search"><span class="dashicons dashicons-no-alt"></span></button>
                </div>
                <div class="nobash-files-search-options">
                    <label>Search in <select id="nobash-files-search-scope"><option value="current">Current folder</option><option value="root">Entire WordPress site</option></select></label>
                    <label class="nobash-files-search-check"><input id="nobash-files-search-content" type="checkbox"> Search inside text/code files</label>
                    <span id="nobash-files-search-meta" class="nobash-files-muted">Type at least 2 characters</span>
                </div>
                <div id="nobash-files-search-results" class="nobash-files-search-results"></div>
            </section>
            <div id="nobash-files-context-menu" class="nobash-files-context-menu" hidden></div>
            <div class="nobash-files-modal-backdrop" id="nobash-files-modal" hidden>
                <div class="nobash-files-modal nobash-files-modal-compact" role="dialog" aria-modal="true" aria-labelledby="nobash-files-modal-title" aria-describedby="nobash-files-modal-message" tabindex="-1">
                    <div class="nobash-files-modal-head">
                        <div class="nobash-files-modal-title-wrap">
                            <div class="nobash-files-modal-icon" id="nobash-files-modal-icon" aria-hidden="true"><span id="nobash-files-modal-icon-glyph" class="dashicons dashicons-admin-generic"></span></div>
                            <div class="nobash-files-modal-heading"><h2 id="nobash-files-modal-title">Action</h2><p id="nobash-files-modal-message"></p></div>
                        </div>
                        <button class="nobash-files-modal-close" id="nobash-files-modal-close" type="button" aria-label="Close dialog"><span class="dashicons dashicons-no-alt"></span></button>
                    </div>
                    <div class="nobash-files-modal-body">
                        <label id="nobash-files-modal-input-wrap" class="nobash-files-modal-field"><span id="nobash-files-modal-input-label">Name</span><input id="nobash-files-modal-input" type="text" autocomplete="off"></label>
                    </div>
                    <div class="nobash-files-modal-actions">
                        <button class="button nobash-files-modal-secondary" id="nobash-files-modal-cancel" type="button"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span><span>Cancel</span></button>
                        <button class="button button-primary nobash-files-modal-primary" id="nobash-files-modal-confirm" type="button"><span id="nobash-files-modal-confirm-icon" class="dashicons dashicons-yes-alt" aria-hidden="true"></span><span id="nobash-files-modal-confirm-label">Continue</span></button>
                    </div>
                </div>
            </div>
            <div class="nobash-files-modal-backdrop" id="nobash-files-archive-modal" hidden>
                <div class="nobash-files-modal nobash-files-archive-modal" role="dialog" aria-modal="true" aria-labelledby="nobash-files-archive-modal-title" aria-describedby="nobash-files-archive-modal-message" tabindex="-1">
                    <div class="nobash-files-modal-head">
                        <div class="nobash-files-modal-title-wrap">
                            <div class="nobash-files-modal-icon nobash-files-modal-icon-archive" aria-hidden="true"><span class="dashicons dashicons-media-archive"></span></div>
                            <div class="nobash-files-modal-heading"><h2 id="nobash-files-archive-modal-title">Create archive</h2><p id="nobash-files-archive-modal-message">Package selected files safely.</p></div>
                        </div>
                        <button class="nobash-files-modal-close" id="nobash-files-archive-modal-close" type="button" aria-label="Close archive dialog"><span class="dashicons dashicons-no-alt"></span></button>
                    </div>
                    <div class="nobash-files-modal-body">
                        <div class="nobash-files-modal-summary" id="nobash-files-archive-selection"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><strong>1 item selected</strong></div>
                        <div id="nobash-files-archive-create-fields" class="nobash-files-form-stack nobash-files-archive-create-fields">
                            <label class="nobash-files-modal-field nobash-files-modal-field-wide"><span>Archive name</span><input id="nobash-files-archive-name" type="text" value="archive" autocomplete="off"></label>
                            <div class="nobash-files-modal-grid">
                                <label class="nobash-files-modal-field"><span>Format</span><select id="nobash-files-archive-format"><option value="zip">ZIP — recommended</option><option value="tar">TAR</option><option value="tar.gz">TAR.GZ</option></select></label>
                                <label class="nobash-files-modal-field"><span>Save in</span><input id="nobash-files-archive-destination" type="text" placeholder="Current folder"></label>
                            </div>
                        </div>
                        <div id="nobash-files-archive-extract-fields" class="nobash-files-form-stack nobash-files-archive-extract-fields" hidden>
                            <div id="nobash-files-archive-preview" class="nobash-files-archive-preview"></div>
                            <div class="nobash-files-modal-grid">
                                <label class="nobash-files-modal-field"><span>Extract to</span><input id="nobash-files-extract-destination" type="text" placeholder="Current folder"></label>
                                <label class="nobash-files-modal-field"><span>If a file already exists</span><select id="nobash-files-extract-conflict"><option value="rename">Keep both — safest</option><option value="skip">Skip existing</option><option value="overwrite">Replace existing</option></select><small id="nobash-files-extract-helper">Existing files will be preserved.</small></label>
                            </div>
                        </div>
                    </div>
                    <div class="nobash-files-modal-actions">
                        <button class="button nobash-files-modal-secondary" id="nobash-files-archive-modal-cancel" type="button"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span><span>Cancel</span></button>
                        <button class="button button-primary nobash-files-modal-primary" id="nobash-files-archive-modal-confirm" type="button"><span id="nobash-files-archive-modal-confirm-icon" class="dashicons dashicons-media-archive" aria-hidden="true"></span><span id="nobash-files-archive-modal-confirm-label">Create archive</span></button>
                    </div>
                </div>
            </div>
        <?php
    }

    private static function shell_end(): void {
        echo '</div>';
    }

    public static function render_app(): void {
        self::shell( 'manager' );
        ?>
        <section class="nobash-files-quick-grid nobash-files-quick-grid-five nobash-files-manager-shortcuts" aria-label="Quick access">
            <button class="nobash-files-quick" data-path="wp-content/themes" type="button"><span class="nobash-files-quick-icon purple dashicons dashicons-admin-appearance"></span><div><strong>Themes</strong><small>Active & installed themes</small></div><span class="dashicons dashicons-arrow-right-alt2 nobash-files-quick-arrow"></span></button>
            <button class="nobash-files-quick" data-path="wp-content/plugins" type="button"><span class="nobash-files-quick-icon green dashicons dashicons-admin-plugins"></span><div><strong>Plugins</strong><small>Manage plugin files</small></div><span class="dashicons dashicons-arrow-right-alt2 nobash-files-quick-arrow"></span></button>
            <button class="nobash-files-quick" data-path="wp-content/uploads" type="button"><span class="nobash-files-quick-icon blue dashicons dashicons-upload"></span><div><strong>Uploads</strong><small>Media files & uploads</small></div><span class="dashicons dashicons-arrow-right-alt2 nobash-files-quick-arrow"></span></button>
            <button class="nobash-files-quick" data-smart="favorites" type="button"><span class="nobash-files-quick-icon rose dashicons dashicons-star-filled"></span><div><strong>Favorites</strong><small>Your saved files & folders</small></div><span class="dashicons dashicons-arrow-right-alt2 nobash-files-quick-arrow"></span></button>
            <button class="nobash-files-quick" data-smart="recent" type="button"><span class="nobash-files-quick-icon amber dashicons dashicons-clock"></span><div><strong>Recent</strong><small>Recently opened files</small></div><span class="dashicons dashicons-arrow-right-alt2 nobash-files-quick-arrow"></span></button>
        </section>
        <main class="nobash-files-manager-grid nobash-files-manager-rebuilt">
            <aside class="nobash-files-panel nobash-files-tree-panel">
                <div class="nobash-files-panel-head nobash-files-tree-head"><strong>Folders</strong><div class="nobash-files-panel-head-actions"><button class="nobash-files-icon-btn" id="nobash-files-tree-new-folder" type="button" title="New folder" aria-label="New folder"><span class="dashicons dashicons-plus-alt2"></span></button><button class="nobash-files-icon-btn" id="nobash-files-refresh" type="button" title="Refresh folders" aria-label="Refresh folders"><span class="dashicons dashicons-update"></span></button></div></div>
                <div id="nobash-files-tree" class="nobash-files-tree"></div>
                <div class="nobash-files-tree-more"><button class="nobash-files-mini-link" id="nobash-files-tree-load-more" type="button" hidden>Load more folders</button></div>
                <footer class="nobash-files-tree-storage" id="nobash-files-tree-storage">
                    <div class="nobash-files-tree-storage-row"><span><span class="dashicons dashicons-database"></span> Storage</span><strong id="nobash-files-storage-text">Server managed</strong></div>
                    <div class="nobash-files-storage-progress" aria-hidden="true"><span id="nobash-files-storage-progress"></span></div>
                </footer>
            </aside>
            <section class="nobash-files-panel nobash-files-files-panel">
                <div class="nobash-files-breadcrumb nobash-files-manager-breadcrumb" id="nobash-files-breadcrumb"></div>
                <div class="nobash-files-explorer-toolbar nobash-files-explorer-toolbar-primary">
                    <div class="nobash-files-explorer-left">
                        <button class="nobash-files-tool-button" id="nobash-files-nav-back" type="button" disabled><span class="dashicons dashicons-arrow-left-alt2"></span><span>Back</span></button>
                        <button class="nobash-files-tool-button" id="nobash-files-nav-forward" type="button" disabled><span>Forward</span><span class="dashicons dashicons-arrow-right-alt2"></span></button>
                        <button class="nobash-files-tool-button" id="nobash-files-toolbar-refresh" type="button"><span class="dashicons dashicons-update"></span><span>Refresh</span></button>
                        <div class="nobash-files-dropdown-wrap">
                            <button class="nobash-files-tool-button" id="nobash-files-manager-more" type="button" aria-expanded="false"><span>More</span><span class="dashicons dashicons-arrow-down-alt2"></span></button>
                            <div class="nobash-files-manager-more-menu" id="nobash-files-manager-more-menu" hidden>
                                <button type="button" data-manager-more="favorite"><span class="dashicons dashicons-star-empty"></span>Favorite this folder</button>
                                <button type="button" data-manager-more="recent"><span class="dashicons dashicons-clock"></span>Recent files</button>
                                <button type="button" data-manager-more="new-folder"><span class="dashicons dashicons-plus-alt2"></span>New folder</button>
                            </div>
                        </div>
                    </div>
                    <div class="nobash-files-explorer-right">
                        <label class="nobash-files-sort-control"><span>Sort by:</span><select id="nobash-files-sort" aria-label="Sort files"><option value="name-asc">Name (A–Z)</option><option value="name-desc">Name (Z–A)</option><option value="modified-desc">Newest</option><option value="modified-asc">Oldest</option><option value="size-desc">Largest</option><option value="size-asc">Smallest</option></select></label>
                        <div class="nobash-files-view-switch" role="group" aria-label="View">
                            <button id="nobash-files-list-view" class="is-active" type="button" aria-label="List view" title="List view"><span class="dashicons dashicons-list-view"></span></button>
                            <button id="nobash-files-grid-view" type="button" aria-label="Grid view" title="Grid view"><span class="dashicons dashicons-grid-view"></span></button>
                        </div>
                    </div>
                </div>
                <div class="nobash-files-selection-bar" id="nobash-files-selection-bar" hidden>
                    <strong id="nobash-files-selected-count">0 selected</strong><span class="nobash-files-selection-spacer"></span>
                    <button class="button button-small" data-bulk="download"><span class="dashicons dashicons-download"></span> Download</button>
                    <button class="button button-small" data-bulk="archive"><span class="dashicons dashicons-media-archive"></span> Archive</button>
                    <button class="button button-small" data-bulk="extract"><span class="dashicons dashicons-open-folder"></span> Extract</button>
                    <button class="button button-small" data-bulk="copy"><span class="dashicons dashicons-admin-page"></span> Copy</button>
                    <button class="button button-small" data-bulk="move"><span class="dashicons dashicons-move"></span> Move</button>
                    <button class="button button-small" data-bulk="duplicate"><span class="dashicons dashicons-images-alt2"></span> Duplicate</button>
                    <button class="button button-small" data-bulk="rename"><span class="dashicons dashicons-edit"></span> Rename</button>
                    <button class="button button-small nobash-files-danger" data-bulk="trash"><span class="dashicons dashicons-trash"></span> Trash</button>
                </div>
                <div class="nobash-files-table-wrap" id="nobash-files-list-container"><table class="nobash-files-table"><thead><tr><th class="nobash-files-check-col"><input type="checkbox" id="nobash-files-select-all" aria-label="Select all"></th><th>Name</th><th>Type</th><th>Size</th><th>Modified</th><th>Status</th><th class="nobash-files-actions-col"><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span></th></tr></thead><tbody id="nobash-files-files"></tbody></table></div>
                <div id="nobash-files-grid-container" class="nobash-files-file-grid" hidden></div>
                <footer class="nobash-files-table-footer"><span id="nobash-files-count">0 items</span><button class="nobash-files-mini-link" id="nobash-files-load-more" type="button" hidden>Load more</button><span id="nobash-files-folder-state">Only permitted folders are shown</span></footer>
            </section>
            <aside class="nobash-files-panel nobash-files-details nobash-files-manager-details" id="nobash-files-details" hidden><div class="nobash-files-empty nobash-files-manager-empty"><div class="nobash-files-empty-folder-art" aria-hidden="true"><span class="dashicons dashicons-category"></span><i></i><b></b></div><strong>Select a file or folder</strong><p>Choose an item to preview it, inspect details, or take a safe action.</p><div class="nobash-files-empty-capabilities"><span><i class="dashicons dashicons-visibility"></i><b>Preview</b><small>View file contents and details</small></span><span><i class="dashicons dashicons-edit"></i><b>Edit</b><small>Open supported files in Code Editor</small></span><span><i class="dashicons dashicons-shield-alt"></i><b>Safe &amp; Secure</b><small>Protected by Safe Mode</small></span></div></div></aside>
        </main>
        <?php
        self::shell_end();
    }

    public static function render_editor(): void {
        self::shell( 'editor' );
        ?>
        <main class="nobash-files-editor-grid nobash-files-editor-rebuilt">
            <aside class="nobash-files-panel nobash-files-tree-panel nobash-files-editor-tree-panel">
                <div class="nobash-files-panel-head nobash-files-editor-tree-head">
                    <strong>Files</strong>
                    <div class="nobash-files-editor-tree-actions">
                        <button class="nobash-files-icon-btn" id="nobash-files-editor-tree-add" type="button" aria-label="New folder" title="New folder"><span class="dashicons dashicons-plus-alt2"></span></button>
                        <button class="nobash-files-icon-btn" id="nobash-files-refresh" type="button" aria-label="Refresh files" title="Refresh"><span class="dashicons dashicons-update"></span></button>
                    </div>
                </div>
                <div id="nobash-files-tree" class="nobash-files-tree"></div>
                <div class="nobash-files-tree-more"><button class="button button-small" id="nobash-files-tree-load-more" type="button" hidden>Load more files</button></div>
                <div class="nobash-files-editor-tree-foot"><span class="dashicons dashicons-shield-alt"></span><span>Safe editor workspace</span></div>
            </aside>
            <section class="nobash-files-panel nobash-files-editor-panel">
                <div class="nobash-files-breadcrumb nobash-files-editor-breadcrumb" id="nobash-files-breadcrumb"></div>
                <div class="nobash-files-editor-toolbar">
                    <button class="button button-primary nobash-files-btn nobash-files-editor-save" id="nobash-files-save" type="button"><span class="dashicons dashicons-saved"></span><span>Save</span></button>
                    <button class="button nobash-files-btn" id="nobash-files-validate" type="button"><span class="dashicons dashicons-shield"></span><span>Validate</span></button>
                    <button class="button nobash-files-btn" id="nobash-files-find" type="button"><span class="dashicons dashicons-search"></span><span>Find</span></button>
                    <button class="button nobash-files-btn" id="nobash-files-replace" type="button"><span class="dashicons dashicons-update-alt"></span><span>Replace</span></button>
                    <button class="button nobash-files-btn" id="nobash-files-compare" type="button"><span class="dashicons dashicons-image-flip-horizontal"></span><span>Compare</span></button>
                    <button class="button nobash-files-btn" id="nobash-files-command" type="button"><span class="dashicons dashicons-editor-code"></span><span>Commands</span><span class="dashicons dashicons-arrow-down-alt2 nobash-files-toolbar-caret"></span></button>
                    <div class="nobash-files-editor-more-wrap">
                        <button class="button nobash-files-btn" id="nobash-files-editor-more" type="button" aria-expanded="false"><span>More</span><span class="dashicons dashicons-arrow-down-alt2 nobash-files-toolbar-caret"></span></button>
                        <div class="nobash-files-editor-more-menu" id="nobash-files-editor-more-menu" hidden>
                            <button type="button" id="nobash-files-editor-reload"><span class="dashicons dashicons-update"></span>Reload latest</button>
                            <button type="button" id="nobash-files-editor-undo"><span class="dashicons dashicons-undo"></span>Revert unsaved</button>
                            <button type="button" id="nobash-files-editor-close-current"><span class="dashicons dashicons-no-alt"></span>Close current tab</button>
                        </div>
                    </div>
                    <span class="nobash-files-editor-spacer"></span>
                    <button class="button nobash-files-btn nobash-files-editor-icon-action" id="nobash-files-fullscreen" type="button" title="Full screen" aria-label="Full screen"><span class="dashicons dashicons-editor-expand"></span></button>
                </div>
                <div class="nobash-files-editor-tabs" id="nobash-files-editor-tabs"><div class="nobash-files-editor-tab is-active nobash-files-editor-tab-empty">Open a file from the tree</div><button type="button" class="nobash-files-editor-tab-add" id="nobash-files-editor-tab-add" aria-label="Open another file" title="Open another file"><span class="dashicons dashicons-plus-alt2"></span></button></div>
                <div id="nobash-files-find-panel" class="nobash-files-find-panel" hidden><label><span>Find</span><input id="nobash-files-find-text" type="text" autocomplete="off"></label><label><span>Replace</span><input id="nobash-files-replace-text" type="text" autocomplete="off"></label><button class="button button-small" id="nobash-files-find-next" type="button">Find Next</button><button class="button button-small" id="nobash-files-replace-one" type="button">Replace</button><button class="button button-small" id="nobash-files-replace-all" type="button">Replace All</button><span id="nobash-files-find-meta" class="nobash-files-muted"></span><button class="nobash-files-icon-btn" id="nobash-files-find-close" type="button" aria-label="Close find"><span class="dashicons dashicons-no-alt"></span></button></div>
                <div class="nobash-files-code-shell" id="nobash-files-code-shell">
                    <pre id="nobash-files-lines" class="nobash-files-lines">1</pre>
                    <textarea id="nobash-files-editor" class="nobash-files-code" spellcheck="false" aria-label="Source code editor" placeholder="Select an editable file from the left panel."></textarea>
                    <aside class="nobash-files-editor-minimap" id="nobash-files-editor-minimap" aria-hidden="true"><div class="nobash-files-minimap-code" id="nobash-files-minimap-code"></div><span class="nobash-files-minimap-viewport" id="nobash-files-minimap-viewport"></span></aside>
                    <div class="nobash-files-source-loading" id="nobash-files-source-loading" hidden><span class="spinner is-active"></span><strong>Loading source…</strong></div>
                </div>
                <footer class="nobash-files-code-status">
                    <span class="nobash-files-language-chip" id="nobash-files-language">Text</span>
                    <span id="nobash-files-cursor">Ln 1, Col 1</span>
                    <span id="nobash-files-indent-state">Spaces: 2</span>
                    <span>UTF-8</span><span>LF</span>
                    <span class="nobash-files-code-status-spacer"></span>
                    <span id="nobash-files-draft-state" class="nobash-files-draft-state"><span class="nobash-files-status-dot"></span> Draft recovery ready</span>
                    <span id="nobash-files-editor-state" class="nobash-files-editor-health"><span class="dashicons dashicons-shield"></span>Server validation on save</span>
                </footer>
                <div class="nobash-files-diff-card" id="nobash-files-diff-card" hidden><div class="nobash-files-panel-head"><strong id="nobash-files-diff-title">Changes</strong><button class="button button-small" id="nobash-files-close-diff">Close</button></div><div id="nobash-files-diff-content" class="nobash-files-diff-content"></div></div>
                <div class="nobash-files-command-palette" id="nobash-files-command-palette" hidden><div class="nobash-files-command-box"><div class="nobash-files-command-search"><span class="dashicons dashicons-search"></span><input id="nobash-files-command-input" type="search" placeholder="Type a command…" autocomplete="off"><kbd>Esc</kbd></div><div id="nobash-files-command-results" class="nobash-files-command-results"></div></div></div>
            </section>
            <aside class="nobash-files-editor-inspector">
                <section class="nobash-files-panel nobash-files-info-card" id="nobash-files-file-info"><div class="nobash-files-empty"><span class="dashicons dashicons-editor-code"></span><strong>No file open</strong><p>Select a text or code file to begin.</p></div></section>
                <section class="nobash-files-panel nobash-files-status-card"><div class="nobash-files-card-title">Validation Status</div><div id="nobash-files-validation-status" class="nobash-files-validation neutral"><span class="dashicons dashicons-minus"></span><div><strong>Not validated</strong><p>Validation runs before every safe save.</p></div></div><button class="button" id="nobash-files-validate-again" type="button">Validate Again</button></section>
                <section class="nobash-files-panel nobash-files-version-card"><div class="nobash-files-panel-head"><strong>Version History</strong><a class="nobash-files-mini-link" href="<?php echo esc_url( admin_url( 'admin.php?page=nobash-files-versions' ) ); ?>">View all</a></div><div id="nobash-files-versions" class="nobash-files-version-list"><p class="nobash-files-muted">Open a file to view versions.</p></div></section>
                <section class="nobash-files-panel nobash-files-editor-safe-card">
                    <div class="nobash-files-card-title">Safe Actions</div>
                    <button type="button" class="nobash-files-editor-safe-action" id="nobash-files-editor-backup" disabled><span class="dashicons dashicons-backup"></span><span><strong>Create Backup</strong><small>Save a manual safety version</small></span></button>
                    <button type="button" class="nobash-files-editor-safe-action" id="nobash-files-editor-restore-latest" disabled><span class="dashicons dashicons-backup"></span><span><strong>Restore Backup</strong><small>Restore the latest safety version</small></span></button>
                    <button type="button" class="nobash-files-editor-safe-action" id="nobash-files-editor-duplicate" disabled><span class="dashicons dashicons-admin-page"></span><span><strong>Duplicate File</strong><small>Create a safe copy beside this file</small></span></button>
                </section>
            </aside>
        </main>
        <?php
        self::shell_end();
    }

    public static function render_versions(): void {
        if ( ! Nobash_Files_Access::can_use() ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'nobash-files' ) );
        }
        try {
            Nobash_Files_Access::assert_permission( 'versions' );
        } catch ( Throwable $e ) {
            wp_die( esc_html__( 'You do not have permission to view version history.', 'nobash-files' ), '', array( 'response' => 403 ) );
        }

        $fs = new Nobash_Files_Filesystem();
        $rows = Nobash_Files_DB::version_history_rows( 2000 );
        if ( ! current_user_can( 'manage_options' ) ) {
            $rows = array_values( array_filter( $rows, static fn( $row ): bool => Nobash_Files_Access::can_paths( 'versions', array( (string) ( $row['relative_path'] ?? '' ) ), $fs ) ) );
        }

        $search = isset( $_GET['nobash_files_history_search'] ) ? sanitize_text_field( wp_unslash( $_GET['nobash_files_history_search'] ) ) : '';
        $type   = isset( $_GET['nobash_files_history_type'] ) ? sanitize_key( wp_unslash( $_GET['nobash_files_history_type'] ) ) : 'all';
        $status = isset( $_GET['nobash_files_history_status'] ) ? sanitize_key( wp_unslash( $_GET['nobash_files_history_status'] ) ) : 'all';
        $sort   = isset( $_GET['nobash_files_history_sort'] ) ? sanitize_key( wp_unslash( $_GET['nobash_files_history_sort'] ) ) : 'newest';
        $per    = isset( $_GET['nobash_files_per_page'] ) ? absint( $_GET['nobash_files_per_page'] ) : 10;
        $per    = in_array( $per, array( 10, 20, 50 ), true ) ? $per : 10;
        $page   = isset( $_GET['nobash_files_history_page'] ) ? max( 1, absint( $_GET['nobash_files_history_page'] ) ) : 1;

        $filtered = array_values( array_filter( $rows, static function( $row ) use ( $search, $type, $status ) {
            $path = (string) ( $row['relative_path'] ?? '' );
            $action = sanitize_key( (string) ( $row['action'] ?? '' ) );
            if ( '' !== $search && false === stripos( $path, $search ) ) { return false; }
            if ( 'all' !== $type ) {
                $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
                if ( $ext !== $type ) { return false; }
            }
            if ( 'all' !== $status && $action !== $status ) { return false; }
            return true;
        } ) );
        if ( 'oldest' === $sort ) { $filtered = array_reverse( $filtered ); }
        elseif ( 'largest' === $sort ) { usort( $filtered, static fn( $a, $b ) => (int) ( $b['file_size'] ?? 0 ) <=> (int) ( $a['file_size'] ?? 0 ) ); }

        $total = count( $filtered );
        $pages = max( 1, (int) ceil( $total / $per ) );
        $page  = min( $page, $pages );
        $visible = array_slice( $filtered, ( $page - 1 ) * $per, $per );

        $allowed_rows = $rows;
        $stats = current_user_can( 'manage_options' ) ? Nobash_Files_DB::version_history_stats() : array(
            'snapshots' => count( $allowed_rows ),
            'bytes' => array_sum( array_map( static fn( $r ) => (int) ( $r['file_size'] ?? 0 ), $allowed_rows ) ),
            'latest_at' => (string) ( $allowed_rows[0]['created_at'] ?? '' ),
        );
        $undo_count = Nobash_Files_DB::undoable_count();
        $latest_id = (int) ( $allowed_rows[0]['id'] ?? 0 );
        $restore_points = array_values( array_filter( $allowed_rows, static fn( $r ) => 'restore' === sanitize_key( (string) ( $r['action'] ?? '' ) ) ) );
        $restore_points = array_slice( $restore_points, 0, 3 );

        $selected_id = isset( $_GET['nobash_files_version'] ) ? absint( $_GET['nobash_files_version'] ) : (int) ( $filtered[0]['id'] ?? $allowed_rows[0]['id'] ?? 0 );
        $selected = $selected_id ? Nobash_Files_DB::get_version( $selected_id ) : null;
        if ( $selected && ! Nobash_Files_Access::can_paths( 'versions', array( (string) $selected['relative_path'] ), $fs ) ) {
            $selected = null;
        }
        $compare_id = isset( $_GET['nobash_files_compare'] ) ? absint( $_GET['nobash_files_compare'] ) : 0;
        $compare = $compare_id ? Nobash_Files_DB::get_version( $compare_id ) : null;
        if ( $compare && ! Nobash_Files_Access::can_paths( 'versions', array( (string) $compare['relative_path'] ), $fs ) ) {
            $compare = null;
        }

        $user_ids = array_values( array_unique( array_filter( array_map( static fn( $r ) => (int) ( $r['user_id'] ?? 0 ), $rows ) ) ) );
        $users = array();
        foreach ( $user_ids as $user_id ) {
            $user = get_user_by( 'id', $user_id );
            if ( $user ) { $users[ $user_id ] = $user->display_name ?: $user->user_login; }
        }

        $notice = isset( $_GET['nobash_files_history_notice'] ) ? sanitize_key( wp_unslash( $_GET['nobash_files_history_notice'] ) ) : '';
        self::shell( 'versions' );
        if ( $notice ) {
            $messages = array( 'restored' => 'Snapshot restored safely.', 'undone' => 'Latest file operation undone.', 'downloaded' => 'Snapshot download started.' );
            if ( isset( $messages[ $notice ] ) ) { echo '<div class="nobash-files-history-page-notice"><span class="dashicons dashicons-yes-alt"></span>' . esc_html( $messages[ $notice ] ) . '</div>'; }
        }
        ?>
        <main class="nobash-files-version-history-page">
            <section class="nobash-files-history-titlebar">
                <div class="nobash-files-history-title-icon"><span class="dashicons dashicons-backup"></span></div>
                <div><h2>File history</h2><p>Find an earlier copy of a file to preview, download or restore.</p></div>
            </section>

            <div class="nobash-files-history-layout">
                <section class="nobash-files-history-main">
                    <div class="nobash-files-history-metrics">
                        <article><span class="nobash-files-history-metric-icon snapshots"><span class="dashicons dashicons-backup"></span></span><div><small>Safety Snapshots</small><strong><?php echo esc_html( number_format_i18n( (int) $stats['snapshots'] ) ); ?></strong><p>Total retained snapshots</p></div></article>
                        <article><span class="nobash-files-history-metric-icon undo"><span class="dashicons dashicons-undo"></span></span><div><small>Undo Queue</small><strong><?php echo esc_html( number_format_i18n( $undo_count ) ); ?></strong><p>Pending file operations</p></div></article>
                        <article><span class="nobash-files-history-metric-icon storage"><span class="dashicons dashicons-database"></span></span><div><small>Storage Used</small><strong><?php echo esc_html( size_format( (int) $stats['bytes'], 1 ) ); ?></strong><p>By retained snapshots</p></div></article>
                    </div>

                    <?php if ( $compare ) :
                        $current = null; $compare_error = '';
                        try { $fs = new Nobash_Files_Filesystem(); Nobash_Files_Access::assert_paths( 'read', array( (string) $compare['relative_path'] ), $fs ); $current = $fs->read_file( (string) $compare['relative_path'] ); }
                        catch ( Throwable $e ) { $compare_error = $e->getMessage(); }
                    ?>
                    <section class="nobash-files-history-compare" id="nobash-files-history-compare">
                        <div class="nobash-files-history-section-head"><div><span class="dashicons dashicons-editor-code"></span><div><strong>Compare snapshot</strong><p><?php echo esc_html( (string) $compare['relative_path'] ); ?></p></div></div><a class="nobash-files-icon-link" href="<?php echo esc_url( admin_url( 'admin.php?page=nobash-files-versions' ) ); ?>" aria-label="Close comparison"><span class="dashicons dashicons-no-alt"></span></a></div>
                        <?php if ( $compare_error ) : ?><div class="nobash-files-history-inline-warning"><?php echo esc_html( $compare_error ); ?></div><?php else : ?>
                        <div class="nobash-files-history-compare-grid"><div><header><strong>Snapshot</strong><small><?php echo esc_html( (string) $compare['created_at'] ); ?></small></header><pre><?php echo esc_html( substr( (string) $compare['content'], 0, 120000 ) ); ?></pre></div><div><header><strong>Current file</strong><small>Live filesystem</small></header><pre><?php echo esc_html( substr( (string) ( $current['content'] ?? '' ), 0, 120000 ) ); ?></pre></div></div>
                        <?php endif; ?>
                    </section>
                    <?php endif; ?>

                    <section class="nobash-files-history-table-card">
                        <form class="nobash-files-history-filters" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
                            <input type="hidden" name="page" value="nobash-files-versions">
                            <label class="nobash-files-history-search"><span class="dashicons dashicons-search"></span><input type="search" name="nobash_files_history_search" value="<?php echo esc_attr( $search ); ?>" placeholder="Search files or paths…"></label>
                            <select name="nobash_files_history_type" aria-label="File type"><option value="all">All File Types</option><?php foreach ( array( 'php','css','js','json','html','txt','md','ini' ) as $ext ) : ?><option value="<?php echo esc_attr( $ext ); ?>" <?php selected( $type, $ext ); ?>><?php echo esc_html( strtoupper( $ext ) ); ?></option><?php endforeach; ?></select>
                            <select name="nobash_files_history_status" aria-label="Snapshot type"><option value="all">All Statuses</option><option value="save" <?php selected( $status, 'save' ); ?>>Edited</option><option value="manual" <?php selected( $status, 'manual' ); ?>>Created Backup</option><option value="restore" <?php selected( $status, 'restore' ); ?>>Restore Point</option></select>
                            <select name="nobash_files_history_sort" aria-label="Sort"><option value="newest" <?php selected( $sort, 'newest' ); ?>>Newest First</option><option value="oldest" <?php selected( $sort, 'oldest' ); ?>>Oldest First</option><option value="largest" <?php selected( $sort, 'largest' ); ?>>Largest First</option></select>
                            <button class="nobash-files-history-refresh" type="submit" aria-label="Apply filters"><span class="dashicons dashicons-update"></span></button>
                        </form>
                        <div class="nobash-files-history-rows">
                        <?php if ( empty( $visible ) ) : ?><div class="nobash-files-history-empty"><span class="dashicons dashicons-backup"></span><strong>No snapshots match these filters.</strong><p>Open Code Editor and save or manually back up a file to create a snapshot.</p></div><?php endif; ?>
                        <?php foreach ( $visible as $index => $row ) :
                            $id = (int) $row['id']; $path = (string) $row['relative_path']; $action = sanitize_key( (string) $row['action'] );
                            $name = basename( $path ); $dir = dirname( $path ); if ( '.' === $dir ) { $dir = '/'; } else { $dir = '/' . ltrim( $dir, '/' ); }
                            $meta = self::history_action_meta( $action ); $author = $users[ (int) $row['user_id'] ] ?? ( (int) $row['user_id'] ? 'User #' . (int) $row['user_id'] : 'System' );
                            $preview_url = add_query_arg( array( 'page'=>'nobash-files-versions','nobash_files_version'=>$id ), admin_url( 'admin.php' ) );
                            $compare_url = add_query_arg( array( 'page'=>'nobash-files-versions','nobash_files_compare'=>$id,'nobash_files_version'=>$id ), admin_url( 'admin.php' ) );
                            $download_url = wp_nonce_url( admin_url( 'admin-post.php?action=nobash_files_history_download&version_id=' . $id ), 'nobash_files_history_download_' . $id );
                        ?>
                            <article class="nobash-files-history-row <?php echo $id === $latest_id ? 'is-latest' : ''; ?>">
                                <div class="nobash-files-history-node"><span></span></div>
                                <div class="nobash-files-history-file-icon <?php echo esc_attr( $meta['tone'] ); ?>"><span class="dashicons dashicons-media-code"></span></div>
                                <div class="nobash-files-history-file"><strong><?php echo esc_html( $name ); ?></strong><small><?php echo esc_html( $dir ); ?></small></div>
                                <div><span class="nobash-files-history-action-badge <?php echo esc_attr( $meta['tone'] ); ?>"><span class="dashicons <?php echo esc_attr( $meta['icon'] ); ?>"></span><?php echo esc_html( $meta['label'] ); ?></span></div>
                                <time><?php echo esc_html( mysql2date( 'M j, Y', (string) $row['created_at'] ) ); ?><small><?php echo esc_html( mysql2date( 'g:i A', (string) $row['created_at'] ) ); ?></small></time>
                                <div class="nobash-files-history-author"><span class="dashicons dashicons-admin-users"></span><?php echo esc_html( $author ); ?></div>
                                <div class="nobash-files-history-size"><?php echo esc_html( size_format( (int) $row['file_size'], 1 ) ); ?></div>
                                <div><span class="nobash-files-history-status <?php echo esc_attr( $meta['tone'] ); ?>"><?php echo esc_html( $meta['status'] ); ?></span></div>
                                <div class="nobash-files-history-actions"><a class="nobash-files-history-btn" href="<?php echo esc_url( $preview_url ); ?>"><span class="dashicons dashicons-visibility"></span>Preview</a><a class="nobash-files-history-btn" href="<?php echo esc_url( $compare_url ); ?>#nobash-files-history-compare"><span class="dashicons dashicons-admin-page"></span>Compare</a><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="nobash_files_history_restore"><input type="hidden" name="version_id" value="<?php echo esc_attr( $id ); ?>"><?php wp_nonce_field( 'nobash_files_history_restore_' . $id ); ?><button class="nobash-files-history-btn restore" type="submit"><span class="dashicons dashicons-backup"></span>Restore</button></form><a class="nobash-files-history-more" href="<?php echo esc_url( $download_url ); ?>" aria-label="Download snapshot" title="Download snapshot"><span class="dashicons dashicons-download"></span></a></div>
                            </article>
                        <?php endforeach; ?>
                        </div>
                        <footer class="nobash-files-history-footer"><span><?php if ( $total ) { echo esc_html( sprintf( /* translators: 1: First snapshot number, 2: Last snapshot number, 3: Total snapshot count. */ __( 'Showing %1$d–%2$d of %3$d snapshots', 'nobash-files' ), ( ( $page - 1 ) * $per ) + 1, min( $page * $per, $total ), $total ) ); } else { echo '0 snapshots'; } ?></span><nav class="nobash-files-history-pagination" aria-label="Version history pages"><?php
                            $base_args = array_filter( array( 'page'=>'nobash-files-versions','nobash_files_history_search'=>$search,'nobash_files_history_type'=>$type,'nobash_files_history_status'=>$status,'nobash_files_history_sort'=>$sort,'nobash_files_per_page'=>$per ) );
                            for ( $i = max( 1, $page - 2 ); $i <= min( $pages, $page + 2 ); $i++ ) { $u = add_query_arg( array_merge( $base_args, array( 'nobash_files_history_page'=>$i ) ), admin_url( 'admin.php' ) ); echo '<a class="' . ( $i === $page ? 'is-current' : '' ) . '" href="' . esc_url( $u ) . '">' . esc_html( (string) $i ) . '</a>'; }
                        ?></nav><form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>"><input type="hidden" name="page" value="nobash-files-versions"><input type="hidden" name="nobash_files_history_search" value="<?php echo esc_attr( $search ); ?>"><input type="hidden" name="nobash_files_history_type" value="<?php echo esc_attr( $type ); ?>"><input type="hidden" name="nobash_files_history_status" value="<?php echo esc_attr( $status ); ?>"><input type="hidden" name="nobash_files_history_sort" value="<?php echo esc_attr( $sort ); ?>"><label>Rows per page <select name="nobash_files_per_page" onchange="this.form.submit()"><option value="10" <?php selected( $per, 10 ); ?>>10</option><option value="20" <?php selected( $per, 20 ); ?>>20</option><option value="50" <?php selected( $per, 50 ); ?>>50</option></select></label></form></footer>
                    </section>
                </section>

                <aside class="nobash-files-history-side">
                    <section class="nobash-files-history-side-card nobash-files-history-latest" id="nobash-files-snapshot-preview">
                        <div class="nobash-files-history-side-head"><strong><?php echo isset( $_GET['nobash_files_version'] ) ? 'Snapshot Details' : 'Latest Snapshot'; ?></strong><?php if ( $selected ) : ?><span><?php echo isset( $_GET['nobash_files_version'] ) ? 'Selected' : 'Latest'; ?></span><?php endif; ?></div>
                        <?php if ( $selected ) : $selected_path = (string) $selected['relative_path']; $selected_author = $users[ (int) $selected['user_id'] ] ?? ( (int) $selected['user_id'] ? 'User #' . (int) $selected['user_id'] : 'System' ); $selected_ext = strtoupper( pathinfo( $selected_path, PATHINFO_EXTENSION ) ?: 'FILE' ); ?>
                            <div class="nobash-files-history-latest-file"><span class="dashicons dashicons-media-code"></span><div><strong><?php echo esc_html( basename( $selected_path ) ); ?></strong><small>/<?php echo esc_html( ltrim( $selected_path, '/' ) ); ?></small></div></div>
                            <dl><dt>Snapshot</dt><dd>#<?php echo esc_html( (string) (int) $selected['id'] ); ?></dd><dt>Captured</dt><dd><?php echo esc_html( mysql2date( 'M j, Y g:i A', (string) $selected['created_at'] ) ); ?></dd><dt>Author</dt><dd><?php echo esc_html( $selected_author ); ?></dd><dt>Size</dt><dd><?php echo esc_html( size_format( (int) $selected['file_size'], 1 ) ); ?></dd><dt>Type</dt><dd><?php echo esc_html( $selected_ext ); ?> File</dd><dt>SHA256</dt><dd><code><?php echo esc_html( substr( (string) $selected['checksum'], 0, 12 ) . '…' ); ?></code></dd></dl>
                            <details class="nobash-files-history-snapshot-code" <?php echo isset( $_GET['nobash_files_version'] ) ? 'open' : ''; ?>><summary><span class="dashicons dashicons-visibility"></span>Preview snapshot</summary><pre><?php echo esc_html( substr( (string) $selected['content'], 0, 80000 ) ); ?></pre></details>
                            <a class="button button-primary nobash-files-history-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=nobash-files-editor&file=' . rawurlencode( $selected_path ) ) ); ?>"><span class="dashicons dashicons-editor-code"></span>Open Live File</a>
                        <?php else : ?><div class="nobash-files-history-side-empty"><span class="dashicons dashicons-backup"></span><p>No snapshots yet.</p></div><?php endif; ?>
                    </section>

                    <section class="nobash-files-history-side-card" id="nobash-files-undo-queue"><div class="nobash-files-history-undo-card"><span class="dashicons dashicons-undo"></span><div><strong>Operation Undo</strong><p><?php echo esc_html( $undo_count ? $undo_count . ' action' . ( 1 === $undo_count ? '' : 's' ) . ' can be undone' : 'No pending undo actions' ); ?></p></div></div><?php if ( $undo_count ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="nobash_files_history_undo"><?php wp_nonce_field( 'nobash_files_history_undo' ); ?><button class="nobash-files-history-link-button" type="submit">Undo latest operation <span class="dashicons dashicons-arrow-right-alt2"></span></button></form><?php endif; ?></section>

                    <section class="nobash-files-history-side-card"><div class="nobash-files-history-side-head"><strong>Recent Restore Points</strong></div><div class="nobash-files-history-restore-list"><?php if ( empty( $restore_points ) ) : ?><p class="nobash-files-muted">Restore points appear after a version is restored.</p><?php else : foreach ( $restore_points as $rp ) : ?><a href="<?php echo esc_url( add_query_arg( array( 'page'=>'nobash-files-versions','nobash_files_version'=>(int)$rp['id'] ), admin_url( 'admin.php' ) ) ); ?>"><span class="dashicons dashicons-media-code"></span><div><strong><?php echo esc_html( basename( (string) $rp['relative_path'] ) ); ?></strong><small><?php echo esc_html( mysql2date( 'M j, Y g:i A', (string) $rp['created_at'] ) ); ?></small></div><em>Restored</em></a><?php endforeach; endif; ?></div></section>
                </aside>
            </div>
        </main>
        <?php
        self::shell_end();
    }

    private static function history_action_meta( string $action ): array {
        $map = array(
            'save'    => array( 'label' => 'Edited', 'status' => 'Edited', 'tone' => 'edited', 'icon' => 'dashicons-edit' ),
            'manual'  => array( 'label' => 'Created Backup', 'status' => 'Backup', 'tone' => 'backup', 'icon' => 'dashicons-database-add' ),
            'restore' => array( 'label' => 'Restore Point', 'status' => 'Restored', 'tone' => 'restored', 'icon' => 'dashicons-backup' ),
        );
        return $map[ $action ] ?? array( 'label' => 'Snapshot', 'status' => 'Archived', 'tone' => 'archived', 'icon' => 'dashicons-media-code' );
    }

    public static function history_restore(): void {
        if ( ! Nobash_Files_Access::can_use() ) { wp_die( esc_html__( 'You do not have permission to restore snapshots.', 'nobash-files' ), '', array( 'response' => 403 ) ); }
        $id = isset( $_POST['version_id'] ) ? absint( $_POST['version_id'] ) : 0;
        check_admin_referer( 'nobash_files_history_restore_' . $id );
        try {
            $row = Nobash_Files_DB::get_version( $id );
            if ( ! $row ) { throw new RuntimeException( 'Snapshot not found.' ); }
            $fs = new Nobash_Files_Filesystem();
            Nobash_Files_Access::assert_paths( 'restore', array( (string) $row['relative_path'] ), $fs );
            $fs->restore_version( $id );
            wp_safe_redirect( add_query_arg( array( 'page'=>'nobash-files-versions','nobash_files_history_notice'=>'restored','nobash_files_version'=>$id ), admin_url( 'admin.php' ) ) );
            exit;
        } catch ( Throwable $e ) { wp_die( esc_html( $e->getMessage() ), '', array( 'response' => 400 ) ); }
    }

    public static function history_download(): void {
        if ( ! Nobash_Files_Access::can_use() ) { wp_die( esc_html__( 'You do not have permission to download snapshots.', 'nobash-files' ), '', array( 'response' => 403 ) ); }
        $id = isset( $_GET['version_id'] ) ? absint( $_GET['version_id'] ) : 0;
        check_admin_referer( 'nobash_files_history_download_' . $id );
        $row = Nobash_Files_DB::get_version( $id );
        if ( ! $row ) { wp_die( esc_html__( 'Snapshot not found.', 'nobash-files' ), '', array( 'response' => 404 ) ); }
        $fs = new Nobash_Files_Filesystem();
        try { Nobash_Files_Access::assert_paths( 'versions', array( (string) $row['relative_path'] ), $fs ); }
        catch ( Throwable $e ) { wp_die( esc_html( $e->getMessage() ), '', array( 'response' => 403 ) ); }
        nocache_headers();
        $name = sanitize_file_name( basename( (string) $row['relative_path'] ) );
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $name ) . '.snapshot-' . $id . '"' );
        header( 'Content-Length: ' . strlen( (string) $row['content'] ) );
        echo (string) $row['content']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw authorized snapshot download.
        exit;
    }

    public static function history_undo(): void {
        if ( ! Nobash_Files_Access::can_use() ) { wp_die( esc_html__( 'You do not have permission to undo file operations.', 'nobash-files' ), '', array( 'response' => 403 ) ); }
        check_admin_referer( 'nobash_files_history_undo' );
        try {
            Nobash_Files_Access::assert_permission( 'undo' );
            $fs = new Nobash_Files_Filesystem();
            $operation = Nobash_Files_DB::last_undoable();
            if ( $operation ) {
                Nobash_Files_Access::assert_operation_paths( 'undo', $operation, $fs );
            }
            $fs->undo_last_operation();
            wp_safe_redirect( add_query_arg( array( 'page'=>'nobash-files-versions','nobash_files_history_notice'=>'undone' ), admin_url( 'admin.php' ) ) );
            exit;
        } catch ( Throwable $e ) { wp_die( esc_html( $e->getMessage() ), '', array( 'response' => 400 ) ); }
    }
    public static function integrity_scan(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to run integrity scans.', 'nobash-files' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'nobash_files_integrity_scan' );
        $notice = 'scan-complete';
        try {
            $scanner = new Nobash_Files_Integrity();
            $result  = $scanner->scan();
            if ( empty( $result['complete'] ) ) {
                $notice = 'scan-incomplete';
            } elseif ( empty( $result['core']['available'] ) ) {
                $notice = 'checksums-unavailable';
            }
        } catch ( Throwable $e ) {
            $notice = 'scan-failed';
            set_transient( 'nobash_files_integrity_error_' . get_current_user_id(), wp_strip_all_tags( $e->getMessage() ), MINUTE_IN_SECONDS );
        }
        wp_safe_redirect( add_query_arg( array( 'page' => 'nobash-files-integrity', 'nobash_files_integrity_notice' => $notice ), admin_url( 'admin.php' ) ) );
        exit;
    }

    private static function integrity_issue_rows( array $scan ): array {
        $rows = array();
        $core = isset( $scan['core'] ) && is_array( $scan['core'] ) ? $scan['core'] : array();
        foreach ( array( 'modified' => 'Modified', 'missing' => 'Missing', 'unexpected' => 'Unexpected' ) as $key => $label ) {
            foreach ( (array) ( $core[ $key ] ?? array() ) as $item ) {
                $path = is_array( $item ) ? (string) ( $item['path'] ?? '' ) : (string) $item;
                if ( '' === $path ) { continue; }
                $rows[] = array( 'path' => $path, 'status' => $key, 'label' => $label );
            }
        }
        return $rows;
    }

    private static function integrity_status_label( string $status ): string {
        return array( 'modified' => 'Modified', 'missing' => 'Missing', 'unexpected' => 'Unexpected', 'verified' => 'Verified' )[ $status ] ?? ucfirst( $status );
    }

    private static function integrity_file_meta( string $path, string $status ): array {
        $clean = Nobash_Files_Explorer::clean_path( $path );
        $root  = rtrim( wp_normalize_path( ABSPATH ), '/' );
        $abs   = '' === $clean ? $root : $root . '/' . $clean;
        $exists = is_file( $abs ) && ! is_link( $abs );
        $size = $exists ? @filesize( $abs ) : false;
        $mtime = $exists ? @filemtime( $abs ) : false;
        $actual = $exists ? @md5_file( $abs ) : false;
        $expected = '';
        global $wp_version;
        if ( 'unexpected' !== $status ) {
            if ( ! function_exists( 'get_core_checksums' ) ) {
                $update_file = ABSPATH . 'wp-admin/includes/update.php';
                if ( is_readable( $update_file ) ) { require_once $update_file; }
            }
            if ( function_exists( 'get_core_checksums' ) ) {
                $locale = function_exists( 'get_locale' ) ? get_locale() : 'en_US';
                $checksums = get_core_checksums( (string) $wp_version, $locale );
                if ( ! is_array( $checksums ) && 'en_US' !== $locale ) {
                    $checksums = get_core_checksums( (string) $wp_version, 'en_US' );
                }
                if ( is_array( $checksums ) && isset( $checksums[ $clean ] ) ) {
                    $expected = strtolower( (string) $checksums[ $clean ] );
                }
            }
        }
        return array(
            'path' => $clean,
            'name' => basename( $clean ),
            'status' => $status,
            'exists' => $exists,
            'size' => false === $size ? 0 : (int) $size,
            'mtime' => false === $mtime ? 0 : (int) $mtime,
            'actual' => false === $actual ? '' : strtolower( (string) $actual ),
            'expected' => $expected,
        );
    }

    private static function integrity_format_duration( int $milliseconds ): string {
        $seconds = max( 0, (int) round( $milliseconds / 1000 ) );
        return sprintf( '%02d:%02d', intdiv( $seconds, 60 ), $seconds % 60 );
    }

    public static function render_integrity(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to view Core Integrity.', 'nobash-files' ), '', array( 'response' => 403 ) );
        }
        self::shell( 'integrity' );
        $scan = Nobash_Files_Integrity::last_scan();
        $core = isset( $scan['core'] ) && is_array( $scan['core'] ) ? $scan['core'] : array();
        $rows = self::integrity_issue_rows( $scan );
        $counts = array( 'modified' => 0, 'missing' => 0, 'unexpected' => 0 );
        foreach ( $rows as $row ) { if ( isset( $counts[ $row['status'] ] ) ) { $counts[ $row['status'] ]++; } }
        $issues = count( $rows );
        $verified = (int) ( $core['verified'] ?? 0 );
        $total_expected = $verified + $counts['modified'] + $counts['missing'];
        $scanned_at = (string) ( $scan['scanned_at'] ?? '' );
        $duration = self::integrity_format_duration( (int) ( $scan['duration_ms'] ?? 0 ) );
        $complete = ! empty( $scan ) && ! empty( $scan['complete'] );
        $available = ! empty( $core['available'] );
        $q = isset( $_GET['nobash_files_integrity_q'] ) ? sanitize_text_field( wp_unslash( $_GET['nobash_files_integrity_q'] ) ) : '';
        $status_filter = isset( $_GET['nobash_files_integrity_status'] ) ? sanitize_key( wp_unslash( $_GET['nobash_files_integrity_status'] ) ) : 'all';
        if ( ! in_array( $status_filter, array( 'all', 'modified', 'missing', 'unexpected' ), true ) ) { $status_filter = 'all'; }
        $filtered = array_values( array_filter( $rows, static function ( array $row ) use ( $q, $status_filter ): bool {
            if ( 'all' !== $status_filter && $row['status'] !== $status_filter ) { return false; }
            if ( '' !== $q && false === stripos( $row['path'], $q ) ) { return false; }
            return true;
        } ) );
        $selected_path = isset( $_GET['nobash_files_integrity_file'] ) ? Nobash_Files_Explorer::clean_path( (string) wp_unslash( $_GET['nobash_files_integrity_file'] ) ) : '';
        $selected_status = '';
        if ( '' !== $selected_path ) {
            foreach ( $rows as $row ) { if ( $row['path'] === $selected_path ) { $selected_status = $row['status']; break; } }
        }
        if ( '' === $selected_path && ! empty( $filtered ) ) { $selected_path = $filtered[0]['path']; $selected_status = $filtered[0]['status']; }
        $selected = '' !== $selected_path ? self::integrity_file_meta( $selected_path, $selected_status ?: 'modified' ) : array();
        $notice = isset( $_GET['nobash_files_integrity_notice'] ) ? sanitize_key( wp_unslash( $_GET['nobash_files_integrity_notice'] ) ) : '';
        $base_url = admin_url( 'admin.php?page=nobash-files-integrity' );
        ?>
        <main class="nobash-files-integrity-page-v2">
            <?php if ( '' !== $notice ) : ?>
                <?php
                $notice_class = 'success';
                $notice_text = 'Core integrity scan completed.';
                if ( 'scan-incomplete' === $notice ) { $notice_class = 'warning'; $notice_text = 'The scan reached its safety budget. Results are incomplete.'; }
                elseif ( 'checksums-unavailable' === $notice ) { $notice_class = 'warning'; $notice_text = 'The scan ran, but official WordPress checksums could not be retrieved.'; }
                elseif ( 'scan-failed' === $notice ) { $notice_class = 'error'; $notice_text = (string) get_transient( 'nobash_files_integrity_error_' . get_current_user_id() ); delete_transient( 'nobash_files_integrity_error_' . get_current_user_id() ); if ( '' === $notice_text ) { $notice_text = 'Core integrity scan failed.'; } }
                ?>
                <div class="nobash-files-integrity-page-notice is-<?php echo esc_attr( $notice_class ); ?>"><span class="dashicons <?php echo 'success' === $notice_class ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span><?php echo esc_html( $notice_text ); ?></div>
            <?php endif; ?>
            <header class="nobash-files-integrity-page-title">
                <div><h2>Check site files</h2><p>Check WordPress files for changes. Scanning does not change your files.</p></div>
            </header>

            <section class="nobash-files-integrity-summary-v2">
                <article><span class="nobash-files-integrity-summary-icon is-green"><span class="dashicons dashicons-shield-alt"></span></span><div><small>Core Files Verified</small><strong><?php echo esc_html( number_format_i18n( $verified ) ); ?><?php echo $total_expected ? ' / ' . esc_html( number_format_i18n( $total_expected ) ) : ''; ?></strong><p><?php echo $available && $total_expected ? esc_html( number_format_i18n( ( $verified / max( 1, $total_expected ) ) * 100, 1 ) ) . '% of expected core files match' : 'Run a scan to verify core files'; ?></p></div></article>
                <article><span class="nobash-files-integrity-summary-icon is-amber"><span class="dashicons dashicons-warning"></span></span><div><small>Issues Found</small><strong><?php echo esc_html( (string) $issues ); ?></strong><p><?php echo esc_html( $counts['modified'] . ' modified · ' . $counts['missing'] . ' missing · ' . $counts['unexpected'] . ' unexpected' ); ?></p></div></article>
                <article><span class="nobash-files-integrity-summary-icon is-blue"><span class="dashicons dashicons-clock"></span></span><div><small>Last Scan</small><strong><?php echo $scanned_at ? esc_html( mysql2date( 'M j, Y g:i A', $scanned_at ) ) : 'Not run yet'; ?></strong><p><?php echo $scanned_at ? 'Duration: ' . esc_html( $duration ) : 'No previous core scan'; ?></p></div></article>
            </section>

            <div class="nobash-files-integrity-content-v2">
                <section class="nobash-files-integrity-main-v2">
                    <article class="nobash-files-integrity-scan-card-v2">
                        <div class="nobash-files-integrity-scan-copy"><span class="nobash-files-integrity-scan-icon"><span class="dashicons dashicons-screenoptions"></span></span><div><h3>Check WordPress files</h3><p>Compare WordPress core files against official checksums from WordPress.org.</p><div class="nobash-files-integrity-scan-features"><span><i class="dashicons dashicons-tag"></i>Official checksums</span><span><i class="dashicons dashicons-shield"></i>Read-only safe scan</span><span><i class="dashicons dashicons-randomize"></i>Detailed results</span></div></div></div>
                        <div class="nobash-files-integrity-scan-actions"><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="nobash_files_integrity_scan"><?php wp_nonce_field( 'nobash_files_integrity_scan' ); ?><button class="button button-primary nobash-files-integrity-run-button" type="submit"><span class="dashicons dashicons-update"></span>Check files</button></form><?php if ( $scanned_at ) : ?><a class="button nobash-files-integrity-report-button" href="#nobash-files-integrity-results"><span class="dashicons dashicons-media-document"></span>View last results</a><?php endif; ?></div>
                    </article>

                    <?php if ( ! $complete && $scanned_at ) : ?><div class="nobash-files-integrity-inline-alert"><span class="dashicons dashicons-warning"></span><div><strong>Scan coverage is incomplete</strong><p>The configured file/time safety budget was reached. Review these results as partial.</p></div></div><?php endif; ?>

                    <section class="nobash-files-integrity-results-v2" id="nobash-files-integrity-results">
                        <div class="nobash-files-integrity-results-head"><div><h3>Scan results</h3><p>Review files that differ from the official WordPress release.</p></div><span class="nobash-files-integrity-availability <?php echo $available ? ( $issues ? 'is-warning' : 'is-clean' ) : 'is-neutral'; ?>"><?php echo $available ? ( $issues ? 'Issues detected' : 'Checksums verified' ) : 'Checksums unavailable'; ?></span></div>
                        <div class="nobash-files-integrity-result-cards">
                            <article class="is-modified"><div><small>Modified Files</small><strong><?php echo esc_html( (string) $counts['modified'] ); ?></strong><p>Files changed</p></div><span class="dashicons dashicons-edit"></span></article>
                            <article class="is-missing"><div><small>Missing Files</small><strong><?php echo esc_html( (string) $counts['missing'] ); ?></strong><p>Files not found</p></div><span class="dashicons dashicons-marker"></span></article>
                            <article class="is-unexpected"><div><small>Unexpected Files</small><strong><?php echo esc_html( (string) $counts['unexpected'] ); ?></strong><p>Not part of core</p></div><span class="dashicons dashicons-archive"></span></article>
                            <article class="<?php echo $available && 0 === $issues ? 'is-clean' : 'is-status'; ?>"><div><small>Checksum Status</small><strong><?php echo $available ? ( $issues ? 'Issues Detected' : 'Verified' ) : 'Unavailable'; ?></strong><p><?php echo $scanned_at ? 'Scan completed' : 'Waiting for first scan'; ?></p></div><span class="dashicons dashicons-shield-alt"></span></article>
                        </div>

                        <form class="nobash-files-integrity-filters-v2" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
                            <input type="hidden" name="page" value="nobash-files-integrity">
                            <nav class="nobash-files-integrity-tabs" aria-label="Integrity result filters">
                                <?php foreach ( array( 'all' => count( $rows ), 'modified' => $counts['modified'], 'missing' => $counts['missing'], 'unexpected' => $counts['unexpected'] ) as $filter => $count_value ) : ?>
                                    <a class="<?php echo $status_filter === $filter ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'nobash_files_integrity_status' => $filter, 'nobash_files_integrity_q' => $q ), $base_url ) ); ?>"><?php echo esc_html( 'all' === $filter ? 'All' : ucfirst( $filter ) ); ?> <span>(<?php echo esc_html( (string) $count_value ); ?>)</span></a>
                                <?php endforeach; ?>
                            </nav>
                            <div class="nobash-files-integrity-search"><span class="dashicons dashicons-search"></span><input type="search" name="nobash_files_integrity_q" value="<?php echo esc_attr( $q ); ?>" placeholder="Search file path…"></div>
                            <select name="nobash_files_integrity_status" aria-label="Status"><option value="all" <?php selected( $status_filter, 'all' ); ?>>All Status</option><option value="modified" <?php selected( $status_filter, 'modified' ); ?>>Modified</option><option value="missing" <?php selected( $status_filter, 'missing' ); ?>>Missing</option><option value="unexpected" <?php selected( $status_filter, 'unexpected' ); ?>>Unexpected</option></select>
                            <button class="button" type="submit">Apply</button>
                        </form>

                        <div class="nobash-files-integrity-table-wrap">
                            <table class="nobash-files-integrity-table-v2">
                                <thead><tr><th class="nobash-files-integrity-check"><input type="checkbox" disabled aria-label="Select all"></th><th>File</th><th>Type</th><th>Status</th><th>Detected</th><th>Actions</th></tr></thead>
                                <tbody>
                                <?php if ( empty( $filtered ) ) : ?><tr><td colspan="6"><div class="nobash-files-integrity-no-results"><span class="dashicons <?php echo $scanned_at ? 'dashicons-yes-alt' : 'dashicons-search'; ?>"></span><strong><?php echo $scanned_at ? ( $issues ? 'No results match these filters' : 'No core integrity issues found' ) : 'Run a core scan to begin'; ?></strong><p><?php echo $scanned_at ? ( $issues ? 'Change the search or status filter to see other findings.' : 'The scanned WordPress core files match the official checksum manifest.' ) : 'The scan is read-only and does not change any files.'; ?></p></div></td></tr><?php else : ?>
                                <?php foreach ( $filtered as $row ) :
                                    $path = $row['path']; $status = $row['status'];
                                    $selected_row = $selected_path === $path;
                                    $review_url = add_query_arg( array( 'page' => 'nobash-files-integrity', 'nobash_files_integrity_status' => $status_filter, 'nobash_files_integrity_q' => $q, 'nobash_files_integrity_file' => $path ), admin_url( 'admin.php' ) );
                                    $preview_url = wp_nonce_url( add_query_arg( array( 'action' => 'nobash_files_preview', 'path' => $path ), admin_url( 'admin-post.php' ) ), 'nobash_files_preview', 'nobash_files_preview_nonce' );
                                    $parent = dirname( $path ); if ( '.' === $parent ) { $parent = ''; }
                                    $manager_url = add_query_arg( array( 'page' => 'nobash-files', 'nobash_files_path' => $parent ), admin_url( 'admin.php' ) );
                                ?>
                                    <tr class="<?php echo $selected_row ? 'is-selected' : ''; ?>"><td class="nobash-files-integrity-check"><input type="checkbox" disabled></td><td><a class="nobash-files-integrity-file-link" href="<?php echo esc_url( $review_url ); ?>"><span class="dashicons dashicons-media-code"></span><strong><?php echo esc_html( $path ); ?></strong></a></td><td><span class="nobash-files-integrity-type-dot is-<?php echo esc_attr( $status ); ?>"></span><?php echo esc_html( self::integrity_status_label( $status ) ); ?></td><td><span class="nobash-files-integrity-status-pill is-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( self::integrity_status_label( $status ) ); ?></span></td><td><?php echo $scanned_at ? esc_html( mysql2date( 'M j, Y g:i A', $scanned_at ) ) : '—'; ?></td><td><div class="nobash-files-integrity-row-actions"><?php if ( 'missing' !== $status ) : ?><a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( $preview_url ); ?>"><span class="dashicons dashicons-visibility"></span>Preview</a><?php endif; ?><a class="button" href="<?php echo esc_url( $review_url ); ?>"><span class="dashicons dashicons-editor-code"></span>Compare</a><a class="button" href="<?php echo esc_url( $manager_url ); ?>"><span class="dashicons dashicons-search"></span>Review</a></div></td></tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <footer class="nobash-files-integrity-table-footer">Showing <?php echo esc_html( (string) count( $filtered ) ); ?> of <?php echo esc_html( (string) count( $rows ) ); ?> issue results</footer>
                    </section>
                </section>

                <aside class="nobash-files-integrity-side-v2">
                    <section class="nobash-files-integrity-detail-card">
                        <div class="nobash-files-integrity-detail-head"><strong>Selected File Details</strong><?php if ( ! empty( $selected ) ) : ?><span class="nobash-files-integrity-status-pill is-<?php echo esc_attr( $selected['status'] ); ?>"><?php echo esc_html( self::integrity_status_label( $selected['status'] ) ); ?></span><?php endif; ?></div>
                        <?php if ( empty( $selected ) ) : ?><div class="nobash-files-integrity-side-empty"><span class="dashicons dashicons-media-code"></span><strong>Select a finding</strong><p>File details and checksum comparison will appear here.</p></div><?php else : ?>
                            <div class="nobash-files-integrity-selected-file"><span class="dashicons dashicons-wordpress"></span><div><strong><?php echo esc_html( $selected['name'] ); ?></strong><small><?php echo esc_html( $selected['path'] ); ?></small></div></div>
                            <dl class="nobash-files-integrity-detail-list"><dt>Status</dt><dd><span class="nobash-files-integrity-type-dot is-<?php echo esc_attr( $selected['status'] ); ?>"></span><?php echo esc_html( self::integrity_status_label( $selected['status'] ) ); ?></dd><dt>File Type</dt><dd>Core File</dd><dt>File Size</dt><dd><?php echo $selected['exists'] ? esc_html( size_format( $selected['size'], 1 ) ) : 'Missing'; ?></dd><dt>Last Modified</dt><dd><?php echo $selected['mtime'] ? esc_html( wp_date( 'M j, Y g:i A', $selected['mtime'] ) ) : '—'; ?></dd></dl>
                            <div class="nobash-files-integrity-checksums"><strong>Checksums</strong><label><span>Expected (WordPress.org)</span><code><?php echo $selected['expected'] ? esc_html( $selected['expected'] ) : 'Not available'; ?></code></label><label><span>Actual (Your Site)</span><code><?php echo $selected['actual'] ? esc_html( $selected['actual'] ) : ( $selected['exists'] ? 'Unreadable' : 'File missing' ); ?></code></label></div>
                            <div class="nobash-files-integrity-recommendation"><strong>Recommended Next Step</strong><div><span class="dashicons dashicons-shield-alt"></span><p><?php echo 'modified' === $selected['status'] ? 'This core file differs from the official checksum. Review the change before deciding whether to restore the original.' : ( 'missing' === $selected['status'] ? 'This official core file is missing. Confirm it was not intentionally removed before repairing WordPress core.' : 'This file exists inside a core directory but is not part of the official WordPress manifest. Review why it is present.' ); ?></p></div><a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'nobash-files', 'nobash_files_path' => ( '.' === dirname( $selected['path'] ) ? '' : dirname( $selected['path'] ) ) ), admin_url( 'admin.php' ) ) ); ?>"><span class="dashicons dashicons-editor-code"></span>Open in File Manager</a></div>
                        <?php endif; ?>
                    </section>
                    <section class="nobash-files-integrity-tools-card-v2"><div class="nobash-files-integrity-tools-title"><strong>Advanced Integrity</strong></div><p>Trusted baselines, monitoring, quarantine and verified repair are included.</p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=nobash-files-security' ) ); ?>">Open Security &amp; Repair</a></section>
                </aside>
            </div>
        </main>
        <?php
        self::shell_end();
    }
    public static function render_security(): void { self::simple_page( 'security', 'Security & repair', 'Review flagged files and choose a safe next step.' ); }
    public static function render_monitoring(): void { self::simple_page( 'monitoring', 'Automatic checks', 'Choose when to check your site and where to send alerts.' ); }
    public static function render_activity(): void {
        if ( ! Nobash_Files_Access::can_use() ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'nobash-files' ) );
        }
        try {
            Nobash_Files_Access::assert_permission( 'activity' );
        } catch ( Throwable $e ) {
            wp_die( esc_html__( 'You do not have permission to view activity.', 'nobash-files' ), '', array( 'response' => 403 ) );
        }

        $rows = Nobash_Files_DB::activity( 400 );
        if ( ! current_user_can( 'manage_options' ) ) {
            $user_id = get_current_user_id();
            $rows = array_values( array_filter( $rows, static function( $row ) use ( $user_id ) {
                $path = (string) ( $row['relative_path'] ?? '' );
                return (int) ( $row['user_id'] ?? 0 ) === $user_id && Nobash_Files_Access::is_path_allowed( $path );
            } ) );
        }

        $search = isset( $_GET['nobash_files_activity_search'] ) ? sanitize_text_field( wp_unslash( $_GET['nobash_files_activity_search'] ) ) : '';
        $scope  = isset( $_GET['nobash_files_activity_scope'] ) ? sanitize_key( wp_unslash( $_GET['nobash_files_activity_scope'] ) ) : 'all';
        $page   = isset( $_GET['nobash_files_activity_page'] ) ? max( 1, absint( $_GET['nobash_files_activity_page'] ) ) : 1;
        $per    = 20;

        $users = array();
        foreach ( array_values( array_unique( array_filter( array_map( static fn( $row ) => (int) ( $row['user_id'] ?? 0 ), $rows ) ) ) ) as $user_id ) {
            $user = get_user_by( 'id', $user_id );
            if ( $user ) {
                $users[ $user_id ] = $user->display_name ?: $user->user_login;
            }
        }

        $prepared = array();
        $stats = array(
            'total' => 0,
            'edits' => 0,
            'uploads' => 0,
            'trash' => 0,
            'security' => 0,
            'system' => 0,
        );
        foreach ( $rows as $row ) {
            $action = sanitize_key( (string) ( $row['action'] ?? '' ) );
            $meta = self::activity_action_meta( $action );
            $details = json_decode( (string) ( $row['details'] ?? '' ), true );
            if ( ! is_array( $details ) ) {
                $details = array();
            }
            $path = (string) ( $row['relative_path'] ?? '' );
            $prepared[] = array(
                'id' => (int) ( $row['id'] ?? 0 ),
                'user_id' => (int) ( $row['user_id'] ?? 0 ),
                'user_label' => $users[ (int) ( $row['user_id'] ?? 0 ) ] ?? 'System',
                'action' => $action,
                'meta' => $meta,
                'path' => $path,
                'path_label' => '' !== $path ? $path : 'Site-wide event',
                'details' => $details,
                'details_text' => self::activity_details_text( $action, $details ),
                'created_at' => (string) ( $row['created_at'] ?? '' ),
                'created_label' => ! empty( $row['created_at'] ) ? mysql2date( 'M j, Y g:i A', (string) $row['created_at'] ) : '',
            );
            $stats['total']++;
            if ( isset( $stats[ $meta['bucket'] ] ) ) {
                $stats[ $meta['bucket'] ]++;
            }
        }

        $filtered = array_values( array_filter( $prepared, static function( $row ) use ( $search, $scope ) {
            if ( 'all' !== $scope && $row['meta']['category'] !== $scope ) {
                return false;
            }
            if ( '' === $search ) {
                return true;
            }
            $haystacks = array(
                (string) $row['meta']['label'],
                (string) $row['path_label'],
                (string) $row['details_text'],
                (string) $row['user_label'],
            );
            foreach ( $haystacks as $haystack ) {
                if ( false !== stripos( $haystack, $search ) ) {
                    return true;
                }
            }
            return false;
        } ) );

        $total = count( $filtered );
        $pages = max( 1, (int) ceil( $total / $per ) );
        $page  = min( $page, $pages );
        $visible = array_slice( $filtered, ( $page - 1 ) * $per, $per );
        $latest = $prepared[0] ?? null;
        $base_url = admin_url( 'admin.php?page=nobash-files-activity' );

        self::shell( 'activity' );
        ?>
        <main class="nobash-files-activity-page-v2">
            <header class="nobash-files-activity-page-title">
                <div>
                    <h2>Recent activity</h2>
                    <p>Review recent file operations, uploads, Trash actions, edits, integrity events and undo activity.</p>
                </div>
                <div class="nobash-files-activity-title-actions">
                    <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=nobash-files' ) ); ?>"><span class="dashicons dashicons-portfolio"></span>Open File Manager</a>
                    <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=nobash-files-versions' ) ); ?>"><span class="dashicons dashicons-backup"></span>Open Version History</a>
                </div>
            </header>

            <section class="nobash-files-activity-hero-v2 <?php echo $latest ? 'has-latest' : 'is-empty'; ?>">
                <div class="nobash-files-activity-hero-icon"><span class="dashicons <?php echo esc_attr( $latest ? $latest['meta']['icon'] : 'dashicons-clock' ); ?>"></span></div>
                <div class="nobash-files-activity-hero-copy">
                    <span class="nobash-files-eyebrow">Recent activity feed</span>
                    <h3><?php echo $latest ? esc_html( $latest['meta']['label'] ) : 'No activity yet'; ?></h3>
                    <p><?php echo $latest ? esc_html( $latest['path_label'] ) : 'Recent file operations, validation events, uploads and recovery actions will appear here.'; ?></p>
                    <small><?php echo $latest ? esc_html( $latest['details_text'] ) . ' · ' . esc_html( $latest['created_label'] ) : 'Use Explorer, the Code Editor or Core Integrity to begin building an activity timeline.'; ?></small>
                </div>
                <div class="nobash-files-activity-hero-actions">
                    <span class="nobash-files-activity-status-pill is-<?php echo esc_attr( $latest['meta']['tone'] ?? 'neutral' ); ?>"><?php echo esc_html( $latest['meta']['status'] ?? 'Ready' ); ?></span>
                </div>
            </section>

            <section class="nobash-files-activity-metrics-v2">
                <article><span class="nobash-files-activity-metric-icon is-blue"><span class="dashicons dashicons-clock"></span></span><div><small>Total Events</small><strong><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></strong><p>Captured in the activity log</p></div></article>
                <article><span class="nobash-files-activity-metric-icon is-indigo"><span class="dashicons dashicons-edit"></span></span><div><small>Edits & Versions</small><strong><?php echo esc_html( number_format_i18n( $stats['edits'] ) ); ?></strong><p>Edits, manual versions and restores</p></div></article>
                <article><span class="nobash-files-activity-metric-icon is-green"><span class="dashicons dashicons-upload"></span></span><div><small>Uploads & Moves</small><strong><?php echo esc_html( number_format_i18n( $stats['uploads'] ) ); ?></strong><p>Uploads, folders and file movement</p></div></article>
                <article><span class="nobash-files-activity-metric-icon is-amber"><span class="dashicons dashicons-trash"></span></span><div><small>Trash & Cleanup</small><strong><?php echo esc_html( number_format_i18n( $stats['trash'] ) ); ?></strong><p>Trash, restore and permanent delete activity</p></div></article>
            </section>

            <div class="nobash-files-activity-layout-v2">
                <section class="nobash-files-activity-main-v2">
                    <form class="nobash-files-activity-filters-v2" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
                        <input type="hidden" name="page" value="nobash-files-activity">
                        <nav class="nobash-files-activity-tabs" aria-label="Activity filters">
                            <?php foreach ( array( 'all' => 'All', 'edits' => 'Edits', 'uploads' => 'Uploads', 'trash' => 'Trash', 'security' => 'Security', 'system' => 'System' ) as $filter_key => $label ) : ?>
                                <a class="<?php echo $scope === $filter_key ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'nobash_files_activity_scope' => $filter_key, 'nobash_files_activity_search' => $search ), $base_url ) ); ?>"><?php echo esc_html( $label ); ?></a>
                            <?php endforeach; ?>
                        </nav>
                        <div class="nobash-files-activity-search"><span class="dashicons dashicons-search"></span><input type="search" name="nobash_files_activity_search" value="<?php echo esc_attr( $search ); ?>" placeholder="Search paths, actions or users…"></div>
                        <input type="hidden" name="nobash_files_activity_scope" value="<?php echo esc_attr( $scope ); ?>">
                        <button class="button" type="submit">Apply</button>
                    </form>

                    <section class="nobash-files-activity-feed-v2">
                        <div class="nobash-files-activity-feed-head"><div><h3>Recent events</h3><p><?php echo esc_html( number_format_i18n( $total ) ); ?> matching event<?php echo 1 === $total ? '' : 's'; ?></p></div><span class="nobash-files-activity-feed-badge"><?php echo esc_html( ucfirst( 'all' === $scope ? 'all activity' : $scope ) ); ?></span></div>
                        <div class="nobash-files-activity-list-v2">
                            <?php if ( empty( $visible ) ) : ?>
                                <div class="nobash-files-activity-empty-v2"><span class="dashicons dashicons-search"></span><strong>No matching events</strong><p>Try a different filter or search term.</p></div>
                            <?php else : foreach ( $visible as $row ) : ?>
                                <article class="nobash-files-activity-row-v2 tone-<?php echo esc_attr( $row['meta']['tone'] ); ?>">
                                    <div class="nobash-files-activity-row-icon"><span class="dashicons <?php echo esc_attr( $row['meta']['icon'] ); ?>"></span></div>
                                    <div class="nobash-files-activity-row-copy">
                                        <div class="nobash-files-activity-row-head"><strong><?php echo esc_html( $row['meta']['label'] ); ?></strong><span class="nobash-files-activity-status-pill is-<?php echo esc_attr( $row['meta']['tone'] ); ?>"><?php echo esc_html( $row['meta']['status'] ); ?></span></div>
                                        <div class="nobash-files-activity-row-path"><?php echo esc_html( $row['path_label'] ); ?></div>
                                        <p><?php echo esc_html( $row['details_text'] ); ?></p>
                                        <small>By <?php echo esc_html( $row['user_label'] ); ?></small>
                                    </div>
                                    <time datetime="<?php echo esc_attr( $row['created_at'] ); ?>"><?php echo esc_html( $row['created_label'] ); ?></time>
                                </article>
                            <?php endforeach; endif; ?>
                        </div>
                        <?php if ( $pages > 1 ) : ?>
                            <div class="nobash-files-activity-pagination-v2">
                                <?php if ( $page > 1 ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'nobash-files-activity', 'nobash_files_activity_scope' => $scope, 'nobash_files_activity_search' => $search, 'nobash_files_activity_page' => $page - 1 ), admin_url( 'admin.php' ) ) ); ?>">Previous</a><?php endif; ?>
                                <span>Page <?php echo esc_html( (string) $page ); ?> of <?php echo esc_html( (string) $pages ); ?></span>
                                <?php if ( $page < $pages ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'nobash-files-activity', 'nobash_files_activity_scope' => $scope, 'nobash_files_activity_search' => $search, 'nobash_files_activity_page' => $page + 1 ), admin_url( 'admin.php' ) ) ); ?>">Next</a><?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                </section>

                <aside class="nobash-files-activity-side-v2">
                    <section class="nobash-files-activity-side-card">
                        <div class="nobash-files-activity-side-head"><strong>Activity summary</strong><span>Live</span></div>
                        <dl class="nobash-files-activity-side-list">
                            <dt>Latest event</dt><dd><?php echo $latest ? esc_html( $latest['created_label'] ) : '—'; ?></dd>
                            <dt>Security events</dt><dd><?php echo esc_html( number_format_i18n( $stats['security'] ) ); ?></dd>
                            <dt>System events</dt><dd><?php echo esc_html( number_format_i18n( $stats['system'] ) ); ?></dd>
                            <dt>Undo ready</dt><dd><?php echo esc_html( number_format_i18n( Nobash_Files_DB::undoable_count() ) ); ?></dd>
                        </dl>
                    </section>
                    <section class="nobash-files-activity-side-card">
                        <div class="nobash-files-activity-side-head"><strong>What is recorded</strong></div>
                        <ul class="nobash-files-activity-side-bullets">
                            <li><span class="dashicons dashicons-yes-alt"></span>File edits, uploads and folder changes</li>
                            <li><span class="dashicons dashicons-yes-alt"></span>Trash, restore and permanent delete actions</li>
                            <li><span class="dashicons dashicons-yes-alt"></span>Integrity scans, baseline changes and access events</li>
                            <li><span class="dashicons dashicons-yes-alt"></span>Manual safety versions and undo activity</li>
                        </ul>
                    </section>
                    <section class="nobash-files-activity-side-card nobash-files-activity-side-note">
                        <div class="nobash-files-activity-side-head"><strong>Privacy & retention</strong></div>
                        <p>Activity keeps the acting user, path and safe operation details so you can review changes later. Use WordPress Privacy tools when you need to export or anonymize stored metadata.</p>
                        <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=nobash-files-settings' ) ); ?>">Open Settings</a>
                    </section>
                </aside>
            </div>
        </main>
        <?php
        self::shell_end();
    }
    public static function render_trash(): void {
        if ( ! Nobash_Files_Access::can_use() ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'nobash-files' ) );
        }
        try {
            Nobash_Files_Access::assert_permission( 'trash' );
        } catch ( Throwable $e ) {
            wp_die( esc_html__( 'You do not have permission to view Trash.', 'nobash-files' ), '', array( 'response' => 403 ) );
        }

        $fs = new Nobash_Files_Filesystem();
        $all_entries = self::trash_entries_dataset( $fs );
        $view = isset( $_GET['nobash_files_trash_view'] ) ? sanitize_key( wp_unslash( $_GET['nobash_files_trash_view'] ) ) : 'all';
        if ( ! in_array( $view, array( 'all', 'file', 'folder', 'batch' ), true ) ) {
            $view = 'all';
        }
        $q = isset( $_GET['nobash_files_trash_q'] ) ? sanitize_text_field( wp_unslash( $_GET['nobash_files_trash_q'] ) ) : '';
        $filtered = array_values( array_filter( $all_entries, static function ( array $entry ) use ( $view, $q ): bool {
            if ( 'all' !== $view && $entry['type_slug'] !== $view ) {
                return false;
            }
            if ( '' !== $q ) {
                $haystack = strtolower( implode( ' ', array( $entry['search_terms'] ?? array() ) ) );
                if ( false === strpos( $haystack, strtolower( $q ) ) ) {
                    return false;
                }
            }
            return true;
        } ) );

        $per_page = 8;
        $page_num = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $total_filtered = count( $filtered );
        $total_pages = max( 1, (int) ceil( $total_filtered / $per_page ) );
        $page_num = min( $page_num, $total_pages );
        $offset = ( $page_num - 1 ) * $per_page;
        $visible_rows = array_slice( $filtered, $offset, $per_page );

        $stats = array(
            'entries' => count( $all_entries ),
            'bytes'   => 0,
            'latest'  => '',
            'cleanup' => false,
        );
        foreach ( $all_entries as $entry ) {
            $stats['bytes'] += (int) $entry['bytes'];
            if ( '' === $stats['latest'] || strtotime( $entry['created_at'] ) > strtotime( $stats['latest'] ) ) {
                $stats['latest'] = $entry['created_at'];
            }
        }
        $notice = isset( $_GET['nobash_files_trash_notice'] ) ? sanitize_key( wp_unslash( $_GET['nobash_files_trash_notice'] ) ) : '';
        $notice_data = self::trash_notice_data( $notice );
        $counts_by_type = array( 'all' => count( $all_entries ), 'file' => 0, 'folder' => 0, 'batch' => 0 );
        foreach ( $all_entries as $entry ) {
            if ( isset( $counts_by_type[ $entry['type_slug'] ] ) ) {
                $counts_by_type[ $entry['type_slug'] ]++;
            }
        }
        $base_url = admin_url( 'admin.php?page=nobash-files-trash' );

        self::shell( 'trash' );
        ?>
        <main class="nobash-files-trash-page-v2">
            <?php if ( ! empty( $notice_data['text'] ) ) : ?>
                <div class="nobash-files-trash-notice is-<?php echo esc_attr( $notice_data['class'] ); ?>"><span class="dashicons <?php echo esc_attr( $notice_data['icon'] ); ?>"></span><?php echo esc_html( $notice_data['text'] ); ?></div>
            <?php endif; ?>

            <header class="nobash-files-trash-title-v2">
                <div>
                    <h2><span class="dashicons dashicons-trash"></span>Trash</h2>
                    <p>Restore removed files safely or permanently delete recovery snapshots you no longer need.</p>
                </div>
                <div class="nobash-files-trash-title-actions">
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-nobash-files-trash-empty-form>
                        <input type="hidden" name="action" value="nobash_files_trash_empty">
                        <?php wp_nonce_field( 'nobash_files_trash_empty' ); ?>
                        <button class="button nobash-files-trash-danger-button" type="submit" <?php disabled( empty( $all_entries ) ); ?>><span class="dashicons dashicons-trash"></span>Empty Trash</button>
                    </form>
                    <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=nobash-files-settings' ) ); ?>"><span class="dashicons dashicons-admin-generic"></span>Settings</a>
                </div>
            </header>

            <section class="nobash-files-trash-stats-v2">
                <article><span class="nobash-files-trash-stat-icon is-blue"><span class="dashicons dashicons-media-default"></span></span><div><small>Total recovery items</small><strong><?php echo esc_html( number_format_i18n( $stats['entries'] ) ); ?></strong><p>Recovery entries currently available</p></div></article>
                <article><span class="nobash-files-trash-stat-icon is-green"><span class="dashicons dashicons-database"></span></span><div><small>Recoverable size</small><strong><?php echo esc_html( size_format( max( 0, (int) $stats['bytes'] ), 1 ) ); ?></strong><p>Private Trash storage used by snapshots</p></div></article>
                <article><span class="nobash-files-trash-stat-icon is-purple"><span class="dashicons dashicons-clock"></span></span><div><small>Last deleted</small><strong><?php echo $stats['latest'] ? esc_html( mysql2date( 'j M Y', $stats['latest'] ) ) : 'Nothing yet'; ?></strong><p><?php echo $stats['latest'] ? esc_html( mysql2date( 'g:i A', $stats['latest'] ) ) : 'No recent Trash activity'; ?></p></div></article>
                <article><span class="nobash-files-trash-stat-icon is-amber"><span class="dashicons dashicons-update"></span></span><div><small>Cleanup mode</small><strong>Manual</strong><p>Nothing is removed automatically</p></div></article>
            </section>

            <div class="nobash-files-trash-layout-v2">
                <section class="nobash-files-trash-main-v2">
                    <form class="nobash-files-trash-filters-v2" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
                        <input type="hidden" name="page" value="nobash-files-trash">
                        <nav class="nobash-files-trash-tabs" aria-label="Trash filters">
                            <?php foreach ( array( 'all' => 'All', 'file' => 'Files', 'folder' => 'Folders', 'batch' => 'Batches' ) as $filter_key => $label ) : ?>
                                <a class="<?php echo $view === $filter_key ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'nobash-files-trash', 'nobash_files_trash_view' => $filter_key, 'nobash_files_trash_q' => $q ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $label ); ?> <span>(<?php echo esc_html( (string) $counts_by_type[ $filter_key ] ); ?>)</span></a>
                            <?php endforeach; ?>
                        </nav>
                        <div class="nobash-files-trash-search"><span class="dashicons dashicons-search"></span><input type="search" name="nobash_files_trash_q" value="<?php echo esc_attr( $q ); ?>" placeholder="Search trashed items, paths or names…"></div>
                        <button class="button" type="submit">Apply</button>
                    </form>

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nobash-files-trash-table-card" data-nobash-files-trash-table-form>
                        <input type="hidden" name="action" value="nobash_files_trash_bulk">
                        <input type="hidden" name="redirect_view" value="<?php echo esc_attr( $view ); ?>">
                        <input type="hidden" name="redirect_q" value="<?php echo esc_attr( $q ); ?>">
                        <input type="hidden" name="redirect_paged" value="<?php echo esc_attr( (string) $page_num ); ?>">
                        <?php wp_nonce_field( 'nobash_files_trash_bulk' ); ?>

                        <div class="nobash-files-trash-table-wrap-v2">
                            <table class="nobash-files-trash-table-v2">
                                <thead>
                                    <tr>
                                        <th class="check-column"><input type="checkbox" disabled aria-label="Select all"></th>
                                        <th>Item</th>
                                        <th>Original location</th>
                                        <th>Deleted</th>
                                        <th>Size</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if ( empty( $visible_rows ) ) : ?>
                                    <tr>
                                        <td colspan="7">
                                            <div class="nobash-files-trash-empty-v2">
                                                <span class="dashicons dashicons-trash"></span>
                                                <strong><?php echo empty( $all_entries ) ? 'Trash is empty' : 'No Trash items match these filters'; ?></strong>
                                                <p><?php echo empty( $all_entries ) ? 'Deleted items moved to safe private recovery will appear here.' : 'Try a different filter or search term.'; ?></p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else : ?>
                                    <?php foreach ( $visible_rows as $row ) : ?>
                                        <tr>
                                            <td class="check-column"><input type="checkbox" name="op_ids[]" value="<?php echo esc_attr( (string) $row['id'] ); ?>"></td>
                                            <td>
                                                <div class="nobash-files-trash-item-main">
                                                    <span class="nobash-files-trash-item-icon is-<?php echo esc_attr( $row['type_slug'] ); ?>"><span class="dashicons <?php echo esc_attr( $row['icon'] ); ?>"></span></span>
                                                    <div>
                                                        <strong><?php echo esc_html( $row['display_name'] ); ?></strong>
                                                        <small><?php echo esc_html( $row['type_label'] ); ?><?php echo $row['item_count'] > 1 ? ' · ' . esc_html( number_format_i18n( $row['item_count'] ) ) . ' items' : ''; ?></small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><code><?php echo esc_html( $row['location_label'] ); ?></code></td>
                                            <td><strong><?php echo esc_html( mysql2date( 'j M Y', $row['created_at'] ) ); ?></strong><small><?php echo esc_html( mysql2date( 'g:i A', $row['created_at'] ) ); ?></small></td>
                                            <td><?php echo esc_html( size_format( max( 0, (int) $row['bytes'] ), 1 ) ); ?></td>
                                            <td><span class="nobash-files-trash-status-pill"><span class="dashicons dashicons-lock"></span>Private</span></td>
                                            <td>
                                                <div class="nobash-files-trash-row-actions">
                                                    <button class="button button-primary button-small" type="submit" name="row_action" value="restore:<?php echo esc_attr( (string) $row['id'] ); ?>"><span class="dashicons dashicons-undo"></span>Restore</button>
                                                    <button class="button button-small nobash-files-trash-danger-button is-small" type="submit" name="row_action" data-nobash-files-trash-delete-row value="delete:<?php echo esc_attr( (string) $row['id'] ); ?>"><span class="dashicons dashicons-trash"></span>Delete</button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="nobash-files-trash-table-footer-v2">
                            <div class="nobash-files-trash-bulk-actions">
                                <select name="bulk_action">
                                    <option value="">Bulk actions</option>
                                    <option value="restore">Restore selected</option>
                                    <option value="delete">Delete permanently</option>
                                </select>
                                <button class="button" type="submit">Apply</button>
                            </div>
                            <div class="nobash-files-trash-pagination">
                                <span><?php echo esc_html( (string) ( 0 === $total_filtered ? 0 : $offset + 1 ) ); ?>–<?php echo esc_html( (string) min( $offset + count( $visible_rows ), $total_filtered ) ); ?> of <?php echo esc_html( (string) $total_filtered ); ?> items</span>
                                <?php if ( $total_pages > 1 ) : ?>
                                    <div class="nobash-files-trash-pagination-links">
                                        <?php $prev_page = max( 1, $page_num - 1 ); $next_page = min( $total_pages, $page_num + 1 ); ?>
                                        <a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => 'nobash-files-trash', 'nobash_files_trash_view' => $view, 'nobash_files_trash_q' => $q, 'paged' => $prev_page ), admin_url( 'admin.php' ) ) ); ?>" <?php echo 1 === $page_num ? 'aria-disabled="true"' : ''; ?>>‹</a>
                                        <?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
                                            <a class="button button-small <?php echo $i === $page_num ? 'is-current' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'nobash-files-trash', 'nobash_files_trash_view' => $view, 'nobash_files_trash_q' => $q, 'paged' => $i ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( (string) $i ); ?></a>
                                        <?php endfor; ?>
                                        <a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => 'nobash-files-trash', 'nobash_files_trash_view' => $view, 'nobash_files_trash_q' => $q, 'paged' => $next_page ), admin_url( 'admin.php' ) ) ); ?>" <?php echo $page_num === $total_pages ? 'aria-disabled="true"' : ''; ?>>›</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </section>

                <aside class="nobash-files-trash-side-v2">
                    <section class="nobash-files-trash-side-card">
                        <div class="nobash-files-trash-side-head"><span class="dashicons dashicons-info-outline"></span><strong>About Trash</strong></div>
                        <p>Trash stores private recovery snapshots, not publicly accessible files.</p>
                    </section>
                    <section class="nobash-files-trash-side-card">
                        <div class="nobash-files-trash-side-head"><span class="dashicons dashicons-shield-alt"></span><strong>Recovery rules</strong></div>
                        <ul>
                            <li><span class="dashicons dashicons-yes"></span>Files are moved to Trash before deletion</li>
                            <li><span class="dashicons dashicons-yes"></span>Original files are never overwritten</li>
                            <li><span class="dashicons dashicons-yes"></span>Snapshots remain private and secure</li>
                            <li><span class="dashicons dashicons-yes"></span>You can restore or delete anytime</li>
                        </ul>
                    </section>
                    <section class="nobash-files-trash-side-card">
                        <div class="nobash-files-trash-side-head"><span class="dashicons dashicons-backup"></span><strong>Retention</strong></div>
                        <p>Trash cleanup is manual. Recovery snapshots remain available until you restore them or delete them permanently.</p>
                    </section>
                    <section class="nobash-files-trash-side-card">
                        <div class="nobash-files-trash-side-head"><span class="dashicons dashicons-shield"></span><strong>Safety note</strong></div>
                        <p>Trash is part of Safe File Management. Restore checks never overwrite a newer file that already exists at the original path.</p>
                    </section>
                </aside>
            </div>
        </main>
        <?php
        self::shell_end();
    }

    private static function trash_entries_dataset( Nobash_Files_Filesystem $fs ): array {
        $private_dir = rtrim( $fs->private_dir(), '/' );
        $rows = Nobash_Files_DB::trash_entries( 250 );
        $entries = array();
        foreach ( $rows as $row ) {
            $operation = Nobash_Files_DB::get_operation( (int) $row['id'] );
            if ( ! $operation ) {
                continue;
            }
            if ( ! current_user_can( 'manage_options' ) && ! Nobash_Files_Access::can_paths( 'trash', Nobash_Files_Access::operation_paths( $operation ), $fs ) ) {
                continue;
            }
            $payload = is_array( $operation['undo_payload'] ?? null ) ? $operation['undo_payload'] : array();
            $snapshots = array();
            foreach ( (array) ( $payload['items'] ?? array() ) as $item ) {
                $token = sanitize_file_name( (string) ( $item['snapshot'] ?? '' ) );
                if ( '' === $token ) {
                    continue;
                }
                $snapshots[] = self::trash_snapshot_summary( $private_dir, $token, (string) ( $item['path'] ?? '' ) );
            }
            $bytes = 0;
            $file_count = 0;
            $folder_count = 0;
            foreach ( $snapshots as $snapshot ) {
                $bytes += (int) $snapshot['bytes'];
                $file_count += (int) $snapshot['files'];
                $folder_count += (int) $snapshot['folders'];
            }
            $single_path = (string) ( $row['relative_path'] ?? '' );
            $path_candidates = array_filter( array_map( static fn( array $item ): string => (string) ( $item['path'] ?? '' ), (array) ( $payload['items'] ?? array() ) ) );
            if ( 1 === count( $path_candidates ) ) {
                $single_path = (string) reset( $path_candidates );
            }
            $type_slug = 'batch';
            $type_label = 'Batch';
            $icon = 'dashicons-category';
            if ( 1 === count( $path_candidates ) ) {
                $first = $snapshots[0] ?? array( 'root_type' => 'file' );
                $type_slug = 'dir' === ( $first['root_type'] ?? 'file' ) ? 'folder' : 'file';
                $type_label = self::trash_type_label( $single_path, $type_slug );
                $icon = self::trash_type_icon( $single_path, $type_slug );
            }
            $display_name = 'Batch recovery item';
            if ( '' !== $single_path ) {
                $display_name = basename( $single_path );
            } elseif ( ! empty( $path_candidates ) ) {
                $display_name = basename( (string) reset( $path_candidates ) );
            }
            $location_label = '/';
            if ( '' !== $single_path ) {
                $parent = dirname( $single_path );
                $location_label = '.' === $parent ? '/' : '/' . trim( $parent, '/' ) . '/';
            }
            $entries[] = array(
                'id' => (int) $row['id'],
                'display_name' => $display_name,
                'type_label' => $type_label,
                'type_slug' => $type_slug,
                'icon' => $icon,
                'location_label' => $location_label,
                'created_at' => (string) $row['created_at'],
                'bytes' => $bytes,
                'item_count' => max( 1, (int) ( $row['item_count'] ?? 0 ) ),
                'search_terms' => array_merge( array( $display_name, $single_path, $location_label, $type_label ), array_values( $path_candidates ) ),
            );
        }
        return $entries;
    }

    private static function trash_snapshot_summary( string $private_dir, string $token, string $source_path = '' ): array {
        $summary = array( 'bytes' => 0, 'files' => 0, 'folders' => 0, 'root_type' => 'file', 'source_path' => $source_path );
        $manifest_path = wp_normalize_path( $private_dir . '/snapshots/' . $token . '/manifest.json' );
        if ( ! is_readable( $manifest_path ) ) {
            return $summary;
        }
        $manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
        if ( ! is_array( $manifest ) ) {
            return $summary;
        }
        if ( ! empty( $manifest['source_path'] ) ) {
            $summary['source_path'] = (string) $manifest['source_path'];
        }
        $entries = (array) ( $manifest['entries'] ?? array() );
        if ( ! empty( $entries[0]['type'] ) ) {
            $summary['root_type'] = 'dir' === $entries[0]['type'] ? 'dir' : 'file';
        }
        foreach ( $entries as $entry ) {
            if ( 'file' === ( $entry['type'] ?? '' ) ) {
                $summary['files']++;
                $summary['bytes'] += (int) ( $entry['size'] ?? 0 );
            } elseif ( 'dir' === ( $entry['type'] ?? '' ) ) {
                $summary['folders']++;
            }
        }
        return $summary;
    }

    private static function trash_type_label( string $path, string $type_slug ): string {
        if ( 'folder' === $type_slug ) {
            return 'Folder';
        }
        $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        if ( in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif' ), true ) ) {
            return 'Image';
        }
        if ( in_array( $ext, array( 'php', 'js', 'css', 'json', 'html', 'htm', 'xml', 'yml', 'yaml', 'txt', 'md', 'sh' ), true ) ) {
            return 'File';
        }
        return 'File';
    }

    private static function trash_type_icon( string $path, string $type_slug ): string {
        if ( 'folder' === $type_slug ) {
            return 'dashicons-category';
        }
        $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        if ( in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif' ), true ) ) {
            return 'dashicons-format-image';
        }
        if ( in_array( $ext, array( 'php', 'js', 'css', 'json', 'html', 'htm', 'xml', 'yml', 'yaml' ), true ) ) {
            return 'dashicons-editor-code';
        }
        return 'dashicons-media-default';
    }

    private static function trash_notice_data( string $notice ): array {
        switch ( $notice ) {
            case 'restored':
                return array( 'class' => 'success', 'icon' => 'dashicons-yes-alt', 'text' => 'Trash item restored successfully.' );
            case 'deleted':
                return array( 'class' => 'success', 'icon' => 'dashicons-yes-alt', 'text' => 'Trash item deleted permanently.' );
            case 'bulk-restored':
                return array( 'class' => 'success', 'icon' => 'dashicons-yes-alt', 'text' => 'Selected Trash items were restored.' );
            case 'bulk-deleted':
                return array( 'class' => 'success', 'icon' => 'dashicons-yes-alt', 'text' => 'Selected Trash items were deleted permanently.' );
            case 'emptied':
                return array( 'class' => 'success', 'icon' => 'dashicons-yes-alt', 'text' => 'Trash was emptied successfully.' );
            case 'empty':
                return array( 'class' => 'warning', 'icon' => 'dashicons-info-outline', 'text' => 'There are no Trash items to process.' );
            case 'invalid-action':
                return array( 'class' => 'warning', 'icon' => 'dashicons-warning', 'text' => 'Choose a Trash action first.' );
            case 'failed':
                $message = (string) get_transient( 'nobash_files_trash_error_' . get_current_user_id() );
                delete_transient( 'nobash_files_trash_error_' . get_current_user_id() );
                return array( 'class' => 'error', 'icon' => 'dashicons-warning', 'text' => '' !== $message ? $message : 'Trash action failed.' );
        }
        return array( 'class' => '', 'icon' => 'dashicons-info-outline', 'text' => '' );
    }

    public static function trash_bulk(): void {
        if ( ! Nobash_Files_Access::can_use() ) {
            wp_die( esc_html__( 'You do not have permission to change Trash.', 'nobash-files' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'nobash_files_trash_bulk' );
        try {
            Nobash_Files_Access::assert_permission( 'trash' );
        } catch ( Throwable $e ) {
            wp_die( esc_html( $e->getMessage() ), '', array( 'response' => 403 ) );
        }
        $row_action = isset( $_POST['row_action'] ) ? sanitize_text_field( wp_unslash( $_POST['row_action'] ) ) : '';
        $bulk_action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
        $ids = array_values( array_filter( array_map( 'absint', (array) ( $_POST['op_ids'] ?? array() ) ) ) );
        if ( '' !== $row_action ) {
            $parts = explode( ':', $row_action );
            $bulk_action = sanitize_key( $parts[0] ?? '' );
            $ids = array( absint( $parts[1] ?? 0 ) );
        }
        if ( empty( $ids ) ) {
            self::trash_redirect( 'empty' );
        }
        if ( ! in_array( $bulk_action, array( 'restore', 'delete' ), true ) ) {
            self::trash_redirect( 'invalid-action' );
        }
        try {
            $fs = new Nobash_Files_Filesystem();
            foreach ( $ids as $id ) {
                $operation = Nobash_Files_DB::get_operation( $id );
                if ( ! $operation ) {
                    throw new RuntimeException( 'Trash recovery entry not found.' );
                }
                Nobash_Files_Access::assert_operation_paths( 'restore' === $bulk_action ? 'restore' : 'delete', $operation, $fs );
                if ( 'restore' === $bulk_action ) {
                    $fs->restore_trash_operation( $id );
                } else {
                    $fs->purge_trash_operation( $id );
                }
            }
            if ( '' !== $row_action ) {
                self::trash_redirect( 'restore' === $bulk_action ? 'restored' : 'deleted' );
            }
            self::trash_redirect( 'restore' === $bulk_action ? 'bulk-restored' : 'bulk-deleted' );
        } catch ( Throwable $e ) {
            set_transient( 'nobash_files_trash_error_' . get_current_user_id(), $e->getMessage(), MINUTE_IN_SECONDS * 5 );
            self::trash_redirect( 'failed' );
        }
    }

    public static function trash_empty(): void {
        if ( ! Nobash_Files_Access::can_use() ) {
            wp_die( esc_html__( 'You do not have permission to empty Trash.', 'nobash-files' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'nobash_files_trash_empty' );
        try {
            $fs = new Nobash_Files_Filesystem();
            Nobash_Files_Access::assert_permission( 'trash' );
            Nobash_Files_Access::assert_permission( 'delete' );
            if ( ! current_user_can( 'manage_options' ) ) {
                foreach ( $fs->trash_entries() as $operation ) {
                    Nobash_Files_Access::assert_operation_paths( 'delete', $operation, $fs );
                }
            }
            $result = $fs->empty_trash();
            self::trash_redirect( ! empty( $result['purged'] ) ? 'emptied' : 'empty' );
        } catch ( Throwable $e ) {
            set_transient( 'nobash_files_trash_error_' . get_current_user_id(), $e->getMessage(), MINUTE_IN_SECONDS * 5 );
            self::trash_redirect( 'failed' );
        }
    }

    private static function trash_redirect( string $notice ): void {
        $view = isset( $_POST['redirect_view'] ) && is_string( $_POST['redirect_view'] ) ? sanitize_key( wp_unslash( $_POST['redirect_view'] ) ) : 'all';
        $q = isset( $_POST['redirect_q'] ) && is_string( $_POST['redirect_q'] ) ? sanitize_text_field( wp_unslash( $_POST['redirect_q'] ) ) : '';
        $paged = max( 1, absint( isset( $_POST['redirect_paged'] ) && is_scalar( $_POST['redirect_paged'] ) ? wp_unslash( (string) $_POST['redirect_paged'] ) : 1 ) );
        $args = array( 'page' => 'nobash-files-trash', 'nobash_files_trash_notice' => $notice, 'nobash_files_trash_view' => $view );
        if ( '' !== $q ) {
            $args['nobash_files_trash_q'] = $q;
        }
        if ( $paged > 1 ) {
            $args['paged'] = $paged;
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }
    public static function render_access(): void { self::simple_page( 'access', 'Team access', 'Choose who can use your files and what they can do.' ); }
    public static function render_multisite(): void { self::simple_page( 'multisite', 'Network sites', 'Open a site’s files or review its security status.' ); }

    private static function activity_action_meta( string $action ): array {
        $map = array(
            'edited_file' => array( 'label' => 'Edited file', 'status' => 'Edited', 'tone' => 'edited', 'icon' => 'dashicons-edit', 'category' => 'edits', 'bucket' => 'edits' ),
            'created_manual_version' => array( 'label' => 'Created manual version', 'status' => 'Version', 'tone' => 'version', 'icon' => 'dashicons-backup', 'category' => 'edits', 'bucket' => 'edits' ),
            'restored_version' => array( 'label' => 'Restored version', 'status' => 'Restored', 'tone' => 'success', 'icon' => 'dashicons-image-rotate', 'category' => 'edits', 'bucket' => 'edits' ),
            'uploaded_file' => array( 'label' => 'Uploaded file', 'status' => 'Uploaded', 'tone' => 'success', 'icon' => 'dashicons-upload', 'category' => 'uploads', 'bucket' => 'uploads' ),
            'created_folder' => array( 'label' => 'Created folder', 'status' => 'Created', 'tone' => 'success', 'icon' => 'dashicons-category', 'category' => 'uploads', 'bucket' => 'uploads' ),
            'created_upload_folder' => array( 'label' => 'Created upload folder', 'status' => 'Created', 'tone' => 'success', 'icon' => 'dashicons-category', 'category' => 'uploads', 'bucket' => 'uploads' ),
            'renamed_item' => array( 'label' => 'Renamed item', 'status' => 'Renamed', 'tone' => 'neutral', 'icon' => 'dashicons-admin-page', 'category' => 'uploads', 'bucket' => 'uploads' ),
            'duplicated_item' => array( 'label' => 'Duplicated item', 'status' => 'Duplicated', 'tone' => 'neutral', 'icon' => 'dashicons-admin-page', 'category' => 'uploads', 'bucket' => 'uploads' ),
            'moved_items' => array( 'label' => 'Moved items', 'status' => 'Moved', 'tone' => 'neutral', 'icon' => 'dashicons-move', 'category' => 'uploads', 'bucket' => 'uploads' ),
            'copied_items' => array( 'label' => 'Copied items', 'status' => 'Copied', 'tone' => 'neutral', 'icon' => 'dashicons-admin-page', 'category' => 'uploads', 'bucket' => 'uploads' ),
            'created_archive' => array( 'label' => 'Created archive', 'status' => 'Archive', 'tone' => 'neutral', 'icon' => 'dashicons-portfolio', 'category' => 'system', 'bucket' => 'system' ),
            'extracted_archive' => array( 'label' => 'Extracted archive', 'status' => 'Extracted', 'tone' => 'neutral', 'icon' => 'dashicons-open-folder', 'category' => 'system', 'bucket' => 'system' ),
            'trashed_items' => array( 'label' => 'Trashed items', 'status' => 'Trash', 'tone' => 'warning', 'icon' => 'dashicons-trash', 'category' => 'trash', 'bucket' => 'trash' ),
            'restored_from_trash' => array( 'label' => 'Restored from Trash', 'status' => 'Restored', 'tone' => 'success', 'icon' => 'dashicons-undo', 'category' => 'trash', 'bucket' => 'trash' ),
            'permanently_deleted_trash' => array( 'label' => 'Permanently deleted Trash', 'status' => 'Deleted', 'tone' => 'danger', 'icon' => 'dashicons-remove', 'category' => 'trash', 'bucket' => 'trash' ),
            'undid_operation' => array( 'label' => 'Undid operation', 'status' => 'Undone', 'tone' => 'success', 'icon' => 'dashicons-undo', 'category' => 'trash', 'bucket' => 'trash' ),
            'integrity_scan' => array( 'label' => 'Integrity scan', 'status' => 'Scan', 'tone' => 'neutral', 'icon' => 'dashicons-shield-alt', 'category' => 'security', 'bucket' => 'security' ),
            'integrity_baseline_created' => array( 'label' => 'Integrity baseline created', 'status' => 'Baseline', 'tone' => 'success', 'icon' => 'dashicons-lock', 'category' => 'security', 'bucket' => 'security' ),
            'integrity_baseline_deleted' => array( 'label' => 'Integrity baseline deleted', 'status' => 'Baseline', 'tone' => 'warning', 'icon' => 'dashicons-unlock', 'category' => 'security', 'bucket' => 'security' ),
            'access_denied' => array( 'label' => 'Access denied', 'status' => 'Blocked', 'tone' => 'danger', 'icon' => 'dashicons-shield', 'category' => 'security', 'bucket' => 'security' ),
            'cancelled_upload_sessions' => array( 'label' => 'Cancelled upload sessions', 'status' => 'Cancelled', 'tone' => 'warning', 'icon' => 'dashicons-no-alt', 'category' => 'system', 'bucket' => 'system' ),
        );
        return $map[ $action ] ?? array( 'label' => ucwords( str_replace( '_', ' ', $action ) ), 'status' => 'Logged', 'tone' => 'neutral', 'icon' => 'dashicons-clock', 'category' => 'system', 'bucket' => 'system' );
    }

    private static function activity_details_text( string $action, array $details ): string {
        switch ( $action ) {
            case 'edited_file':
                return ! empty( $details['bytes'] ) ? 'Saved ' . size_format( (int) $details['bytes'], 1 ) . ' with safe edit tracking.' : 'Saved changes safely.';
            case 'created_manual_version':
                return 'A manual safety snapshot was created before or during editing.';
            case 'restored_version':
                return 'A stored safety version was restored to the live file.';
            case 'uploaded_file':
                return ! empty( $details['bytes'] ) ? 'Uploaded ' . size_format( (int) $details['bytes'], 1 ) . '.' : 'A new file was uploaded.';
            case 'created_folder':
            case 'created_upload_folder':
                return 'A new folder was created.';
            case 'renamed_item':
                return ! empty( $details['from'] ) ? 'Renamed from ' . (string) $details['from'] . '.' : 'An item was renamed.';
            case 'duplicated_item':
                return ! empty( $details['from'] ) ? 'Duplicated from ' . (string) $details['from'] . '.' : 'An item was duplicated.';
            case 'moved_items':
            case 'copied_items':
                return ! empty( $details['paths'] ) && is_array( $details['paths'] ) ? count( $details['paths'] ) . ' item(s) were processed.' : 'Multiple items were processed.';
            case 'trashed_items':
                return ! empty( $details['count'] ) ? (int) $details['count'] . ' item(s) were moved to Trash.' : 'Items were moved to Trash.';
            case 'restored_from_trash':
                return 'A trashed item was restored.';
            case 'permanently_deleted_trash':
                return 'A Trash item was permanently deleted.';
            case 'undid_operation':
                return ! empty( $details['action'] ) ? 'Reversed the previous ' . str_replace( '_', ' ', (string) $details['action'] ) . ' action.' : 'Reversed the latest undoable action.';
            case 'created_archive':
                return ! empty( $details['sources'] ) && is_array( $details['sources'] ) ? count( $details['sources'] ) . ' source item(s) were packaged.' : 'Selected items were packaged into an archive.';
            case 'extracted_archive':
                return ! empty( $details['created'] ) ? (int) $details['created'] . ' item(s) were extracted.' : 'An archive was extracted.';
            case 'integrity_scan':
                return 'Core integrity results were refreshed for review.';
            case 'integrity_baseline_created':
                return ! empty( $details['files'] ) ? 'Trusted baseline generated from ' . (int) $details['files'] . ' file(s).' : 'A new trusted baseline was created.';
            case 'integrity_baseline_deleted':
                return 'The current trusted baseline was deleted.';
            case 'access_denied':
                return ! empty( $details['permission'] ) ? 'Blocked while attempting ' . (string) $details['permission'] . '.' : 'A protected action was blocked.';
            case 'cancelled_upload_sessions':
                return ! empty( $details['count'] ) ? (int) $details['count'] . ' pending upload session(s) were cancelled.' : 'Pending upload sessions were cancelled.';
            default:
                return 'This activity was logged for review.';
        }
    }

    public static function render_agency(): void { self::simple_page( 'agency', 'Access templates', 'Save folder and action settings, then apply them to a role or person.' ); }
    public static function render_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to change Nobash settings.', 'nobash-files' ), '', array( 'response' => 403 ) );
        }
        $choices = class_exists( 'Nobash_Files_Onboarding' ) ? Nobash_Files_Onboarding::workspace_choices() : array( '' => array( 'label' => 'WordPress root', 'description' => 'Open the complete WordPress filesystem.' ) );
        $workspace = class_exists( 'Nobash_Files_Onboarding' ) ? Nobash_Files_Onboarding::default_workspace() : '';
        $page_size = (int) get_option( 'nobash_files_directory_page_size', 250 );
        $max_edit  = (int) get_option( 'nobash_files_max_edit_bytes', 1048576 );
        $chunk     = (int) get_option( 'nobash_files_chunk_bytes', 2097152 );
        $effective_chunk = class_exists( 'Nobash_Files_Runtime' ) ? Nobash_Files_Runtime::effective_chunk_bytes( $chunk ) : $chunk;
        $version_max = max( 5, (int) apply_filters( 'nobash_files_version_history_max', 100 ) );
        $version_limit = max( 5, min( $version_max, (int) get_option( 'nobash_files_version_limit', 5 ) ) );
        $uninstall = in_array( (string) get_option( 'nobash_files_uninstall_policy', 'keep' ), array( 'keep', 'remove' ), true ) ? (string) get_option( 'nobash_files_uninstall_policy', 'keep' ) : 'keep';
        $word_wrap = '1' === get_option( 'nobash_files_editor_word_wrap', '0' );
        $runtime = class_exists( 'Nobash_Files_Runtime' ) ? Nobash_Files_Runtime::profile() : array();
        $notice = isset( $_GET['nobash_files_settings_notice'] ) ? sanitize_key( wp_unslash( $_GET['nobash_files_settings_notice'] ) ) : '';
        self::shell( 'settings' );
        ?>
        <main class="nobash-files-settings-page-v2">
            <?php if ( $notice ) : ?>
                <div class="nobash-files-settings-page-notice is-success"><span class="dashicons dashicons-yes-alt"></span><?php echo esc_html( 'reset' === $notice ? 'Settings reset to safe defaults.' : 'Settings saved.' ); ?></div>
            <?php endif; ?>
            <header class="nobash-files-settings-page-title">
                <div><h2>Settings</h2><p>Choose how files open, how the editor works and how many backups to keep.</p></div>
                <div class="nobash-files-settings-page-actions">
                    <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=nobash_files_settings_export' ), 'nobash_files_settings_export' ) ); ?>"><span class="dashicons dashicons-download"></span>Export Settings</a>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="nobash_files_settings_reset"><?php wp_nonce_field( 'nobash_files_settings_reset' ); ?><button class="button" type="submit"><span class="dashicons dashicons-image-rotate"></span>Reset Defaults</button></form>
                </div>
            </header>

            <form class="nobash-files-settings-form-v2" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="nobash_files_settings_save">
                <?php wp_nonce_field( 'nobash_files_settings_save' ); ?>
                <div class="nobash-files-settings-grid-v2">
                    <div class="nobash-files-settings-main-v2">
                        <section class="nobash-files-settings-card-v2">
                            <header><div><h3>Explorer</h3><p>Choose the starting folder and how many items to load.</p></div><span class="dashicons dashicons-open-folder"></span></header>
                            <label class="nobash-files-settings-row-v2"><span><strong>Starting folder</strong><small>Directory opened when File Manager starts. It does not change access permissions.</small></span><select name="default_workspace"><?php foreach ( $choices as $value => $meta ) : ?><option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $workspace, (string) $value ); ?>><?php echo esc_html( (string) $meta['label'] ); ?> — /<?php echo esc_html( (string) $value ); ?></option><?php endforeach; ?></select></label>
                            <label class="nobash-files-settings-row-v2"><span><strong>Items to load at once</strong><small>Smaller values reduce PHP memory use in very large folders.</small></span><select name="directory_page_size"><option value="100" <?php selected( $page_size, 100 ); ?>>100 — lowest memory</option><option value="250" <?php selected( $page_size, 250 ); ?>>250 — recommended</option><option value="500" <?php selected( $page_size, 500 ); ?>>500 — faster browsing</option></select></label>
                        </section>

                        <section class="nobash-files-settings-card-v2">
                            <header><div><h3>Editor</h3><p>Choose how text and code files open.</p></div><span class="dashicons dashicons-editor-code"></span></header>
                            <div class="nobash-files-settings-row-v2"><span><strong>Syntax highlighting</strong><small>Follows your WordPress profile preference.</small></span><span class="nobash-files-settings-fixed-badge">WordPress managed</span></div>
                            <label class="nobash-files-settings-row-v2"><span><strong>Word wrap</strong><small>Wrap long lines visually without changing file contents.</small></span><input class="nobash-files-switch-input" type="checkbox" name="editor_word_wrap" value="1" <?php checked( $word_wrap ); ?>></label>
                            <label class="nobash-files-settings-row-v2"><span><strong>Maximum file size to edit</strong><small>Larger files remain available for download/preview but stay out of the browser editor.</small></span><select name="max_edit_bytes"><option value="524288" <?php selected( $max_edit, 524288 ); ?>>512 KB</option><option value="1048576" <?php selected( $max_edit, 1048576 ); ?>>1 MB — recommended</option><option value="2097152" <?php selected( $max_edit, 2097152 ); ?>>2 MB</option><option value="5242880" <?php selected( $max_edit, 5242880 ); ?>>5 MB</option></select></label>
                        </section>

                        <section class="nobash-files-settings-card-v2">
                            <header><div><h3>Uploads</h3><p>Large uploads are sent in smaller parts to fit your server limits.</p></div><span class="dashicons dashicons-upload"></span></header>
                            <label class="nobash-files-settings-row-v2"><span><strong>Size of each upload part</strong><small>Large files and full-folder uploads use resumable sequential chunks.</small></span><select name="chunk_bytes"><option value="1048576" <?php selected( $chunk, 1048576 ); ?>>1 MB</option><option value="2097152" <?php selected( $chunk, 2097152 ); ?>>2 MB — recommended</option><option value="4194304" <?php selected( $chunk, 4194304 ); ?>>4 MB</option><option value="8388608" <?php selected( $chunk, 8388608 ); ?>>8 MB</option></select></label>
                            <div class="nobash-files-settings-row-v2"><span><strong>Actual upload part size</strong><small>Calculated from memory, upload and POST limits. Nobash never raises PHP/server limits.</small></span><span class="nobash-files-settings-value-pill"><?php echo esc_html( size_format( $effective_chunk, 1 ) ); ?></span></div>
                        </section>
                    </div>

                    <aside class="nobash-files-settings-side-v2">
                        <section class="nobash-files-settings-card-v2 is-side">
                            <header><div><h3>Workspace</h3><p>Current runtime and opening location.</p></div><span class="dashicons dashicons-category"></span></header>
                            <dl class="nobash-files-settings-summary-list"><dt>Current workspace</dt><dd><?php echo esc_html( '' === $workspace ? 'WordPress root — /' : '/' . $workspace ); ?></dd><dt>Directory page</dt><dd><?php echo esc_html( number_format_i18n( $page_size ) ); ?> items</dd><dt>PHP memory limit</dt><dd><?php echo esc_html( isset( $runtime['memory_limit'] ) && class_exists( 'Nobash_Files_Runtime' ) ? Nobash_Files_Runtime::format_bytes( (int) $runtime['memory_limit'] ) : 'Unknown' ); ?></dd><dt>Effective chunk</dt><dd><?php echo esc_html( size_format( $effective_chunk, 1 ) ); ?></dd></dl>
                            <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=nobash-files' ) ); ?>"><span class="dashicons dashicons-open-folder"></span>Open File Manager</a>
                        </section>

                        <section class="nobash-files-settings-card-v2 is-side">
                            <header><div><h3>File backups</h3><p>Choose how many earlier copies to keep for each file.</p></div><span class="dashicons dashicons-backup"></span></header>
                            <label class="nobash-files-settings-side-field"><span>Backups kept per file</span><select name="version_limit"><?php foreach ( array_values( array_filter( array( 5, 10, 20, 30, 50, 100 ), static fn( $v ) => $v <= $version_max ) ) as $v ) : ?><option value="<?php echo esc_attr( (string) $v ); ?>" <?php selected( $version_limit, $v ); ?>><?php echo esc_html( (string) $v ); ?> versions</option><?php endforeach; ?></select></label>
                        </section>

                        <section class="nobash-files-settings-card-v2 is-side">
                            <header><div><h3>Other</h3><p>Recovery, preview and deletion behavior.</p></div><span class="dashicons dashicons-admin-generic"></span></header>
                            <div class="nobash-files-settings-row-v2 compact"><span><strong>Secure preview</strong><small>HTML/SVG never render inline through preview.</small></span><span class="nobash-files-settings-fixed-badge is-green">Always on</span></div>
                            <div class="nobash-files-settings-row-v2 compact"><span><strong>Trash cleanup</strong><small>Trash recovery is manual; Nobash does not silently purge recovery snapshots.</small></span><span class="nobash-files-settings-fixed-badge">Manual</span></div>
                            <label class="nobash-files-settings-side-field"><span>When the plugin is deleted</span><select name="uninstall_policy"><option value="keep" <?php selected( $uninstall, 'keep' ); ?>>Keep Nobash data — safest</option><option value="remove" <?php selected( $uninstall, 'remove' ); ?>>Remove Nobash data permanently</option></select></label>
                        </section>
                    </aside>
                </div>

                <footer class="nobash-files-settings-footer-v2"><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=nobash-files-settings' ) ); ?>">Cancel</a><button class="button button-primary" type="submit"><span class="dashicons dashicons-saved"></span>Save settings</button></footer>
            </form>
        </main>
        <?php
        self::shell_end();
    }

    public static function settings_save(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You do not have permission to change Nobash settings.', 'nobash-files' ), '', array( 'response' => 403 ) ); }
        check_admin_referer( 'nobash_files_settings_save' );
        $workspace = isset( $_POST['default_workspace'] ) ? sanitize_text_field( wp_unslash( $_POST['default_workspace'] ) ) : '';
        if ( class_exists( 'Nobash_Files_Onboarding' ) && array_key_exists( $workspace, Nobash_Files_Onboarding::workspace_choices() ) ) { update_option( 'nobash_files_default_workspace', $workspace, false ); }
        $page_size = absint( $_POST['directory_page_size'] ?? 250 ); if ( in_array( $page_size, array( 100,250,500 ), true ) ) { update_option( 'nobash_files_directory_page_size', $page_size, false ); }
        $max_edit = absint( $_POST['max_edit_bytes'] ?? 1048576 ); if ( in_array( $max_edit, array( 524288,1048576,2097152,5242880 ), true ) ) { update_option( 'nobash_files_max_edit_bytes', $max_edit, false ); }
        update_option( 'nobash_files_editor_word_wrap', isset( $_POST['editor_word_wrap'] ) ? '1' : '0', false );
        $chunk = absint( $_POST['chunk_bytes'] ?? 2097152 ); if ( in_array( $chunk, array( 1048576,2097152,4194304,8388608 ), true ) ) { update_option( 'nobash_files_chunk_bytes', $chunk, false ); }
        $max_versions = max( 5, (int) apply_filters( 'nobash_files_version_history_max', 100 ) ); $versions = max( 5, min( $max_versions, absint( $_POST['version_limit'] ?? 5 ) ) ); update_option( 'nobash_files_version_limit', $versions, false );
        $uninstall = sanitize_key( (string) ( $_POST['uninstall_policy'] ?? 'keep' ) ); if ( in_array( $uninstall, array( 'keep','remove' ), true ) ) { update_option( 'nobash_files_uninstall_policy', $uninstall, false ); }
        wp_safe_redirect( add_query_arg( array( 'page' => 'nobash-files-settings', 'nobash_files_settings_notice' => 'saved' ), admin_url( 'admin.php' ) ) ); exit;
    }

    public static function settings_reset(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You do not have permission to reset Nobash settings.', 'nobash-files' ), '', array( 'response' => 403 ) ); }
        check_admin_referer( 'nobash_files_settings_reset' );
        update_option( 'nobash_files_default_workspace', class_exists( 'Nobash_Files_Onboarding' ) ? Nobash_Files_Onboarding::recommended_workspace() : '', false );
        update_option( 'nobash_files_directory_page_size', 250, false ); update_option( 'nobash_files_max_edit_bytes', 1048576, false ); update_option( 'nobash_files_editor_word_wrap', '0', false ); update_option( 'nobash_files_chunk_bytes', 2097152, false ); update_option( 'nobash_files_version_limit', 5, false ); update_option( 'nobash_files_uninstall_policy', 'keep', false );
        wp_safe_redirect( add_query_arg( array( 'page' => 'nobash-files-settings', 'nobash_files_settings_notice' => 'reset' ), admin_url( 'admin.php' ) ) ); exit;
    }

    public static function settings_export(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You do not have permission to export Nobash settings.', 'nobash-files' ), '', array( 'response' => 403 ) ); }
        check_admin_referer( 'nobash_files_settings_export' );
        $data = array( 'version' => NOBASH_FILES_VERSION, 'exported_at' => gmdate( 'c' ), 'settings' => array( 'default_workspace' => class_exists( 'Nobash_Files_Onboarding' ) ? Nobash_Files_Onboarding::default_workspace() : '', 'directory_page_size' => (int) get_option( 'nobash_files_directory_page_size', 250 ), 'max_edit_bytes' => (int) get_option( 'nobash_files_max_edit_bytes', 1048576 ), 'editor_word_wrap' => '1' === get_option( 'nobash_files_editor_word_wrap', '0' ), 'chunk_bytes' => (int) get_option( 'nobash_files_chunk_bytes', 2097152 ), 'version_limit' => (int) get_option( 'nobash_files_version_limit', 5 ), 'uninstall_policy' => (string) get_option( 'nobash_files_uninstall_policy', 'keep' ) ) );
        while ( ob_get_level() ) { ob_end_clean(); } nocache_headers(); header( 'Content-Type: application/json; charset=utf-8' ); header( 'Content-Disposition: attachment; filename="nobash-files-settings-' . gmdate( 'Y-m-d' ) . '.json"' ); header( 'X-Content-Type-Options: nosniff' ); echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ); exit;
    }

    public static function toggle_safe_mode(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You do not have permission to change Safe Mode.', 'nobash-files' ), '', array( 'response' => 403 ) ); }
        check_admin_referer( 'nobash_files_toggle_safe_mode' );
        update_option( 'nobash_files_safe_mode', '1' === get_option( 'nobash_files_safe_mode', '1' ) ? '0' : '1', false );
        $redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : admin_url( 'admin.php?page=nobash-files-settings' );
        if ( ! str_starts_with( $redirect, admin_url() ) ) { $redirect = admin_url( 'admin.php?page=nobash-files-settings' ); }
        wp_safe_redirect( $redirect ); exit;
    }

    private static function current_nobash_files_admin_url(): string {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'nobash-files';
        $allowed = array( 'nobash-files','nobash-files-tools','nobash-files-editor','nobash-files-versions','nobash-files-integrity','nobash-files-activity','nobash-files-trash','nobash-files-settings','nobash-files-security','nobash-files-monitoring','nobash-files-access','nobash-files-agency','nobash-files-network' );
        if ( ! in_array( $page, $allowed, true ) ) { $page = 'nobash-files'; }
        return admin_url( 'admin.php?page=' . $page );
    }

    public static function simple_page( string $view, string $title, string $desc ): void {
        self::shell( $view );
        echo '<main class="nobash-files-simple"><section class="nobash-files-panel"><h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $desc ) . '</p><div id="nobash-files-simple-content"></div></section></main>';
        self::shell_end();
    }


    public static function audit_export(): void {
        if ( ! Nobash_Files_Agency::can_manage() ) {
            wp_die( esc_html__( 'You do not have permission to export the audit log.', 'nobash-files' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'nobash_files_audit_export' );
        try {
            $filters = array(
                'site_id' => isset( $_GET['site_id'] ) ? absint( $_GET['site_id'] ) : 0,
                'action'  => isset( $_GET['audit_action'] ) ? sanitize_key( wp_unslash( $_GET['audit_action'] ) ) : '',
                'user_id' => isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0,
            );
            $rows = Nobash_Files_Agency::audit_rows( $filters, 5000 );
            while ( ob_get_level() ) {
                ob_end_clean();
            }
            nocache_headers();
            header( 'Content-Type: text/csv; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename="nobash-files-audit-' . gmdate( 'Y-m-d-His' ) . '.csv"' );
            header( 'X-Content-Type-Options: nosniff' );
            $out = fopen( 'php://output', 'wb' );
            if ( false === $out ) {
                throw new RuntimeException( 'Unable to open audit export stream.' );
            }
            $csv_safe = static function ( $value ): string {
                $value = (string) $value;
                if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@' ), true ) ) {
                    return "'" . $value;
                }
                return $value;
            };
            fputcsv( $out, array( 'Site ID', 'Site', 'Date', 'User ID', 'User', 'Action', 'Path', 'Details' ), ',', '"', '\\' );
            foreach ( $rows as $row ) {
                fputcsv( $out, array(
                    (int) $row['site_id'],
                    $csv_safe( $row['site_name'] ),
                    $csv_safe( $row['created_at'] ),
                    (int) $row['user_id'],
                    $csv_safe( $row['user'] ),
                    $csv_safe( $row['action'] ),
                    $csv_safe( $row['path'] ),
                    $csv_safe( wp_json_encode( $row['details'], JSON_UNESCAPED_SLASHES ) ),
                ), ',', '"', '\\' );
            }
            fclose( $out );
            exit;
        } catch ( Throwable $e ) {
            wp_die( esc_html( $e->getMessage() ), esc_html__( 'Audit export failed', 'nobash-files' ), array( 'response' => 400 ) );
        }
    }

    public static function preview(): void {
        if ( ! Nobash_Files_Access::can_use() ) {
            wp_die( esc_html__( 'You do not have permission to preview this file.', 'nobash-files' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'nobash_files_preview', 'nobash_files_preview_nonce' );

        try {
            $path = isset( $_GET['path'] ) && is_string( $_GET['path'] ) ? sanitize_text_field( wp_unslash( $_GET['path'] ) ) : '';
            $fs = new Nobash_Files_Filesystem();
            Nobash_Files_Access::assert_paths( 'read', array( $path ), $fs );
            $data = $fs->preview_file( $path );
            $size = (int) filesize( $data['path'] );
            $start = 0;
            $end   = max( 0, $size - 1 );
            $status = 200;

            if ( isset( $_SERVER['HTTP_RANGE'] ) ) {
                $range = is_string( $_SERVER['HTTP_RANGE'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) ) : '';
                if ( preg_match( '/^bytes=(\d*)-(\d*)$/', $range, $matches ) ) {
                    if ( '' === $matches[1] && '' !== $matches[2] ) {
                        $suffix = min( $size, max( 0, (int) $matches[2] ) );
                        $start  = max( 0, $size - $suffix );
                    } elseif ( '' !== $matches[1] ) {
                        $start = (int) $matches[1];
                    }
                    if ( '' !== $matches[2] ) {
                        $end = min( $end, (int) $matches[2] );
                    }
                    if ( $start > $end || $start >= $size ) {
                        header( 'Content-Range: bytes */' . $size );
                        status_header( 416 );
                        exit;
                    }
                    $status = 206;
                }
            }

            while ( ob_get_level() ) {
                ob_end_clean();
            }
            nocache_headers();
            status_header( $status );
            header( 'Content-Type: ' . $data['mime'] );
            header( 'Accept-Ranges: bytes' );
            header( 'Content-Length: ' . (string) ( $end - $start + 1 ) );
            if ( 206 === $status ) {
                header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
            }
            header( 'Content-Disposition: inline; filename="' . str_replace( '"', '', sanitize_file_name( basename( $data['path'] ) ) ) . '"' );
            header( 'X-Content-Type-Options: nosniff' );
            header( 'Referrer-Policy: no-referrer' );
            header( "Content-Security-Policy: default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'none'; script-src 'none'; object-src 'none'; frame-ancestors 'self'" );

            $handle = fopen( $data['path'], 'rb' );
            if ( false === $handle ) {
                throw new RuntimeException( 'Unable to open preview.' );
            }
            if ( 0 !== $start ) {
                fseek( $handle, $start );
            }
            $remaining = $end - $start + 1;
            while ( $remaining > 0 && ! feof( $handle ) ) {
                $chunk = fread( $handle, min( 1048576, $remaining ) );
                if ( false === $chunk ) {
                    break;
                }
                echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary stream.
                $remaining -= strlen( $chunk );
                if ( function_exists( 'flush' ) ) {
                    flush();
                }
            }
            fclose( $handle );
            exit;
        } catch ( Nobash_Files_Access_Denied $e ) {
            wp_die( esc_html__( 'Your access profile does not allow this file.', 'nobash-files' ), esc_html__( 'Preview denied', 'nobash-files' ), array( 'response' => 403 ) );
        } catch ( Throwable $e ) {
            wp_die( esc_html( $e->getMessage() ), esc_html__( 'Preview failed', 'nobash-files' ), array( 'response' => 400 ) );
        }
    }

    public static function download(): void {
        if ( ! Nobash_Files_Access::can_use() ) {
            wp_die( esc_html__( 'You do not have permission to download these files.', 'nobash-files' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'nobash_files_download', 'nobash_files_download_nonce' );
        $raw = isset( $_POST['paths'] ) && is_string( $_POST['paths'] ) ? wp_unslash( $_POST['paths'] ) : '[]';
        $paths = json_decode( (string) $raw, true );
        if ( ! is_array( $paths ) ) {
            wp_die( esc_html__( 'Invalid download request.', 'nobash-files' ), '', array( 'response' => 400 ) );
        }

        $package = null;
        try {
            $fs = new Nobash_Files_Filesystem();
            $safe_paths = array_values( array_map( 'strval', $paths ) );
            Nobash_Files_Access::assert_paths( 'download', $safe_paths, $fs );
            Nobash_Files_Access::assert_recursive_safe( $safe_paths, 'download', $fs );
            $package = $fs->create_download_package( $paths );
            $path = (string) $package['path'];
            if ( ! is_file( $path ) || ! is_readable( $path ) ) {
                throw new RuntimeException( 'Download package is unavailable.' );
            }
            while ( ob_get_level() ) {
                ob_end_clean();
            }
            nocache_headers();
            header( 'Content-Type: ' . (string) $package['mime'] );
            header( 'Content-Length: ' . (string) filesize( $path ) );
            header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', sanitize_file_name( (string) $package['name'] ) ) . '"' );
            header( 'X-Content-Type-Options: nosniff' );
            $handle = fopen( $path, 'rb' );
            if ( false === $handle ) {
                throw new RuntimeException( 'Unable to open download.' );
            }
            fpassthru( $handle );
            fclose( $handle );
            if ( ! empty( $package['temporary'] ) ) {
                wp_delete_file( $path );
            }
            exit;
        } catch ( Nobash_Files_Access_Denied $e ) {
            if ( is_array( $package ) && ! empty( $package['temporary'] ) && ! empty( $package['path'] ) ) {
                wp_delete_file( (string) $package['path'] );
            }
            wp_die( esc_html__( 'Your access profile does not allow this download.', 'nobash-files' ), esc_html__( 'Download denied', 'nobash-files' ), array( 'response' => 403 ) );
        } catch ( Throwable $e ) {
            if ( is_array( $package ) && ! empty( $package['temporary'] ) && ! empty( $package['path'] ) ) {
                wp_delete_file( (string) $package['path'] );
            }
            wp_die( esc_html( $e->getMessage() ), esc_html__( 'Download failed', 'nobash-files' ), array( 'response' => 400 ) );
        }
    }
}
