# Tests

Plain PHP: no PHPUnit, no WordPress, no database. Each file exits 0 when
everything passes and 1 on any failure, and refuses to run over HTTP (the
plugin zip ships this folder).

```bash
for t in tests/*.php; do php "$t" || break; done
```

| Test | Covers |
|------|--------|
| `delivery-rules-test.php` | `FisHotel_Delivery_Rules`: delivery days, blackout dates, lead time, horizon, per-day capacity, the 7 pm Central cut-off and DST days |
| `order-meta-test.php` | `FisHotel_Order_Meta` through the WooCommerce order API, and a source scan that fails when raw post-meta calls on orders come back (see `docs/HPOS.md`) |
| `ship-day-test.php` | The Ship Day pick list: invoice lines in every shape, classify(), weather flags, the "not checked" weather line (Weather off / on but not checked yet / checked), bags and confirmed boxes |
| `hook-callbacks-test.php` | A static scan (no plugin code is run): every `add_action` / `add_filter` / `remove_*` / `add_shortcode` / `register_*_hook` callback and every other `[ $this, 'x' ]` / `[ __CLASS__, 'x' ]` literal names a method or function the plugin defines. `$this` inside a trait is `FisHotel_Batch_Manager`, so a hook in `class-admin.php` may point at a method in `class-ajax.php`. A hook that names a missing method is a fatal error the moment it fires, so a typo fails here instead of on the live site. A self-check proves the scanner still reports each kind of broken callback. |

GitHub Actions (`.github/workflows/lint.yml`) runs `php -l` on every PHP
file under PHP 7.4 and 8.3 and every test under 8.3, on each push to `main`
and each pull request.
