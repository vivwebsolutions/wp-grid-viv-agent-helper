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

    // Viv-docs#41 — events demo. Lightweight CPT (no Tribe Events Calendar
    // dependency). Posts use post_date for the event date, and meta
    // 'viv_map_location' (matches the resource map facet pattern) for
    // venue lat/lng + 'event_venue' for the venue name.
    register_post_type('event', [
        'label'        => 'Events',
        'labels'       => ['name' => 'Events', 'singular_name' => 'Event'],
        'public'       => true,
        'has_archive'  => true,
        'show_in_rest' => true,
        'supports'     => ['title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'],
        'rewrite'      => ['slug' => 'events'],
        'menu_icon'    => 'dashicons-calendar-alt',
    ]);
    register_taxonomy('event_category', ['event'], [
        'label'        => 'Event Categories',
        'hierarchical' => true,
        'public'       => true,
        'show_in_rest' => true,
        'rewrite'      => ['slug' => 'event-category'],
    ]);

    // Viv-docs#106 — mu-plugins run on every load but never trigger an
    // activation hook, so the rewrite rules cache can go stale when the
    // CPT/taxonomy registrations change. Flush once per content version
    // so single-event URLs and event-category archives resolve.
    $cpt_version = '2026-04-28-1';
    if ( get_option( 'demo_cpt_rewrite_version' ) !== $cpt_version ) {
        flush_rewrite_rules( false );
        update_option( 'demo_cpt_rewrite_version', $cpt_version, true );
    }

    // Events are inserted with post_date='now' so wp_insert_post doesn't
    // schedule them as future-publish (Viv-docs#41 round 7). Real event
    // date lives in the 'event_date' post_meta. WPGB cards render
    // post_field=the_date from $post->post_date — for event posts we want
    // the meta value instead. Hook the per-post object filter that fires
    // inside class-posts.php:450 and rewrite the timestamp.
    add_filter('wp_grid_builder/grid/the_object', function($post) {
        if (!is_object($post) || ($post->post_type ?? '') !== 'event') {
            return $post;
        }
        $event_date = get_post_meta($post->ID, 'event_date', true);
        if (!$event_date) {
            return $post;
        }
        $ts = strtotime($event_date);
        if ($ts) {
            // class-posts.php stores post_date as Unix timestamp ('U').
            $post->post_date = $ts;
        }
        return $post;
    });

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

    // Viv-docs#89 — product_brand taxonomy for the WooCommerce eCommerce
    // recreation. Sample products are round-robin-assigned to the 5 terms
    // (WordPress, Automattic, Press Co, Open Source Co, Pixel Press) for
    // the Brands facet on /wpgb-ecommerce-official/. Need this in the
    // CPT registration hook so it persists across page loads.
    if (post_type_exists('product')) {
        register_taxonomy('product_brand', ['product'], [
            'label'             => 'Brands',
            'labels'            => ['name'=>'Brands','singular_name'=>'Brand'],
            'hierarchical'      => false,
            'public'            => true,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'rewrite'           => ['slug'=>'brand'],
        ]);
    }
});

// Custom favicon + Open Graph meta for social sharing
add_action('wp_head', function() {
    echo '<link rel="icon" type="image/svg+xml" href="' . get_stylesheet_directory_uri() . '/favicon.svg">' . "\n";

    // Open Graph meta tags for social sharing
    $title = wp_title('–', false, 'right') . get_bloginfo('name');
    $desc = 'Interactive demos of every Viv Grid Builder plugin — faceted search, toggle filters, save search, bookmarks, autocomplete, maps, and more.';
    $url = home_url($_SERVER['REQUEST_URI'] ?? '/');

    if (is_singular()) {
        global $post;
        if ($post) {
            $title = $post->post_title . ' – ' . get_bloginfo('name');
            if ($post->post_excerpt) $desc = $post->post_excerpt;
        }
    }

    echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
    echo '<meta property="og:description" content="' . esc_attr(wp_trim_words($desc, 30)) . '">' . "\n";
    echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
    echo '<meta property="og:type" content="website">' . "\n";
    echo '<meta property="og:site_name" content="Viv Grid Demo">' . "\n";
}, 1);

// Accessibility: skip-to-content link + focus styles (Viv-docs #43)
add_action('wp_body_open', function() {
    echo '<a class="viv-skip-link" href="#main">Skip to content</a>';
});
add_action('wp_head', function() {
    ?>
    <style>
    /* Skip-to-content link */
    .viv-skip-link {
        position: absolute;
        top: -100px;
        left: 10px;
        z-index: 10000;
        background: #1a6ebd;
        color: #fff;
        padding: 8px 16px;
        border-radius: 0 0 4px 4px;
        text-decoration: none;
        font-size: 14px;
        font-weight: 600;
    }
    .viv-skip-link:focus {
        top: 0;
        outline: 2px solid #fff;
        outline-offset: 2px;
    }
    /* Visible focus indicators for keyboard nav */
    .wpgb-facet a:focus-visible,
    .wpgb-facet button:focus-visible,
    .wpgb-facet input:focus-visible,
    .wpgb-facet select:focus-visible {
        outline: 2px solid #3b82f6;
        outline-offset: 2px;
    }
    </style>
    <?php
});

// Navigation menu fixes (vivwebsolutions/Viv-docs#60) — loads on ALL pages
add_action('wp_head', function() {
    ?>
    <style>
    /* Widen dropdown to fit longer demo names */
    .main-navigation .sub-menu {
        width: 260px;
    }
    /* Category labels (Filtering, Layouts, Features) — light on dark dropdown bg */
    .main-navigation .sub-menu > .menu-item-type-custom > a[href="#"] {
        font-weight: 700;
        color: #8899aa !important;
        text-transform: uppercase;
        font-size: 10px;
        letter-spacing: 0.8px;
        pointer-events: none;
        cursor: default;
        padding: 12px 20px 4px !important;
        border-top: 1px solid rgba(255, 255, 255, 0.1);
        border-bottom: none !important;
        margin-top: 2px;
    }
    /* First category doesn't need top border */
    .main-navigation .sub-menu > .menu-item-type-custom:first-child > a[href="#"] {
        border-top: none;
        margin-top: 0;
        padding-top: 6px !important;
    }
    /* Demo links: indent under category labels */
    .main-navigation .sub-menu > li:not(.menu-item-type-custom) > a {
        padding-left: 28px !important;
    }
    </style>
    <?php
});

// Widen content area on pages with WPGB grids (issue vivwebsolutions/Viv-docs#26)
add_action('wp_head', function() {
    if ( ! is_singular('page') ) return;
    ?>
    <style>
    /* Ensure highlighter tooltips don't block facet inputs */
    .viv-hl-callout {
        pointer-events: none;
    }
    .viv-hl-callout .viv-hl-close {
        pointer-events: auto;
    }
    /* Reduce excessive whitespace between header and content */
    .site-content {
        padding-top: 20px;
    }
    .entry-header {
        margin-bottom: 10px;
    }
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
    /* Card grid: ensure masonry container inherits viewport height.
       WPGB v2 sets height on .wpgb-viewport but masonry stays 0px
       because all cards are position:absolute. This causes visual gaps. */
    .wpgb-masonry {
        width: 100% !important;
        min-height: inherit;
        height: 100%;
    }
    /* Hide header/footer when page is embedded in an iframe (Viv-docs #34) */
    body.in-iframe .site-header,
    body.in-iframe .site-footer,
    body.in-iframe .entry-title { display: none !important; }
    body.in-iframe #primary { max-width: 100%; width: 100%; margin: 0; padding: 8px; }
    body.in-iframe .entry-content { margin-top: 0; }
    /* Map v2 split layout: cards list next to map (Viv-docs #41) */
    .vivgb-map-split .wpgb-wrapper {
        display: flex;
        flex-direction: row;
        gap: 0;
    }
    .vivgb-map-split .wpgb-sidebar {
        flex: 0 0 240px;
    }
    .vivgb-map-split .wpgb-main {
        flex: 1;
        display: flex;
        flex-direction: row;
    }
    .vivgb-map-split .wpgb-facet:has(#vivgb-map) {
        flex: 1 1 60%;
        height: 500px !important;
    }
    .vivgb-map-split #vivgb-map {
        height: 500px !important;
    }
    .vivgb-map-split .wpgb-layout {
        flex: 0 0 40%;
        max-height: 500px;
        overflow-y: auto;
    }
    .vivgb-map-split .wpgb-card {
        width: 100% !important;
        position: static !important;
        border-bottom: 1px solid #e0e0e0;
    }
    .vivgb-map-split .wpgb-card.active {
        border-left: 3px solid #3b82f6;
        background: #f0f7ff;
    }
    @media (max-width: 768px) {
        .vivgb-map-split .wpgb-main {
            flex-direction: column;
        }
        .vivgb-map-split .wpgb-facet:has(#vivgb-map),
        .vivgb-map-split .wpgb-layout {
            flex: none;
            width: 100%;
        }
    }
    /* Hide the "Sorry, no content found" placeholder before viv-addon AJAX replaces it */
    .wpgb-no-result {
        display: none !important;
    }
    /* Loading indicator: show a subtle pulse while grid loads (UX audit #43) */
    .wpgb-loading-viv .wpgb-viewport::before {
        content: '';
        display: block;
        width: 40px;
        height: 40px;
        margin: 40px auto;
        border: 3px solid #e0e0e0;
        border-top-color: #3b82f6;
        border-radius: 50%;
        animation: viv-spin 0.8s linear infinite;
    }
    @keyframes viv-spin {
        to { transform: rotate(360deg); }
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
        function highlightElement(selector, tooltipText) {
            var el = document.querySelector(selector);
            if (!el) return;
            // Open parent accordion if closed
            var acc = el.querySelector('.vivgb-acc.closed');
            if (acc) acc.classList.remove('closed');
            // Scroll into view
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            // Add highlight
            el.classList.add('viv-highlight');
            // Show tooltip if text provided
            if (tooltipText) {
                var tip = document.createElement('div');
                tip.className = 'viv-highlight-tip';
                tip.textContent = tooltipText;
                tip.style.cssText = 'position:absolute;top:-36px;left:50%;transform:translateX(-50%);background:#1a6ebd;color:#fff;padding:6px 14px;border-radius:6px;font-size:13px;font-weight:600;white-space:nowrap;z-index:300;pointer-events:none;';
                el.style.position = el.style.position || 'relative';
                el.appendChild(tip);
                setTimeout(function() { if (tip.parentNode) tip.remove(); }, 3500);
            }
            setTimeout(function() { el.classList.remove('viv-highlight'); }, 3500);
        }
        // Handle URL param
        var params = new URLSearchParams(window.location.search);
        var hl = params.get('highlight');
        if (hl) {
            // Wait for viv-addon to load content, then highlight with tooltip
            setTimeout(function() { highlightElement(hl, 'Featured element'); }, 2000);
        }
        // Handle click on feature links — update URL param + highlight
        document.addEventListener('click', function(e) {
            var link = e.target.closest('.viv-feature-link');
            if (link) {
                e.preventDefault();
                var sel = link.getAttribute('data-highlight');
                var tip = link.getAttribute('data-tooltip') || link.textContent.trim();
                if (sel) {
                    // Update URL without reload so the link is shareable
                    var url = new URL(window.location);
                    url.searchParams.set('highlight', sel);
                    history.replaceState(null, '', url);
                    highlightElement(sel, tip);
                }
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

// Viv-docs#83 workaround: WPGB lazy-load fails to strip wpgb-card-hidden on
// some card templates (Jade #70, Ruby #77). Force visibility after layout.
add_action('wp_footer', function() {
    ?>
    <style>
    .wpgb-card.wpgb-card-hidden .wpgb-card-wrapper {
        visibility: visible !important;
        opacity: 1 !important;
        transform: none !important;
    }
    </style>
    <script>
    (function(){
        function reveal() {
            document.querySelectorAll('.wpgb-card.wpgb-card-hidden').forEach(function(c){
                c.classList.remove('wpgb-card-hidden');
            });
        }
        if (document.readyState === 'complete') reveal();
        else window.addEventListener('load', reveal);
        // Also re-run after WPGB layout settles
        setTimeout(reveal, 500);
        setTimeout(reveal, 1500);
    })();
    </script>
    <?php
}, 99);

// Viv-docs#83 — Container-query fix for horizontal-layout cards (Jade #70,
// Ironstone #69, Topaz #80, Lava #73 — any card with `card_layout: horizontal`
// in WPGB). WPGB generates per-card CSS using viewport @media queries
// (min-width: 480px) which fires the horizontal layout based on the BROWSER
// width, not the actual card column width. With multi-column grids + sidebars
// the card itself is much narrower than 480px and the horizontal flex layout
// squeezes content into one-character-per-line columns.
//
// The fix uses CSS container queries (Chrome 105+, Safari 16+, Firefox 110+)
// to override per-card-element so layout responds to the column width, not
// viewport width.
add_action('wp_footer', function() {
    ?>
    <style>
    /* Make every WPGB card a containment context so per-card responsive CSS
       can target actual column width, not viewport width. */
    .wp-grid-builder .wpgb-card { container-type: inline-size; }

    /* Force vertical (stacked) layout when the card column is below the
       horizontal-design threshold WPGB used (480px). Wildcard rules so any
       newly-imported card with horizontal CSS auto-inherits the fix without
       needing to enumerate IDs. Generalised 2026-04-28 after WPGB-IMPORTER
       discovery (Viv-docs#82, #90) added new card IDs to the system. */
    @container (max-width: 480px) {
      .wp-grid-builder .wpgb-card .wpgb-card-inner {
        flex-direction: column !important;
      }
      .wp-grid-builder .wpgb-card .wpgb-card-media,
      .wp-grid-builder .wpgb-card .wpgb-card-media + .wpgb-card-content {
        width: 100% !important;
      }
      /* Per-card body padding tighten — these designs ship with 4em padding
         that eats horizontal space when the card is narrow. Add card IDs
         here as new horizontal-layout cards arrive. */
      .wp-grid-builder .wpgb-card-8 .wpgb-card-body,   /* Jade (bundled)   */
      .wp-grid-builder .wpgb-card-29 .wpgb-card-body,  /* Ironstone        */
      .wp-grid-builder .wpgb-card-50 .wpgb-card-body,  /* Lava             */
      .wp-grid-builder .wpgb-card-70 .wpgb-card-body { /* Jade (hand-built)*/
        padding: 1.5em !important;
      }
    }
    </style>
    <?php

    // ?in_iframe=1 — used by /demo-card-styles/ + /demo-mobile-filters/
    // pages to embed grid demos without the wrapping theme chrome.
    // Without this, the iframe shows the parent header/title/footer
    // again inside, eating ~250px of space.
    if ( ! empty( $_GET['in_iframe'] ) ) :
    ?>
    <style>
    body.in-iframe-mode > header,
    body.in-iframe-mode .site-header,
    body.in-iframe-mode .wp-block-template-part[data-area="header"],
    body.in-iframe-mode > footer,
    body.in-iframe-mode .site-footer,
    body.in-iframe-mode .wp-block-template-part[data-area="footer"],
    body.in-iframe-mode .entry-header,
    body.in-iframe-mode .post-content > header,
    body.in-iframe-mode .menu-toggle { display: none !important; }
    body.in-iframe-mode { padding: 0 !important; margin: 0 !important; }
    body.in-iframe-mode .entry-content,
    body.in-iframe-mode .post-content,
    body.in-iframe-mode main { padding-top: 0 !important; margin-top: 0 !important; }
    </style>
    <script>
      document.documentElement.classList.add('in-iframe-mode');
      document.addEventListener('DOMContentLoaded', () => document.body.classList.add('in-iframe-mode'));
    </script>
    <?php
    endif;
}, 100);

// Viv-docs#105 — at wide viewports (>1280px) the demo pages' text
// content stretched edge-to-edge, hurting readability. Cap content
// width on text-heavy pages while leaving grid pages full-bleed
// (grids manage their own column layout).
add_action('wp_head', function() {
    if ( is_page() && ! is_front_page() ) {
        return; // grids on demo pages need full bleed
    }
    ?>
    <style>
    @media (min-width: 960px) {
      body.home .entry-content,
      body.home .post-content,
      body.home main {
        max-width: 920px;
        margin-left: auto;
        margin-right: auto;
      }
    }
    </style>
    <?php
});

// Viv-docs#104 — Genesis Block Theme's submenu toggle buttons render
// without an aria-label or visible text (just <i class="gbicon-angle-down">),
// so screen readers announce them as unlabeled buttons. The buttons are
// injected by theme JS after initial parse, so we use a MutationObserver
// to label them whenever they appear.
add_action('wp_footer', function() {
    ?>
    <script>
      (function () {
        function labelToggleButtons(root) {
          (root || document).querySelectorAll('button.toggle-sub:not([aria-label])').forEach(btn => {
            btn.setAttribute('aria-label', 'Toggle submenu');
          });
          (root || document).querySelectorAll('button.menu-toggle:not([aria-label])').forEach(btn => {
            if (!btn.textContent.trim()) btn.setAttribute('aria-label', 'Toggle menu');
          });
        }
        labelToggleButtons();
        document.addEventListener('DOMContentLoaded', () => labelToggleButtons());
        const obs = new MutationObserver(muts => {
          for (const m of muts) for (const n of m.addedNodes) {
            if (n.nodeType === 1) labelToggleButtons(n.parentNode || n);
          }
        });
        obs.observe(document.documentElement, { childList: true, subtree: true });
      })();
    </script>
    <?php
});

// Viv-docs#60 — Header menu polish for the Genesis Block theme nav. Per
// the issue: "menu text black and block, submenu should point inward".
// Ensures consistent dark text, adds chevron indicators for menu items
// that have children, and rotates the chevron when the submenu is open.
add_action('wp_head', function() {
    ?>
    <style>
    /* Top-level menu — consistent dark text, no underline. */
    .wp-block-page-list a,
    .wp-block-navigation a,
    nav.menu-primary a,
    .menu-primary li a {
      color: #1a1a1a;
      text-decoration: none;
      font-weight: 500;
    }
    .wp-block-page-list a:hover,
    .wp-block-navigation a:hover,
    nav.menu-primary a:hover,
    .menu-primary li a:hover {
      color: #1a6ebd;
    }
    /* Items with children get a downward chevron via pseudo-element. */
    .menu-item-has-children > a::after {
      content: " \25BE"; /* ▾ */
      display: inline-block;
      margin-left: 4px;
      transition: transform .15s ease;
      opacity: .65;
    }
    /* Rotate the chevron when hovering the parent (submenu opens). */
    .menu-item-has-children:hover > a::after,
    .menu-item-has-children:focus-within > a::after {
      transform: rotate(180deg);
    }
    /* Nested submenus (sub-menu items that themselves have children) get
       an inward-pointing arrow instead. "Point inward" = toward the
       sub-sub-menu that opens to the right. */
    .sub-menu .menu-item-has-children > a::after {
      content: " \25B8"; /* ▸ */
      transform: none !important;
    }
    /* Submenu container — solid white box with subtle shadow + tight padding
       so items read as a discrete block. */
    .sub-menu {
      background: #fff;
      border: 1px solid #e5e5e5;
      box-shadow: 0 4px 12px rgba(0,0,0,0.08);
      padding: 4px 0;
      border-radius: 6px;
      min-width: 200px;
    }
    .sub-menu li a {
      padding: 8px 14px;
      display: block;
      white-space: nowrap;
    }
    .sub-menu li a:hover {
      background: #f5f5f5;
    }
    </style>
    <?php
}, 99);

// Viv-docs#88 — Toolbar layout for blog recreation page. Lay out the four
// area-top-1 facets (Search, Categories, Date, Sort) horizontally instead of
// stacked. Scoped to /wpgb-blog-official/ via page slug class.
add_action('wp_footer', function() {
    if (!is_page('wpgb-blog-official')) return;
    ?>
    <style>
    .page-id-155 .wp-grid-builder .wpgb-grid-35 .wpgb-area-top-1 {
      display: grid;
      grid-template-columns: 1.5fr 1fr 1fr 1fr;
      gap: 12px;
      align-items: end;
      margin-bottom: 1.5em;
    }
    @media (max-width: 768px) {
      .page-id-155 .wp-grid-builder .wpgb-grid-35 .wpgb-area-top-1 {
        grid-template-columns: 1fr 1fr;
      }
    }
    .page-id-155 .wp-grid-builder .wpgb-grid-35 .wpgb-area-top-1 .wpgb-facet {
      width: 100%; min-width: 0;
    }
    .page-id-155 .wp-grid-builder .wpgb-grid-35 .wpgb-area-top-1 .wpgb-facet-title {
      font-size: 12px; text-transform: uppercase; letter-spacing: .04em;
      color: #666; font-weight: 600; margin: 0 0 4px;
    }
    /* Viv-docs#88 — "Read more →" affordance on Jade card body. The card
       title is already a link to the resource; this CSS appends a visual
       cue at the bottom of each card body. Pseudo-element to avoid
       editing Jade's block layout JSON. */
    .page-id-155 .wp-grid-builder .wpgb-grid-35 .wpgb-card-8 .wpgb-card-body::after {
      content: "Read more \2192";
      display: block;
      margin-top: 12px;
      font-weight: 600;
      font-size: 14px;
      color: #1a6ebd;
      letter-spacing: .01em;
    }
    </style>
    <?php
}, 101);

// Viv-docs#87 — Horizontal chip filter style for taxonomy facets on portfolio
// recreation page. Restricts the chip styling to /wpgb-portfolio-official/ so
// the existing sidebar checkboxes elsewhere keep their default look. Uses
// :is(...) so this rule cascades across nested facet containers.
add_action('wp_footer', function() {
    if (!is_page(['wpgb-portfolio-official', 'wpgb-portfolio'])) return;
    ?>
    <style>
    /* Portfolio chip filter style applied to BOTH /wpgb-portfolio-official/
       (page 156, grid 36) AND the original /wpgb-portfolio/ (page 143,
       grid 30, now also wired to the Portfolio Image card per the round-5
       parity work). WPGB checkbox facet markup is:
         <fieldset><div .wpgb-checkbox-facet><ul .wpgb-hierarchical-list>
           <li><div .wpgb-checkbox aria-pressed="false"><input type=hidden>
             <span .wpgb-checkbox-control><span .wpgb-checkbox-label>
               Term (count)</span></span></div></li>...
       The click target is .wpgb-checkbox itself, NOT a real input. */
    :is(.page-id-156, .page-id-143) .wp-grid-builder :is(.wpgb-grid-36, .wpgb-grid-30) .wpgb-area-top-1 {
      margin: 0 auto 2em; max-width: 1100px; padding: 0 16px;
    }
    :is(.page-id-156, .page-id-143) .wpgb-facet-2 fieldset { border: 0; padding: 0; margin: 0; }
    :is(.page-id-156, .page-id-143) .wpgb-facet-2 .wpgb-checkbox-facet { padding: 0; }
    :is(.page-id-156, .page-id-143) .wpgb-facet-2 ul.wpgb-hierarchical-list {
      display: flex; flex-wrap: wrap; justify-content: center;
      gap: 8px; padding: 0; margin: 0; list-style: none;
    }
    :is(.page-id-156, .page-id-143) .wpgb-facet-2 ul.wpgb-hierarchical-list li { list-style: none; margin: 0; }
    :is(.page-id-156, .page-id-143) .wpgb-facet-2 .wpgb-checkbox {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 8px 16px; border-radius: 999px;
      background: #f5f5f5; color: #1a1a1a;
      font-size: 14px; font-weight: 500;
      border: 1px solid transparent; cursor: pointer;
      transition: background-color .12s ease, color .12s ease;
    }
    :is(.page-id-156, .page-id-143) .wpgb-facet-2 .wpgb-checkbox:hover { background: #e5e5e5; }
    :is(.page-id-156, .page-id-143) .wpgb-facet-2 .wpgb-checkbox[aria-pressed="true"] {
      background: #1a6ebd; color: #fff;
    }
    :is(.page-id-156, .page-id-143) .wpgb-facet-2 .wpgb-checkbox-control { display: none; }
    :is(.page-id-156, .page-id-143) .wpgb-facet-2 .wpgb-checkbox-label { display: inline-flex; align-items: baseline; gap: 6px; }
    :is(.page-id-156, .page-id-143) .wpgb-facet-2 .wpgb-checkbox-label > span {
      opacity: .65; font-weight: 400; font-size: 13px;
    }
    :is(.page-id-156, .page-id-143) .wpgb-facet-2 .wpgb-checkbox[aria-pressed="true"] .wpgb-checkbox-label > span {
      opacity: .85;
    }
    /* Image-only card: prevent masonry collapse for cards with no body content. */
    :is(.page-id-156, .page-id-143) .wpgb-card-84 .wpgb-card-body { padding: 0 !important; }
    :is(.page-id-156, .page-id-143) .wpgb-card-84 .wpgb-card-media { border-radius: 4px; overflow: hidden; aspect-ratio: 4/3; }
    :is(.page-id-156, .page-id-143) .wpgb-card-84 .wpgb-card-wrapper { min-height: 240px; }
    </style>
    <?php
}, 101);

/**
 * Viv-docs#120 — WooCommerce enqueues 6 frontend JS files on every page
 * (order-attribution, sourcebuster, js-cookie, blockUI, add-to-cart,
 * woocommerce.min). They're only needed on shop/cart/checkout/product
 * pages plus anywhere with an Add to Cart button. On a 50-JS-file
 * /demo-events/ load, 6 of those were WC scripts that did literally
 * nothing.
 *
 * Dequeue them on non-WC pages.
 */
add_action( 'wp_print_scripts', function () {
    if ( ! function_exists( 'is_woocommerce' ) ) {
        return;
    }
    // Pages where WC scripts ARE needed
    if ( is_woocommerce() || is_cart() || is_checkout() || is_account_page() ) {
        return;
    }
    // Also keep them if the current post embeds a Woo shortcode/block
    global $post;
    if ( ! empty( $post->post_content ) ) {
        if ( has_shortcode( $post->post_content, 'add_to_cart' )
             || has_shortcode( $post->post_content, 'product' )
             || has_shortcode( $post->post_content, 'products' )
             || has_block( 'woocommerce/all-products', $post )
             || has_block( 'woocommerce/single-product', $post ) ) {
            return;
        }
    }
    // Also keep on the WPGB eCommerce demo pages (they include product cards)
    if ( is_page() && ( strpos( get_the_permalink(), 'wpgb-ecommerce' ) !== false || strpos( get_the_permalink(), 'shop' ) !== false ) ) {
        return;
    }

    foreach ( [
        'wc-add-to-cart',
        'wc-cart-fragments',
        'woocommerce',
        'wc-blocks-style',
        'jquery-blockui',
        'js-cookie',
        'wc-order-attribution',
        'sourcebuster-js',
    ] as $h ) {
        wp_dequeue_script( $h );
        wp_deregister_script( $h );
    }
}, 100 );

/**
 * Viv-docs#123 — Block WordPress user enumeration. By default WP exposes
 * /wp-json/wp/v2/users (returns name + slug for every user) and redirects
 * /?author=N to /author/<slug>/ (revealing usernames). Both are common
 * vectors for brute-force pre-attack reconnaissance.
 *
 * On the demo site we have 6 users — 4 author personas that ARE legitimate
 * public faces (post authors), 1 admin, 1 shopmanager. Hide the admin +
 * shopmanager; let the 4 author personas through normally for byline links.
 */

// 1. Block ?author=N enumeration. Hook on parse_request which fires
//    BEFORE WordPress's canonical author redirect.
add_action( 'parse_request', function ( $wp ) {
    if ( is_admin() ) return;
    $author_in_get   = isset( $_GET['author'] ) && is_numeric( $_GET['author'] );
    $author_in_query = isset( $wp->query_vars['author'] ) && is_numeric( $wp->query_vars['author'] );
    if ( $author_in_get || $author_in_query ) {
        wp_redirect( home_url(), 301 );
        exit;
    }
} );

// 2. Filter the public REST users endpoint to hide privileged roles.
add_filter( 'rest_user_query', function ( $args ) {
    if ( ! is_user_logged_in() ) {
        $args['role__not_in'] = [ 'administrator', 'shop_manager' ];
    }
    return $args;
} );

// 2b. Defense in depth: block ?include / ?slug bypasses on the users route.
add_filter( 'rest_pre_dispatch', function ( $result, $server, $request ) {
    if ( $result !== null ) return $result;
    if ( ! is_user_logged_in() && '/wp/v2/users' === $request->get_route() ) {
        $params = $request->get_query_params();
        if ( ! empty( $params['include'] ) || ! empty( $params['slug'] ) ) {
            return new WP_Error( 'rest_user_invalid_request', 'Filtering users by include/slug is not allowed for unauthenticated requests.', [ 'status' => 401 ] );
        }
    }
    return $result;
}, 10, 3 );

// 3. Strip the wp-json user link from <head> for guests (defense in depth).
remove_action( 'wp_head', 'rest_output_link_wp_head' );
remove_action( 'template_redirect', 'rest_output_link_header', 11 );

/**
 * Viv-docs#124 — Standard WordPress hardening for the demo install.
 * Most of these matter more on production (WPE) than local, but the
 * mu-plugin runs everywhere so settle the defaults here.
 */

// 1. Hide X-Powered-By header (leaks PHP version, helps attackers target CVEs).
//    nginx is configured separately; this covers PHP's emission.
@header_remove( 'X-Powered-By' );

// 2. Don't emit the WordPress generator meta tag (reveals WP version).
remove_action( 'wp_head', 'wp_generator' );
add_filter( 'the_generator', '__return_empty_string' );

// 3. Disable XML-RPC. Don't override the server class (returning a
//    non-existent class name makes WP error out with 500). The
//    xmlrpc_enabled filter alone makes every method method respond
//    with a 'XML-RPC services are disabled' fault.
add_filter( 'xmlrpc_enabled', '__return_false' );

// 4. NOTE: /readme.html, /license.txt, /wp-config-sample.php are static
//    files served directly by nginx — PHP-level redirect doesn't catch
//    them. To block them on Local by Flywheel, the right place is the
//    site's nginx conf (conf/nginx/site.conf.hbs):
//
//      location ~* /(readme\.html|license\.txt|wp-config-sample\.php)$ {
//          deny all;
//          return 404;
//      }
//
//    On WPE, add equivalent rules to wp-engine-htaccess. Documented in
//    Viv-docs#124. Don't try to hook from PHP — it won't fire.
