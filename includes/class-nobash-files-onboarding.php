<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nobash_Files_Onboarding {
    private const OPTION_PENDING   = 'nobash_files_onboarding_pending';
    private const OPTION_COMPLETE  = 'nobash_files_onboarding_complete_version';
    private const OPTION_COMPLETED = 'nobash_files_onboarding_completed_at';

    public static function init(): void {
        add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ), 20 );
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 9 );
        add_action( 'admin_post_nobash_files_onboarding_save', array( __CLASS__, 'save' ) );
        add_action( 'admin_post_nobash_files_onboarding_restart', array( __CLASS__, 'restart' ) );
    }

    public static function activate( bool $network_wide = false ): void {
        if ( is_multisite() && $network_wide ) { return; }
        if ( '' === (string) get_option( self::OPTION_COMPLETE, '' ) ) {
            update_option( self::OPTION_PENDING, '1', false );
        }
    }

    public static function menu(): void {
        $hook = add_submenu_page(
            null,
            __( 'Nobash Files Setup', 'nobash-files' ),
            __( 'Setup Guide', 'nobash-files' ),
            'manage_options',
            'nobash-files-setup',
            array( __CLASS__, 'render' )
        );
        if ( $hook ) { Nobash_Files_Admin::register_page_hook( $hook ); }
    }

    public static function maybe_redirect(): void {
        if ( '1' !== get_option( self::OPTION_PENDING, '0' ) || ! current_user_can( 'manage_options' ) ) { return; }
        if ( wp_doing_ajax() || wp_doing_cron() || defined( 'REST_REQUEST' ) && REST_REQUEST ) { return; }
        if ( is_network_admin() ) { return; }
        if ( isset( $_GET['activate-multi'] ) ) { return; }
        // Do not hijack an unrelated admin request. Redirect only from WordPress' normal
        // single-plugin activation return screen; Setup Guide remains available afterward.
        global $pagenow;
        if ( 'plugins.php' !== (string) $pagenow || ! isset( $_GET['activate'] ) ) { return; }
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( 'nobash-files-setup' === $page ) { return; }
        wp_safe_redirect( admin_url( 'admin.php?page=nobash-files-setup' ) );
        exit;
    }

    public static function workspace_choices(): array {
        $choices = array( '' => array( 'label' => __( 'WordPress root', 'nobash-files' ), 'description' => __( 'Start with the complete WordPress filesystem.', 'nobash-files' ) ) );
        try {
            $fs = new Nobash_Files_Filesystem();
            $content = $fs->relative_from_absolute( realpath( WP_CONTENT_DIR ) ?: WP_CONTENT_DIR );
            if ( '' !== $content ) {
                $choices[ $content ] = array( 'label' => __( 'WP Content', 'nobash-files' ), 'description' => __( 'Begin with themes, plugins and uploads. Recommended for most users.', 'nobash-files' ) );
            }
            $uploads = wp_upload_dir( null, false );
            if ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
                $upload_real = realpath( (string) $uploads['basedir'] );
                if ( false !== $upload_real ) {
                    $upload_rel = $fs->relative_from_absolute( $upload_real );
                    if ( '' !== $upload_rel ) {
                        $choices[ $upload_rel ] = array( 'label' => __( 'Uploads', 'nobash-files' ), 'description' => __( 'Start in the media uploads directory.', 'nobash-files' ) );
                    }
                }
            }
        } catch ( Throwable $e ) {
            // Root remains a safe fallback when a custom content/uploads path is outside ABSPATH.
        }
        return $choices;
    }

    public static function recommended_workspace(): string {
        $choices = self::workspace_choices();
        try {
            $fs = new Nobash_Files_Filesystem();
            $content_real = realpath( WP_CONTENT_DIR );
            if ( false !== $content_real ) {
                $content = $fs->relative_from_absolute( $content_real );
                if ( array_key_exists( $content, $choices ) ) { return $content; }
            }
        } catch ( Throwable $e ) {
            // Fall back to the WordPress root when content is outside ABSPATH.
        }
        return '';
    }

    public static function default_workspace(): string {
        $choices = self::workspace_choices();
        $missing = '__nobash_files_missing_workspace__';
        $saved   = get_option( 'nobash_files_default_workspace', $missing );
        if ( $missing !== $saved && array_key_exists( (string) $saved, $choices ) ) { return (string) $saved; }
        return self::recommended_workspace();
    }

    public static function complete(): bool {
        return '' !== (string) get_option( self::OPTION_COMPLETE, '' );
    }

    public static function save(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You do not have permission to configure Nobash Files.', 'nobash-files' ), '', array( 'response' => 403 ) ); }
        check_admin_referer( 'nobash_files_onboarding_save' );
        $step = isset( $_POST['nobash_files_step'] ) ? absint( $_POST['nobash_files_step'] ) : 1;

        if ( 2 === $step ) {
            update_option( 'nobash_files_safe_mode', ! empty( $_POST['safe_mode'] ) ? '1' : '0', false );
            $max_edit = isset( $_POST['max_edit_bytes'] ) ? absint( $_POST['max_edit_bytes'] ) : 1048576;
            update_option( 'nobash_files_max_edit_bytes', max( 524288, min( 5242880, $max_edit ) ), false );
            $max_versions = max( 5, (int) apply_filters( 'nobash_files_version_history_max', 100 ) );
            $requested = isset( $_POST['version_limit'] ) ? absint( $_POST['version_limit'] ) : min( 5, $max_versions );
            update_option( 'nobash_files_version_limit', max( 5, min( $max_versions, $requested ) ), false );
        } elseif ( 3 === $step ) {
            $requested = isset( $_POST['default_workspace'] ) ? sanitize_text_field( wp_unslash( $_POST['default_workspace'] ) ) : '';
            $choices   = self::workspace_choices();
            update_option( 'nobash_files_default_workspace', array_key_exists( $requested, $choices ) ? $requested : '', false );
            update_option( self::OPTION_PENDING, '0', false );
            update_option( self::OPTION_COMPLETE, NOBASH_FILES_VERSION, false );
            update_option( self::OPTION_COMPLETED, current_time( 'mysql' ), false );
        } elseif ( 99 === $step ) {
            update_option( 'nobash_files_safe_mode', '1', false );
            update_option( 'nobash_files_max_edit_bytes', 1048576, false );
            update_option( 'nobash_files_chunk_bytes', 2097152, false );
            $max_versions = max( 5, (int) apply_filters( 'nobash_files_version_history_max', 100 ) );
            update_option( 'nobash_files_version_limit', min( 30, $max_versions ), false );
            update_option( 'nobash_files_default_workspace', self::recommended_workspace(), false );
            update_option( self::OPTION_PENDING, '0', false );
            update_option( self::OPTION_COMPLETE, NOBASH_FILES_VERSION, false );
            update_option( self::OPTION_COMPLETED, current_time( 'mysql' ), false );
            wp_safe_redirect( admin_url( 'admin.php?page=nobash-files' ) );
            exit;
        }

        $next = 2 === $step ? 3 : ( 3 === $step ? 4 : 2 );
        wp_safe_redirect( admin_url( 'admin.php?page=nobash-files-setup&step=' . $next ) );
        exit;
    }

    public static function restart(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You do not have permission to configure Nobash Files.', 'nobash-files' ), '', array( 'response' => 403 ) ); }
        check_admin_referer( 'nobash_files_onboarding_restart' );
        update_option( self::OPTION_PENDING, '1', false );
        wp_safe_redirect( admin_url( 'admin.php?page=nobash-files-setup&step=1' ) );
        exit;
    }

    private static function stepper( int $step ): void {
        $labels = array( 1 => 'Welcome', 2 => 'Safety', 3 => 'Workspace', 4 => 'Ready' );
        echo '<nav class="nobash-files-setup-steps" aria-label="Setup progress">';
        foreach ( $labels as $number => $label ) {
            $class = $number < $step ? 'is-complete' : ( $number === $step ? 'is-active' : '' );
            echo '<div class="nobash-files-setup-step ' . esc_attr( $class ) . '"><span>' . ( $number < $step ? '<span class="dashicons dashicons-yes-alt"></span>' : esc_html( (string) $number ) ) . '</span><small>' . esc_html( $label ) . '</small></div>';
        }
        echo '</nav>';
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You do not have permission to view this page.', 'nobash-files' ) ); }
        $step = isset( $_GET['step'] ) ? max( 1, min( 4, absint( $_GET['step'] ) ) ) : 1;
        $max_versions = max( 5, (int) apply_filters( 'nobash_files_version_history_max', 100 ) );
        ?>
        <div class="wrap nobash-files-setup-page">
            <div class="nobash-files-setup-shell">
                <header class="nobash-files-setup-brand"><span class="nobash-files-setup-logo dashicons dashicons-open-folder"></span><div><strong>Nobash Files</strong><small>Safe file management for WordPress</small></div><span class="nobash-files-edition-pill">FREE</span></header>
                <?php self::stepper( $step ); ?>
                <section class="nobash-files-setup-card">
                <?php if ( 1 === $step ) : ?>
                    <div class="nobash-files-setup-hero-icon"><span class="dashicons dashicons-shield-alt"></span></div>
                    <span class="nobash-files-eyebrow">Welcome</span><h1>Your WordPress files, in one place.</h1>
                    <p class="nobash-files-setup-lead">Nobash combines a familiar file explorer with protected edits, recoverable changes and WordPress-aware integrity checks. This short setup keeps the defaults simple.</p>
                    <div class="nobash-files-setup-benefits"><article><span class="dashicons dashicons-undo"></span><div><strong>Recoverable changes</strong><p>Undo supported file operations and restore editor versions.</p></div></article><article><span class="dashicons dashicons-editor-code"></span><div><strong>Safer editing</strong><p>Validate code and detect files changed outside the editor.</p></div></article><article><span class="dashicons dashicons-shield"></span><div><strong>WordPress aware</strong><p>Protected paths and official core integrity verification are built in.</p></div></article></div>
                    <div class="nobash-files-setup-actions"><a class="button button-primary button-hero" href="<?php echo esc_url( admin_url( 'admin.php?page=nobash-files-setup&step=2' ) ); ?>">Start setup <span class="dashicons dashicons-arrow-right-alt2"></span></a><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="nobash_files_onboarding_save"><input type="hidden" name="nobash_files_step" value="99"><?php wp_nonce_field( 'nobash_files_onboarding_save' ); ?><button class="button button-link" type="submit">Use recommended defaults</button></form></div>
                <?php elseif ( 2 === $step ) : ?>
                    <span class="nobash-files-eyebrow">Safety defaults</span><h1>Keep your files protected.</h1><p class="nobash-files-setup-lead">These settings can be changed later. Safe Mode is recommended for every site.</p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nobash-files-setup-form"><input type="hidden" name="action" value="nobash_files_onboarding_save"><input type="hidden" name="nobash_files_step" value="2"><?php wp_nonce_field( 'nobash_files_onboarding_save' ); ?>
                        <label class="nobash-files-setup-option is-featured"><div><span class="dashicons dashicons-shield-alt"></span><div><strong>Safe Mode</strong><p>Create safety versions before code changes and require confirmation for protected WordPress areas.</p></div></div><input type="checkbox" name="safe_mode" value="1" <?php checked( '1', get_option( 'nobash_files_safe_mode', '1' ) ); ?>></label>
                        <div class="nobash-files-setup-fields"><label><span>Editor file-size limit</span><select name="max_edit_bytes"><option value="524288">512 KB</option><option value="1048576" <?php selected( 1048576, (int) get_option( 'nobash_files_max_edit_bytes', 1048576 ) ); ?>>1 MB — recommended</option><option value="2097152">2 MB</option><option value="5242880">5 MB</option></select><small>Larger files remain manageable in Explorer but are not loaded into the browser editor.</small></label><label><span>Versions kept per file</span><select name="version_limit"><?php foreach ( array( 5,10,20,30,50,100 ) as $limit ) { if ( $limit > $max_versions ) continue; ?><option value="<?php echo esc_attr( $limit ); ?>" <?php selected( $limit, (int) get_option( 'nobash_files_version_limit', 5 ) ); ?>><?php echo esc_html( $limit . ' versions' ); ?></option><?php } ?></select><small>Keep up to 100 safety versions per file; choose a retention limit that fits your storage.</small></label></div>
                        <div class="nobash-files-setup-actions"><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=nobash-files-setup&step=1' ) ); ?>">Back</a><button class="button button-primary" type="submit">Continue <span class="dashicons dashicons-arrow-right-alt2"></span></button></div>
                    </form>
                <?php elseif ( 3 === $step ) : ?>
                    <span class="nobash-files-eyebrow">Starting workspace</span><h1>Choose your starting folder.</h1><p class="nobash-files-setup-lead">This only changes the starting view. Administrators can still navigate anywhere inside the permitted WordPress root.</p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nobash-files-setup-form"><input type="hidden" name="action" value="nobash_files_onboarding_save"><input type="hidden" name="nobash_files_step" value="3"><?php wp_nonce_field( 'nobash_files_onboarding_save' ); ?>
                        <div class="nobash-files-workspace-options"><?php $selected = self::default_workspace(); foreach ( self::workspace_choices() as $value => $meta ) : ?><label class="nobash-files-workspace-option"><input type="radio" name="default_workspace" value="<?php echo esc_attr( $value ); ?>" <?php checked( $selected, $value ); ?>><span class="dashicons <?php echo '' === $value ? 'dashicons-admin-site-alt3' : ( str_contains( $value, 'uploads' ) ? 'dashicons-format-image' : 'dashicons-category' ); ?>"></span><div><strong><?php echo esc_html( $meta['label'] ); ?></strong><p><?php echo esc_html( $meta['description'] ); ?></p><code>/<?php echo esc_html( $value ); ?></code></div><span class="nobash-files-radio-check dashicons dashicons-yes"></span></label><?php endforeach; ?></div>
                        <div class="nobash-files-setup-actions"><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=nobash-files-setup&step=2' ) ); ?>">Back</a><button class="button button-primary" type="submit">Finish setup <span class="dashicons dashicons-yes"></span></button></div>
                    </form>
                <?php else : ?>
                    <div class="nobash-files-setup-success"><span class="dashicons dashicons-yes-alt"></span></div><span class="nobash-files-eyebrow">Setup complete</span><h1>Your file workspace is ready.</h1><p class="nobash-files-setup-lead">Safe Mode is <?php echo '1' === get_option( 'nobash_files_safe_mode', '1' ) ? '<strong>on</strong>' : '<strong>off</strong>'; ?>. Your starting workspace is <code>/<?php echo esc_html( self::default_workspace() ); ?></code>.</p>
                    <div class="nobash-files-setup-ready-grid"><article><span class="dashicons dashicons-open-folder"></span><strong>File Explorer</strong><p>Upload, organize, preview, archive and recover files.</p></article><article><span class="dashicons dashicons-editor-code"></span><strong>Code Editor</strong><p>Multi-tab editing with validation, diff and version recovery.</p></article><article><span class="dashicons dashicons-shield"></span><strong>Core Integrity</strong><p>Compare WordPress core files with official checksums.</p></article><article><span class="dashicons dashicons-star-filled"></span><strong>All tools included</strong><p>Monitoring, security, access and agency tools are available.</p></article></div>
                    <div class="nobash-files-setup-actions is-centered"><a class="button button-primary button-hero" href="<?php echo esc_url( admin_url( 'admin.php?page=nobash-files' ) ); ?>">Open File Manager <span class="dashicons dashicons-arrow-right-alt2"></span></a><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=nobash-files-editor' ) ); ?>">Open Code Editor</a></div>
                <?php endif; ?>
                </section>
                <footer class="nobash-files-setup-footer"><span><span class="dashicons dashicons-lock"></span> No account required for local file management</span><span>Nobash Files <?php echo esc_html( NOBASH_FILES_VERSION ); ?></span></footer>
            </div>
        </div>
        <?php
    }
}
