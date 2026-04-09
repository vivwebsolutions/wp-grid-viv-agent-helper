<?php
/**
 * Auto-Register: scans active plugins for viv facet/block classes and keeps
 * the vivgb_data WP option in sync — no activation hook required.
 *
 * Runs on plugins_loaded (priority 20, after viv-addon loads at default 10).
 * Result is cached in a transient; cleared whenever a plugin is activated/deactivated.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Viv_Auto_Register {

    const TRANSIENT = 'viv_agent_registered';

    public static function init() {
        add_action( 'plugins_loaded', [ __CLASS__, 'maybe_sync' ], 20 );
        add_action( 'activated_plugin',   [ __CLASS__, 'clear_cache' ] );
        add_action( 'deactivated_plugin', [ __CLASS__, 'clear_cache' ] );
    }

    /**
     * Sync vivgb_data with all installed viv plugins.
     * Skips entirely if the transient says we're already up to date.
     */
    public static function maybe_sync() {
        if ( get_transient( self::TRANSIENT ) ) {
            return;
        }
        self::sync();
        set_transient( self::TRANSIENT, true, DAY_IN_SECONDS );
    }

    /**
     * Scan plugins directory, find anything with lib/register-facet.php or
     * lib/register-block.php, register it in vivgb_data if not already there.
     *
     * @return array Summary of what was added.
     */
    public static function sync() {
        $vivgb_data = get_option( 'vivgb_data', [] );
        if ( ! is_array( $vivgb_data ) ) {
            $vivgb_data = [];
        }
        if ( ! isset( $vivgb_data['plugins'] ) ) {
            $vivgb_data['plugins'] = [];
        }

        $added   = [];
        $plugins_dir = WP_CONTENT_DIR . '/plugins/';

        // Find all register-facet.php files in plugin lib/ directories
        foreach ( glob( $plugins_dir . '*/lib/register-facet.php' ) as $facet_file ) {
            $plugin_dir  = basename( dirname( dirname( $facet_file ) ) );
            $type        = self::get_facet_type_from_file( $facet_file );

            if ( ! $type ) continue;

            if ( empty( $vivgb_data['plugins'][ $type ] ) ) {
                $vivgb_data['plugins'][ $type ] = [ 'dir' => $plugin_dir ];
                $added[] = $type;
            }
        }

        if ( ! empty( $added ) ) {
            update_option( 'vivgb_data', $vivgb_data );
        }

        return [
            'added'   => $added,
            'plugins' => $vivgb_data['plugins'],
        ];
    }

    /**
     * Include a register-facet.php in an isolated scope, run the filter,
     * and return the first new facet type key it registered.
     *
     * @param string $file Absolute path to register-facet.php
     * @return string|null  Facet type key, or null if nothing found.
     */
    private static function get_facet_type_from_file( $file ) {
        // Snapshot before
        $before = apply_filters( 'wp_grid_builder/facets', [] );

        // Include the plugin's registration file — this calls add_filter()
        include_once $file;

        // Snapshot after
        $after = apply_filters( 'wp_grid_builder/facets', [] );

        $new_types = array_diff_key( $after, $before );
        if ( empty( $new_types ) ) {
            return null;
        }

        return array_key_first( $new_types );
    }

    public static function clear_cache() {
        delete_transient( self::TRANSIENT );
    }

    /**
     * Return current state for diagnostics.
     *
     * @return array
     */
    public static function get_status() {
        $vivgb_data = get_option( 'vivgb_data', [] );
        $plugins    = $vivgb_data['plugins'] ?? [];
        $status     = [];

        foreach ( $plugins as $type => $info ) {
            $dir   = $info['dir'] ?? '';
            $path  = WP_CONTENT_DIR . '/plugins/' . $dir . '/lib/register-facet.php';
            $status[ $type ] = [
                'dir'        => $dir,
                'file_exists' => file_exists( $path ),
            ];
        }

        return $status;
    }
}

Viv_Auto_Register::init();
