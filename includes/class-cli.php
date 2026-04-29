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
            $dir       = $info['dir'] ?? '?';
            $facet_php = WP_CONTENT_DIR . '/plugins/' . $dir . '/lib/register-facet.php';
            $plugin_dir = WP_CONTENT_DIR . '/plugins/' . $dir;
            if ( file_exists( $facet_php ) ) {
                $mark = '✓';
            } elseif ( is_dir( $plugin_dir ) ) {
                // Plugin is installed but isn't a facet plugin (no
                // lib/register-facet.php). Common for indexer/UI plugins
                // like wp-grid-viv-better-search, popup, highlighter.
                $mark = '⚙ utility';
            } else {
                $mark = '✗ (plugin dir missing)';
            }
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
            $has_native = ! empty( $s->card_types ) && is_array( $s->card_types );
            self::check( 'card_theme_template',
                ! empty( $tpl ) && file_exists( ABSPATH . $tpl ) || $has_native,
                empty( $tpl )
                    ? ( $has_native ? 'Not set — using native card_types instead (OK)' : 'Not set AND no card_types — cards will render empty' )
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

            // viv-addon's AJAX path renders both PHP-template cards AND
            // native card_types just fine, so the only real mismatch is
            // when card_theme_template is set but en_viv_search isn't —
            // then the template is dead code (native rendering won't use it).
            $is_viv    = ! empty( $s->en_viv_search ) && $s->en_viv_search === true;
            $has_tpl   = ! empty( $s->card_theme_template );
            if ( ! $is_viv && $has_tpl ) {
                WP_CLI::warning( "  ⚠ MISMATCH: en_viv_search=false but card_theme_template is set. The PHP template won't be used by native WPGB rendering. Either set en_viv_search=true to use the template, or clear the template path." );
            }

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
     * [--en-viv-search=<bool>]
     * : Set en_viv_search to true|false. Bare flag form (--en-viv-search)
     *   without a value still defaults to true for backwards compatibility.
     *
     * [--card-template=<path>]
     * : Set card_theme_template (relative to ABSPATH, e.g. wp-content/viv-card-template.php).
     *   Pass empty string to clear.
     *
     * [--layout=<json>]
     * : Set grid_layout as JSON string
     *
     * [--mobile-filters=<bool>]
     * : Set en_viv_mobile_filters (true|false)
     *
     * [--mobile-breakpoint=<px>]
     * : Set viv_mob_breakpoint (e.g. 992)
     *
     * ## EXAMPLES
     *
     *     wp viv patch-grid --id=2 --en-viv-search=true --card-template=wp-content/viv-card-template.php
     *     wp viv patch-grid --id=14 --en-viv-search=false  # disable AJAX rendering
     *     wp viv patch-grid --id=38 --mobile-filters=true --mobile-breakpoint=768
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

        // Coerce --en-viv-search=true|false; bare flag still means true.
        $to_bool = function( $v ) {
            if ( is_bool( $v ) ) return $v;
            $v = strtolower( (string) $v );
            return ! in_array( $v, [ 'false', '0', 'no', 'off', '' ], true );
        };

        if ( array_key_exists( 'en-viv-search', $assoc_args ) ) {
            $val = $to_bool( $assoc_args['en-viv-search'] );
            $settings['en_viv_search'] = $val;
            $changed[] = 'en_viv_search = ' . ( $val ? 'true' : 'false' );
        }

        if ( array_key_exists( 'mobile-filters', $assoc_args ) ) {
            $val = $to_bool( $assoc_args['mobile-filters'] );
            $settings['en_viv_mobile_filters'] = $val;
            $changed[] = 'en_viv_mobile_filters = ' . ( $val ? 'true' : 'false' );
        }

        if ( array_key_exists( 'mobile-breakpoint', $assoc_args ) ) {
            $bp = (int) $assoc_args['mobile-breakpoint'];
            $settings['viv_mob_breakpoint'] = $bp;
            $changed[] = "viv_mob_breakpoint = {$bp}";
        }

        if ( array_key_exists( 'card-template', $assoc_args ) ) {
            $tpl = (string) $assoc_args['card-template'];
            if ( '' === $tpl ) {
                $settings['card_theme_template'] = '';
                $changed[] = 'card_theme_template = (cleared)';
            } else {
                if ( ! file_exists( ABSPATH . $tpl ) ) {
                    WP_CLI::warning( "card-template file not found at ABSPATH/{$tpl} — setting anyway." );
                }
                $settings['card_theme_template'] = $tpl;
                $changed[] = "card_theme_template = {$tpl}";
            }
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
            WP_CLI::warning( 'Nothing to change. Use --en-viv-search, --card-template, --layout, --mobile-filters, or --mobile-breakpoint.' );
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
                switch ( $info['kind'] ?? ( $info['file_exists'] ? 'facet' : 'missing' ) ) {
                    case 'facet':   $mark = '✓'; break;
                    case 'utility': $mark = '⚙ utility'; break;
                    default:        $mark = '✗ (plugin dir missing)';
                }
                WP_CLI::line( "  {$mark} {$type} → {$info['dir']}" );
            }
        }

        WP_CLI::line( '' );
        WP_CLI::line( '── Grids ──' );
        $grids = $wpdb->get_results( "SELECT id, name, settings FROM {$wpdb->prefix}wpgb_grids" );

        foreach ( $grids as $grid ) {
            $s          = json_decode( $grid->settings );
            $is_viv     = ! empty( $s->en_viv_search ) && $s->en_viv_search === true;
            $has_tpl    = ! empty( $s->card_theme_template );
            // Layout is OK if it's an object (facets wired) OR an empty
            // array (sliders/carousels with no toolbar facets — valid).
            // Only flag if it's null/missing or a non-empty array (which
            // would suggest a malformed serialization).
            $gl = $s->grid_layout ?? null;
            if ( is_object( $gl ) ) {
                $layout_fmt = '✓ layout';
            } elseif ( is_array( $gl ) && empty( $gl ) ) {
                $layout_fmt = '✓ layout (empty)';
            } else {
                $layout_fmt = '✗ layout';
            }
            $type       = ! empty( $s->type ) ? $s->type : 'masonry';
            $carousel   = ! empty( $s->carousel ) ? ' carousel' : '';

            // Determine rendering source
            $source = $is_viv ? 'viv' : 'wpgb';

            // Warn on real mismatches only. en_viv_search=true with
            // native card_types and NO PHP template is fine — viv-addon's
            // AJAX path renders native cards too. Only flag if the legacy
            // expectation (PHP template required) was clearly intended:
            // when there's both card_theme_template AND en_viv_search=false.
            $warn = '';
            if ( ! $is_viv && $has_tpl ) {
                // PHP template configured but rendering native — template is dead code.
                $warn = ' ⚠ MISMATCH (template unused)';
            }

            WP_CLI::line( "  [{$grid->id}] {$grid->name}  [{$type}{$carousel}] source={$source}  {$layout_fmt}{$warn}" );
        }

        WP_CLI::line( '' );
        WP_CLI::line( '── PHP ──' );
        WP_CLI::line( '  PHP version:     ' . PHP_VERSION );
        WP_CLI::line( '  display_errors:  ' . ini_get('display_errors') );
        WP_CLI::line( '  WP version:      ' . get_bloginfo('version') );
    }

    /**
     * Audit all facets for silent-broken state: wired into a grid layout
     * yet have 0 rows in wp_wpgb_index. These render fine but never match
     * any post when filtered.
     *
     * Skips facet types that don't index (selection, search, viv_parent,
     * viv_toggle, load_more, pagination, per_page, result_count, reset).
     *
     * ## EXAMPLES
     *
     *     wp viv audit-facets
     *
     * @subcommand audit-facets
     */
    public function audit_facets( $args, $assoc_args ) {
        global $wpdb;

        // Facet types that legitimately don't store rows in wp_wpgb_index:
        // - selection/search/sort/reset/load_more/pagination/per_page/result_count: utility facets that don't filter via index
        // - viv_parent: groups other facets in an accordion, no own data
        // - viv_toggle: inverts an excluded_value at query time
        // - viv_save_search: lets user persist the current filter state, no own index
        // - viv_bookmark: filters to "my bookmarks" at runtime via VIV_BOO data
        // - viv_autocomplete: uses better-search engine, not the WPGB index
        $no_index_types = [
            'selection', 'search', 'sort', 'reset',
            'load_more', 'pagination', 'per_page', 'result_count',
            'viv_parent', 'viv_toggle',
            'viv_save_search', 'viv_save_search2',
            'viv_bookmark', 'viv_autocomplete',
        ];

        // Build map of facet_id -> grid_name for facets actually wired into a layout.
        $used = [];
        $grids = $wpdb->get_results( "SELECT id, name, settings FROM {$wpdb->prefix}wpgb_grids" );
        foreach ( $grids as $g ) {
            $s = json_decode( $g->settings, true );
            $layout = $s['grid_layout'] ?? null;
            if ( ! is_array( $layout ) ) continue;
            foreach ( $layout as $cfg ) {
                if ( ! is_array( $cfg ) ) continue;
                $facets_in_area = $cfg['facets'] ?? null;
                if ( ! is_array( $facets_in_area ) ) continue;
                foreach ( $facets_in_area as $fid ) {
                    $used[ (int) $fid ] = $g->name;
                }
            }
        }

        $facets = $wpdb->get_results( "SELECT id, name, slug, type, source FROM {$wpdb->prefix}wpgb_facets ORDER BY id" );
        $broken = [];
        foreach ( $facets as $f ) {
            if ( ! isset( $used[ (int) $f->id ] ) ) continue;
            if ( in_array( $f->type, $no_index_types, true ) ) continue;
            $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpgb_index WHERE slug = %s", $f->slug ) );
            if ( $count === 0 ) {
                $broken[] = "  ⚠ [{$f->id}] type={$f->type} slug={$f->slug} source=\"{$f->source}\" — used in grid \"" . $used[ (int) $f->id ] . "\" — 0 index entries";
            }
        }

        WP_CLI::line( '── Facet health audit ──' );
        WP_CLI::line( '  Total facets: ' . count( $facets ) );
        WP_CLI::line( '  Used in grids: ' . count( $used ) );
        WP_CLI::line( '' );
        if ( empty( $broken ) ) {
            WP_CLI::success( 'All wired facets have non-empty indexes.' );
        } else {
            WP_CLI::warning( count( $broken ) . ' facet(s) wired into a grid but indexed empty:' );
            foreach ( $broken as $line ) WP_CLI::line( $line );
            WP_CLI::line( '' );
            WP_CLI::line( 'Common causes: wrong "source" column value (e.g. "post_date" instead of "post_field/post_date"); taxonomy slug mismatch; meta_key not yet present on any post.' );
            WP_CLI::line( 'See Viv-docs#116 for a worked example.' );
        }
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
            'type'          => $data['type'] ?? ( $type === 'facet' ? 'checkbox' : 'masonry' ),
            'settings'      => wp_json_encode( $data ),
        ];

        // Type-specific fields
        if ( $type === 'grid' || $type === 'facet' ) {
            $row['source'] = $data['source'] ?? '';
        }
        if ( $type === 'facet' ) {
            $row['slug'] = $data['slug'] ?? sanitize_title( $name );
            // Validate facet type to prevent wrong values (e.g., 'masonry')
            $valid_facet_types = [ 'checkbox', 'radio', 'button', 'select', 'search', 'autocomplete', 'range', 'number', 'date', 'color', 'rating', 'hierarchy', 'az_index', 'selection', 'sort', 'pagination', 'load_more', 'per_page', 'result_count', 'reset', 'apply', 'viv_toggle', 'viv_parent', 'viv_save_search', 'viv_bookmark', 'viv_map', 'viv_autocomplete', 'custom_html' ];
            if ( ! in_array( $row['type'], $valid_facet_types, true ) ) {
                WP_CLI::warning( "Facet type '{$row['type']}' is not a known facet type. Valid types: " . implode( ', ', $valid_facet_types ) );
            }
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
     * [--grids]
     * : Import demo grids (Blog, Portfolio, eCommerce)
     *
     * [--all]
     * : Import everything (32 items)
     *
     * ## EXAMPLES
     *
     *     wp viv import-demos --all
     *     wp viv import-demos --cards --facets --grids
     *
     * @subcommand import-demos
     */
    public function import_demos( $args, $assoc_args ) {

        $demo_file = WP_CONTENT_DIR . '/plugins/wp-grid-builder/admin/json/demos.json';
        if ( ! file_exists( $demo_file ) ) {
            WP_CLI::error( 'Demo file not found: ' . $demo_file );
            return;
        }

        $data = json_decode( file_get_contents( $demo_file ), true );
        $all  = isset( $assoc_args['all'] );

        // Build content array with only requested types
        $content = [];
        if ( $all || isset( $assoc_args['cards'] ) )  $content['cards']  = $data['cards']  ?? [];
        if ( $all || isset( $assoc_args['facets'] ) )  $content['facets'] = $data['facets'] ?? [];
        if ( $all || isset( $assoc_args['styles'] ) )  $content['styles'] = $data['styles'] ?? [];
        if ( $all || isset( $assoc_args['grids'] ) )   $content['grids']  = $data['grids']  ?? [];

        if ( empty( $content ) ) {
            WP_CLI::error( 'Specify --cards, --facets, --styles, --grids, or --all' );
            return;
        }

        // Use WPGB's own Import class for proper sanitization and CSS generation.
        // Raw SQL inserts produce empty cards because the v2 layout format needs normalization.
        $import_class = 'WP_Grid_Builder\Includes\Routes\Import';
        if ( ! class_exists( $import_class ) ) {
            WP_CLI::error( 'WPGB Import class not found. Is WP Grid Builder active?' );
            return;
        }

        $import  = new $import_class();
        $request = new \WP_REST_Request( 'POST', '/wpgb/v2/import' );
        $request->set_param( 'content', $content );

        $response = $import->import( $request );
        $result   = $response->get_data();

        WP_CLI::success( $result['message'] ?? 'Import complete.' );
    }

    /**
     * Describe all available settings for a WPGB object type.
     *
     * ## OPTIONS
     *
     * <type>
     * : Object type: grid, card, facet, or style
     *
     * [--format=<format>]
     * : Output format: table (default) or json
     *
     * ## EXAMPLES
     *
     *     wp viv describe grid
     *     wp viv describe facet
     *     wp viv describe grid --format=json
     *
     * @subcommand describe
     */
    public function describe_object( $args, $assoc_args ) {
        $type    = $args[0] ?? '';
        $format  = $assoc_args['format'] ?? 'table';
        $schemas = self::get_describe_schemas();

        if ( ! isset( $schemas[ $type ] ) ) {
            WP_CLI::error( "Unknown type: {$type}. Use: grid, card, facet, or style" );
            return;
        }

        if ( 'json' === $format ) {
            WP_CLI::line( wp_json_encode(
                [
                    'type'     => $type,
                    'settings' => $schemas[ $type ],
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ) );
            return;
        }

        WP_CLI::line( "── {$type} settings ──" );
        WP_CLI::line( '' );
        foreach ( $schemas[ $type ] as $key => $info ) {
            $line = "  {$key}";
            $line .= str_repeat( ' ', max( 1, 28 - strlen( $key ) ) );
            $line .= "({$info['type']})  {$info['desc']}";
            if ( ! empty( $info['values'] ) ) {
                $line .= "  [{$info['values']}]";
            }
            if ( array_key_exists( 'default', $info ) && '' !== $info['default'] && null !== $info['default'] ) {
                $line .= "  default={$info['default']}";
            }
            if ( ! empty( $info['applies_to'] ) ) {
                $line .= "  applies_to={$info['applies_to']}";
            }
            if ( ! empty( $info['example'] ) ) {
                $line .= "  e.g. {$info['example']}";
            }
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
     * Rebuild the WPGB facet index from scratch.
     *
     * Walks every published post and re-runs the indexer for each. By
     * default also clears existing index entries first. Use --clear-only
     * to clear without rebuilding.
     *
     * ## OPTIONS
     *
     * [--facet=<id>]
     * : Reindex a specific facet's slug only (still walks all posts; only
     *   the named facet's index rows are deleted/rebuilt).
     *
     * [--clear-only]
     * : Clear without rebuilding. Useful before manual edits.
     *
     * ## EXAMPLES
     *
     *     wp viv reindex                  # Clear all + rebuild for every post
     *     wp viv reindex --facet=1        # Clear + rebuild slug for facet 1
     *     wp viv reindex --clear-only     # Clear all without rebuilding
     *
     * @subcommand reindex
     */
    public function reindex( $args, $assoc_args ) {
        global $wpdb;

        $facet_id   = ! empty( $assoc_args['facet'] ) ? (int) $assoc_args['facet'] : 0;
        $clear_only = ! empty( $assoc_args['clear-only'] );

        if ( $facet_id ) {
            $facet = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, name, slug FROM {$wpdb->prefix}wpgb_facets WHERE id = %d", $facet_id
            ) );
            if ( ! $facet ) { WP_CLI::error( "Facet {$facet_id} not found." ); return; }

            $deleted = $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}wpgb_index WHERE slug = %s", $facet->slug
            ) );
            WP_CLI::line( "Cleared {$deleted} index entries for facet {$facet->name} ({$facet->slug})" );

            if ( $clear_only ) {
                WP_CLI::success( 'Cleared. Run without --clear-only to rebuild.' );
                return;
            }

            // Rebuild: walk every post that could match this facet and reindex
            if ( ! class_exists( '\WP_Grid_Builder\Includes\Indexer' ) ) {
                WP_CLI::error( 'WPGB Indexer class not available.' );
                return;
            }
            $indexer  = new \WP_Grid_Builder\Includes\Indexer();
            $post_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish'" );
            $count    = 0;
            foreach ( $post_ids as $pid ) {
                $indexer->index_object_id( (int) $pid, 'post' );
                $count++;
            }
            $new_entries = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}wpgb_index WHERE slug = %s", $facet->slug
            ) );
            WP_CLI::success( "Reindexed {$count} posts. Facet now has {$new_entries} index entries." );
        } else {
            $count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpgb_index" );
            $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}wpgb_index" );
            WP_CLI::line( "Cleared {$count} index entries." );

            if ( $clear_only ) {
                WP_CLI::success( 'Cleared. Run without --clear-only to rebuild, or use the WPGB admin Index page.' );
                return;
            }

            if ( ! class_exists( '\WP_Grid_Builder\Includes\Indexer' ) ) {
                WP_CLI::error( 'WPGB Indexer class not available.' );
                return;
            }
            $indexer  = new \WP_Grid_Builder\Includes\Indexer();
            $post_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish'" );
            foreach ( $post_ids as $pid ) {
                $indexer->index_object_id( (int) $pid, 'post' );
            }
            $new_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpgb_index" );
            WP_CLI::success( "Reindexed " . count( $post_ids ) . " posts. Index now has {$new_total} entries." );
        }
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

    /**
     * Helper: schema metadata for wp viv describe.
     */
    private static function get_describe_schemas() {
        return [
            'grid' => [
                'name'                     => [ 'type' => 'string',  'desc' => 'Grid display name' ],
                'type'                     => [ 'type' => 'string',  'desc' => 'Layout type', 'values' => 'masonry, metro, justified' ],
                'source'                   => [ 'type' => 'string',  'desc' => 'Data source', 'values' => 'post_type, taxonomy, user' ],
                'post_type'                => [ 'type' => 'array',   'desc' => 'Post types to query', 'example' => '["resource","post"]' ],
                'post_status'              => [ 'type' => 'array',   'desc' => 'Post statuses', 'example' => '["publish"]' ],
                'posts_per_page'           => [ 'type' => 'int',     'desc' => 'Items per page (-1 for all)' ],
                'cards'                    => [ 'type' => 'array',   'desc' => 'Card IDs (v1 format)', 'example' => '[1]' ],
                'card_types'               => [ 'type' => 'array',   'desc' => 'Card assignments with conditions (v2)', 'example' => '[{"card":1,"conditions":[]}]' ],
                'facets'                   => [ 'type' => 'array',   'desc' => 'Facet IDs used by grid', 'example' => '[1,2,5]' ],
                'card_sizes'               => [ 'type' => 'array',   'desc' => 'Responsive column/height per breakpoint' ],
                'grid_layout'              => [ 'type' => 'object',  'desc' => 'Facet placement in areas', 'example' => '{"area-top-1":{"facets":[1,2]}}' ],
                'thumbnail_size'           => [ 'type' => 'string',  'desc' => 'WP image size', 'values' => 'thumbnail, medium, medium_large, large, full' ],
                'carousel'                 => [ 'type' => 'boolean', 'desc' => 'Enable carousel/slider mode', 'default' => 'false' ],
                'auto_play'                => [ 'type' => 'int',     'desc' => 'Carousel autoplay ms (0=off)', 'default' => '0' ],
                'draggable'                => [ 'type' => 'boolean', 'desc' => 'Allow drag to scroll carousel' ],
                'slide_align'              => [ 'type' => 'string',  'desc' => 'Carousel alignment', 'values' => 'left, center, right' ],
                'group_cells'              => [ 'type' => 'int',     'desc' => 'Cards per carousel slide' ],
                'en_viv_search'            => [ 'type' => 'boolean', 'desc' => '(viv) Use viv-addon AJAX instead of WPGB default', 'default' => 'false' ],
                'en_viv_metas'             => [ 'type' => 'string',  'desc' => '(viv) Comma-separated post meta keys to pre-fetch' ],
                'en_viv_popup'             => [ 'type' => 'boolean', 'desc' => '(viv) Enable card popup lightbox', 'default' => 'false' ],
                'en_viv_popup_card'        => [ 'type' => 'int',     'desc' => '(viv) Card ID used for popup rendering' ],
                'viv_popup_link_class'     => [ 'type' => 'string',  'desc' => '(viv) CSS class that triggers popup links' ],
                'card_theme_template'      => [ 'type' => 'string',  'desc' => '(viv) PHP template path relative to ABSPATH', 'example' => 'wp-content/viv-card-template.php' ],
                'en_viv_mobile_filters'    => [ 'type' => 'boolean', 'desc' => '(viv) Enable mobile filter drawer', 'default' => 'false' ],
                'viv_mob_breakpoint'       => [ 'type' => 'int',     'desc' => '(viv) Mobile breakpoint in px', 'default' => '992' ],
                'use_blurry_images'        => [ 'type' => 'boolean', 'desc' => '(viv) Blur media until full image loads', 'default' => 'false' ],
                'viv_aspect_ratio'         => [ 'type' => 'string',  'desc' => '(viv) CSS aspect-ratio for media blocks', 'example' => '16 / 9' ],
                'use_viv_style'            => [ 'type' => 'boolean', 'desc' => '(viv) Load css/viv-style.css', 'default' => 'false' ],
                'viv_view_toggle'          => [ 'type' => 'boolean', 'desc' => '(viv) Enable grid/list view toggle', 'default' => 'false' ],
                'viv_list_card'            => [ 'type' => 'int',     'desc' => '(viv) Card ID to use for list view mode' ],
                'selections_as_terms_color'=> [ 'type' => 'boolean', 'desc' => '(viv) Tint selection chips using term colors', 'default' => 'false' ],
                'no_results_msg'           => [ 'type' => 'string',  'desc' => '(viv) Custom empty-results message' ],
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
                'name'                      => [ 'type' => 'string',  'desc' => 'Facet display name' ],
                'slug'                      => [ 'type' => 'string',  'desc' => 'URL parameter name (e.g. _category)' ],
                'type'                      => [ 'type' => 'string',  'desc' => 'Facet type', 'values' => 'checkbox, radio, button, select, search, autocomplete, range, number, date, color, rating, hierarchy, az_index, selection, sort, pagination, load_more, per_page, result_count, reset, apply, viv_toggle, viv_parent, viv_save_search, viv_bookmark, viv_map, viv_autocomplete, custom_html' ],
                'source'                    => [ 'type' => 'string',  'desc' => 'Data source', 'example' => 'taxonomy/category, post_field/post_author' ],
                'filter_type'               => [ 'type' => 'string',  'desc' => 'Filter behavior type' ],
                'show_empty'                => [ 'type' => 'boolean', 'desc' => 'Show choices with zero results' ],
                'show_count'                => [ 'type' => 'boolean', 'desc' => 'Show result count per choice' ],
                'orderby'                   => [ 'type' => 'string',  'desc' => 'Sort choices by', 'values' => 'count, name, facet_order' ],
                'order'                     => [ 'type' => 'string',  'desc' => 'Sort direction', 'values' => 'asc, desc' ],
                'logic'                     => [ 'type' => 'string',  'desc' => 'Multi-select logic', 'values' => 'AND, OR' ],
                'hierarchical'              => [ 'type' => 'boolean', 'desc' => 'Show as hierarchy tree' ],
                'viv_acc'                   => [ 'type' => 'boolean', 'desc' => '(viv) Wrap facet in accordion UI', 'default' => 'false', 'applies_to' => 'any' ],
                'viv_acc_subitems'          => [ 'type' => 'boolean', 'desc' => '(viv) Collapse child terms under the accordion', 'default' => 'false', 'applies_to' => 'checkbox' ],
                'disable_sort'              => [ 'type' => 'boolean', 'desc' => '(viv) Skip default alphabetical sorting', 'default' => 'false', 'applies_to' => 'any' ],
                'selelctions_group_titles'  => [ 'type' => 'boolean', 'desc' => '(viv) Show taxonomy title before selection chips', 'default' => 'false', 'applies_to' => 'selection' ],
                'facet_name_to_badge'       => [ 'type' => 'boolean', 'desc' => '(viv) Add facet name badge to selection chips', 'default' => 'false', 'applies_to' => 'selection' ],
                'selelction_title'          => [ 'type' => 'string',  'desc' => '(viv) Label shown above selection chips', 'applies_to' => 'selection' ],
                'clear_all_button'          => [ 'type' => 'boolean', 'desc' => '(viv) Show Clear All button below chips', 'default' => 'false', 'applies_to' => 'selection' ],
                'clear_all_button_text'     => [ 'type' => 'string',  'desc' => '(viv) Custom label for the Clear All button', 'applies_to' => 'selection' ],
                'search_in_choices'         => [ 'type' => 'boolean', 'desc' => '(viv) Search field inside the checkbox choice list', 'default' => 'false', 'applies_to' => 'checkbox' ],
                'choices_select_all'        => [ 'type' => 'boolean', 'desc' => '(viv) Show Select All when searching choices', 'default' => 'false', 'applies_to' => 'checkbox' ],
                'custom_values'             => [ 'type' => 'boolean', 'desc' => '(viv) Enable custom override code for choice values', 'default' => 'false', 'applies_to' => 'checkbox' ],
            ],
            'style' => [
                'name'  => [ 'type' => 'string', 'desc' => 'Style preset name' ],
                'type'  => [ 'type' => 'string', 'desc' => 'Target type', 'values' => 'checkbox, radio, select, button, ...' ],
                'css'   => [ 'type' => 'string', 'desc' => 'Generated CSS (auto-built from settings)' ],
            ],
        ];
    }
}
