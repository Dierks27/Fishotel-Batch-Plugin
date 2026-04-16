# FisHotel Batch Manager

WordPress plugin for managing marine fish batches, supplier sourcing (North Star Aquatics, SDC), customer requests, and hotel program features.

## GitHub Auto-Updater Pattern (No Releases Required)

This plugin updates instantly from `main` branch pushes — no GitHub Releases needed. The key difference from plugins that require manual releases:

### How it works (`includes/class-updater.php`)

1. **Reads the raw plugin header directly from GitHub's `main` branch** — not from the GitHub Releases API. It fetches `https://raw.githubusercontent.com/{owner}/{repo}/main/{plugin-file}.php` and parses the `Version: X.X.X` header.

2. **Compares against the locally installed version.** If GitHub's version is higher, it injects update data into WordPress's `update_plugins` transient.

3. **Downloads the zip from `main` branch** — uses `https://github.com/{owner}/{repo}/archive/refs/heads/main.zip` as the package URL, not a release asset.

4. **Fixes the extracted folder name** — GitHub zips extract to `{Repo}-main/` but WordPress expects the plugin's folder name. The `upgrader_source_selection` filter renames it.

5. **Cache-busts GitHub's CDN** — appends `?nocache={timestamp}` to the raw URL and sends `Cache-Control: no-cache` headers so updates are visible immediately after push.

6. **Caches the remote version for 1 hour** via a transient to avoid hammering GitHub on every admin page load.

7. **Force-check URL** — visiting any admin page with `?fishotel_force_update_check=1` clears all caches and re-checks immediately.

### Why other plugins break

Plugins that use the GitHub **Releases API** (`/repos/{owner}/{repo}/releases/latest`) require you to create a tagged release on GitHub before WordPress sees the update. The pattern above skips releases entirely by reading the raw file from `main`.

### Critical details to replicate

- The `Version:` header in the main plugin file **must be bumped** on every push, or WordPress won't see a difference.
- The `github_zip_url` must point to `/archive/refs/heads/main.zip` (not a release asset).
- The `fix_folder_name` filter is essential — without it WordPress extracts to the wrong directory and the plugin breaks.
- The updater class is instantiated at the bottom of the main plugin file: `new FisHotel_GitHub_Updater( __FILE__ );`

### Template for other plugins

```php
class My_GitHub_Updater {
    private $plugin_slug;
    private $plugin_file;
    private $github_raw_url = 'https://raw.githubusercontent.com/OWNER/REPO/main/PLUGIN-FILE.php';
    private $github_zip_url = 'https://github.com/OWNER/REPO/archive/refs/heads/main.zip';
    private $transient_key  = 'my_plugin_updater_version';
    private $cache_seconds  = 3600;

    public function __construct( $plugin_file ) {
        $this->plugin_file = $plugin_file;
        $this->plugin_slug = plugin_basename( $plugin_file );
        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
        add_filter( 'plugins_api',                           [ $this, 'plugin_info' ], 10, 3 );
        add_filter( 'upgrader_source_selection',             [ $this, 'fix_folder_name' ], 10, 4 );
    }

    // ... (copy from includes/class-updater.php)
}
```

## Architecture

- **Main file:** `fishotel-batch-manager.php` — plugin header, class definition, trait composition, hook registration
- **Traits:** All functionality lives in traits under `includes/`:
  - `class-admin.php` — Admin pages, menus, post types, settings
  - `class-northstar.php` — North Star Aquatics + SDC sourcing integrations
  - `class-ajax.php` — AJAX handlers
  - `class-woocommerce.php` — WooCommerce integration
  - `class-shortcodes.php` — Frontend shortcodes
  - `class-hotel-program.php` — Hotel program features
  - `class-casino.php` / `class-arcade.php` — Gamification
  - `class-helpers.php` — Utility functions
  - `class-updater.php` — GitHub auto-updater (standalone class, not a trait)

## Versioning

- Bump the `Version:` header in `fishotel-batch-manager.php` on every push
- Also update the `Description:` line with a brief changelog entry (e.g. `v10.21.0 - Add SDC CSV import`)
- The updater relies on `version_compare()` so use semver: `MAJOR.MINOR.PATCH`

## Development

- Branch naming: `claude/{feature-name}-{id}`
- Merge to `main` via fast-forward when ready — WordPress sees the update within 1 hour (or immediately with force-check URL)
