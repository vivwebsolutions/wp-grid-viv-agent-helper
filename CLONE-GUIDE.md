# Clone This Demo Site

Quick guide for setting up your own copy of the Viv Grid demo site.

## Prerequisites

- [Local by Flywheel](https://localwp.com/) installed
- Git access to `vivwebsolutions/` repos
- WP Grid Builder v2.x zip (commercial plugin)

## Step 1: Create Local Site

1. Open Local → **+ New Site**
2. Name: anything (e.g., `viv-demo`)
3. PHP: 8.2.x, MySQL: 8.0.x, nginx
4. Note your site URL (e.g., `http://viv-demo.local:10054/`)

## Step 2: Clone Plugins

```bash
cd /path/to/your-site/app/public/wp-content/plugins/

# Core (required)
git clone https://github.com/vivwebsolutions/wp-grid-viv-addon.git
git clone https://github.com/vivwebsolutions/wp-grid-viv-agent-helper.git

# Feature plugins (install what you need)
git clone https://github.com/vivwebsolutions/wp-grid-viv-toggle.git
git clone https://github.com/vivwebsolutions/wp-grid-viv-parent.git
git clone https://github.com/vivwebsolutions/wp-grid-viv-save-search2.git
git clone https://github.com/vivwebsolutions/wp-grid-viv-bookmarks.git
git clone https://github.com/vivwebsolutions/wp-grid-viv-autocomplete.git
git clone https://github.com/vivwebsolutions/wp-grid-viv-search-in-choices.git
git clone https://github.com/vivwebsolutions/wp-grid-viv-facet-tooltips.git
git clone https://github.com/vivwebsolutions/wp-grid-viv-mobile-filters.git
git clone https://github.com/vivwebsolutions/wp-grid-viv-map.git
```

Also upload WP Grid Builder v2.x zip via WP Admin.

## Step 3: Copy Key Files

From the `wp-grid-viv-agent-helper` repo:

```bash
# mu-plugin (CPT, taxonomies, shims, layout CSS)
cp wp-content/plugins/wp-grid-viv-agent-helper/demo-cpt.php wp-content/mu-plugins/

# Card template
# Copy from the glossary site or create your own
```

## Step 4: Configure wp-config.php

Add after `WP_DEBUG`:
```php
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
```

## Step 5: Activate Plugins

Activate all plugins via WP Admin → Plugins. This runs the activation hooks that register each plugin in `vivgb_data`.

Or use WP-CLI:
```bash
wp plugin activate --all
wp viv register-plugins
wp viv status
```

## Step 6: Import Database (Optional)

To get the full demo content (62 posts, 13 grids, 16 facets, all pages):

1. Get the SQL export from the glossary site: `bash export-db.sh`
2. Import: `wp db import glossary-export.sql`
3. Search-replace URLs: `wp search-replace 'http://glossary.local:10053' 'http://YOUR-SITE-URL' --all-tables`
4. Flush: `wp cache flush && wp rewrite flush`

## Step 7: Verify

```bash
# Run health check (update BASE URL first)
bash wp-content/plugins/wp-grid-viv-agent-helper/health-check.sh local

# Or check manually
wp viv verify-grid --id=1
wp viv status
```

## Key Files Reference

| File | Purpose |
|------|---------|
| `mu-plugins/demo-cpt.php` | CPT, taxonomies, WPGB v2 shims, layout CSS |
| `wp-content/viv-card-template.php` | Card rendering (SHORTINIT-safe) |
| `wp-content/themes/genesis-block-theme/favicon.svg` | Site favicon |

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| Grid shows "Sorry, no content found" | Check `en_viv_search: true` in grid settings |
| AJAX returns PHP warnings in JSON | Add `display_errors = 0` to wp-config.php |
| Facets render empty | Run `wp viv register-plugins` |
| Autocomplete returns no results | Populate `wp_vivgb_search` table (see SETUP-NOTES.md #12) |

See [SETUP-NOTES.md](SETUP-NOTES.md) for 12+ documented issues and fixes.
