<?php
/**
 * Plugin Name:       Makasete SEO
 * Plugin URI:        https://makasete.app
 * Description:       Connects your WordPress site to the Makasete AI SEO automation platform. Enables automated creation, editing, and management of posts, categories, tags, and media.
 * Version:           1.8.2
 * Author:            Makasete
 * License:           GPL-2.0+
 * Text Domain:       makasete-seo
 * Domain Path:       /languages
 * Requires at least: 5.6
 * Requires PHP:      8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MAKASETE_VERSION', '1.8.2' );
define( 'MAKASETE_PLUGIN_FILE', __FILE__ );
define( 'MAKASETE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MAKASETE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once MAKASETE_PLUGIN_DIR . 'includes/class-rest-controller.php';

/**
 * Initialize the REST API controller.
 */
function makasete_init() {
    $controller = new Makasete_REST_Controller();
    $controller->register_routes();
}
add_action( 'rest_api_init', 'makasete_init' );

/**
 * Load plugin textdomain for translations.
 */
function makasete_load_textdomain() {
    load_plugin_textdomain(
        'makasete-seo',
        false,
        dirname( MAKASETE_PLUGIN_BASENAME ) . '/languages'
    );
}
add_action( 'init', 'makasete_load_textdomain' );

/**
 * Whitelist Makasete endpoints in common JWT Authentication plugins.
 * This prevents them from erroneously trying to parse our Basic Auth Application Password as a JWT.
 * Patterns are derived from Makasete_REST_Controller::REST_NAMESPACE so renaming
 * the namespace updates both places at once.
 */
function makasete_jwt_whitelist( $endpoints ) {
    $ns          = Makasete_REST_Controller::REST_NAMESPACE;
    $endpoints[] = '/wp-json/' . $ns . '/*';
    $endpoints[] = '/?rest_route=/' . $ns . '/*';
    return $endpoints;
}
add_filter( 'jwt_auth_whitelist', 'makasete_jwt_whitelist' );
add_filter( 'jwt_auth_white_list', 'makasete_jwt_whitelist' );

/**
 * Bypass blanket REST API restrictions for Makasete endpoints.
 *
 * Many security plugins (Wordfence, iThemes Security, SiteGuard WP, etc.)
 * and managed-host policies hook into ``rest_authentication_errors`` to
 * block unauthenticated REST requests wholesale. The block fires before
 * a route's ``permission_callback`` runs, so even a valid Application
 * Password never gets a chance to authorize the call — and some blockers
 * respond with the site's homepage HTML (or a redirect to ``/``) rather
 * than a JSON error, which the Makasete backend can't decode and surfaces
 * as ``WP REST API unreachable … non-JSON-200``.
 *
 * This filter clears that blanket block for ``/makasete/v1/*`` routes
 * only. We require ``is_user_logged_in()`` so we never *lower* auth: WP's
 * Application Password handler runs on ``determine_current_user`` (which
 * fires before this filter), so a valid Basic-Auth header has already
 * promoted the user by the time we get here. If the caller wasn't
 * authenticated, the original error is returned untouched. Each route
 * still runs its own ``permission_callback``, which checks the matching
 * WP capability (``upload_files``, ``edit_posts``, ``manage_categories``,
 * etc.).
 *
 * Priority 999 so we run after the security plugin has set its error.
 */
function makasete_bypass_rest_blockers( $result ) {
    if ( ! is_wp_error( $result ) ) {
        return $result;
    }

    $ns           = Makasete_REST_Controller::REST_NAMESPACE;
    $is_makasete  = false;

    $request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
    $path         = strtok( $request_uri, '?' );
    if ( $path && strpos( $path, '/' . rest_get_url_prefix() . '/' . $ns . '/' ) !== false ) {
        $is_makasete = true;
    }

    if ( ! $is_makasete && isset( $_GET['rest_route'] ) ) {
        $rest_route = (string) $_GET['rest_route'];
        if ( strpos( $rest_route, '/' . $ns . '/' ) === 0 ) {
            $is_makasete = true;
        }
    }

    if ( ! $is_makasete ) {
        return $result;
    }

    return is_user_logged_in() ? true : $result;
}
add_filter( 'rest_authentication_errors', 'makasete_bypass_rest_blockers', 999 );

/**
 * Ensure WebP is in WordPress's ``upload_mimes`` allow-list.
 *
 * Core added ``image/webp`` in 5.8 (May 2021), but security plugins,
 * themes, and host policies still routinely filter it back out. The
 * Makasete pipeline produces WebP eye-catch images, and
 * ``wp_check_filetype_and_ext()`` consults this list before our upload
 * handler ever sees the file — so a missing entry here turns into a
 * 400 ``invalid_mime`` even though the bytes are a valid WebP.
 */
function makasete_allow_webp_uploads( $mimes ) {
    if ( ! isset( $mimes['webp'] ) ) {
        $mimes['webp'] = 'image/webp';
    }
    return $mimes;
}
add_filter( 'upload_mimes', 'makasete_allow_webp_uploads' );

/**
 * Add a settings page so users can verify the connection from WP admin.
 */
function makasete_add_admin_menu() {
    add_options_page(
        __( 'Makasete SEO', 'makasete-seo' ),
        __( 'Makasete SEO', 'makasete-seo' ),
        'manage_options',
        'makasete-seo',
        'makasete_settings_page'
    );
}
add_action( 'admin_menu', 'makasete_add_admin_menu' );

function makasete_settings_page() {
    $site_url = get_site_url();
    $rest_url = rest_url( Makasete_REST_Controller::REST_NAMESPACE . '/status' );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Makasete SEO', 'makasete-seo' ); ?></h1>
        <div class="makasete-card">
            <h2 id="makasete-status-heading">
                <span id="makasete-status-indicator" class="makasete-pill makasete-pill--pending"><?php esc_html_e( 'Checking…', 'makasete-seo' ); ?></span>
                <span id="makasete-status-label"><?php esc_html_e( 'Plugin status', 'makasete-seo' ); ?></span>
            </h2>
            <p><?php esc_html_e( 'Your WordPress site is ready to connect to the Makasete platform.', 'makasete-seo' ); ?></p>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Plugin version', 'makasete-seo' ); ?></th>
                    <td><code><?php echo esc_html( MAKASETE_VERSION ); ?></code></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Site URL', 'makasete-seo' ); ?></th>
                    <td><code><?php echo esc_html( $site_url ); ?></code></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'API Endpoint', 'makasete-seo' ); ?></th>
                    <td><code><?php echo esc_html( $rest_url ); ?></code></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Live check', 'makasete-seo' ); ?></th>
                    <td><pre id="makasete-status-output" class="makasete-status-output"><?php esc_html_e( 'Contacting REST endpoint…', 'makasete-seo' ); ?></pre></td>
                </tr>
            </table>
            <hr>
            <h3><?php esc_html_e( 'Authentication Setup', 'makasete-seo' ); ?></h3>
            <p><?php echo wp_kses_post( __( 'To connect this site to Makasete, you need a <strong>WordPress Application Password</strong>:', 'makasete-seo' ) ); ?></p>
            <ol>
                <li><?php echo wp_kses_post( __( 'Go to <strong>Users → Profile</strong> in the WordPress admin.', 'makasete-seo' ) ); ?></li>
                <li><?php echo wp_kses_post( __( 'Scroll to the <strong>Application Passwords</strong> section.', 'makasete-seo' ) ); ?></li>
                <li><?php echo wp_kses_post( __( 'Enter <code>Makasete</code> as the application name and click <strong>Add New Application Password</strong>.', 'makasete-seo' ) ); ?></li>
                <li><?php esc_html_e( 'Copy the generated password and paste it into the Makasete dashboard when connecting this site.', 'makasete-seo' ); ?></li>
            </ol>
            <p><a href="<?php echo esc_url( admin_url( 'profile.php#application-passwords-section' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Go to Application Passwords →', 'makasete-seo' ); ?></a></p>
        </div>
    </div>
    <?php
}

/**
 * Enqueue admin CSS/JS only on the plugin's settings page. The REST endpoint,
 * nonce, and translated pill labels are passed to JS via wp_localize_script so
 * the script itself stays static and cacheable.
 */
function makasete_enqueue_admin_assets( $hook ) {
    if ( $hook !== 'settings_page_makasete-seo' ) {
        return;
    }
    wp_enqueue_style(
        'makasete-admin',
        plugins_url( 'assets/admin.css', MAKASETE_PLUGIN_FILE ),
        [],
        MAKASETE_VERSION
    );
    wp_enqueue_script(
        'makasete-admin',
        plugins_url( 'assets/admin.js', MAKASETE_PLUGIN_FILE ),
        [],
        MAKASETE_VERSION,
        true
    );
    wp_localize_script( 'makasete-admin', 'makaseteAdmin', [
        'endpoint' => rest_url( Makasete_REST_Controller::REST_NAMESPACE . '/status' ),
        'nonce'    => wp_create_nonce( 'wp_rest' ),
        'labels'   => [
            'connected'   => __( 'Connected', 'makasete-seo' ),
            'error'       => __( 'Error', 'makasete-seo' ),
            'unreachable' => __( 'Unreachable', 'makasete-seo' ),
        ],
    ] );
}
add_action( 'admin_enqueue_scripts', 'makasete_enqueue_admin_assets' );
