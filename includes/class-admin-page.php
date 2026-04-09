<?php
/**
 * Admin diagnostics page: WP Admin → Tools → Viv Diagnostics
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Viv_Admin_Page {

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
        add_action( 'admin_post_viv_sync_plugins', [ __CLASS__, 'handle_sync' ] );
    }

    public static function add_menu() {
        add_management_page(
            'Viv Diagnostics',
            'Viv Diagnostics',
            'manage_options',
            'viv-diagnostics',
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function handle_sync() {
        check_admin_referer( 'viv_sync_plugins' );
        Viv_Auto_Register::sync();
        Viv_Auto_Register::clear_cache();
        wp_redirect( admin_url( 'tools.php?page=viv-diagnostics&synced=1' ) );
        exit;
    }

    public static function render_page() {
        global $wpdb;
        $plugin_status = Viv_Auto_Register::get_status();
        $grids         = $wpdb->get_results( "SELECT id, name, settings FROM {$wpdb->prefix}wpgb_grids ORDER BY id ASC" );
        ?>
        <div class="wrap">
            <h1>🔧 Viv Gridbuilder Diagnostics</h1>

            <?php if ( ! empty( $_GET['synced'] ) ) : ?>
                <div class="notice notice-success"><p>vivgb_data synced successfully.</p></div>
            <?php endif; ?>

            <!-- Plugin Registry -->
            <h2>Plugin Registry <small style="font-size:13px;font-weight:normal;">(vivgb_data option)</small></h2>
            <table class="widefat striped" style="max-width:700px">
                <thead><tr><th>Status</th><th>Type</th><th>Directory</th><th>File</th></tr></thead>
                <tbody>
                <?php if ( empty( $plugin_status ) ) : ?>
                    <tr><td colspan="4" style="color:#c00">⚠ vivgb_data is empty — click Sync below</td></tr>
                <?php else : ?>
                    <?php foreach ( $plugin_status as $type => $info ) :
                        $ok = $info['file_exists'];
                    ?>
                    <tr>
                        <td><?php echo $ok ? '✅' : '❌'; ?></td>
                        <td><code><?php echo esc_html( $type ); ?></code></td>
                        <td><?php echo esc_html( $info['dir'] ); ?></td>
                        <td style="color:<?php echo $ok ? 'green' : 'red'; ?>"><?php echo $ok ? 'Found' : 'Missing'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin-top:10px">
                <input type="hidden" name="action" value="viv_sync_plugins">
                <?php wp_nonce_field('viv_sync_plugins'); ?>
                <button type="submit" class="button button-primary">Sync Plugins Now</button>
                <span style="margin-left:10px;color:#666;font-size:12px">Scans wp-content/plugins for viv plugin registrations</span>
            </form>

            <!-- Grid Checks -->
            <h2 style="margin-top:30px">Grid Settings</h2>
            <table class="widefat striped" style="max-width:900px">
                <thead>
                    <tr>
                        <th>ID</th><th>Name</th>
                        <th title="en_viv_search: true">Viv Search</th>
                        <th title="card_theme_template">Card Template</th>
                        <th title="grid_layout is JSON object">Layout Format</th>
                        <th>Quick Fix</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $grids as $grid ) :
                    $s       = json_decode( $grid->settings );
                    $viv_ok  = ! empty( $s->en_viv_search ) && $s->en_viv_search === true;
                    $tpl     = $s->card_theme_template ?? '';
                    $tpl_ok  = ! empty( $tpl ) && file_exists( ABSPATH . $tpl );
                    $lay_ok  = is_object( $s->grid_layout ?? null );
                    $all_ok  = $viv_ok && $tpl_ok && $lay_ok;
                ?>
                <tr>
                    <td><?php echo (int) $grid->id; ?></td>
                    <td><?php echo esc_html( $grid->name ); ?></td>
                    <td><?php echo $viv_ok ? '✅' : '❌'; ?></td>
                    <td><?php echo $tpl_ok ? '✅ <code style="font-size:11px">' . esc_html($tpl) . '</code>' : '❌ ' . ( $tpl ? '<code style="color:red;font-size:11px">' . esc_html($tpl) . ' (missing)</code>' : 'not set' ); ?></td>
                    <td><?php echo $lay_ok ? '✅' : '❌'; ?></td>
                    <td>
                        <?php if ( ! $all_ok ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpgb-grids&action=edit&id=' . (int)$grid->id ) ); ?>" class="button button-small">Edit in WPGB</a>
                        <?php else : ?>
                        <span style="color:green">All good</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <!-- PHP / Environment -->
            <h2 style="margin-top:30px">Environment</h2>
            <table class="widefat striped" style="max-width:500px">
                <tbody>
                <?php
                $checks = [
                    'PHP Version'      => PHP_VERSION,
                    'display_errors'   => ini_get('display_errors') ? '⚠ ON (should be off for viv-addon AJAX)' : '✅ Off',
                    'WordPress'        => get_bloginfo('version'),
                    'WPGB installed'   => class_exists('WP_Grid_Builder') ? '✅ Yes' : '❌ No',
                    'viv-addon active' => function_exists('get_vivgb_options') ? '✅ Yes' : '❌ No',
                ];
                foreach ( $checks as $label => $val ) : ?>
                <tr><th style="width:180px"><?php echo esc_html($label); ?></th><td><?php echo wp_kses_post($val); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <!-- WP-CLI Reference -->
            <h2 style="margin-top:30px">WP-CLI Commands</h2>
            <pre style="background:#1e1e1e;color:#d4d4d4;padding:16px;border-radius:6px;max-width:700px;overflow:auto;line-height:1.6"><?php echo esc_html(
"# Register all viv plugins in vivgb_data (fix missing activation hooks)
wp viv register-plugins

# Check a grid has all required viv settings
wp viv verify-grid --id=1

# Fix a grid's viv settings
wp viv patch-grid --id=2 --en-viv-search --card-template=wp-content/viv-card-template.php

# Full site status
wp viv status"
            ); ?></pre>

            <!-- Links -->
            <h2 style="margin-top:30px">Viv Plugins</h2>
            <ul style="list-style:disc;padding-left:20px">
                <li><a href="https://vivwebsolutions.com/" target="_blank">ViV Web Solutions</a></li>
                <li><strong>wp-grid-viv-addon</strong> — AJAX layer and custom card rendering for WP Grid Builder</li>
                <li><strong>wp-grid-viv-toggle</strong> — Toggle facet (show/exclude by term value)</li>
            </ul>

        </div>
        <?php
    }
}

Viv_Admin_Page::init();
