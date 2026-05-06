=== Makasete SEO ===
Contributors: makasete
Tags: seo, automation, ai, content, claude
Requires at least: 5.6
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.7.4
License: GPLv2 or later

Connects your WordPress site to the Makasete AI SEO automation platform.

== Description ==

Makasete SEO is a connector plugin that exposes a secure REST API allowing the Makasete platform to automate your WordPress content:

* Create, update, and delete posts on `post` or registered custom post types (trash or permanent)
* Fetch a single post, its revision history, and SEO metadata
* List posts with rich filters (status, date range, category, tag, author, orderby)
* Manage categories and tags — create, update, delete with reassign
* Upload, list, fetch, update, and delete media
* Sync SEO title / meta description into Yoast or RankMath when detected
* Track rewrites via automatic `last_rewritten_at` / `rewrite_count` postmeta
* Read existing posts for internal linking analysis

All requests are authenticated using WordPress Application Passwords (built-in since WP 5.6). No third-party authentication plugins are needed. Each endpoint enforces the WordPress capability that matches the real action being performed (for example, `upload_files` for media upload, `manage_categories` for category creation, `publish_posts` for publishing).

== Installation ==

1. Download the makasete-seo.zip file from your Makasete dashboard
2. Go to **Plugins → Add New → Upload Plugin** in your WordPress admin
3. Upload the zip file and click **Activate Plugin**
4. Go to **Settings → Makasete SEO** to complete setup

== Frequently Asked Questions ==

= Is this plugin secure? =

Yes. All API endpoints require valid WordPress Application Password authentication. Each endpoint additionally enforces the specific WordPress capability for the action being performed, so a low-privilege user cannot upload files or create categories through the API.

= Does this plugin send my content to external servers? =

The plugin itself does not send any data externally. It only receives requests from the Makasete platform you explicitly connected.

= What happens when I delete the plugin? =

The plugin's `uninstall.php` removes all postmeta keys the plugin wrote (`_makasete_meta_description`, `_makasete_seo_title`, `_makasete_last_rewritten_at`, `_makasete_rewrite_count`), so no orphan rows are left in your database.

== Changelog ==

= 1.7.4 =
* **Fixes "WP REST API unreachable / non-JSON-200" failures on hosts running aggressive REST blockers.** Many security plugins (Wordfence, iThemes Security, SiteGuard WP, etc.) and managed-host policies hook into `rest_authentication_errors` to deny unauthenticated REST requests wholesale, sometimes responding with the site's homepage HTML instead of a JSON error. The plugin now bypasses that blanket block for `/makasete/v1/*` routes — but only when WP has already authenticated the caller (so we never lower auth). Each route still runs its own `permission_callback` against the matching capability.
* `POST /media/upload` and `POST /media/upload-from-url` MIME detection is now layered: WP's `wp_check_filetype_and_ext()` first, then `finfo_open()` / `mime_content_type()`, then magic-byte sniffing (covers PNG, JPEG, GIF, WebP), and finally the filename extension. Fixes WebP uploads on installs where the WP-core allow-list filters out `image/webp` even though the bytes are a valid WebP.
* `image/webp` is added to `upload_mimes` defensively, so `wp_check_filetype_and_ext()` recognizes it on hosts/themes that strip it back out after WP 5.8 added it to core.

= 1.7.2 =
* **Security:** `GET /posts/{id}` and `GET /posts/{id}/revisions` no longer fall back to the `edit_posts` capability when `read_post` denies access. A Contributor account can no longer fetch another author's `private` post via the API. **Breaking** for sites that relied on Contributors authenticating to the API — grant them the post-specific capability (or `read_private_posts`) instead.
* `MAKASETE_REST_MAX_DOWNLOAD_BYTES`: the 20 MB server-to-server download cap in `POST /media/upload-from-url` is now overrideable via a `define()` in `wp-config.php`. Closes a doc drift where the 1.6.0 changelog claimed the cap was configurable but no such constant existed.
* `POST /media/upload-from-url` HEAD-probes `Content-Type` in addition to `Content-Length`, so an oversized non-image payload (e.g. a 19 MB tarball served at `/image.jpg`) is rejected up front instead of after the full download. Charset / boundary suffixes are stripped before the `image/` prefix check.
* Pathless source URLs (e.g. `https://cdn.example.com/` with `filename` omitted) now fall back to an `image-<timestamp>` filename so `media_handle_sideload` doesn't crash on an empty name. The caller-supplied `filename` still wins when provided.
* `featured_image_id` validation: the single `invalid_attachment` error is split into `attachment_not_found` and `attachment_not_image` for clearer client-side diagnostics.
* Media captions: `POST /media/upload`, `POST /media/upload-from-url`, and `PUT /media/{id}` now sanitize `caption` with `sanitize_textarea_field()` instead of `sanitize_text_field()`, so multi-line captions round-trip correctly into `post_excerpt`.
* Internal cleanup: both `rest_url( 'makasete/v1/status' )` call sites in the admin/enqueue layer now derive the namespace from `Makasete_REST_Controller::REST_NAMESPACE`, matching the JWT-whitelist pattern from 1.7.1.

= 1.7.1 =
* Third audit pass. `format_post()` now passes `fields=ids` to `wp_get_post_revisions()` so list responses stop hydrating full revision `WP_Post` rows just to count them — big win on sites with many-revisioned posts at `per_page=100`.
* Filter thrash: `allowed_meta_keys()` and `allowed_post_types()` are memoized per request, so `makasete_allowed_meta_keys` and `makasete_allowed_post_types` fire at most once per request instead of once per post in list responses / once per error-path re-read.
* `wp_count_terms()` now branches on WP version: 6.0+ gets the `$args`-only form (passing `taxonomy` inside `$args`) instead of the positional form that triggers a `_doing_it_wrong` deprecation notice. Pre-6.0 keeps the positional form for 5.6-5.9 back-compat.
* `upload_media_from_url()` uses `wp_delete_file()` instead of `@unlink()` for temp-file cleanup, routing through the `wp_delete_file` filter and dropping the error-suppression operator.
* JWT whitelist patterns in the main plugin file are now derived from `Makasete_REST_Controller::REST_NAMESPACE` instead of hard-coded literals, so renaming the namespace stays in sync.
* Admin settings page: inline `<style>` / `<script>` extracted to `assets/admin.css` / `assets/admin.js`, enqueued only on `settings_page_makasete-seo`. REST endpoint, nonce, and translated pill labels are passed via `wp_localize_script`. Cleaner separation of concerns; no behavior change.
* readme.txt FAQ: lists all four `_makasete_*` keys removed on uninstall (previously only mentioned the two SEO keys — `_makasete_last_rewritten_at` / `_makasete_rewrite_count` are cleaned too).

= 1.7.0 =
* Second audit pass. `PUT /posts/{id}` increments `_makasete_rewrite_count` via an atomic SQL `UPDATE … = meta_value + 1`, closing the read-modify-write race where two concurrent rewrites could both land on the same count. `validate_iso8601` now rejects impossible calendar dates (e.g. `2023-02-30`) via `checkdate` instead of letting `strtotime` silently roll them into the next month.
* `POST /media/upload-from-url`: HEAD-probes `Content-Length` before the full download and returns 413 up front when the remote file exceeds the 20 MB cap. The post-download size check stays as a backstop for servers that lie about or omit `Content-Length`.
* `POST /posts` no longer triggers a pointless `delete_post_thumbnail` when `featured_image_id: 0` is sent on create (a fresh post has no thumbnail to clear). The "zero means clear" semantics now only apply on update.
* `GET /categories` and `GET /tags`: paginated (`per_page`, `page`, `search`) and return `{ terms, total, total_pages }`. Sites with thousands of tags no longer OOM on list. `GET /posts/{id}/revisions` paginated similarly; response shape is now `{ revisions, total, total_pages }`.
* Internal refactor: `create_category`/`create_tag` share a `create_term()` helper; `apply_post_side_effects()` no longer re-checks `wp_attachment_is_image()` (already validated upstream) and only calls `sync_seo_meta()` when `seo_title`/`meta_description` were actually supplied. `detect_seo_plugin()` / `detect_multilingual_plugin()` memoize their answer per request, collapsing O(N) plugin probes during list responses. Publish-tier status literal (`publish`, `future`, `private`) extracted to `PUBLISH_TIER_STATUSES`.
* Media uploads now pass caption into `media_handle_upload` / `media_handle_sideload` via `post_data` instead of a second `wp_update_post` round-trip.
* `post_write_args()` schema: dropped `sanitize_callback` entries that were never firing because handlers read from `get_json_params()`. Sanitization lives in the handler where it actually runs; the schema now honestly documents types/enums only.
* Backend: `wordpress_client.upload_image()` uses a dedicated 180 s timeout (new `HTTP_TIMEOUT_UPLOAD` constant) so a 20 MB sideload plus thumbnail regen doesn't trip the default 60 s timeout.

= 1.6.0 =
* Robustness polish. Rewrite audit now only stamps `_makasete_last_rewritten_at` / `_makasete_rewrite_count` when the payload actually touches `title`/`content`/`excerpt` — sticky-only, meta-only, and taxonomy-only updates no longer inflate the counter. `updated` in the `PUT /posts/{id}` response reflects the real `wp_update_post` result instead of being hard-coded to `true`.
* `POST /posts` / `PUT /posts/{id}`: `publish_at` no longer clobbers an explicit `status` — it defaults to `future` only when no status was supplied on create. `author_id` is now validated against `get_userdata()` before insert/update. Invalid `featured_image_id` returns 400 consistently on both create and update (previously silently ignored on create).
* Validation: `validate_iso8601` now requires a real ISO date/datetime (was passing loose strings like `"tomorrow"` via `strtotime`). `validate_http_url` rejects IP-literal hosts on private / reserved / loopback ranges to blunt SSRF via `download_url()`.
* `POST /media/upload-from-url`: rejects downloads over 20 MB (configurable via the `MAKASETE_REST_*` constants), returning 413.
* `POST /tags`: now honors the `description` field declared in the route schema (previously silently dropped).
* `POST /posts/{id}/duplicate`: duplicates now carry over WPML/Polylang language and allow-listed custom meta, matching what a fresh `POST /posts` with those fields would write.
* Internal refactor: `create_post` and `update_post` share `apply_post_params_to_data()` / `apply_post_side_effects()`; `get_categories`/`get_tags` share `list_terms()`; media uploads share `finalize_attachment()`. No wire-level behavior change.
* Cleanup: legacy `PUT /posts/{id}/featured-image` endpoint removed — use `PUT /posts/{id}` with `featured_image_id` instead. Unnecessary `flush_rewrite_rules()` on activate/deactivate removed (REST routes don't use rewrite rules). `uninstall.php` now also clears `_makasete_last_rewritten_at` and `_makasete_rewrite_count`.

= 1.5.0 =
* Sticky posts: new `PUT /posts/{id}/sticky` route (body `{ "sticky": bool }`) and `sticky` flag accepted on `POST`/`PUT /posts`. `is_sticky` is surfaced in every post payload. Standard `post` only — sticky is a core concept that doesn't map to custom post types.
* Discussion policy: `comment_status` and `ping_status` (each `open` or `closed`) are accepted on create / update and echoed back on read, so the pipeline can auto-disable comments / pingbacks on AI-generated posts.
* Users: new `GET /users` endpoint (`per_page`, `page`, `search`, `role`) requires the `list_users` cap. Returns `id`, `username`, `display_name`, `email`, `roles`, `post_count` — enough to populate an author-picker in the dashboard.
* Custom meta passthrough: `meta` object accepted on create / update and returned on read, gated by a new `makasete_allowed_meta_keys` filter. Default allow-list is empty — site owners opt in per key, non-scalar values are silently dropped, and `null` deletes the meta. Keeps theme-specific fields addressable without a plugin rev.
* Post duplicate: new `POST /posts/{id}/duplicate` creates a draft copy with the source's content, excerpt, categories, tags, featured image, discussion settings, and Makasete / SEO-plugin meta. Title gets a `(copy)` suffix; the current user becomes the author.

= 1.4.0 =
* Status probe: `GET /status` now also reports `php_version`, `locale`, `timezone`, `theme_name`, and `multilingual_plugin` (`wpml`/`polylang`/null) so the Makasete backend can tailor generation to the target site without a second round-trip.
* Restore from trash: new `POST /posts/{id}/restore` endpoint wraps `wp_untrash_post`. Requires `edit_post` on the target; returns 400 if the post is not currently trashed. Exposed in the backend client as `restore_post(post_id)`.
* Reading metrics: every post payload now includes `word_count`, `character_count`, and `reading_time_minutes`. Reading time is CJK-aware — when the char/word ratio suggests Japanese/Chinese/Korean content the minutes are computed from characters (≈500 chars/min) instead of words.
* Multilingual: `create_post` / `update_post` accept an optional `language` code. When WPML or Polylang is active the code is persisted via the plugin's public API (`pll_set_post_language` or the `wpml_set_element_language_details` action). Every post payload surfaces `language` and a `translations` map (`{ lang_code: post_id }`). On single-language sites the field is silently ignored and both keys return `null` / `[]`.

= 1.3.0 =
* SEO plugins: When Yoast or RankMath is active, the plugin now mirrors the SEO title / meta description into their postmeta keys (`_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, `rank_math_title`, `rank_math_description`) on create and update. `GET /posts` / `GET /posts/{id}` prefer the active SEO plugin's values when reading back, so manual edits in the WP admin stay authoritative. `GET /status` reports which SEO plugin was detected.
* Post list filters: `GET /posts` now accepts `status[]`, `post_type`, `after`, `before`, `modified_after`, `modified_before`, `category_id`, `tag_id`, `author_id`, `orderby` (`date`/`modified`/`title`/`id`), and `order` (`ASC`/`DESC`). Powers rewrite pipelines that need to find stale posts.
* Revisions: New `GET /posts/{id}/revisions` endpoint. `revision_count` is surfaced in every post payload.
* Rewrite audit: `PUT /posts/{id}` automatically stamps `_makasete_last_rewritten_at` and increments `_makasete_rewrite_count`. Both are exposed in the post payload and in the update response.
* Custom post types: Posts endpoints now work against any post type registered via the `makasete_allowed_post_types` filter (default: `['post']`). `post_type` is accepted on create / list and returned on every post payload.
* Authorship: `create_post` / `update_post` accept an optional `author_id` (requires `edit_others_posts`). `GET /posts` accepts `author_id` as a filter.
* Preview links: `preview_link` is included in post payloads and in `create_post` responses so dashboards can link to draft previews.
* Categories: Added `PUT /categories/{id}` and `DELETE /categories/{id}` (with `reassign`). Create/update accept `description`.
* Tags: Added `PUT /tags/{id}` and `DELETE /tags/{id}`. Create/update accept `description`.
* Media: Added `GET /media` (with `search`, `mime_type`, paging), `GET /media/{id}`, and `PUT /media/{id}` for updating alt / caption / description / title.

= 1.2.0 =
* Media: Added `DELETE /media/{id}` with a `force` query param. `force=true` (default) permanently removes the attachment record and the file on disk; `force=false` sends the attachment to trash. Requires `delete_post` capability on the attachment.

= 1.1.0 =
* Security: Per-endpoint capability checks (`publish_posts`, `upload_files`, `manage_categories`, `manage_post_tags`, `edit_post`, `delete_post`) instead of a single blanket `edit_posts` check.
* Validation: Every write endpoint now declares an `args` schema with `type`, `sanitize_callback`, and `validate_callback`. Invalid input is rejected before it reaches the callback.
* Posts: Added `GET /posts/{id}` for single-post fetch. List response now includes `content`, `meta_description`, `seo_title`, `author_id`, and GMT dates.
* Posts: `DELETE /posts/{id}` now accepts `?force=true` for permanent deletion (trash remains the default).
* Posts: `update_post` validates `status` against the same allow-list as `create_post`.
* Media: Mime-type allow-list enforced on uploads (png/jpeg/webp/gif). Added `POST /media/upload-from-url` for server-to-server image ingestion.
* Featured image: Validates that the attachment exists and is an image before assigning.
* Categories: Idempotent creation — re-creating an existing category returns its ID instead of an error (matches tag behavior). Parent category is validated.
* Cleanup: Added `uninstall.php` that removes orphan `_makasete_*` postmeta on plugin deletion.
* Admin: Settings page now performs a live status check against the REST endpoint and shows a connection pill.
* i18n: Loads text domain and wraps user-facing strings with translation functions.
* Internals: Renamed `NAMESPACE` class constant (PHP reserved word) to `REST_NAMESPACE`.

= 1.0.0 =
* Initial release
