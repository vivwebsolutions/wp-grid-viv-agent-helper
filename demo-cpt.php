<?php
/**
 * Demo CPT + Taxonomies for Viv Grid Plugin demos
 */

// Register 'resource' CPT
add_action('init', function() {
    register_post_type('resource', [
        'label'        => 'Resources',
        'labels'       => ['name' => 'Resources', 'singular_name' => 'Resource'],
        'public'       => true,
        'has_archive'  => true,
        'show_in_rest' => true,
        'supports'     => ['title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'],
        'rewrite'      => ['slug' => 'resources'],
    ]);

    // Category taxonomy for resources
    register_taxonomy('resource_category', ['resource'], [
        'label'        => 'Resource Categories',
        'hierarchical' => true,
        'public'       => true,
        'show_in_rest' => true,
        'rewrite'      => ['slug' => 'resource-category'],
    ]);

    // Type taxonomy (flat)
    register_taxonomy('resource_type', ['resource'], [
        'label'        => 'Resource Types',
        'hierarchical' => false,
        'public'       => true,
        'show_in_rest' => true,
        'rewrite'      => ['slug' => 'resource-type'],
    ]);

    // Difficulty taxonomy
    register_taxonomy('resource_difficulty', ['resource'], [
        'label'        => 'Difficulty',
        'hierarchical' => false,
        'public'       => true,
        'show_in_rest' => true,
        'rewrite'      => ['slug' => 'resource-difficulty'],
    ]);

    // Format / access taxonomy
    register_taxonomy('resource_format', ['resource'], [
        'label'        => 'Format',
        'hierarchical' => false,
        'public'       => true,
        'show_in_rest' => true,
        'rewrite'      => ['slug' => 'resource-format'],
    ]);
});

// Widen content area on pages with WPGB grids (issue vivwebsolutions/Viv-docs#26)
add_action('wp_head', function() {
    if ( ! is_singular('page') ) return;
    ?>
    <style>
    /* Widen the content area on pages with WPGB grids */
    .page #primary {
        max-width: 1200px;
        width: 90%;
    }
    /* Grid wrapper: sidebar sticks left, cards fill remaining space */
    .wpgb-wrapper {
        display: flex;
        gap: 24px;
    }
    .wpgb-sidebar.wpgb-sidebar-left {
        flex: 0 0 260px;
        min-width: 200px;
    }
    .wpgb-main {
        flex: 1 1 0%;
        min-width: 0;
    }
    /* Responsive: stack on narrow screens */
    @media (max-width: 768px) {
        .wpgb-wrapper {
            flex-direction: column;
        }
        .wpgb-sidebar.wpgb-sidebar-left {
            flex: none;
            width: 100%;
        }
    }
    /* Card grid: ensure cards fill available width */
    .wpgb-masonry {
        width: 100% !important;
    }
    /* Hide header/footer when page is embedded in an iframe (Viv-docs #34) */
    body.in-iframe .site-header,
    body.in-iframe .site-footer,
    body.in-iframe .entry-title { display: none !important; }
    body.in-iframe #primary { max-width: 100%; width: 100%; margin: 0; padding: 8px; }
    body.in-iframe .entry-content { margin-top: 0; }
    /* Hide the "Sorry, no content found" placeholder before viv-addon AJAX replaces it */
    .wpgb-no-result {
        display: none !important;
    }
    /* Feature highlight pulse animation (Viv-docs #27) */
    @keyframes viv-highlight-pulse {
        0% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.5); }
        50% { box-shadow: 0 0 0 8px rgba(59, 130, 246, 0.2); }
        100% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0); }
    }
    .viv-highlight {
        animation: viv-highlight-pulse 1s ease-in-out 3;
        outline: 2px solid #3b82f6;
        outline-offset: 4px;
        border-radius: 6px;
    }
    a.viv-feature-link {
        display: inline-block;
        background: #f0f4ff;
        color: #3b82f6;
        padding: 4px 12px;
        border-radius: 16px;
        text-decoration: none;
        font-size: 14px;
        margin: 2px 4px 2px 0;
        border: 1px solid #d0deff;
        transition: background 0.2s;
    }
    a.viv-feature-link:hover {
        background: #dbeafe;
    }
    </style>
    <script>
    // Feature highlight: scroll to and pulse a facet element (Viv-docs #27)
    // Usage: <a href="#" class="viv-feature-link" data-highlight=".wpgb-facet-9">By Topic</a>
    // Or via URL: ?highlight=.wpgb-facet-9
    document.addEventListener('DOMContentLoaded', function() {
        function highlightElement(selector) {
            var el = document.querySelector(selector);
            if (!el) return;
            // Open parent accordion if closed
            var acc = el.querySelector('.vivgb-acc.closed');
            if (acc) acc.classList.remove('closed');
            // Scroll into view
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            // Add highlight
            el.classList.add('viv-highlight');
            setTimeout(function() { el.classList.remove('viv-highlight'); }, 3500);
        }
        // Handle URL param
        var params = new URLSearchParams(window.location.search);
        var hl = params.get('highlight');
        if (hl) {
            // Wait for viv-addon to load content
            setTimeout(function() { highlightElement(hl); }, 2000);
        }
        // Handle click on feature links
        document.addEventListener('click', function(e) {
            var link = e.target.closest('.viv-feature-link');
            if (link) {
                e.preventDefault();
                var sel = link.getAttribute('data-highlight');
                if (sel) highlightElement(sel);
            }
        });
    });
    </script>
    <?php
});

// Force WPGB to enqueue facets.js (viv-addon bypasses normal facet rendering so it never registers)
add_action('wp_grid_builder/grid/render', function() {
    if (function_exists('wpgb_register_script')) {
        wpgb_register_script('wpgb-facets');
    }
});

// WPGB v2 compatibility shim: viv-addon checks wpgb.init which no longer exists in v2.3+
// This inline script runs before wp-grid-viv-addon.js and patches WP_Grid_Builder.instance
add_action('wp_enqueue_scripts', function() {
    wp_add_inline_script('wpgb-viv-addon', '
if (typeof WP_Grid_Builder !== "undefined" && WP_Grid_Builder.instance) {
    var _vivOrigInstance = WP_Grid_Builder.instance.bind(WP_Grid_Builder);
    WP_Grid_Builder.instance = function(id) {
        var inst = _vivOrigInstance(id);
        if (inst && typeof inst.init === "undefined") {
            inst.init = true;
        }
        return inst;
    };
}
', 'before');
}, 20);
