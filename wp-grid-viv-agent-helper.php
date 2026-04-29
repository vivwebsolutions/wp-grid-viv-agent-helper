<?php
/**
 * Plugin Name: Viv Gridbuilder Agent Helper
 * Description: Setup, diagnostic and WP-CLI tooling for WP Grid Builder + Viv plugins. Makes agent-assisted and headless setup reliable.
 * Version:     0.1.0
 * Author:      ViV Web Solutions
 * Author URI:  https://vivwebsolutions.com/
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'VIV_AGENT_HELPER_VERSION', '0.1.0' );
define( 'VIV_AGENT_HELPER_DIR', plugin_dir_path( __FILE__ ) );
define( 'VIV_AGENT_HELPER_URL', plugin_dir_url( __FILE__ ) );

require_once VIV_AGENT_HELPER_DIR . 'includes/class-auto-register.php';
require_once VIV_AGENT_HELPER_DIR . 'includes/class-admin-page.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once VIV_AGENT_HELPER_DIR . 'includes/class-cli.php';
    WP_CLI::add_command( 'viv', 'Viv_Agent_CLI' );
}

/**
 * Viv-docs#121 — WPGB writes per-card CSS files to uploads/wpgb/cards/<id>.css
 * but doesn't clean them up when a card row is deleted. Listen for
 * wp_grid_builder/delete/cards (fired in includes/routes/class-objects.php
 * after a card row is removed via the dashboard or REST API) and remove
 * the matching files so the uploads directory doesn't accumulate orphans.
 */
add_action( 'wp_grid_builder/delete/cards', function ( $ids ) {
    if ( ! is_array( $ids ) || empty( $ids ) ) {
        return;
    }
    $dir = WP_CONTENT_DIR . '/uploads/wpgb/cards';
    foreach ( $ids as $id ) {
        $file = $dir . '/' . (int) $id . '.css';
        if ( file_exists( $file ) ) {
            @unlink( $file );
        }
    }
} );
