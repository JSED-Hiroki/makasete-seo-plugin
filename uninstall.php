<?php
/**
 * Makasete SEO uninstall handler.
 *
 * Runs only when the plugin is deleted via the WP admin (not on deactivation).
 * Cleans up the postmeta this plugin writes so no orphan rows are left behind.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_post_meta_by_key( '_makasete_meta_description' );
delete_post_meta_by_key( '_makasete_seo_title' );
delete_post_meta_by_key( '_makasete_last_rewritten_at' );
delete_post_meta_by_key( '_makasete_rewrite_count' );
