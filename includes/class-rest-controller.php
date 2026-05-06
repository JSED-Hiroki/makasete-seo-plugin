<?php
/**
 * Makasete REST Controller
 *
 * Registers and handles all /wp-json/makasete/v1/ endpoints.
 * Authentication is handled by WordPress Application Passwords (Basic Auth).
 * Each endpoint enforces the WP capability that matches the real-world action.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Makasete_REST_Controller {

    const REST_NAMESPACE = 'makasete/v1';

    /**
     * Statuses the plugin accepts for post_status.
     */
    const ALLOWED_STATUSES = [ 'publish', 'draft', 'future', 'pending', 'private' ];

    /**
     * Statuses that require the `publish_posts` capability. Used by both the
     * create and update permission callbacks so "who can publish" is answered
     * in one place.
     */
    const PUBLISH_TIER_STATUSES = [ 'publish', 'future', 'private' ];

    /**
     * Mime types accepted for media uploads.
     */
    const ALLOWED_IMAGE_MIMES = [ 'image/png', 'image/jpeg', 'image/webp', 'image/gif' ];

    /**
     * Default maximum size (in bytes) for server-to-server media downloads.
     * Probed via a HEAD request before the full GET so oversize payloads don't
     * waste disk or memory, and re-checked after download as a belt-and-braces
     * guard for servers that don't honor HEAD or omit Content-Length.
     *
     * Site owners can override at runtime by defining
     * `MAKASETE_REST_MAX_DOWNLOAD_BYTES` in wp-config.php. Reads go through
     * `max_download_bytes()` below so the override is honored everywhere.
     */
    const MAX_DOWNLOAD_BYTES = 20 * 1024 * 1024; // 20 MB

    /**
     * Effective download cap: the `MAKASETE_REST_MAX_DOWNLOAD_BYTES` constant if
     * defined and a positive int, otherwise the class default.
     */
    private function max_download_bytes(): int {
        if ( defined( 'MAKASETE_REST_MAX_DOWNLOAD_BYTES' ) ) {
            $override = (int) constant( 'MAKASETE_REST_MAX_DOWNLOAD_BYTES' );
            if ( $override > 0 ) {
                return $override;
            }
        }
        return self::MAX_DOWNLOAD_BYTES;
    }

    /**
     * Pick a filename for a URL sideload. Prefers the caller-supplied name; falls
     * back to the URL's basename; if that resolves to empty (e.g. `https://host/`
     * with no path segment) synthesizes an `image-<timestamp>` name so
     * `media_handle_sideload` has something to work with.
     */
    private function derive_upload_filename( string $url, string $requested ): string {
        $candidate = sanitize_file_name( $requested );
        if ( $candidate !== '' ) {
            return $candidate;
        }
        $path      = (string) wp_parse_url( $url, PHP_URL_PATH );
        $candidate = sanitize_file_name( basename( $path ) );
        if ( $candidate !== '' ) {
            return $candidate;
        }
        return 'image-' . time();
    }

    /**
     * Detect an uploaded file's MIME type with fallbacks.
     *
     * `wp_check_filetype_and_ext()` is the right first check on a healthy
     * WP install, but it returns an empty type whenever the extension
     * isn't in `get_allowed_mime_types()` — e.g. WP < 5.8 with no
     * `image/webp` entry, or a security plugin that strips WebP. Hosts
     * without the `fileinfo` PHP extension also lose the
     * `mime_content_type()` fallback that core normally uses.
     *
     * Strategy mirrors the Soro connector's approach: try WP's check
     * first, then fileinfo, then magic bytes (the only layer immune to
     * PHP-extension and WP-allow-list misconfiguration), then the
     * filename extension as a last resort. Returns '' when no layer
     * can identify the file; the caller rejects with `invalid_mime`.
     */
    private function detect_image_mime_type( string $tmp_name, string $filename ): string {
        $type = wp_check_filetype_and_ext( $tmp_name, $filename );
        $mime = (string) ( $type['type'] ?? '' );
        if ( $mime !== '' ) {
            return $mime;
        }

        if ( function_exists( 'finfo_open' ) ) {
            $finfo = finfo_open( FILEINFO_MIME_TYPE );
            if ( $finfo ) {
                $detected = finfo_file( $finfo, $tmp_name );
                finfo_close( $finfo );
                if ( is_string( $detected ) && $detected !== '' ) {
                    return $detected;
                }
            }
        }
        if ( function_exists( 'mime_content_type' ) ) {
            $detected = @mime_content_type( $tmp_name );
            if ( is_string( $detected ) && $detected !== '' ) {
                return $detected;
            }
        }

        $handle = @fopen( $tmp_name, 'rb' );
        if ( $handle ) {
            $bytes = (string) fread( $handle, 12 );
            fclose( $handle );
            if ( strncmp( $bytes, "\xFF\xD8\xFF", 3 ) === 0 ) {
                return 'image/jpeg';
            }
            if ( strncmp( $bytes, "\x89PNG\r\n\x1A\n", 8 ) === 0 ) {
                return 'image/png';
            }
            if ( strncmp( $bytes, 'GIF87a', 6 ) === 0 || strncmp( $bytes, 'GIF89a', 6 ) === 0 ) {
                return 'image/gif';
            }
            if ( strncmp( $bytes, 'RIFF', 4 ) === 0 && substr( $bytes, 8, 4 ) === 'WEBP' ) {
                return 'image/webp';
            }
        }

        $ext = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
        $ext_to_mime = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
        ];
        return $ext_to_mime[ $ext ] ?? '';
    }

    /**
     * Per-request memo for detect_seo_plugin() / detect_multilingual_plugin().
     * List responses call format_post() in a loop and each call would otherwise
     * re-run the defined()/class_exists() probes. Reset implicitly every
     * request because the controller is re-instantiated in makasete_init().
     */
    private ?string $seo_plugin_cache = null;
    private bool    $seo_plugin_cached = false;
    private ?string $ml_plugin_cache  = null;
    private bool    $ml_plugin_cached = false;
    private ?array  $allowed_meta_keys_cache = null;
    private ?array  $allowed_post_types_cache = null;

    /**
     * Register all REST routes.
     */
    public function register_routes() {

        // ── Status ──────────────────────────────────────────────────────────
        register_rest_route( self::REST_NAMESPACE, '/status', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_status' ],
            'permission_callback' => [ $this, 'can_read' ],
        ] );

        // ── Posts ────────────────────────────────────────────────────────────
        register_rest_route( self::REST_NAMESPACE, '/posts', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_posts' ],
                'permission_callback' => [ $this, 'can_read' ],
                'args'                => [
                    'per_page'        => [ 'default' => 100, 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'sanitize_callback' => 'absint' ],
                    'page'            => [ 'default' => 1,   'type' => 'integer', 'minimum' => 1, 'sanitize_callback' => 'absint' ],
                    'search'          => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                    'post_type'       => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
                    'status'          => [ 'type' => 'array', 'items' => [ 'type' => 'string', 'enum' => self::ALLOWED_STATUSES ] ],
                    'after'           => [ 'type' => 'string', 'validate_callback' => [ $this, 'validate_iso8601' ] ],
                    'before'          => [ 'type' => 'string', 'validate_callback' => [ $this, 'validate_iso8601' ] ],
                    'modified_after'  => [ 'type' => 'string', 'validate_callback' => [ $this, 'validate_iso8601' ] ],
                    'modified_before' => [ 'type' => 'string', 'validate_callback' => [ $this, 'validate_iso8601' ] ],
                    'category_id'     => [ 'type' => 'integer', 'minimum' => 1, 'sanitize_callback' => 'absint' ],
                    'tag_id'          => [ 'type' => 'integer', 'minimum' => 1, 'sanitize_callback' => 'absint' ],
                    'author_id'       => [ 'type' => 'integer', 'minimum' => 1, 'sanitize_callback' => 'absint' ],
                    'orderby'         => [ 'type' => 'string', 'enum' => [ 'date', 'modified', 'title', 'id' ], 'default' => 'date' ],
                    'order'           => [ 'type' => 'string', 'enum' => [ 'ASC', 'DESC', 'asc', 'desc' ], 'default' => 'DESC' ],
                ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'create_post' ],
                'permission_callback' => [ $this, 'can_create_post' ],
                'args'                => $this->post_write_args(),
            ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/posts/(?P<id>[\d]+)/revisions', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_post_revisions' ],
            'permission_callback' => [ $this, 'can_read_post' ],
            'args'                => [
                'per_page' => [ 'default' => 50, 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'sanitize_callback' => 'absint' ],
                'page'     => [ 'default' => 1,  'type' => 'integer', 'minimum' => 1, 'sanitize_callback' => 'absint' ],
            ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/posts/(?P<id>[\d]+)/restore', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'restore_post' ],
            'permission_callback' => [ $this, 'can_edit_post' ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/posts/(?P<id>[\d]+)/sticky', [
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => [ $this, 'set_sticky' ],
            'permission_callback' => [ $this, 'can_edit_post' ],
            'args'                => [
                'sticky' => [ 'required' => true, 'type' => 'boolean' ],
            ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/posts/(?P<id>[\d]+)/duplicate', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'duplicate_post' ],
            'permission_callback' => [ $this, 'can_duplicate_post' ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/posts/(?P<id>[\d]+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_post' ],
                'permission_callback' => [ $this, 'can_read_post' ],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'update_post' ],
                'permission_callback' => [ $this, 'can_edit_post' ],
                'args'                => $this->post_write_args(),
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'delete_post' ],
                'permission_callback' => [ $this, 'can_delete_post' ],
                'args'                => [
                    'force' => [ 'default' => false, 'type' => 'boolean' ],
                ],
            ],
        ] );

        // ── Users ────────────────────────────────────────────────────────────
        register_rest_route( self::REST_NAMESPACE, '/users', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_users' ],
            'permission_callback' => [ $this, 'can_list_users' ],
            'args'                => [
                'per_page' => [ 'default' => 50, 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'sanitize_callback' => 'absint' ],
                'page'     => [ 'default' => 1,  'type' => 'integer', 'minimum' => 1, 'sanitize_callback' => 'absint' ],
                'search'   => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'role'     => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
            ],
        ] );

        // ── Categories ───────────────────────────────────────────────────────
        register_rest_route( self::REST_NAMESPACE, '/categories', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_categories' ],
                'permission_callback' => [ $this, 'can_read' ],
                'args'                => $this->term_list_args(),
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'create_category' ],
                'permission_callback' => [ $this, 'can_manage_categories' ],
                'args'                => [
                    'name'        => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'validate_callback' => [ $this, 'validate_non_empty_string' ] ],
                    'slug'        => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_title' ],
                    'parent_id'   => [ 'type' => 'integer', 'minimum' => 0, 'sanitize_callback' => 'absint' ],
                    'description' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ],
                ],
            ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/categories/(?P<id>[\d]+)', [
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'update_category' ],
                'permission_callback' => [ $this, 'can_manage_categories' ],
                'args'                => [
                    'name'        => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                    'slug'        => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_title' ],
                    'parent_id'   => [ 'type' => 'integer', 'minimum' => 0, 'sanitize_callback' => 'absint' ],
                    'description' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ],
                ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'delete_category' ],
                'permission_callback' => [ $this, 'can_manage_categories' ],
                'args'                => [
                    'reassign' => [ 'type' => 'integer', 'minimum' => 0, 'sanitize_callback' => 'absint' ],
                ],
            ],
        ] );

        // ── Tags ─────────────────────────────────────────────────────────────
        register_rest_route( self::REST_NAMESPACE, '/tags', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_tags' ],
                'permission_callback' => [ $this, 'can_read' ],
                'args'                => $this->term_list_args(),
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'create_tag' ],
                'permission_callback' => [ $this, 'can_manage_tags' ],
                'args'                => [
                    'name'        => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'validate_callback' => [ $this, 'validate_non_empty_string' ] ],
                    'slug'        => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_title' ],
                    'description' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ],
                ],
            ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/tags/(?P<id>[\d]+)', [
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'update_tag' ],
                'permission_callback' => [ $this, 'can_manage_tags' ],
                'args'                => [
                    'name'        => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                    'slug'        => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_title' ],
                    'description' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ],
                ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'delete_tag' ],
                'permission_callback' => [ $this, 'can_manage_tags' ],
            ],
        ] );

        // ── Media ─────────────────────────────────────────────────────────────
        register_rest_route( self::REST_NAMESPACE, '/media/upload', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'upload_media' ],
            'permission_callback' => [ $this, 'can_upload' ],
            'args'                => [
                'alt'     => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'caption' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ],
            ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/media/upload-from-url', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'upload_media_from_url' ],
            'permission_callback' => [ $this, 'can_upload' ],
            'args'                => [
                'url'      => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'validate_callback' => [ $this, 'validate_http_url' ] ],
                'filename' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_file_name' ],
                'alt'      => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'caption'  => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ],
            ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/media', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'list_media' ],
            'permission_callback' => [ $this, 'can_read' ],
            'args'                => [
                'per_page'  => [ 'default' => 50, 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'sanitize_callback' => 'absint' ],
                'page'      => [ 'default' => 1,  'type' => 'integer', 'minimum' => 1, 'sanitize_callback' => 'absint' ],
                'search'    => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'mime_type' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/media/(?P<id>[\d]+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_media' ],
                'permission_callback' => [ $this, 'can_read' ],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'update_media' ],
                'permission_callback' => [ $this, 'can_edit_media' ],
                'args'                => [
                    'alt'         => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                    'caption'     => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ],
                    'description' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ],
                    'title'       => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'delete_media' ],
                'permission_callback' => [ $this, 'can_delete_media' ],
                'args'                => [
                    'force' => [ 'default' => true, 'type' => 'boolean' ],
                ],
            ],
        ] );
    }

    // ── Permission callbacks ─────────────────────────────────────────────────

    public function can_read( WP_REST_Request $request ) {
        return current_user_can( 'edit_posts' )
            ? true
            : $this->forbidden();
    }

    public function can_read_post( WP_REST_Request $request ) {
        $id = (int) $request->get_param( 'id' );
        // `read_post` is a meta-cap that already maps to `read_private_posts`
        // for private posts and `edit_post` for drafts. Relying on it alone is
        // capability-correct; the previous `edit_posts` fallback let
        // Contributors fetch private posts they had no right to read.
        return current_user_can( 'read_post', $id ) ? true : $this->forbidden();
    }

    public function can_create_post( WP_REST_Request $request ) {
        if ( ! current_user_can( 'edit_posts' ) ) {
            return $this->forbidden();
        }
        $needs_publish = in_array( $request->get_param( 'status' ), self::PUBLISH_TIER_STATUSES, true )
            || ! empty( $request->get_param( 'publish_at' ) );
        if ( $needs_publish && ! current_user_can( 'publish_posts' ) ) {
            return $this->forbidden( __( 'You are not allowed to publish posts.', 'makasete-seo' ) );
        }
        return true;
    }

    public function can_edit_post( WP_REST_Request $request ) {
        $id = (int) $request->get_param( 'id' );
        if ( ! current_user_can( 'edit_post', $id ) ) {
            return $this->forbidden();
        }
        $status = $request->get_param( 'status' );
        if ( in_array( $status, self::PUBLISH_TIER_STATUSES, true ) && ! current_user_can( 'publish_posts' ) ) {
            return $this->forbidden( __( 'You are not allowed to publish posts.', 'makasete-seo' ) );
        }
        return true;
    }

    public function can_delete_post( WP_REST_Request $request ) {
        $id = (int) $request->get_param( 'id' );
        return current_user_can( 'delete_post', $id ) ? true : $this->forbidden();
    }

    public function can_manage_categories( WP_REST_Request $request ) {
        return current_user_can( 'manage_categories' ) ? true : $this->forbidden();
    }

    public function can_manage_tags( WP_REST_Request $request ) {
        // Core ties tag management to either `manage_post_tags` (explicit) or
        // `manage_categories` (the role-map that Editors actually ship with).
        // Accept either so Editor accounts aren't blocked from tag CRUD.
        if ( current_user_can( 'manage_post_tags' ) || current_user_can( 'manage_categories' ) ) {
            return true;
        }
        return $this->forbidden();
    }

    public function can_upload( WP_REST_Request $request ) {
        return current_user_can( 'upload_files' ) ? true : $this->forbidden();
    }

    public function can_delete_media( WP_REST_Request $request ) {
        $id   = (int) $request->get_param( 'id' );
        $post = get_post( $id );
        if ( ! $post || $post->post_type !== 'attachment' ) {
            return new WP_Error( 'not_found', __( 'Attachment not found', 'makasete-seo' ), [ 'status' => 404 ] );
        }
        return current_user_can( 'delete_post', $id ) ? true : $this->forbidden();
    }

    public function can_edit_media( WP_REST_Request $request ) {
        $id   = (int) $request->get_param( 'id' );
        $post = get_post( $id );
        if ( ! $post || $post->post_type !== 'attachment' ) {
            return new WP_Error( 'not_found', __( 'Attachment not found', 'makasete-seo' ), [ 'status' => 404 ] );
        }
        return current_user_can( 'edit_post', $id ) ? true : $this->forbidden();
    }

    public function can_list_users( WP_REST_Request $request ) {
        return current_user_can( 'list_users' ) ? true : $this->forbidden();
    }

    public function can_duplicate_post( WP_REST_Request $request ) {
        $id = (int) $request->get_param( 'id' );
        return current_user_can( 'edit_post', $id ) ? true : $this->forbidden();
    }

    private function forbidden( string $message = '' ): WP_Error {
        return new WP_Error(
            'rest_forbidden',
            $message !== '' ? $message : __( 'You do not have permission to perform this action.', 'makasete-seo' ),
            [ 'status' => rest_authorization_required_code() ]
        );
    }

    // ── Validators ───────────────────────────────────────────────────────────

    public function validate_non_empty_string( $value ): bool {
        return is_string( $value ) && trim( $value ) !== '';
    }

    public function validate_http_url( $value ): bool {
        if ( ! is_string( $value ) || $value === '' ) {
            return false;
        }
        $parts = wp_parse_url( $value );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return false;
        }
        if ( ! in_array( $parts['scheme'] ?? '', [ 'http', 'https' ], true ) ) {
            return false;
        }
        if ( ! wp_http_validate_url( $value ) ) {
            return false;
        }
        // Reject IP-literal hosts on private / reserved / loopback ranges to
        // blunt server-side request forgery via `download_url()`.
        if ( filter_var( $parts['host'], FILTER_VALIDATE_IP ) ) {
            $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
            if ( ! filter_var( $parts['host'], FILTER_VALIDATE_IP, $flags ) ) {
                return false;
            }
        }
        return true;
    }

    // ── Shared arg schemas ───────────────────────────────────────────────────

    private function post_write_args(): array {
        // NOTE: schema-level `sanitize_callback` only fires for values read via
        // $request->get_param(). The handlers take the raw JSON body with
        // get_json_params() and re-sanitize inside apply_post_params_to_data(),
        // so this schema documents types/enums only — sanitization lives in
        // the handler to stay honest about what actually runs.
        return [
            'post_type'         => [ 'type' => 'string' ],
            'title'             => [ 'type' => 'string' ],
            'content'           => [ 'type' => 'string' ],
            'excerpt'           => [ 'type' => 'string' ],
            'slug'              => [ 'type' => 'string' ],
            'status'            => [ 'type' => 'string', 'enum' => self::ALLOWED_STATUSES ],
            'publish_at'        => [ 'type' => 'string', 'validate_callback' => [ $this, 'validate_iso8601' ] ],
            'author_id'         => [ 'type' => 'integer', 'minimum' => 1 ],
            'category_ids'      => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
            'tag_ids'           => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
            'featured_image_id' => [ 'type' => 'integer', 'minimum' => 0 ],
            'meta_description'  => [ 'type' => 'string' ],
            'seo_title'         => [ 'type' => 'string' ],
            'language'          => [ 'type' => 'string' ],
            'comment_status'    => [ 'type' => 'string', 'enum' => [ 'open', 'closed' ] ],
            'ping_status'       => [ 'type' => 'string', 'enum' => [ 'open', 'closed' ] ],
            'sticky'            => [ 'type' => 'boolean' ],
            'meta'              => [ 'type' => 'object' ],
        ];
    }

    /**
     * Query args accepted by the paged term list endpoints (categories/tags).
     */
    private function term_list_args(): array {
        return [
            'per_page' => [ 'default' => 100, 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'sanitize_callback' => 'absint' ],
            'page'     => [ 'default' => 1,   'type' => 'integer', 'minimum' => 1, 'sanitize_callback' => 'absint' ],
            'search'   => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
        ];
    }

    /**
     * Meta keys the REST API is allowed to read/write on posts. Defaults to [] —
     * site owners opt in via filter, e.g. in functions.php:
     *   add_filter( 'makasete_allowed_meta_keys', fn() => [ '_my_theme_cta_label' ] );
     * Keys starting with `_makasete_` or `_yoast_wpseo_` / `rank_math_` are handled
     * by dedicated fields (meta_description, seo_title) and are not writable here.
     */
    private function allowed_meta_keys(): array {
        if ( $this->allowed_meta_keys_cache !== null ) {
            return $this->allowed_meta_keys_cache;
        }
        $keys = apply_filters( 'makasete_allowed_meta_keys', [] );
        if ( ! is_array( $keys ) ) {
            return $this->allowed_meta_keys_cache = [];
        }
        return $this->allowed_meta_keys_cache = array_values( array_unique( array_filter( array_map( 'strval', $keys ) ) ) );
    }

    /**
     * Persist any allow-listed custom meta from the write payload. Scalars only
     * (strings, numbers, booleans); non-scalar values are silently skipped to
     * avoid storing serialized user input.
     */
    private function write_custom_meta( int $post_id, array $meta ): void {
        $allowed = $this->allowed_meta_keys();
        if ( empty( $allowed ) ) {
            return;
        }
        foreach ( $meta as $key => $value ) {
            if ( ! in_array( $key, $allowed, true ) ) {
                continue;
            }
            if ( $value === null ) {
                delete_post_meta( $post_id, $key );
                continue;
            }
            if ( ! is_scalar( $value ) ) {
                continue;
            }
            update_post_meta( $post_id, $key, is_string( $value ) ? sanitize_text_field( $value ) : $value );
        }
    }

    private function read_custom_meta( int $post_id ): array {
        $allowed = $this->allowed_meta_keys();
        if ( empty( $allowed ) ) {
            return [];
        }
        $out = [];
        foreach ( $allowed as $key ) {
            $out[ $key ] = get_post_meta( $post_id, $key, true );
        }
        return $out;
    }

    public function validate_iso8601( $value ): bool {
        if ( ! is_string( $value ) || $value === '' ) {
            return false;
        }
        // Accept `YYYY-MM-DD` or a full ISO8601 datetime. The loose version
        // was passing `"tomorrow"`, `"next monday"`, etc. via strtotime.
        $pattern = '/^(\d{4})-(\d{2})-(\d{2})([T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?)?$/';
        if ( ! preg_match( $pattern, $value, $m ) ) {
            return false;
        }
        // Reject impossible calendar dates (e.g. 2023-02-30) that strtotime
        // would silently roll over into the next month.
        if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
            return false;
        }
        return strtotime( $value ) !== false;
    }

    // ── Post type & SEO plugin helpers ───────────────────────────────────────

    /**
     * Post types the plugin is allowed to touch. Defaults to ['post'] and can be
     * extended by filter — e.g. in functions.php:
     *   add_filter( 'makasete_allowed_post_types', fn( $types ) => array_merge( $types, [ 'landing_page' ] ) );
     */
    private function allowed_post_types(): array {
        if ( $this->allowed_post_types_cache !== null ) {
            return $this->allowed_post_types_cache;
        }
        $types = apply_filters( 'makasete_allowed_post_types', [ 'post' ] );
        if ( ! is_array( $types ) || empty( $types ) ) {
            $types = [ 'post' ];
        }
        return $this->allowed_post_types_cache = array_values( array_unique( array_map( 'sanitize_key', $types ) ) );
    }

    private function resolve_post_type( ?string $requested ): string {
        $allowed = $this->allowed_post_types();
        if ( $requested && in_array( $requested, $allowed, true ) ) {
            return $requested;
        }
        return $allowed[0];
    }

    private function is_allowed_post_type( string $post_type ): bool {
        return in_array( $post_type, $this->allowed_post_types(), true );
    }

    /**
     * Return a short name for the detected SEO plugin, or null if none is active.
     * Memoized per request — list endpoints call this once per post.
     */
    private function detect_seo_plugin(): ?string {
        if ( $this->seo_plugin_cached ) {
            return $this->seo_plugin_cache;
        }
        if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Meta', false ) ) {
            $this->seo_plugin_cache = 'yoast';
        } elseif ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath', false ) ) {
            $this->seo_plugin_cache = 'rankmath';
        } else {
            $this->seo_plugin_cache = null;
        }
        $this->seo_plugin_cached = true;
        return $this->seo_plugin_cache;
    }

    /**
     * Return a short name for the detected multilingual plugin, or null.
     * Memoized per request.
     */
    private function detect_multilingual_plugin(): ?string {
        if ( $this->ml_plugin_cached ) {
            return $this->ml_plugin_cache;
        }
        if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
            $this->ml_plugin_cache = 'wpml';
        } elseif ( defined( 'POLYLANG_VERSION' ) || function_exists( 'pll_get_post_language' ) ) {
            $this->ml_plugin_cache = 'polylang';
        } else {
            $this->ml_plugin_cache = null;
        }
        $this->ml_plugin_cached = true;
        return $this->ml_plugin_cache;
    }

    /**
     * Write SEO title / meta description to the detected SEO plugin's meta keys,
     * in addition to our own `_makasete_*` keys. Safe to call with empty values —
     * empty strings are not written so manual WP-admin edits are preserved.
     */
    private function sync_seo_meta( int $post_id, ?string $seo_title, ?string $meta_description ): void {
        $plugin = $this->detect_seo_plugin();
        if ( $plugin === null ) {
            return;
        }

        $title_keys = [
            'yoast'    => '_yoast_wpseo_title',
            'rankmath' => 'rank_math_title',
        ];
        $desc_keys = [
            'yoast'    => '_yoast_wpseo_metadesc',
            'rankmath' => 'rank_math_description',
        ];

        if ( is_string( $seo_title ) && $seo_title !== '' ) {
            update_post_meta( $post_id, $title_keys[ $plugin ], $seo_title );
        }
        if ( is_string( $meta_description ) && $meta_description !== '' ) {
            update_post_meta( $post_id, $desc_keys[ $plugin ], $meta_description );
        }
    }

    /**
     * Write the post's language via WPML or Polylang, if one is detected.
     */
    private function set_post_language( int $post_id, string $post_type, string $language ): void {
        $lang   = sanitize_key( $language );
        $plugin = $this->detect_multilingual_plugin();
        if ( $lang === '' || $plugin === null ) {
            return;
        }
        if ( $plugin === 'polylang' && function_exists( 'pll_set_post_language' ) ) {
            pll_set_post_language( $post_id, $lang );
        } elseif ( $plugin === 'wpml' ) {
            do_action( 'wpml_set_element_language_details', [
                'element_id'    => $post_id,
                'element_type'  => 'post_' . $post_type,
                'language_code' => $lang,
            ] );
        }
    }

    /**
     * Read current language + translations map for a post. Returns nulls / [] when
     * no multilingual plugin is installed so the response shape stays stable.
     */
    private function read_language_info( int $post_id ): array {
        $plugin = $this->detect_multilingual_plugin();
        if ( $plugin === 'polylang' ) {
            $language     = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $post_id ) : null;
            $translations = function_exists( 'pll_get_post_translations' ) ? (array) pll_get_post_translations( $post_id ) : [];
            return [
                'language'     => $language ?: null,
                'translations' => array_map( 'intval', $translations ),
            ];
        }
        if ( $plugin === 'wpml' ) {
            $details = apply_filters( 'wpml_post_language_details', null, $post_id );
            $language = is_array( $details ) && ! empty( $details['language_code'] )
                ? (string) $details['language_code']
                : null;

            $post_type    = get_post_type( $post_id ) ?: 'post';
            $trid         = apply_filters( 'wpml_element_trid', null, $post_id, 'post_' . $post_type );
            $raw          = $trid ? apply_filters( 'wpml_get_element_translations', null, $trid, 'post_' . $post_type ) : [];
            $translations = [];
            if ( is_array( $raw ) ) {
                foreach ( $raw as $code => $info ) {
                    if ( is_object( $info ) && isset( $info->element_id ) ) {
                        $translations[ (string) $code ] = (int) $info->element_id;
                    }
                }
            }
            return [
                'language'     => $language,
                'translations' => $translations,
            ];
        }
        return [ 'language' => null, 'translations' => [] ];
    }

    /**
     * Read SEO title / meta description preferring the active SEO plugin's keys,
     * falling back to our own. Keeps manual edits in the SEO plugin authoritative.
     */
    private function read_seo_meta( int $post_id ): array {
        $plugin = $this->detect_seo_plugin();
        $title  = '';
        $desc   = '';
        if ( $plugin === 'yoast' ) {
            $title = (string) get_post_meta( $post_id, '_yoast_wpseo_title', true );
            $desc  = (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
        } elseif ( $plugin === 'rankmath' ) {
            $title = (string) get_post_meta( $post_id, 'rank_math_title', true );
            $desc  = (string) get_post_meta( $post_id, 'rank_math_description', true );
        }
        if ( $title === '' ) {
            $title = (string) get_post_meta( $post_id, '_makasete_seo_title', true );
        }
        if ( $desc === '' ) {
            $desc = (string) get_post_meta( $post_id, '_makasete_meta_description', true );
        }
        return [ 'seo_title' => $title, 'meta_description' => $desc ];
    }

    // ── Shared post / term / media helpers ───────────────────────────────────

    /**
     * Return null if the author ID is absent/zero or resolves to a real user,
     * otherwise a WP_Error describing the failure. Keeps the author-validation
     * logic out of create_post / update_post.
     */
    private function validate_author_id( $raw ): ?WP_Error {
        $author_id = (int) $raw;
        if ( $author_id <= 0 ) {
            return null;
        }
        if ( ! get_userdata( $author_id ) ) {
            return new WP_Error(
                'invalid_author',
                __( 'Author does not exist.', 'makasete-seo' ),
                [ 'status' => 400 ]
            );
        }
        return null;
    }

    /**
     * Translate the write payload into a $post_data array suitable for
     * wp_insert_post / wp_update_post. Returns WP_Error on validation failure.
     * Fields that require an existing post ID (featured image, language,
     * sticky, custom meta) are handled by apply_post_side_effects() afterward.
     */
    private function apply_post_params_to_data( array $params, array &$post_data, bool $is_update ): ?WP_Error {
        if ( isset( $params['title'] ) ) {
            $post_data['post_title'] = sanitize_text_field( $params['title'] );
        }
        if ( isset( $params['content'] ) ) {
            $post_data['post_content'] = wp_kses_post( $params['content'] );
        }
        if ( isset( $params['excerpt'] ) ) {
            $post_data['post_excerpt'] = sanitize_textarea_field( $params['excerpt'] );
        }
        if ( isset( $params['slug'] ) ) {
            $post_data['post_name'] = sanitize_title( $params['slug'] );
        }
        if ( isset( $params['comment_status'] ) && in_array( $params['comment_status'], [ 'open', 'closed' ], true ) ) {
            $post_data['comment_status'] = $params['comment_status'];
        }
        if ( isset( $params['ping_status'] ) && in_array( $params['ping_status'], [ 'open', 'closed' ], true ) ) {
            $post_data['ping_status'] = $params['ping_status'];
        }
        if ( isset( $params['status'] ) ) {
            if ( ! in_array( $params['status'], self::ALLOWED_STATUSES, true ) ) {
                return new WP_Error(
                    'invalid_status',
                    sprintf( __( 'Invalid status. Allowed: %s', 'makasete-seo' ), implode( ', ', self::ALLOWED_STATUSES ) ),
                    [ 'status' => 400 ]
                );
            }
            $post_data['post_status'] = $params['status'];
        }
        if ( ! empty( $params['publish_at'] ) ) {
            // Defense in depth: the schema validate_callback also runs for
            // body-JSON fields, but we re-validate here so the handler is
            // self-contained and can't silently feed strtotime a loose string
            // if the schema is ever bypassed (e.g. custom middleware).
            if ( ! $this->validate_iso8601( $params['publish_at'] ) ) {
                return new WP_Error(
                    'invalid_publish_at',
                    __( 'publish_at must be a valid ISO 8601 date or datetime.', 'makasete-seo' ),
                    [ 'status' => 400 ]
                );
            }
            $post_data['post_date']     = get_date_from_gmt( $params['publish_at'], 'Y-m-d H:i:s' );
            $post_data['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', strtotime( $params['publish_at'] ) );
            // Only default to `future` when scheduling and the caller didn't
            // pass an explicit status. Before this the flag clobbered drafts
            // that just wanted a future post_date.
            if ( ! $is_update && ! isset( $params['status'] ) ) {
                $post_data['post_status'] = 'future';
            }
        }
        if ( isset( $params['author_id'] ) ) {
            if ( ! current_user_can( 'edit_others_posts' ) ) {
                return new WP_Error(
                    'rest_forbidden',
                    __( 'You are not allowed to reassign post authorship.', 'makasete-seo' ),
                    [ 'status' => rest_authorization_required_code() ]
                );
            }
            $err = $this->validate_author_id( $params['author_id'] );
            if ( $err ) {
                return $err;
            }
            $post_data['post_author'] = (int) $params['author_id'];
        }
        if ( isset( $params['category_ids'] ) ) {
            $post_data['post_category'] = array_map( 'intval', (array) $params['category_ids'] );
        }
        if ( isset( $params['tag_ids'] ) ) {
            $post_data['tags_input'] = array_map( 'intval', (array) $params['tag_ids'] );
        }
        if ( isset( $params['featured_image_id'] ) ) {
            $attachment_id = (int) $params['featured_image_id'];
            if ( $attachment_id !== 0 ) {
                $attachment = get_post( $attachment_id );
                if ( ! $attachment || $attachment->post_type !== 'attachment' ) {
                    return new WP_Error(
                        'attachment_not_found',
                        __( 'Featured image attachment does not exist.', 'makasete-seo' ),
                        [ 'status' => 400 ]
                    );
                }
                if ( ! wp_attachment_is_image( $attachment_id ) ) {
                    return new WP_Error(
                        'attachment_not_image',
                        __( 'Featured image attachment is not an image.', 'makasete-seo' ),
                        [ 'status' => 400 ]
                    );
                }
            }
        }
        if ( isset( $params['meta_description'] ) ) {
            $post_data['meta_input']['_makasete_meta_description'] = sanitize_textarea_field( $params['meta_description'] );
        }
        if ( isset( $params['seo_title'] ) ) {
            $post_data['meta_input']['_makasete_seo_title'] = sanitize_text_field( $params['seo_title'] );
        }
        return null;
    }

    /**
     * Apply the fields that need an existing post ID: featured image,
     * SEO-plugin mirror, language, sticky flag, and custom meta passthrough.
     *
     * `featured_image_id === 0` means "clear the thumbnail" on update; on a
     * fresh post there is nothing to clear, so the branch is skipped.
     * `apply_post_params_to_data()` has already validated the attachment, so
     * we don't re-check `wp_attachment_is_image()` here.
     */
    private function apply_post_side_effects( int $post_id, string $post_type, array $params, bool $is_update ): void {
        if ( isset( $params['featured_image_id'] ) ) {
            $attachment_id = (int) $params['featured_image_id'];
            if ( $attachment_id === 0 ) {
                if ( $is_update ) {
                    delete_post_thumbnail( $post_id );
                }
            } else {
                set_post_thumbnail( $post_id, $attachment_id );
            }
        }
        if ( isset( $params['seo_title'] ) || isset( $params['meta_description'] ) ) {
            $this->sync_seo_meta(
                $post_id,
                isset( $params['seo_title'] ) ? sanitize_text_field( $params['seo_title'] ) : null,
                isset( $params['meta_description'] ) ? sanitize_textarea_field( $params['meta_description'] ) : null
            );
        }
        if ( ! empty( $params['language'] ) ) {
            $this->set_post_language( $post_id, $post_type, (string) $params['language'] );
        }
        if ( isset( $params['sticky'] ) ) {
            if ( $params['sticky'] ) {
                stick_post( $post_id );
            } else {
                unstick_post( $post_id );
            }
        }
        if ( isset( $params['meta'] ) && is_array( $params['meta'] ) ) {
            $this->write_custom_meta( $post_id, $params['meta'] );
        }
    }

    /**
     * Paged list for a taxonomy. Used by both GET /categories and GET /tags —
     * without pagination these blow up on sites with thousands of tags.
     */
    private function list_terms( WP_REST_Request $request, string $taxonomy ): WP_REST_Response {
        $per_page = (int) $request->get_param( 'per_page' );
        $page     = (int) $request->get_param( 'page' );

        $args = [
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'number'     => $per_page,
            'offset'     => max( 0, ( $page - 1 ) * $per_page ),
            'orderby'    => 'name',
            'order'      => 'ASC',
        ];
        $search = $request->get_param( 'search' );
        if ( $search ) {
            $args['search'] = $search;
        }

        $terms = get_terms( $args );
        if ( is_wp_error( $terms ) ) {
            $terms = [];
        }
        // wp_count_terms argument shape changed in WP 6.0 — taxonomy moved
        // from a positional arg to the `taxonomy` key inside $args. Pre-6.0
        // still wants the positional form; 6.0+ emits a _doing_it_wrong
        // deprecation notice for it, so branch on version.
        $count_args = [ 'hide_empty' => false ];
        if ( $search ) {
            $count_args['search'] = $search;
        }
        if ( version_compare( get_bloginfo( 'version' ), '6.0', '>=' ) ) {
            $count_args['taxonomy'] = $taxonomy;
            $total                  = (int) wp_count_terms( $count_args );
        } else {
            $total = (int) wp_count_terms( $taxonomy, $count_args );
        }
        $total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 0;

        return rest_ensure_response( [
            'terms'       => array_map( [ $this, 'format_term' ], $terms ),
            'total'       => $total,
            'total_pages' => $total_pages,
        ] );
    }

    /**
     * Persist alt text on an attachment and return the shape both upload
     * handlers echo back to the client. Caption (post_excerpt) is passed to
     * media_handle_upload / media_handle_sideload via post_data so there's
     * no second wp_update_post after the attachment is created.
     */
    private function finalize_attachment( int $attachment_id, string $alt ): array {
        if ( $alt !== '' ) {
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
        }
        return [
            'attachment_id' => $attachment_id,
            'url'           => wp_get_attachment_url( $attachment_id ),
            'mime_type'     => get_post_mime_type( $attachment_id ),
        ];
    }

    // ── Status ────────────────────────────────────────────────────────────────

    public function get_status( WP_REST_Request $request ): WP_REST_Response {
        $theme = wp_get_theme();
        return rest_ensure_response( [
            'status'              => 'ok',
            'plugin'              => 'makasete-seo',
            'version'             => MAKASETE_VERSION,
            'site_url'            => get_site_url(),
            // Canonical REST namespace URL — pretty form when rewrite rules
            // are enabled, plain form otherwise. The Makasete backend caches
            // this on the Subscription row and uses it directly to skip its
            // URL-shape discovery loop on subsequent calls.
            'rest_base_url'       => esc_url_raw( rest_url( self::REST_NAMESPACE ) ),
            'site_name'           => get_bloginfo( 'name' ),
            'wp_version'          => get_bloginfo( 'version' ),
            'php_version'         => PHP_VERSION,
            'locale'              => get_locale(),
            'timezone'            => wp_timezone_string(),
            'theme_name'          => $theme ? (string) $theme->get( 'Name' ) : null,
            'user'                => wp_get_current_user()->user_login,
            'seo_plugin'          => $this->detect_seo_plugin(),
            'multilingual_plugin' => $this->detect_multilingual_plugin(),
            'allowed_post_types'  => $this->allowed_post_types(),
            'capabilities'        => [
                'edit_posts'         => current_user_can( 'edit_posts' ),
                'edit_others_posts'  => current_user_can( 'edit_others_posts' ),
                'publish_posts'      => current_user_can( 'publish_posts' ),
                'upload_files'       => current_user_can( 'upload_files' ),
                'manage_categories'  => current_user_can( 'manage_categories' ),
                'manage_post_tags'   => current_user_can( 'manage_post_tags' ),
            ],
        ] );
    }

    // ── Posts ─────────────────────────────────────────────────────────────────

    public function get_posts( WP_REST_Request $request ): WP_REST_Response {
        $requested_type = (string) ( $request->get_param( 'post_type' ) ?? '' );
        $post_type      = $this->resolve_post_type( $requested_type !== '' ? $requested_type : null );

        $status_param = $request->get_param( 'status' );
        $statuses     = is_array( $status_param ) && ! empty( $status_param )
            ? array_values( array_intersect( $status_param, self::ALLOWED_STATUSES ) )
            : self::ALLOWED_STATUSES;

        $orderby_map = [ 'date' => 'date', 'modified' => 'modified', 'title' => 'title', 'id' => 'ID' ];
        $orderby     = $orderby_map[ strtolower( (string) $request->get_param( 'orderby' ) ) ] ?? 'date';
        $order       = strtoupper( (string) $request->get_param( 'order' ) ) === 'ASC' ? 'ASC' : 'DESC';

        $args = [
            'post_type'      => $post_type,
            'post_status'    => $statuses,
            'posts_per_page' => (int) $request->get_param( 'per_page' ),
            'paged'          => (int) $request->get_param( 'page' ),
            'orderby'        => $orderby,
            'order'          => $order,
        ];

        $search = $request->get_param( 'search' );
        if ( $search ) {
            $args['s'] = $search;
        }

        $author_id = (int) $request->get_param( 'author_id' );
        if ( $author_id > 0 ) {
            $args['author'] = $author_id;
        }

        $category_id = (int) $request->get_param( 'category_id' );
        $tag_id      = (int) $request->get_param( 'tag_id' );
        $tax_query   = [];
        if ( $category_id > 0 ) {
            $tax_query[] = [ 'taxonomy' => 'category', 'field' => 'term_id', 'terms' => [ $category_id ] ];
        }
        if ( $tag_id > 0 ) {
            $tax_query[] = [ 'taxonomy' => 'post_tag', 'field' => 'term_id', 'terms' => [ $tag_id ] ];
        }
        if ( $tax_query ) {
            if ( count( $tax_query ) > 1 ) {
                $tax_query['relation'] = 'AND';
            }
            $args['tax_query'] = $tax_query;
        }

        $date_query = [];
        $after           = $request->get_param( 'after' );
        $before          = $request->get_param( 'before' );
        $modified_after  = $request->get_param( 'modified_after' );
        $modified_before = $request->get_param( 'modified_before' );
        if ( $after || $before ) {
            $entry = [ 'column' => 'post_date_gmt', 'inclusive' => true ];
            if ( $after ) {
                $entry['after'] = $after;
            }
            if ( $before ) {
                $entry['before'] = $before;
            }
            $date_query[] = $entry;
        }
        if ( $modified_after || $modified_before ) {
            $entry = [ 'column' => 'post_modified_gmt', 'inclusive' => true ];
            if ( $modified_after ) {
                $entry['after'] = $modified_after;
            }
            if ( $modified_before ) {
                $entry['before'] = $modified_before;
            }
            $date_query[] = $entry;
        }
        if ( $date_query ) {
            $args['date_query'] = $date_query;
        }

        $query = new WP_Query( $args );
        $posts = array_map( [ $this, 'format_post' ], $query->posts );

        return rest_ensure_response( [
            'posts'       => $posts,
            'total'       => (int) $query->found_posts,
            'total_pages' => (int) $query->max_num_pages,
        ] );
    }

    public function get_post( WP_REST_Request $request ) {
        $post_id = (int) $request->get_param( 'id' );
        $post    = get_post( $post_id );
        if ( ! $post || ! $this->is_allowed_post_type( $post->post_type ) ) {
            return new WP_Error( 'not_found', __( 'Post not found', 'makasete-seo' ), [ 'status' => 404 ] );
        }
        return rest_ensure_response( $this->format_post( $post ) );
    }

    public function get_post_revisions( WP_REST_Request $request ) {
        $post_id = (int) $request->get_param( 'id' );
        $post    = get_post( $post_id );
        if ( ! $post || ! $this->is_allowed_post_type( $post->post_type ) ) {
            return new WP_Error( 'not_found', __( 'Post not found', 'makasete-seo' ), [ 'status' => 404 ] );
        }

        $per_page = (int) $request->get_param( 'per_page' );
        $page     = (int) $request->get_param( 'page' );

        $revisions = wp_get_post_revisions( $post_id, [
            'orderby'        => 'date',
            'order'          => 'DESC',
            'posts_per_page' => $per_page,
            'paged'          => $page,
        ] );
        $total = count( wp_get_post_revisions( $post_id, [ 'fields' => 'ids' ] ) );

        $items = [];
        foreach ( $revisions as $rev ) {
            $items[] = [
                'id'        => $rev->ID,
                'parent'    => $post_id,
                'author_id' => (int) $rev->post_author,
                'date'      => $rev->post_date,
                'date_gmt'  => $rev->post_date_gmt,
                'title'     => $rev->post_title,
                'content'   => $rev->post_content,
                'excerpt'   => $rev->post_excerpt,
            ];
        }
        return rest_ensure_response( [
            'revisions'   => $items,
            'total'       => $total,
            'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
        ] );
    }

    private function format_post( WP_Post $post ): array {
        $categories = wp_get_post_categories( $post->ID, [ 'fields' => 'all' ] );
        if ( is_wp_error( $categories ) ) {
            $categories = [];
        }
        $tags = wp_get_post_tags( $post->ID );
        if ( is_wp_error( $tags ) ) {
            $tags = [];
        }

        $seo = $this->read_seo_meta( $post->ID );

        $last_rewritten = get_post_meta( $post->ID, '_makasete_last_rewritten_at', true );
        $rewrite_count  = (int) get_post_meta( $post->ID, '_makasete_rewrite_count', true );

        // Word / character counts on the stripped body. CJK scripts have no word
        // boundaries, so str_word_count collapses to a tiny number — detect that
        // via a char-to-word ratio and use a character-based reading-time instead.
        $plain        = wp_strip_all_tags( (string) $post->post_content );
        $word_count   = str_word_count( $plain );
        $char_count   = mb_strlen( $plain );
        $is_cjk       = $char_count / max( $word_count, 1 ) > 5;
        $reading_time = $is_cjk
            ? (int) ceil( $char_count / 500 )
            : (int) ceil( $word_count / 200 );

        $language_info = $this->read_language_info( $post->ID );

        return [
            'id'                   => $post->ID,
            'post_type'            => $post->post_type,
            'title'                => $post->post_title,
            'slug'                 => $post->post_name,
            'status'               => $post->post_status,
            'date'                 => $post->post_date,
            'date_gmt'             => $post->post_date_gmt,
            'modified'             => $post->post_modified,
            'modified_gmt'         => $post->post_modified_gmt,
            'author_id'            => (int) $post->post_author,
            'content'              => $post->post_content,
            'excerpt'              => get_the_excerpt( $post ),
            'link'                 => get_permalink( $post->ID ),
            'preview_link'         => get_preview_post_link( $post ),
            'categories'           => array_map( fn( $c ) => [ 'id' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug ], $categories ),
            'tags'                 => array_map( fn( $t ) => [ 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug ], $tags ),
            'featured_image'       => get_the_post_thumbnail_url( $post->ID, 'full' ) ?: null,
            'meta_description'     => $seo['meta_description'],
            'seo_title'            => $seo['seo_title'],
            'revision_count'       => count( wp_get_post_revisions( $post->ID, [ 'fields' => 'ids' ] ) ),
            'last_rewritten_at'    => $last_rewritten !== '' ? $last_rewritten : null,
            'rewrite_count'        => $rewrite_count,
            'word_count'           => $word_count,
            'character_count'      => $char_count,
            'reading_time_minutes' => $reading_time,
            'language'             => $language_info['language'],
            'translations'         => $language_info['translations'],
            'comment_status'       => $post->comment_status,
            'ping_status'          => $post->ping_status,
            'is_sticky'            => is_sticky( $post->ID ),
            'meta'                 => $this->read_custom_meta( $post->ID ),
        ];
    }

    public function create_post( WP_REST_Request $request ) {
        $params = $request->get_json_params() ?: [];

        $requested_type = isset( $params['post_type'] ) ? sanitize_key( $params['post_type'] ) : '';
        if ( $requested_type !== '' && ! $this->is_allowed_post_type( $requested_type ) ) {
            return new WP_Error(
                'invalid_post_type',
                sprintf( __( 'Post type not allowed. Allowed: %s', 'makasete-seo' ), implode( ', ', $this->allowed_post_types() ) ),
                [ 'status' => 400 ]
            );
        }
        $post_type = $this->resolve_post_type( $requested_type !== '' ? $requested_type : null );

        $post_data = [
            'post_title'    => '',
            'post_content'  => '',
            'post_excerpt'  => '',
            'post_status'   => 'draft',
            'post_type'     => $post_type,
            'post_category' => [],
            'tags_input'    => [],
        ];

        $err = $this->apply_post_params_to_data( $params, $post_data, false );
        if ( $err ) {
            return $err;
        }

        $post_id = wp_insert_post( $post_data, true );
        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        $this->apply_post_side_effects( $post_id, $post_type, $params, false );

        $post = get_post( $post_id );
        return rest_ensure_response( [
            'id'             => $post_id,
            'link'           => get_permalink( $post_id ),
            'preview_link'   => $post ? get_preview_post_link( $post ) : null,
            'status'         => $post ? $post->post_status : $post_data['post_status'],
            'post_type'      => $post_type,
            'featured_image' => get_the_post_thumbnail_url( $post_id, 'full' ) ?: null,
        ] );
    }

    public function update_post( WP_REST_Request $request ) {
        $post_id = (int) $request->get_param( 'id' );
        $params  = $request->get_json_params() ?: [];

        $existing = get_post( $post_id );
        if ( ! $existing || ! $this->is_allowed_post_type( $existing->post_type ) ) {
            return new WP_Error( 'not_found', __( 'Post not found', 'makasete-seo' ), [ 'status' => 404 ] );
        }

        $post_data = [ 'ID' => $post_id ];
        $err = $this->apply_post_params_to_data( $params, $post_data, true );
        if ( $err ) {
            return $err;
        }

        $result = wp_update_post( $post_data, true );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $this->apply_post_side_effects( $post_id, $existing->post_type, $params, true );

        // Stamp the rewrite audit trail only when content actually changed.
        // Sticky-only, meta-only, and taxonomy-only updates don't count.
        $content_changed = isset( $params['title'] )
            || isset( $params['content'] )
            || isset( $params['excerpt'] );
        if ( $content_changed ) {
            [ $last_rewritten, $rewrite_count ] = $this->bump_rewrite_audit( $post_id );
        } else {
            $last_rewritten = (string) get_post_meta( $post_id, '_makasete_last_rewritten_at', true );
            $rewrite_count  = (int) get_post_meta( $post_id, '_makasete_rewrite_count', true );
        }

        return rest_ensure_response( [
            'id'                => $post_id,
            'updated'           => (int) $result === $post_id,
            'link'              => get_permalink( $post_id ),
            'last_rewritten_at' => $last_rewritten !== '' ? $last_rewritten : null,
            'rewrite_count'     => $rewrite_count,
        ] );
    }

    /**
     * Atomically increment `_makasete_rewrite_count` and stamp
     * `_makasete_last_rewritten_at`. Uses a direct SQL `UPDATE ... = meta_value + 1`
     * so two concurrent rewrites can't both read the same count and race.
     *
     * Returns [ iso_timestamp, new_count ].
     */
    private function bump_rewrite_audit( int $post_id ): array {
        global $wpdb;
        $now = current_time( 'c', true );
        update_post_meta( $post_id, '_makasete_last_rewritten_at', $now );

        // Ensure the row exists so the UPDATE below actually targets it.
        add_post_meta( $post_id, '_makasete_rewrite_count', 0, true );
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = %s",
            $post_id,
            '_makasete_rewrite_count'
        ) );
        // add_post_meta / wpdb->query both bypass the postmeta cache, so drop
        // the stale entry before reading the fresh value back.
        wp_cache_delete( $post_id, 'post_meta' );
        $count = (int) get_post_meta( $post_id, '_makasete_rewrite_count', true );
        return [ $now, $count ];
    }

    public function restore_post( WP_REST_Request $request ) {
        $post_id = (int) $request->get_param( 'id' );
        $post    = get_post( $post_id );
        if ( ! $post || ! $this->is_allowed_post_type( $post->post_type ) ) {
            return new WP_Error( 'not_found', __( 'Post not found', 'makasete-seo' ), [ 'status' => 404 ] );
        }
        if ( $post->post_status !== 'trash' ) {
            return new WP_Error( 'not_trashed', __( 'Post is not in the trash', 'makasete-seo' ), [ 'status' => 400 ] );
        }

        $result = wp_untrash_post( $post_id );
        if ( ! $result ) {
            return new WP_Error( 'restore_failed', __( 'Failed to restore post', 'makasete-seo' ), [ 'status' => 500 ] );
        }

        return rest_ensure_response( $this->format_post( get_post( $post_id ) ) );
    }

    public function set_sticky( WP_REST_Request $request ) {
        $post_id = (int) $request->get_param( 'id' );
        $sticky  = (bool) $request->get_param( 'sticky' );

        $post = get_post( $post_id );
        if ( ! $post || ! $this->is_allowed_post_type( $post->post_type ) ) {
            return new WP_Error( 'not_found', __( 'Post not found', 'makasete-seo' ), [ 'status' => 404 ] );
        }
        // stick_post / unstick_post operate on a site-wide option, so only
        // real posts (not arbitrary CPTs) are valid targets.
        if ( $post->post_type !== 'post' ) {
            return new WP_Error( 'invalid_post_type', __( 'Only standard posts can be made sticky.', 'makasete-seo' ), [ 'status' => 400 ] );
        }

        if ( $sticky ) {
            stick_post( $post_id );
        } else {
            unstick_post( $post_id );
        }

        return rest_ensure_response( [
            'id'        => $post_id,
            'is_sticky' => is_sticky( $post_id ),
        ] );
    }

    public function duplicate_post( WP_REST_Request $request ) {
        $post_id = (int) $request->get_param( 'id' );
        $source  = get_post( $post_id );
        if ( ! $source || ! $this->is_allowed_post_type( $source->post_type ) ) {
            return new WP_Error( 'not_found', __( 'Post not found', 'makasete-seo' ), [ 'status' => 404 ] );
        }

        $new_id = wp_insert_post( [
            'post_title'     => $source->post_title !== ''
                ? sprintf( __( '%s (copy)', 'makasete-seo' ), $source->post_title )
                : __( '(copy)', 'makasete-seo' ),
            'post_content'   => $source->post_content,
            'post_excerpt'   => $source->post_excerpt,
            'post_status'    => 'draft',
            'post_type'      => $source->post_type,
            'post_author'    => (int) get_current_user_id(),
            'comment_status' => $source->comment_status,
            'ping_status'    => $source->ping_status,
        ], true );

        if ( is_wp_error( $new_id ) ) {
            return $new_id;
        }

        wp_set_post_categories( $new_id, wp_get_post_categories( $post_id ) );
        wp_set_post_tags( $new_id, wp_get_post_tags( $post_id, [ 'fields' => 'names' ] ) );

        $thumb_id = get_post_thumbnail_id( $post_id );
        if ( $thumb_id ) {
            set_post_thumbnail( $new_id, $thumb_id );
        }

        // Carry forward Makasete + active-SEO-plugin postmeta so the duplicate
        // starts with the same metadata instead of being blank. Always write
        // our own keys so read_seo_meta() on the duplicate has a fallback even
        // if the SEO plugin is later deactivated; sync_seo_meta() separately
        // mirrors into Yoast / RankMath when active.
        $seo = $this->read_seo_meta( $post_id );
        if ( $seo['seo_title'] !== '' ) {
            update_post_meta( $new_id, '_makasete_seo_title', $seo['seo_title'] );
        }
        if ( $seo['meta_description'] !== '' ) {
            update_post_meta( $new_id, '_makasete_meta_description', $seo['meta_description'] );
        }
        $this->sync_seo_meta(
            $new_id,
            $seo['seo_title'] !== '' ? $seo['seo_title'] : null,
            $seo['meta_description'] !== '' ? $seo['meta_description'] : null
        );

        // Mirror the source language (WPML/Polylang) so the duplicate stays
        // in the same translation set instead of landing in the default lang.
        $lang_info = $this->read_language_info( $post_id );
        if ( ! empty( $lang_info['language'] ) ) {
            $this->set_post_language( $new_id, $source->post_type, (string) $lang_info['language'] );
        }

        // Copy allow-listed custom meta so theme-specific fields survive.
        foreach ( $this->allowed_meta_keys() as $key ) {
            $value = get_post_meta( $post_id, $key, true );
            if ( $value !== '' && $value !== null && $value !== false ) {
                update_post_meta( $new_id, $key, $value );
            }
        }

        return rest_ensure_response( $this->format_post( get_post( $new_id ) ) );
    }

    public function delete_post( WP_REST_Request $request ) {
        $post_id = (int) $request->get_param( 'id' );
        $force   = (bool) $request->get_param( 'force' );

        $post = get_post( $post_id );
        if ( ! $post || ! $this->is_allowed_post_type( $post->post_type ) ) {
            return new WP_Error( 'not_found', __( 'Post not found', 'makasete-seo' ), [ 'status' => 404 ] );
        }

        if ( $force ) {
            $result = wp_delete_post( $post_id, true );
        } else {
            $result = wp_trash_post( $post_id );
        }

        if ( ! $result ) {
            return new WP_Error( 'delete_failed', __( 'Failed to delete post', 'makasete-seo' ), [ 'status' => 500 ] );
        }

        return rest_ensure_response( [
            'id'      => $post_id,
            'deleted' => true,
            'force'   => $force,
        ] );
    }

    // ── Users ────────────────────────────────────────────────────────────────

    public function get_users( WP_REST_Request $request ): WP_REST_Response {
        $args = [
            'number'  => (int) $request->get_param( 'per_page' ),
            'paged'   => (int) $request->get_param( 'page' ),
            'orderby' => 'display_name',
            'order'   => 'ASC',
        ];

        $search = (string) $request->get_param( 'search' );
        if ( $search !== '' ) {
            $args['search']         = '*' . $search . '*';
            $args['search_columns'] = [ 'user_login', 'user_nicename', 'display_name', 'user_email' ];
        }

        $role = (string) $request->get_param( 'role' );
        if ( $role !== '' ) {
            $args['role'] = $role;
        }

        $query = new WP_User_Query( $args );
        $users = array_map( function ( WP_User $user ) {
            return [
                'id'           => (int) $user->ID,
                'username'     => $user->user_login,
                'display_name' => $user->display_name,
                'email'        => $user->user_email,
                'roles'        => array_values( (array) $user->roles ),
                'post_count'   => (int) count_user_posts( $user->ID, 'post', true ),
            ];
        }, $query->get_results() );

        return rest_ensure_response( [
            'users' => $users,
            'total' => (int) $query->get_total(),
        ] );
    }

    // ── Categories ────────────────────────────────────────────────────────────

    public function get_categories( WP_REST_Request $request ): WP_REST_Response {
        return $this->list_terms( $request, 'category' );
    }

    public function create_category( WP_REST_Request $request ) {
        return $this->create_term( $request, 'category', true );
    }

    public function update_category( WP_REST_Request $request ) {
        return $this->update_term( $request, 'category' );
    }

    public function delete_category( WP_REST_Request $request ) {
        return $this->delete_term( $request, 'category' );
    }

    private function format_term( $term ): array {
        return [
            'id'          => (int) $term->term_id,
            'name'        => $term->name,
            'slug'        => $term->slug,
            'parent'      => (int) ( $term->parent ?? 0 ),
            'description' => (string) ( $term->description ?? '' ),
            'count'       => (int) $term->count,
        ];
    }

    /**
     * Shared idempotent term-create for both categories and tags. When
     * `$supports_parent` is true, a `parent_id` field is honored and validated.
     */
    private function create_term( WP_REST_Request $request, string $taxonomy, bool $supports_parent ) {
        $params = $request->get_json_params() ?: [];
        $name   = sanitize_text_field( $params['name'] ?? '' );

        if ( $name === '' ) {
            $msg = $taxonomy === 'category'
                ? __( 'Category name is required', 'makasete-seo' )
                : __( 'Tag name is required', 'makasete-seo' );
            return new WP_Error( 'missing_name', $msg, [ 'status' => 400 ] );
        }

        $args = [ 'slug' => sanitize_title( $params['slug'] ?? $name ) ];
        if ( $supports_parent && ! empty( $params['parent_id'] ) ) {
            $parent_id = (int) $params['parent_id'];
            if ( ! term_exists( $parent_id, $taxonomy ) ) {
                return new WP_Error( 'invalid_parent', __( 'Parent category does not exist', 'makasete-seo' ), [ 'status' => 400 ] );
            }
            $args['parent'] = $parent_id;
        }
        if ( isset( $params['description'] ) ) {
            $args['description'] = sanitize_textarea_field( $params['description'] );
        }

        $result = wp_insert_term( $name, $taxonomy, $args );

        if ( is_wp_error( $result ) ) {
            if ( $result->get_error_code() === 'term_exists' ) {
                return rest_ensure_response( [ 'id' => (int) $result->get_error_data(), 'name' => $name, 'existed' => true ] );
            }
            return $result;
        }

        return rest_ensure_response( [ 'id' => (int) $result['term_id'], 'name' => $name, 'existed' => false ] );
    }

    private function update_term( WP_REST_Request $request, string $taxonomy ) {
        $term_id = (int) $request->get_param( 'id' );
        if ( ! term_exists( $term_id, $taxonomy ) ) {
            return new WP_Error( 'not_found', __( 'Term not found', 'makasete-seo' ), [ 'status' => 404 ] );
        }

        $params = $request->get_json_params() ?: [];
        $args   = [];
        if ( isset( $params['name'] ) )        $args['name']        = sanitize_text_field( $params['name'] );
        if ( isset( $params['slug'] ) )        $args['slug']        = sanitize_title( $params['slug'] );
        if ( isset( $params['description'] ) ) $args['description'] = sanitize_textarea_field( $params['description'] );
        if ( $taxonomy === 'category' && isset( $params['parent_id'] ) ) {
            $parent_id = (int) $params['parent_id'];
            if ( $parent_id !== 0 && ! term_exists( $parent_id, 'category' ) ) {
                return new WP_Error( 'invalid_parent', __( 'Parent category does not exist', 'makasete-seo' ), [ 'status' => 400 ] );
            }
            if ( $parent_id === $term_id ) {
                return new WP_Error( 'invalid_parent', __( 'A term cannot be its own parent', 'makasete-seo' ), [ 'status' => 400 ] );
            }
            $args['parent'] = $parent_id;
        }

        $result = wp_update_term( $term_id, $taxonomy, $args );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $term = get_term( $term_id, $taxonomy );
        if ( is_wp_error( $term ) || ! $term ) {
            return new WP_Error( 'not_found', __( 'Term not found after update', 'makasete-seo' ), [ 'status' => 404 ] );
        }
        return rest_ensure_response( $this->format_term( $term ) );
    }

    private function delete_term( WP_REST_Request $request, string $taxonomy ) {
        $term_id = (int) $request->get_param( 'id' );
        if ( ! term_exists( $term_id, $taxonomy ) ) {
            return new WP_Error( 'not_found', __( 'Term not found', 'makasete-seo' ), [ 'status' => 404 ] );
        }

        $args     = [];
        $reassign = (int) $request->get_param( 'reassign' );
        if ( $reassign > 0 ) {
            if ( $reassign === $term_id ) {
                return new WP_Error( 'invalid_reassign', __( 'Cannot reassign a term to itself', 'makasete-seo' ), [ 'status' => 400 ] );
            }
            if ( ! term_exists( $reassign, $taxonomy ) ) {
                return new WP_Error( 'invalid_reassign', __( 'Reassign target term does not exist', 'makasete-seo' ), [ 'status' => 400 ] );
            }
            $args['default'] = $reassign;
            $args['force_default'] = true;
        }

        $result = wp_delete_term( $term_id, $taxonomy, $args );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( $result === false || $result === 0 ) {
            return new WP_Error( 'delete_failed', __( 'Failed to delete term', 'makasete-seo' ), [ 'status' => 500 ] );
        }

        return rest_ensure_response( [
            'id'       => $term_id,
            'deleted'  => true,
            'reassign' => $reassign > 0 ? $reassign : null,
        ] );
    }

    // ── Tags ──────────────────────────────────────────────────────────────────

    public function get_tags( WP_REST_Request $request ): WP_REST_Response {
        return $this->list_terms( $request, 'post_tag' );
    }

    public function update_tag( WP_REST_Request $request ) {
        return $this->update_term( $request, 'post_tag' );
    }

    public function delete_tag( WP_REST_Request $request ) {
        return $this->delete_term( $request, 'post_tag' );
    }

    public function create_tag( WP_REST_Request $request ) {
        return $this->create_term( $request, 'post_tag', false );
    }

    // ── Media ─────────────────────────────────────────────────────────────────

    public function upload_media( WP_REST_Request $request ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $files = $request->get_file_params();
        if ( empty( $files['file'] ) ) {
            return new WP_Error( 'missing_file', __( 'No file uploaded', 'makasete-seo' ), [ 'status' => 400 ] );
        }

        $upload = $files['file'];
        if ( ! empty( $upload['tmp_name'] ) ) {
            $mime = $this->detect_image_mime_type(
                (string) $upload['tmp_name'],
                (string) ( $upload['name'] ?? '' )
            );
            if ( ! in_array( $mime, self::ALLOWED_IMAGE_MIMES, true ) ) {
                return new WP_Error(
                    'invalid_mime',
                    sprintf( __( 'Unsupported file type. Allowed: %s', 'makasete-seo' ), implode( ', ', self::ALLOWED_IMAGE_MIMES ) ),
                    [ 'status' => 400 ]
                );
            }
        }

        $params    = $request->get_body_params();
        $caption   = sanitize_textarea_field( $params['caption'] ?? '' );
        $post_data = $caption !== '' ? [ 'post_excerpt' => $caption ] : [];

        $attachment_id = media_handle_upload( 'file', 0, $post_data );
        if ( is_wp_error( $attachment_id ) ) {
            return $attachment_id;
        }

        return rest_ensure_response( $this->finalize_attachment(
            (int) $attachment_id,
            sanitize_text_field( $params['alt'] ?? '' )
        ) );
    }

    public function upload_media_from_url( WP_REST_Request $request ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $url      = esc_url_raw( $request->get_param( 'url' ) );
        $filename = $this->derive_upload_filename( $url, (string) $request->get_param( 'filename' ) );
        $alt      = sanitize_text_field( (string) $request->get_param( 'alt' ) );
        $caption  = sanitize_textarea_field( (string) $request->get_param( 'caption' ) );
        $max      = $this->max_download_bytes();

        // HEAD-probe Content-Length + Content-Type first so oversize or
        // non-image payloads never touch disk. Some servers reject HEAD or
        // omit headers; in that case we fall through and rely on the
        // post-download size + magic-byte checks as backstops.
        $head = wp_remote_head( $url, [ 'timeout' => 10, 'redirection' => 5 ] );
        if ( ! is_wp_error( $head ) ) {
            $advertised = (int) wp_remote_retrieve_header( $head, 'content-length' );
            if ( $advertised > $max ) {
                return new WP_Error(
                    'file_too_large',
                    sprintf(
                        __( 'Remote file advertises %1$d bytes, exceeds %2$d byte limit.', 'makasete-seo' ),
                        $advertised,
                        $max
                    ),
                    [ 'status' => 413 ]
                );
            }
            $advertised_type = strtolower( (string) wp_remote_retrieve_header( $head, 'content-type' ) );
            // Strip charset / boundary suffix (e.g. "image/jpeg; charset=...").
            $advertised_type = trim( explode( ';', $advertised_type, 2 )[0] );
            if ( $advertised_type !== '' && strpos( $advertised_type, 'image/' ) !== 0 ) {
                return new WP_Error(
                    'invalid_mime',
                    sprintf(
                        __( 'Remote Content-Type "%s" is not an image.', 'makasete-seo' ),
                        $advertised_type
                    ),
                    [ 'status' => 400 ]
                );
            }
        }

        $tmp = download_url( $url, 30 );
        if ( is_wp_error( $tmp ) ) {
            return $tmp;
        }

        // Backstop: servers that lie about or omit Content-Length still hit the
        // cap here. Catches the compromised-origin case the HEAD probe misses.
        $size = @filesize( $tmp );
        if ( $size !== false && $size > $max ) {
            wp_delete_file( $tmp );
            return new WP_Error(
                'file_too_large',
                sprintf(
                    __( 'Downloaded file is %1$d bytes, exceeds %2$d byte limit.', 'makasete-seo' ),
                    (int) $size,
                    $max
                ),
                [ 'status' => 413 ]
            );
        }

        $mime = $this->detect_image_mime_type( $tmp, $filename );
        if ( ! in_array( $mime, self::ALLOWED_IMAGE_MIMES, true ) ) {
            wp_delete_file( $tmp );
            return new WP_Error(
                'invalid_mime',
                sprintf( __( 'Unsupported file type. Allowed: %s', 'makasete-seo' ), implode( ', ', self::ALLOWED_IMAGE_MIMES ) ),
                [ 'status' => 400 ]
            );
        }

        $post_data     = $caption !== '' ? [ 'post_excerpt' => $caption ] : [];
        $attachment_id = media_handle_sideload( [ 'name' => $filename, 'tmp_name' => $tmp ], 0, null, $post_data );
        if ( is_wp_error( $attachment_id ) ) {
            wp_delete_file( $tmp );
            return $attachment_id;
        }

        return rest_ensure_response( $this->finalize_attachment( (int) $attachment_id, $alt ) );
    }

    public function list_media( WP_REST_Request $request ): WP_REST_Response {
        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => (int) $request->get_param( 'per_page' ),
            'paged'          => (int) $request->get_param( 'page' ),
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];
        $search = $request->get_param( 'search' );
        if ( $search ) {
            $args['s'] = $search;
        }
        $mime = $request->get_param( 'mime_type' );
        if ( $mime ) {
            $args['post_mime_type'] = $mime;
        }

        $query = new WP_Query( $args );
        $items = array_map( [ $this, 'format_media' ], $query->posts );

        return rest_ensure_response( [
            'media'       => $items,
            'total'       => (int) $query->found_posts,
            'total_pages' => (int) $query->max_num_pages,
        ] );
    }

    public function get_media( WP_REST_Request $request ) {
        $id   = (int) $request->get_param( 'id' );
        $post = get_post( $id );
        if ( ! $post || $post->post_type !== 'attachment' ) {
            return new WP_Error( 'not_found', __( 'Attachment not found', 'makasete-seo' ), [ 'status' => 404 ] );
        }
        return rest_ensure_response( $this->format_media( $post ) );
    }

    public function update_media( WP_REST_Request $request ) {
        $id   = (int) $request->get_param( 'id' );
        $post = get_post( $id );
        if ( ! $post || $post->post_type !== 'attachment' ) {
            return new WP_Error( 'not_found', __( 'Attachment not found', 'makasete-seo' ), [ 'status' => 404 ] );
        }

        $params     = $request->get_json_params() ?: [];
        $post_data  = [ 'ID' => $id ];
        $has_fields = false;

        if ( isset( $params['title'] ) ) {
            $post_data['post_title']   = sanitize_text_field( $params['title'] );
            $has_fields                = true;
        }
        if ( isset( $params['caption'] ) ) {
            $post_data['post_excerpt'] = sanitize_textarea_field( $params['caption'] );
            $has_fields                = true;
        }
        if ( isset( $params['description'] ) ) {
            $post_data['post_content'] = sanitize_textarea_field( $params['description'] );
            $has_fields                = true;
        }

        if ( $has_fields ) {
            $result = wp_update_post( $post_data, true );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
        }
        if ( isset( $params['alt'] ) ) {
            update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $params['alt'] ) );
        }

        // Re-fetch so format_media() sees the persisted values, mirroring the
        // wp_update_post → get_post → format sequence used by update_post().
        return rest_ensure_response( $this->format_media( get_post( $id ) ) );
    }

    private function format_media( WP_Post $post ): array {
        $id = $post->ID;
        return [
            'attachment_id' => $id,
            'title'         => $post->post_title,
            'caption'       => $post->post_excerpt,
            'description'   => $post->post_content,
            'alt'           => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
            'url'           => wp_get_attachment_url( $id ),
            'mime_type'     => get_post_mime_type( $id ),
            'date'          => $post->post_date,
            'date_gmt'      => $post->post_date_gmt,
            'author_id'     => (int) $post->post_author,
        ];
    }

    public function delete_media( WP_REST_Request $request ) {
        $id    = (int) $request->get_param( 'id' );
        $force = (bool) $request->get_param( 'force' );

        $post = get_post( $id );
        if ( ! $post || $post->post_type !== 'attachment' ) {
            return new WP_Error( 'not_found', __( 'Attachment not found', 'makasete-seo' ), [ 'status' => 404 ] );
        }

        // wp_delete_attachment removes files from disk when $force is true,
        // otherwise it sends the attachment to trash (post_status = 'trash').
        $result = wp_delete_attachment( $id, $force );

        if ( ! $result ) {
            return new WP_Error( 'delete_failed', __( 'Failed to delete attachment', 'makasete-seo' ), [ 'status' => 500 ] );
        }

        return rest_ensure_response( [
            'attachment_id' => $id,
            'deleted'       => true,
            'force'         => $force,
        ] );
    }
}
