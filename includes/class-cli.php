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

    /* ================================================================
     *  CRUD commands for grids, cards, facets, styles
     * ================================================================ */

    /**
     * List all objects of a type (grids, cards, facets, styles).
     *
     * ## OPTIONS
     *
     * <type>
     * : Object type: grids, cards, facets, or styles
     *
     * [--format=<format>]
     * : Output format: table (default) or json
     *
     * ## EXAMPLES
     *
     *     wp viv list grids
     *     wp viv list cards --format=json
     *     wp viv list facets
     *
     * @subcommand list
     */
    public function list_objects( $args, $assoc_args ) {
        global $wpdb;
        $type   = $args[0] ?? '';
        $format = $assoc_args['format'] ?? 'table';
        $table  = self::get_table( $type );
        if ( ! $table ) return;

        $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id" );
        if ( empty( $rows ) ) {
            WP_CLI::line( "No {$type} found." );
            return;
        }

        if ( $format === 'json' ) {
            $out = [];
            foreach ( $rows as $r ) {
                $item = [ 'id' => (int)$r->id, 'name' => $r->name, 'type' => $r->type ?? '' ];
                if ( ! empty( $r->slug ) ) $item['slug'] = $r->slug;
                if ( ! empty( $r->source ) ) $item['source'] = $r->source;
                $item['settings'] = json_decode( $r->settings ?? '{}' );
                $out[] = $item;
            }
            WP_CLI::line( json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
            return;
        }

        // Table format
        foreach ( $rows as $r ) {
            $extra = '';
            if ( $type === 'grids' ) {
                $s = json_decode( $r->settings );
                $viv = ! empty( $s->en_viv_search ) ? 'viv' : 'wpgb';
                $carousel = ! empty( $s->carousel ) ? ' carousel' : '';
                $extra = "  [{$r->type}{$carousel}] source={$viv}";
            } elseif ( $type === 'facets' ) {
                $extra = "  [{$r->type}] slug={$r->slug}";
            } elseif ( $type === 'cards' ) {
                $extra = "  [{$r->type}]";
            }
            WP_CLI::line( "  [{$r->id}] {$r->name}{$extra}" );
        }
    }

    /**
     * Get full settings JSON for a single object.
     *
     * ## OPTIONS
     *
     * <type>
     * : Object type: grid, card, facet, or style
     *
     * --id=<id>
     * : Object ID
     *
     * ## EXAMPLES
     *
     *     wp viv get grid --id=1
     *     wp viv get card --id=3
     *     wp viv get facet --id=16
     *
     * @subcommand get
     */
    public function get_object( $args, $assoc_args ) {
        global $wpdb;
        $type  = $args[0] ?? '';
        $table = self::get_table( $type . 's' );
        if ( ! $table ) return;
        $id = (int) ( $assoc_args['id'] ?? 0 );
        if ( ! $id ) { WP_CLI::error( '--id is required' ); return; }

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
        if ( ! $row ) { WP_CLI::error( ucfirst( $type ) . " {$id} not found." ); return; }

        $out = (array) $row;
        $out['settings'] = json_decode( $out['settings'] ?? '{}' );
        if ( isset( $out['layout'] ) ) $out['layout'] = json_decode( $out['layout'] ?? '[]' );
        WP_CLI::line( json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
    }

    /**
     * Create a new grid, card, facet, or style from JSON.
     *
     * ## OPTIONS
     *
     * <type>
     * : Object type: grid, card, facet, or style
     *
     * --settings=<json>
     * : Full settings JSON (or path to .json file prefixed with @)
     *
     * [--name=<name>]
     * : Object name (can also be in JSON)
     *
     * ## EXAMPLES
     *
     *     wp viv create grid --name="My Grid" --settings='{"type":"masonry","source":"post_type","post_type":["post"]}'
     *     wp viv create facet --settings='{"name":"My Search","type":"search","slug":"my_search"}'
     *     wp viv create grid --settings=@grid-config.json
     *
     * @subcommand create
     */
    public function create_object( $args, $assoc_args ) {
        global $wpdb;
        $type  = $args[0] ?? '';
        $table = self::get_table( $type . 's' );
        if ( ! $table ) return;

        $json_str = $assoc_args['settings'] ?? '{}';
        if ( substr( $json_str, 0, 1 ) === '@' ) {
            $file = substr( $json_str, 1 );
            if ( ! file_exists( $file ) ) { WP_CLI::error( "File not found: {$file}" ); return; }
            $json_str = file_get_contents( $file );
        }
        $data = json_decode( $json_str, true );
        if ( ! is_array( $data ) ) { WP_CLI::error( 'Invalid JSON' ); return; }

        $name = $assoc_args['name'] ?? $data['name'] ?? 'Untitled';
        $now  = current_time( 'mysql', true );

        $row = [
            'name'          => $name,
            'date'          => $now,
            'modified_date' => $now,
            'type'          => $data['type'] ?? 'masonry',
            'settings'      => wp_json_encode( $data ),
        ];

        // Type-specific fields
        if ( $type === 'grid' || $type === 'facet' ) {
            $row['source'] = $data['source'] ?? '';
        }
        if ( $type === 'facet' ) {
            $row['slug'] = $data['slug'] ?? sanitize_title( $name );
        }
        if ( $type === 'card' ) {
            $row['layout'] = wp_json_encode( $data['layout'] ?? [] );
            $row['css']    = $data['css'] ?? '';
        }
        if ( $type === 'style' ) {
            $row['css'] = $data['css'] ?? '';
        }

        $wpdb->insert( $table, $row );
        $new_id = $wpdb->insert_id;

        if ( $new_id ) {
            WP_CLI::success( ucfirst( $type ) . " created with ID {$new_id}: {$name}" );
        } else {
            WP_CLI::error( "Failed to create {$type}: " . $wpdb->last_error );
        }
    }

    /**
     * Update settings for an existing object (merges with existing settings).
     *
     * ## OPTIONS
     *
     * <type>
     * : Object type: grid, card, facet, or style
     *
     * --id=<id>
     * : Object ID to update
     *
     * --settings=<json>
     * : JSON settings to merge (or path to .json file prefixed with @)
     *
     * [--replace]
     * : Replace all settings instead of merging
     *
     * ## EXAMPLES
     *
     *     wp viv update grid --id=1 --settings='{"en_viv_search":true,"carousel":true}'
     *     wp viv update facet --id=16 --settings='{"min_chars":3}'
     *
     * @subcommand update
     */
    public function update_object( $args, $assoc_args ) {
        global $wpdb;
        $type  = $args[0] ?? '';
        $table = self::get_table( $type . 's' );
        if ( ! $table ) return;
        $id = (int) ( $assoc_args['id'] ?? 0 );
        if ( ! $id ) { WP_CLI::error( '--id is required' ); return; }

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT settings FROM {$table} WHERE id = %d", $id ) );
        if ( ! $row ) { WP_CLI::error( ucfirst( $type ) . " {$id} not found." ); return; }

        $json_str = $assoc_args['settings'] ?? '{}';
        if ( substr( $json_str, 0, 1 ) === '@' ) {
            $file = substr( $json_str, 1 );
            if ( ! file_exists( $file ) ) { WP_CLI::error( "File not found: {$file}" ); return; }
            $json_str = file_get_contents( $file );
        }
        $new_data = json_decode( $json_str, true );
        if ( ! is_array( $new_data ) ) { WP_CLI::error( 'Invalid JSON' ); return; }

        if ( isset( $assoc_args['replace'] ) ) {
            $settings = $new_data;
        } else {
            $settings = json_decode( $row->settings, true ) ?: [];
            $settings = array_merge( $settings, $new_data );
        }

        $update = [ 'settings' => wp_json_encode( $settings ), 'modified_date' => current_time( 'mysql', true ) ];
        if ( isset( $new_data['name'] ) ) $update['name'] = $new_data['name'];
        if ( isset( $new_data['type'] ) ) $update['type'] = $new_data['type'];

        $wpdb->update( $table, $update, [ 'id' => $id ] );
        $changed = array_keys( $new_data );
        WP_CLI::success( ucfirst( $type ) . " {$id} updated: " . implode( ', ', $changed ) );
    }

    /**
     * Delete an object.
     *
     * ## OPTIONS
     *
     * <type>
     * : Object type: grid, card, facet, or style
     *
     * --id=<id>
     * : Object ID to delete
     *
     * ## EXAMPLES
     *
     *     wp viv delete grid --id=5
     *
     * @subcommand delete
     */
    public function delete_object( $args, $assoc_args ) {
        global $wpdb;
        $type  = $args[0] ?? '';
        $table = self::get_table( $type . 's' );
        if ( ! $table ) return;
        $id = (int) ( $assoc_args['id'] ?? 0 );
        if ( ! $id ) { WP_CLI::error( '--id is required' ); return; }

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$table} WHERE id = %d", $id ) );
        if ( ! $row ) { WP_CLI::error( ucfirst( $type ) . " {$id} not found." ); return; }

        $wpdb->delete( $table, [ 'id' => $id ] );
        WP_CLI::success( ucfirst( $type ) . " {$id} ({$row->name}) deleted." );
    }

    /**
     * Import WPGB built-in demo content (cards, facets, styles).
     *
     * ## OPTIONS
     *
     * [--cards]
     * : Import demo cards (20 pre-built designs)
     *
     * [--facets]
     * : Import demo facets (8 common types)
     *
     * [--styles]
     * : Import demo styles
     *
     * [--all]
     * : Import everything
     *
     * ## EXAMPLES
     *
     *     wp viv import-demos --all
     *     wp viv import-demos --cards --styles
     *
     * @subcommand import-demos
     */
    public function import_demos( $args, $assoc_args ) {
        global $wpdb;

        $demo_file = WP_CONTENT_DIR . '/plugins/wp-grid-builder/admin/json/demos.json';
        if ( ! file_exists( $demo_file ) ) {
            WP_CLI::error( 'Demo file not found: ' . $demo_file );
            return;
        }

        $data     = json_decode( file_get_contents( $demo_file ), true );
        $all      = isset( $assoc_args['all'] );
        $do_cards = $all || isset( $assoc_args['cards'] );
        $do_fcts  = $all || isset( $assoc_args['facets'] );
        $do_styl  = $all || isset( $assoc_args['styles'] );

        if ( ! $do_cards && ! $do_fcts && ! $do_styl ) {
            WP_CLI::error( 'Specify --cards, --facets, --styles, or --all' );
            return;
        }

        $now     = current_time( 'mysql', true );
        $counts  = [ 'cards' => 0, 'facets' => 0, 'styles' => 0 ];

        if ( $do_cards && ! empty( $data['cards'] ) ) {
            foreach ( $data['cards'] as $card ) {
                $exists = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}wpgb_cards WHERE name = %s", $card['name']
                ) );
                if ( $exists ) { WP_CLI::line( "  Skip: {$card['name']} (already exists, id={$exists})" ); continue; }
                $wpdb->insert( $wpdb->prefix . 'wpgb_cards', [
                    'name'          => $card['name'],
                    'date'          => $now,
                    'modified_date' => $now,
                    'type'          => $card['type'] ?? 'masonry',
                    'settings'      => wp_json_encode( $card['settings'] ?? [] ),
                    'layout'        => wp_json_encode( $card['layout'] ?? [] ),
                    'css'           => $card['css'] ?? '',
                ] );
                $counts['cards']++;
            }
        }

        if ( $do_fcts && ! empty( $data['facets'] ) ) {
            foreach ( $data['facets'] as $facet ) {
                $slug = $facet['slug'] ?? sanitize_title( $facet['name'] );
                $exists = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}wpgb_facets WHERE slug = %s", $slug
                ) );
                if ( $exists ) { WP_CLI::line( "  Skip: {$facet['name']} (slug={$slug} exists, id={$exists})" ); continue; }
                $wpdb->insert( $wpdb->prefix . 'wpgb_facets', [
                    'name'          => $facet['name'],
                    'slug'          => $slug,
                    'date'          => $now,
                    'modified_date' => $now,
                    'type'          => $facet['type'] ?? 'checkbox',
                    'source'        => $facet['source'] ?? '',
                    'settings'      => wp_json_encode( $facet['settings'] ?? [] ),
                ] );
                $counts['facets']++;
            }
        }

        if ( $do_styl && ! empty( $data['styles'] ) ) {
            foreach ( $data['styles'] as $style ) {
                $exists = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}wpgb_styles WHERE name = %s", $style['name']
                ) );
                if ( $exists ) { WP_CLI::line( "  Skip: {$style['name']} (exists, id={$exists})" ); continue; }
                $wpdb->insert( $wpdb->prefix . 'wpgb_styles', [
                    'name'          => $style['name'],
                    'date'          => $now,
                    'modified_date' => $now,
                    'type'          => $style['type'] ?? 'checkbox',
                    'settings'      => wp_json_encode( $style['settings'] ?? [] ),
                    'css'           => $style['css'] ?? '',
                ] );
                $counts['styles']++;
            }
        }

        WP_CLI::success( sprintf(
            'Imported: %d cards, %d facets, %d styles',
            $counts['cards'], $counts['facets'], $counts['styles']
        ) );
    }

    /**
     * Describe all available settings for a WPGB object type.
     *
     * ## OPTIONS
     *
     * <type>
     * : Object type: grid, card, facet, or style
     *
     * ## EXAMPLES
     *
     *     wp viv describe grid
     *     wp viv describe facet
     *
     * @subcommand describe
     */
    public function describe_object( $args, $assoc_args ) {
        $type = $args[0] ?? '';

        $schemas = [
            'grid' => [
                'name'               => [ 'type' => 'string',  'desc' => 'Grid display name' ],
                'type'               => [ 'type' => 'string',  'desc' => 'Layout type', 'values' => 'masonry, metro, justified' ],
                'source'             => [ 'type' => 'string',  'desc' => 'Data source', 'values' => 'post_type, taxonomy, user' ],
                'post_type'          => [ 'type' => 'array',   'desc' => 'Post types to query', 'example' => '["resource","post"]' ],
                'post_status'        => [ 'type' => 'array',   'desc' => 'Post statuses', 'example' => '["publish"]' ],
                'posts_per_page'     => [ 'type' => 'int',     'desc' => 'Items per page (-1 for all)' ],
                'cards'              => [ 'type' => 'array',   'desc' => 'Card IDs (v1 format)', 'example' => '[1]' ],
                'card_types'         => [ 'type' => 'array',   'desc' => 'Card assignments with conditions (v2)', 'example' => '[{"card":1,"conditions":[]}]' ],
                'facets'             => [ 'type' => 'array',   'desc' => 'Facet IDs used by grid', 'example' => '[1,2,5]' ],
                'card_sizes'         => [ 'type' => 'array',   'desc' => 'Responsive column/height per breakpoint' ],
                'grid_layout'        => [ 'type' => 'object',  'desc' => 'Facet placement in areas', 'example' => '{"area-top-1":{"facets":[1,2]}}' ],
                'thumbnail_size'     => [ 'type' => 'string',  'desc' => 'WP image size', 'values' => 'thumbnail, medium, medium_large, large, full' ],
                'carousel'           => [ 'type' => 'boolean', 'desc' => 'Enable carousel/slider mode' ],
                'auto_play'          => [ 'type' => 'int',     'desc' => 'Carousel autoplay ms (0=off)' ],
                'draggable'          => [ 'type' => 'boolean', 'desc' => 'Allow drag to scroll carousel' ],
                'slide_align'        => [ 'type' => 'string',  'desc' => 'Carousel alignment', 'values' => 'left, center, right' ],
                'group_cells'        => [ 'type' => 'int',     'desc' => 'Cards per carousel slide' ],
                'en_viv_search'      => [ 'type' => 'boolean', 'desc' => '(viv) Use viv-addon AJAX instead of WPGB default' ],
                'card_theme_template'=> [ 'type' => 'string',  'desc' => '(viv) PHP template path relative to ABSPATH' ],
                'en_viv_popup'       => [ 'type' => 'boolean', 'desc' => '(viv) Enable card popup lightbox' ],
                'en_viv_mobile_filters' => [ 'type' => 'boolean', 'desc' => '(viv) Enable mobile filter drawer' ],
                'viv_mob_breakpoint' => [ 'type' => 'int',     'desc' => '(viv) Mobile breakpoint in px' ],
            ],
            'card' => [
                'name'             => [ 'type' => 'string',  'desc' => 'Card display name' ],
                'type'             => [ 'type' => 'string',  'desc' => 'Card type', 'values' => 'masonry, metro' ],
                'card_layout'      => [ 'type' => 'string',  'desc' => 'Alignment', 'values' => 'vertical, horizontal' ],
                'display_media'    => [ 'type' => 'boolean', 'desc' => 'Show media/thumbnail section' ],
                'display_overlay'  => [ 'type' => 'boolean', 'desc' => 'Show media overlay layer' ],
                'display_footer'   => [ 'type' => 'boolean', 'desc' => 'Show footer section' ],
                'flex_media'       => [ 'type' => 'boolean', 'desc' => 'Auto-height media' ],
                'responsive'       => [ 'type' => 'boolean', 'desc' => 'Responsive font scaling' ],
                'layout'           => [ 'type' => 'array',   'desc' => 'Card layers + blocks JSON array' ],
            ],
            'facet' => [
                'name'            => [ 'type' => 'string',  'desc' => 'Facet display name' ],
                'slug'            => [ 'type' => 'string',  'desc' => 'URL parameter name (e.g. _category)' ],
                'type'            => [ 'type' => 'string',  'desc' => 'Facet type', 'values' => 'checkbox, radio, button, select, search, autocomplete, range, number, date, color, rating, hierarchy, az_index, selection, sort, pagination, load_more, per_page, result_count, reset, apply' ],
                'source'          => [ 'type' => 'string',  'desc' => 'Data source', 'example' => 'taxonomy/category, post_field/post_author' ],
                'filter_type'     => [ 'type' => 'string',  'desc' => 'Filter behavior type' ],
                'show_empty'      => [ 'type' => 'boolean', 'desc' => 'Show choices with zero results' ],
                'show_count'      => [ 'type' => 'boolean', 'desc' => 'Show result count per choice' ],
                'orderby'         => [ 'type' => 'string',  'desc' => 'Sort choices by', 'values' => 'count, name, facet_order' ],
                'order'           => [ 'type' => 'string',  'desc' => 'Sort direction', 'values' => 'asc, desc' ],
                'logic'           => [ 'type' => 'string',  'desc' => 'Multi-select logic', 'values' => 'AND, OR' ],
                'hierarchical'    => [ 'type' => 'boolean', 'desc' => 'Show as hierarchy tree' ],
            ],
            'style' => [
                'name'  => [ 'type' => 'string', 'desc' => 'Style preset name' ],
                'type'  => [ 'type' => 'string', 'desc' => 'Target type', 'values' => 'checkbox, radio, select, button, ...' ],
                'css'   => [ 'type' => 'string', 'desc' => 'Generated CSS (auto-built from settings)' ],
            ],
        ];

        if ( ! isset( $schemas[ $type ] ) ) {
            WP_CLI::error( "Unknown type: {$type}. Use: grid, card, facet, or style" );
            return;
        }

        WP_CLI::line( "── {$type} settings ──" );
        WP_CLI::line( '' );
        foreach ( $schemas[ $type ] as $key => $info ) {
            $line = "  {$key}";
            $line .= str_repeat( ' ', max( 1, 28 - strlen( $key ) ) );
            $line .= "({$info['type']})  {$info['desc']}";
            if ( ! empty( $info['values'] ) ) $line .= "  [{$info['values']}]";
            if ( ! empty( $info['example'] ) ) $line .= "  e.g. {$info['example']}";
            WP_CLI::line( $line );
        }
        WP_CLI::line( '' );
    }

    /* ================================================================ */

    /**
     * Export a grid with its cards and facets as a portable JSON bundle.
     *
     * ## OPTIONS
     *
     * --id=<id>
     * : Grid ID to export
     *
     * [--file=<path>]
     * : Write to file instead of stdout
     *
     * ## EXAMPLES
     *
     *     wp viv export --id=3
     *     wp viv export --id=3 --file=grid-3-export.json
     *
     * @subcommand export
     */
    public function export_grid( $args, $assoc_args ) {
        global $wpdb;
        $id = (int) ( $assoc_args['id'] ?? 0 );
        if ( ! $id ) { WP_CLI::error( '--id is required' ); return; }

        $grid = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wpgb_grids WHERE id = %d", $id
        ) );
        if ( ! $grid ) { WP_CLI::error( "Grid {$id} not found." ); return; }

        $settings = json_decode( $grid->settings, true );
        $export   = [ 'grid' => (array) $grid ];
        $export['grid']['settings'] = $settings;

        // Collect card IDs
        $card_ids = [];
        if ( ! empty( $settings['card_types'] ) ) {
            foreach ( $settings['card_types'] as $ct ) {
                if ( ! empty( $ct['card'] ) ) $card_ids[] = (int) $ct['card'];
            }
        }
        if ( ! empty( $settings['cards'] ) ) {
            $card_ids = array_merge( $card_ids, array_map( 'intval', (array) $settings['cards'] ) );
        }
        $card_ids = array_unique( array_filter( $card_ids ) );

        // Fetch cards
        $export['cards'] = [];
        if ( $card_ids ) {
            $rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wpgb_cards WHERE id IN (" . implode( ',', $card_ids ) . ")" );
            foreach ( $rows as $r ) {
                $c = (array) $r;
                $c['settings'] = json_decode( $c['settings'] );
                $c['layout']   = json_decode( $c['layout'] );
                $export['cards'][] = $c;
            }
        }

        // Collect facet IDs from grid settings
        $facet_ids = [];
        if ( ! empty( $settings['facets'] ) ) {
            $facet_ids = array_map( 'intval', (array) $settings['facets'] );
        }
        if ( ! empty( $settings['grid_layout'] ) ) {
            foreach ( (array) $settings['grid_layout'] as $area ) {
                if ( ! empty( $area['facets'] ) ) {
                    $facet_ids = array_merge( $facet_ids, array_map( 'intval', (array) $area['facets'] ) );
                }
            }
        }
        $facet_ids = array_unique( array_filter( $facet_ids ) );

        // Fetch facets
        $export['facets'] = [];
        if ( $facet_ids ) {
            $rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wpgb_facets WHERE id IN (" . implode( ',', $facet_ids ) . ")" );
            foreach ( $rows as $r ) {
                $f = (array) $r;
                $f['settings'] = json_decode( $f['settings'] );
                $export['facets'][] = $f;
            }
        }

        $json = json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

        if ( ! empty( $assoc_args['file'] ) ) {
            file_put_contents( $assoc_args['file'], $json );
            WP_CLI::success( "Exported grid {$id} ({$grid->name}) with " . count( $export['cards'] ) . " cards, " . count( $export['facets'] ) . " facets to {$assoc_args['file']}" );
        } else {
            WP_CLI::line( $json );
        }
    }

    /**
     * Trigger WPGB facet index rebuild.
     *
     * ## OPTIONS
     *
     * [--facet=<id>]
     * : Reindex a specific facet ID only
     *
     * ## EXAMPLES
     *
     *     wp viv reindex
     *     wp viv reindex --facet=1
     *
     * @subcommand reindex
     */
    public function reindex( $args, $assoc_args ) {
        global $wpdb;

        $facet_id = ! empty( $assoc_args['facet'] ) ? (int) $assoc_args['facet'] : 0;

        if ( $facet_id ) {
            $facet = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, name, slug FROM {$wpdb->prefix}wpgb_facets WHERE id = %d", $facet_id
            ) );
            if ( ! $facet ) { WP_CLI::error( "Facet {$facet_id} not found." ); return; }

            // Clear existing index entries for this facet
            $deleted = $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}wpgb_index WHERE slug = %s", $facet->slug
            ) );
            WP_CLI::line( "Cleared {$deleted} index entries for facet {$facet->name} ({$facet->slug})" );
            WP_CLI::line( "To fully reindex, use WP Admin → WP Grid Builder → Settings → Index." );
        } else {
            // Clear entire index
            $count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpgb_index" );
            $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}wpgb_index" );
            WP_CLI::line( "Cleared {$count} index entries." );
            WP_CLI::line( "To rebuild the index, visit WP Admin → WP Grid Builder → Settings → Index." );
            WP_CLI::line( "Or trigger via REST: POST /wp-json/wpgb/v2/settings with indexer action." );
        }

        WP_CLI::success( 'Index cleared. Rebuild via WPGB admin to repopulate.' );
    }

    /* ================================================================ */

    /**
     * Helper: print a pass/fail check line.
     */
    private static function check( $label, $pass, $detail = '' ) {
        $mark = $pass ? '✓' : '✗';
        $line = "  {$mark} {$label}";
        if ( $detail ) $line .= "  — {$detail}";
        WP_CLI::line( $line );
    }

    /**
     * Helper: resolve table name from object type string.
     */
    private static function get_table( $type ) {
        global $wpdb;
        $map = [
            'grids'  => $wpdb->prefix . 'wpgb_grids',
            'cards'  => $wpdb->prefix . 'wpgb_cards',
            'facets' => $wpdb->prefix . 'wpgb_facets',
            'styles' => $wpdb->prefix . 'wpgb_styles',
        ];
        if ( ! isset( $map[ $type ] ) ) {
            WP_CLI::error( "Unknown type: {$type}. Use: grids, cards, facets, or styles" );
            return null;
        }
        return $map[ $type ];
    }
}
