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
  - `class-delivery-rules.php` — Delivery-date rules (standalone class, `fishotel_delivery_rules()`)
  - `class-order-meta.php` — `FisHotel_Order_Meta` (standalone static class): the ONE way to read/write/query WooCommerce **order** meta — HPOS-safe CRUD (`get/update/delete/has/order`), store-agnostic `query()`, `count_by_meta()`, `edit_url()`, the delivery-date migration body
  - `class-ship-day.php` — Ship Day (pick list + weather overview), its own page `admin.php?page=fishotel-ship-day` (`?date=Y-m-d` optional) since 10.34: under FisHotel Tools right after Today when the Misc plugin's menu exists, else a hidden page highlighting Group Orders → Group-order invoices; the old `fishotel-invoicing&tab=ship-day` tab is a one-line pointer. A build with orders made on its ship date is recorded in the `fishotel_ship_day_built` option (ship date → time; the page builds as it opens, so a build days ahead never counts). Invoice fish are read from whichever shape the order carries: the theme's (1.25+) itemised product-less lines (`_fh_invoice_fish` / `_fh_invoice_title` item meta; credits are fee items, never rows), the `_fh_invoice_lines` JSON snapshot on an Invoice line kept whole, or — old orders / unreadable meta — the Invoice product's description (`parse_invoice_lines()`). Pure helpers: `row_kind_for_item()`, `lines_from_item_meta()`
  - `class-ship-day-paperwork.php` — `FisHotel_Ship_Day_Paperwork` (10.35): Ship Day's **Print the day's paperwork** button. One AJAX request (`fishotel_ship_day_paperwork`, own nonce, manage_options) returns the plan — boxes in pick-list order, each live order's invoice link (the WP Overnight "PDF Invoices & Packing Slips" plugin's own `get_document_link()`; a combined box's live sources only, never the wrapper; only Processing / Fulfillment boxes with a fish or dry-goods row — on-hold, weather HOLD and nothing-to-box boxes are warnings, `box_skip()`), the acclimation-sheet / shipping-labels PDFs chosen from the Media Library (option `fishotel_ship_day_paperwork`, label count default 4), "Invoice" product sheets and the pick list / weather pages as data (`FisHotel_Ship_Day::print_pages()`). `assists/ship-day/paperwork.js` with the vendored `assists/vendor/pdf-lib/` (1.17.1, MIT, loaded on this page only) fetches each invoice one at a time and makes `BROTHER_shipday_<ship date>.pdf` and `RX106HD_shipday_<ship date>.pdf`. The plan never makes or numbers an invoice and writes no order; the invoice plugin numbers an invoice once, the first time it is made. **Send to the printers** (10.36, `assists/ship-day/print-watcher.js`, browser only — no request, option or capability on the server): in Chrome / Edge on the shop PC the page is given the FisHotel Print Watcher's folder once (File System Access API, `showDirectoryPicker`; the folder handle lives in that browser's IndexedDB, the "by itself" tick and "already sent" in its localStorage `fishotel_print_watcher`) and writes the two files there — each under a temp name (`sending-N-of-M-shipday_<date>.txt` — `.txt` because Chrome holds back files of unknown types such as `.part` for a Safe Browsing check) and then renamed with `move()`, so the watcher (prints every PDF by its `BROTHER_` / `RX106HD_` prefix once it is 5 s stable, then deletes it) never reads a half-written file; then it looks in the folder for ~90 s ("took the file" / "still in the folder — is the Print Watcher running?"), and never claims a sheet was printed. A file already sent from that browser, or still in the folder, is sent again only after a question; the tick never re-sends by itself and only works while the folder's permission is held. The Open to print / Download links stay for every browser; with a folder chosen the two print tabs are no longer opened. The two files' bytes and names are unchanged (`tests/ship-day-print-watcher-js-test.js` holds the 10.35 sha256 baseline)
  - `class-ship-day-today-line.php` — `FisHotel_Ship_Day_Today_Line`: the Ship Day line on the Misc plugin's Today page (`fishotel_today_lines`, group Shipping): "Ship Day: N orders · M fish go out today for …" until today's pick list is built, then "Ship Day built at … — open again"; nothing on other days; wp-admin only, manage_options, once per request
  - `class-updater.php` — GitHub auto-updater (standalone class, not a trait)
- **Docs:** `docs/HPOS.md` — order-meta inventory, the compatibility declarations and the staging test plan
- **Tests:** `tests/*.php` — plain PHP, no PHPUnit (`php tests/<name>.php`, exit 1 on failure); CI runs them all on 8.3 and `php -l` on 7.4 + 8.3; `tests/*.js` run under node (`node tests/<name>.js`) with `node --check` on the plugin's own scripts (`tests/ship-day-print-watcher-browser-test.js` drives real Chromium when Playwright is installed and says SKIPPED where it is not, as in CI); `tests/README.md` lists what each covers. `tests/hook-callbacks-test.php` fails the build when any hook or menu callback names a method the plugin (main class + its traits) does not define

## HPOS (WooCommerce High-Performance Order Storage)

The plugin declares `custom_order_tables` **compatible** and `cart_checkout_blocks` **incompatible** (`before_woocommerce_init` in the main file); `product_block_editor` is deliberately undeclared (the Selling Price field uses classic product-editor hooks). Rules, enforced by `tests/order-meta-test.php` (it scans the source and fails the build):

- Never `get_post_meta` / `update_post_meta` / `delete_post_meta` / `add_post_meta` on an order id, and never with an order meta key (`_fishotel_shipping_date`, `_fishotel_batch`, `_fishotel_order_type`, `_fishotel_wallet_credited`, ...). Use `FisHotel_Order_Meta::get/update/delete/has( $order_or_id, $key, ... )`, or `$order->get_meta()` / `update_meta_data()` / `save()` when the `WC_Order` is in hand. Batch several keys with `$save = false` and save once.
- Never `WP_Query` / `get_posts` over `shop_order`, and never pass `meta_query` straight to `wc_get_orders()` (the CPT store drops it and WC 9.2+ flags it). Use `FisHotel_Order_Meta::query( $args, $clauses )` — it sends the clauses the right way for the active store and re-checks every result with `get_meta()`.
- `woocommerce_process_shop_order_meta` handlers take 2 args and use the second one (a `WC_Order` on the wc-orders screen). Admin edit links come from `FisHotel_Order_Meta::edit_url()`.
- Plugin CPTs (fish_master, fish_request, fish_batch, notifications, stickers), products and users keep ordinary post/user meta — HPOS only moves orders.
- Full inventory + staging test plan: `docs/HPOS.md`.

## Versioning

- Bump the `Version:` header in `fishotel-batch-manager.php` on every push
- Also update the `Description:` line with a brief changelog entry (e.g. `v10.21.0 - Add SDC CSV import`)
- The updater relies on `version_compare()` so use semver: `MAJOR.MINOR.PATCH`

## Development

- Branch naming: `claude/{feature-name}-{id}`
- Merge to `main` via fast-forward when ready — WordPress sees the update within 1 hour (or immediately with force-check URL)
