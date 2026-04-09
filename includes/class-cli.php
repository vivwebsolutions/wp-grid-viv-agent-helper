<?php
/**
 * WP-CLI commands: wp viv <command>
 *
 * Usage examples:
 *   wp viv register-plugins
 *   wp viv verify-grid --id=1
 *   wp viv patch-grid --id=2 --card-template=wp-content/viv-card-template.php
 *   wp viv status
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Viv_Agent_CLI {

    /**
     * Scan all installed plugins and register any viv plugins in vivgb_data.
     * Safe to run multiple times.
     *
     * ## EXAMPLES
     *
     *     wp viv register-plugins
     *
     * @subcommand register-plugins
     */
    public function register_plugins( $args, $assoc_args ) {
        $result = Viv_Auto_Register::sync();
        Viv_Auto_Register::clear_cache();

        if ( empty( $result['added'] ) ) {
            WP_CLI::success( 'vivgb_data already up to date. Registered plugins: ' . implode( ', ', array_keys( $result['plugins'] ) ) );
        } else {
            WP_CLI::success( 'Registered new plugins: ' . implode( ', ', $result['added'] ) );
        }

        WP_CLI::line( '' );
        WP_CLI::line( 'Current vivgb_data plugins:' );
        foreach ( $result['plugins'] as $type => $info ) {
            $dir  = $info['dir'] ?? '?';
            $path = WP_CONTENT_DIR . '/plugins/' . $dir . '/lib/register-facet.php';
            $mark = file_exists( $path ) ? '✓' : '✗ (file missing)';
            WP_CLI::line( "  {$mark} {$type} → {$dir}" );
        }
    }

    /**
     * Check a WPGB grid has all required viv-addon settings.
     *
     * ## OPTIONS
     *
     * [--id=<id>]
     * : Grid ID to verify (default: all grids)
     *
     * ## EXAMPLES
     *
     *     wp viv verify-grid --id=1
     *     wp viv verify-grid
     *
     * @subcommand verify-grid
     */
    public function verify_grid( $args, $assoc_args ) {
        global $wpdb;

        $where = '';
        if ( ! empty( $assoc_args['id'] ) ) {
            $where = $wpdb->prepare( 'WHERE id = %d', (int) $assoc_args['id'] );
        }

        $grids = $wpdb->get_results( "SELECT id, name, settings FROM {$wpdb->prefix}wpgb_grids $where" );

        if ( empty( $grids ) ) {
            WP_CLI::error( 'No grids found.' );
            return;
        }

        foreach ( $grids as $grid ) {
            $s = json_decode( $grid->settings );
            WP_CLI::line( '' );
            WP_CLI::line( "── Grid {$grid->id}: {$grid->name} ──" );

            self::check( 'en_viv_search',
                ! empty( $s->en_viv_search ) && $s->en_viv_search === true,
                'Set to true so viv-addon takes over AJAX rendering'
            );

            $tpl = $s->card_theme_template ?? '';
            self::check( 'card_theme_template',
                ! empty( $tpl ) && file_exists( ABSPATH . $tpl ),
                empty( $tpl )
                    ? 'Not set — cards will render empty'
                    : "Set to '{$tpl}'" . ( file_exists( ABSPATH . $tpl ) ? '' : ' — FILE NOT FOUND' )
            );

            $layout = $s->grid_layout ?? null;
            $layout_ok = is_object( $layout ) && ! empty( (array) $layout );
            self::check( 'grid_layout format',
                $layout_ok,
                $layout_ok
                    ? 'Object with ' . count( (array) $layout ) . ' areas: ' . implode( ', ', array_keys( (array) $layout ) )
                    : 'Missing or wrong format (must be JSON object with area-name keys)'
            );

            $post_type = $s->post_type ?? $s->source ?? '?';
            WP_CLI::line( "  ℹ source: " . ( is_array( $post_type ) ? implode( ', ', $post_type ) : $post_type ) );
        }

        WP_CLI::line( '' );
    }

    /**
     * Patch a grid's viv-addon settings without touching other settings.
     *
     * ## OPTIONS
     *
     * --id=<id>
     * : Grid ID to patch
     *
     * [--en-viv-search]
     * : Set en_viv_search to true
     *
     * [--card-template=<path>]
     * : Set card_theme_template (relative to ABSPATH, e.g. wp-content/viv-card-template.php)
     *
     * [--layout=<json>]
     * : Set grid_layout as JSON string
     *
     * ## EXAMPLES
     *
     *     wp viv patch-grid --id=2 --en-viv-search --card-template=wp-content/viv-card-template.php
     *
     * @subcommand patch-grid
     */
    public function patch_grid( $args, $assoc_args ) {
        global $wpdb;

        if ( empty( $assoc_args['id'] ) ) {
            WP_CLI::error( '--id is required' );
            return;
        }

        $id  = (int) $assoc_args['id'];
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT settings FROM {$wpdb->prefix}wpgb_grids WHERE id = %d", $id
        ) );

        if ( ! $row ) {
            WP_CLI::error( "Grid {$id} not found." );
            return;
        }

        $settings = json_decode( $row->settings, true );
        $changed  = [];

        if ( isset( $assoc_args['en-viv-search'] ) ) {
            $settings['en_viv_search'] = true;
            $changed[] = 'en_viv_search = true';
        }

        if ( ! empty( $assoc_args['card-template'] ) ) {
            $tpl = $assoc_args['card-template'];
            if ( ! file_exists( ABSPATH . $tpl ) ) {
                WP_CLI::warning( "card-template file not found at ABSPATH/{$tpl} — setting anyway." );
            }
            $settings['card_theme_template'] = $tpl;
            $changed[] = "card_theme_template = {$tpl}";
        }

        if ( ! empty( $assoc_args['layout'] ) ) {
            $layout = json_decode( $assoc_args['layout'] );
            if ( ! $layout ) {
                WP_CLI::error( 'Invalid JSON for --layout' );
                return;
            }
            $settings['grid_layout'] = $layout;
            $changed[] = 'grid_layout updated';
        }

        if ( empty( $changed ) ) {
            WP_CLI::warning( 'Nothing to change. Use --en-viv-search, --card-template, or --layout.' );
            return;
        }

        $wpdb->update(
            $wpdb->prefix . 'wpgb_grids',
            [ 'settings' => wp_json_encode( $settings ) ],
            [ 'id' => $id ]
        );

        WP_CLI::success( "Grid {$id} patched: " . implode( ', ', $changed ) );
    }

    /**
     * Show full diagnostic status: vivgb_data, grids, PHP settings.
     *
     * ## EXAMPLES
     *
     *     wp viv status
     *
     * @subcommand status
     */
    public function status( $args, $assoc_args ) {
        global $wpdb;

        WP_CLI::line( '── vivgb_data plugins ──' );
        $plugin_status = Viv_Auto_Register::get_status();

        if ( empty( $plugin_status ) ) {
            WP_CLI::line( '  ✗ vivgb_data is empty — run: wp viv register-plugins' );
        } else {
            foreach ( $plugin_status as $type => $info ) {
                $mark = $info['file_exists'] ? '✓' : '✗ (file missing)';
                WP_CLI::line( "  {$mark} {$type} → {$info['dir']}" );
            }
        }

        WP_CLI::line( '' );
        WP_CLI::line( '── Grids ──' );
        $grids = $wpdb->get_results( "SELECT id, name, settings FROM {$wpdb->prefix}wpgb_grids" );

        foreach ( $grids as $grid ) {
            $s          = json_decode( $grid->settings );
            $viv        = ! empty( $s->en_viv_search ) ? '✓ viv' : '✗ viv';
            $tpl        = ! empty( $s->card_theme_template ) ? '✓ tpl' : '✗ tpl';
            $layout_fmt = is_object( $s->grid_layout ?? null ) ? '✓ layout' : '✗ layout';
            WP_CLI::line( "  [{$grid->id}] {$grid->name}  {$viv}  {$tpl}  {$layout_fmt}" );
        }

        WP_CLI::line( '' );
        WP_CLI::line( '── PHP ──' );
        WP_CLI::line( '  PHP version:     ' . PHP_VERSION );
        WP_CLI::line( '  display_errors:  ' . ini_get('display_errors') );
        WP_CLI::line( '  WP version:      ' . get_bloginfo('version') );
    }

    /**
     * Helper: print a pass/fail check line.
     */
    private static function check( $label, $pass, $detail = '' ) {
        $mark = $pass ? '✓' : '✗';
        $line = "  {$mark} {$label}";
        if ( $detail ) $line .= "  — {$detail}";
        WP_CLI::line( $line );
    }
}
