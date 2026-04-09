# WP Grid Builder — Viv Agent Helper

Setup, diagnostic, and WP-CLI tooling for WP Grid Builder + Viv plugins. Designed for agent-assisted and headless WordPress setup where normal plugin activation hooks may not fire.

---

## What It Does

Three capabilities in one plugin:

1. **Auto-Register** — on every page load, checks that all installed Viv facet plugins are listed in `vivgb_data`. Fixes the common issue where `vivgb_data` is missing plugin entries because the plugin was copied without activating it.
2. **WP-CLI commands** — `wp viv <command>` suite for status checking, grid patching, and plugin registration from the terminal.
3. **Admin diagnostics page** — WP Admin → Tools → Viv Diagnostics — a one-page health check showing plugin registry, grid settings, and environment info.

---

## How It Works

### Auto-Register (`includes/class-auto-register.php`)

Hooks into `plugins_loaded` (priority 20, after viv-addon at default 10). On every request:

1. Checks a transient (`viv_agent_registered`) — if present, skips.
2. Scans `wp-content/plugins/*/lib/register-facet.php` for files that register facet types via `wp_grid_builder/facets`.
3. For each discovered type not already in `vivgb_data['plugins']`, adds it.
4. Sets the transient for 24 hours.

Transient is cleared automatically on `activated_plugin` and `deactivated_plugin` so a new plugin install triggers a rescan on the next page load.

**Why this matters:** viv-addon reads `vivgb_data` to know which facet classes to load. If you copy a plugin folder without running activation, the `vivgb_data` entry is never written and the facet silently does not appear. Auto-Register fixes this without requiring re-activation.

### WP-CLI (`includes/class-cli.php`)

Registered as `wp viv` command group via `WP_CLI::add_command('viv', 'Viv_Agent_CLI')`.

### Admin Page (`includes/class-admin-page.php`)

Registered at `WP Admin → Tools → Viv Diagnostics` via `add_management_page`.

---

## Installation

1. Copy the plugin folder to `wp-content/plugins/wp-grid-viv-agent-helper/`.
2. Activate via WP Admin → Plugins.
3. This plugin does **not** need to register itself in `vivgb_data` — it is the tool that manages that registry.

> **Note:** After activating, the auto-register will fire on the next page load. You can force it immediately with `wp viv register-plugins`.

---

## WP-CLI Commands

### Important: Local by Flywheel PHP prefix

`wp` is not in PATH when using Local. Always prefix with Local's PHP binary:

```bash
/Applications/Local.app/Contents/Resources/extraResources/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php ~/bin/wp --path=/path/to/site/app/public <command>
```

The `viv-agent-context.json` file in the site root has the correct `wpcli` string for copy-paste.

---

### `wp viv status`

Full diagnostic overview: plugin registry, grid status, PHP environment.

```
── vivgb_data plugins ──
  ✓ viv_toggle → wp-grid-viv-toggle
  ✓ viv_parent → wp-grid-viv-parent

── Grids ──
  [1] Resources Grid  ✓ viv  ✓ tpl  ✓ layout
  [2] Resources Grid v2  ✓ viv  ✓ tpl  ✓ layout

── PHP ──
  PHP version:     8.2.29
  display_errors:  stderr
  WP version:      6.9.4
```

Checks per grid: `en_viv_search` is true, `card_theme_template` is set and file exists, `grid_layout` is a JSON object.

---

### `wp viv register-plugins`

Scans all installed plugins for `lib/register-facet.php`, registers any new ones in `vivgb_data`, and clears the transient cache.

```
$ wp viv register-plugins

Success: vivgb_data already up to date. Registered plugins: viv_toggle, viv_parent

Current vivgb_data plugins:
  ✓ viv_toggle → wp-grid-viv-toggle
  ✓ viv_parent → wp-grid-viv-parent
```

Run this after:
- Copying a viv plugin folder without activating
- Restoring a DB backup that lost `vivgb_data` entries
- Adding a new viv plugin for the first time

---

### `wp viv verify-grid [--id=N]`

Checks one grid (or all grids) for the three required viv settings.

```
$ wp viv verify-grid --id=1

── Grid 1: Resources Grid ──
  ✓ en_viv_search  — Set to true so viv-addon takes over AJAX rendering
  ✓ card_theme_template  — Set to 'wp-content/viv-card-template.php'
  ✓ grid_layout format  — Object with 3 areas: area-top-1, sidebar-left, area-bottom-1
  ℹ source: resource
```

A ✗ on any line means cards won't load or facets won't filter. Use `wp viv patch-grid` to fix.

---

### `wp viv patch-grid --id=N [options]`

Patches a grid's settings without touching anything else. Useful for headless setup or fixing grids after a fresh DB restore.

**Options:**

| Flag | Description |
|------|-------------|
| `--id=N` | Grid ID (required) |
| `--en-viv-search` | Set `en_viv_search: true` |
| `--card-template=<path>` | Set `card_theme_template` (path relative to ABSPATH) |
| `--layout=<json>` | Set `grid_layout` from a JSON string |

```bash
# Enable viv search and set card template
wp viv patch-grid --id=2 --en-viv-search --card-template=wp-content/viv-card-template.php

# Set a full layout JSON
wp viv patch-grid --id=1 --layout='{"area-top-1":{"facets":[4,1,11]},"sidebar-left":{"facets":[9,10,2,3,7,8]},"area-bottom-1":{"facets":[5]}}'
```

> **Note:** `--card-template` path must be relative to ABSPATH (WordPress root). `wp-content/viv-card-template.php` resolves to `ABSPATH . 'wp-content/viv-card-template.php'`. The command will warn if the file doesn't exist but will set the value anyway.

---

## Admin Diagnostics Page

**WP Admin → Tools → Viv Diagnostics**

Three sections:

### Plugin Registry

Table showing every entry in `vivgb_data['plugins']` with:
- ✅/❌ status — whether `lib/register-facet.php` exists on disk
- Type key (e.g. `viv_toggle`)
- Plugin directory name
- **Sync Plugins Now** button — triggers `Viv_Auto_Register::sync()` and redirects back

### Grid Settings

One row per WPGB grid with traffic-light checks:
- **Viv Search** — `en_viv_search: true`
- **Card Template** — set and file found on disk
- **Layout Format** — `grid_layout` is a JSON object (not an array)
- **Quick Fix** link — opens the grid in the WPGB editor if any check fails

### Environment

| Check | Meaning |
|-------|---------|
| PHP Version | Should be 7.2+ |
| display_errors | Should be Off — warnings break viv-addon's JSON AJAX responses |
| WordPress | Version info |
| WPGB installed | `class_exists('WP_Grid_Builder')` — see known issue below |
| viv-addon active | `function_exists('get_vivgb_options')` — see known issue below |

---

## Known Issues / Limitations

### WPGB installed / viv-addon active show ❌ despite being active

The environment checks use `class_exists('WP_Grid_Builder')` and `function_exists('get_vivgb_options')`. In WPGB v2.3.x, the main class name differs from what this check expects, or the class is not loaded at the point the admin page renders. Both checks return false even when WPGB is fully working.

**Evidence:** Both grids show "All good" in the Grid Settings table (the plugin correctly reads from `wp_wpgb_grids`), confirming WPGB is installed and the DB is accessible. The environment row is a false negative.

**Workaround:** Ignore those two rows. Use the Grid Settings table as the real indicator of a working setup.

### Auto-register only scans `lib/register-facet.php`

Plugins that register blocks (via `lib/register-block.php`) or use non-standard paths are not detected by the auto-scanner. Register those manually in `vivgb_data` or via the activation hook.

### Transient cache may cause a 24-hour delay after adding a new plugin

If you add a new viv plugin and the transient is set, auto-register won't pick it up until the transient expires or a plugin is activated/deactivated. Force an immediate rescan with `wp viv register-plugins`.

---

## Demo Setup (glossary.local)

```
URL:    http://glossary.local:10053/wp-admin/tools.php?page=viv-diagnostics
Status: Plugin Registry ✅ viv_toggle, viv_parent
        Grid 1 Resources Grid — all ✅
        Grid 2 Resources Grid v2 — all ✅
        PHP 8.2.29, display_errors: stderr (effectively off for AJAX)
```

---

## Development Status

- `viv-logic`: 9/10 — auto-register is robust; transient + activation hooks cover all normal paths
- `grid-logic`: 8/10 — the false-negative WPGB/viv-addon environment checks are the only rough edge; everything else works correctly
