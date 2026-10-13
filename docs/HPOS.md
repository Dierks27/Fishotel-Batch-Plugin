# HPOS (High-Performance Order Storage) safety — v10.28

**What this is about.** With WooCommerce HPOS the authoritative copy of an order
lives in `wp_wc_orders` / `wp_wc_orders_meta`, not in `wp_posts` / `wp_postmeta`.
A raw `get_post_meta()` / `update_post_meta()` on an order id then reads or writes
a copy WooCommerce ignores (or, with sync on, overwrites on the next sync). For
this plugin that meant: flip HPOS on and every fish order loses its delivery
date and lands on hold (`fishotel_shipping_date_safety_net` /
`fishotel_shipping_date_status_check` hold any fish order without a date).

Since v10.28:

1. every ORDER meta read/write goes through the WC_Order CRUD API via
   `FisHotel_Order_Meta` (`includes/class-order-meta.php`);
2. every order query by meta is store-agnostic (`FisHotel_Order_Meta::query()`);
3. the plugin declares `custom_order_tables` **compatible** and
   `cart_checkout_blocks` **incompatible**. Neither declaration blocks anything:
   the first keeps this plugin out of the "Incompatible plugin(s) detected"
   warning that would otherwise disable the HPOS radio on the Features page;
   the second only makes the Checkout block show a compatibility notice naming
   FisHotel Batch Manager (in the page editor, and to admins on the front end).
   WooCommerce has no block-checkout toggle and cannot refuse the block checkout,
   so the classic `[woocommerce_checkout]` page has to stay in place;
4. `tests/order-meta-test.php` scans the source on every CI run and fails when
   raw post meta on an order comes back.

Meta KEY names are unchanged — the theme (`fishotel_get_effective_delivery_date()`,
`inc/order-fulfillment/`), ShipTracker (`_fishotel_fulfillment_sources`) and the
Misc plugin read them through the same order objects.

---

## 1. Inventory

Every `get_post_meta` / `update_post_meta` / `delete_post_meta` / `add_post_meta`
/ `get_post` / `get_posts` / `WP_Query` / `$wpdb` call in
`fishotel-batch-manager.php` and `includes/*.php` was classified as

- **(a) ORDER** — the id is a WooCommerce order id (`$order_id`, `$order->get_id()`),
  or the query targets `shop_order`. These are the HPOS hazards; all changed.
- **(b) CPT / product / user** — fish_master, fish_request, fish_batch,
  fishotel_notification, fishotel_sticker posts; `product` post meta
  (`_fishotel_selling_price`, `_price`, `_regular_price`, `_sku`,
  `_linked_fish_master`); `usermeta` SQL (casino chips, arcade). WordPress
  post/user meta is the right store for these — unchanged, except where a
  `WC_Product` was already in hand.

Line numbers are the v10.27 (pre-change) lines; "now" is the v10.28 line.

### (a) ORDER-kind call sites — all changed

| v10.27 file: line | Was | Kind | What changed (v10.28) |
|---|---|---|---|
| `includes/class-woocommerce.php: 303` | `get_post_meta( $order_id, '_fishotel_wallet_credited', true )` | order meta read | `FisHotel_Order_Meta::order( $order_id )` then `$order->get_meta()` (now 305–307) |
| `includes/class-woocommerce.php: 314` | `update_post_meta( $order_id, '_fishotel_wallet_credited', 1 )` | order meta write | `FisHotel_Order_Meta::update( $order, …, 1 )` — one `save()` (now 316) |
| `includes/class-woocommerce.php: 744` | `update_post_meta( $order_id, '_fishotel_shipping_date', $date )` on `woocommerce_checkout_update_order_meta` | order meta write (checkout) | New `fishotel_shipping_date_create_order( $order, $data )` on `woocommerce_checkout_create_order` sets the meta on the object **before** WC saves it (now 748–753); the old hook stays as a fallback that writes through the object only when the stored value differs (now 760–769) |
| `includes/class-woocommerce.php: 789` | `admin_url( 'post.php?post=' . $order_id … )` in the hold e-mail | admin edit link | `FisHotel_Order_Meta::edit_url( $order )` → `wc-orders` on HPOS, `post.php` on CPT (now 812) |
| `includes/class-woocommerce.php: 829` | same, status-check e-mail | admin edit link | same (now 852) |
| `includes/class-woocommerce.php: 877` | `fishotel_shipping_date_admin_save( $order_id )` on `woocommerce_process_shop_order_meta` (1 arg) | admin Edit Order save | Takes `( $order_id, $order_or_post )` with 2 args registered; uses the `WC_Order` the **wc-orders** screen passes, falls back to `FisHotel_Order_Meta::order( $order_id )` on the legacy post screen (now 900–936) |
| `includes/class-woocommerce.php: 886` | `get_post_meta( $order_id, '_fishotel_shipping_date', true )` | order meta read | `$order->get_meta()` (now 919) |
| `includes/class-woocommerce.php: 889` | `update_post_meta( $order_id, '_fishotel_shipping_date', $date )` | order meta write | `FisHotel_Order_Meta::update( $order, …, $date )` — one save, note added on the same object (now 924) |
| `includes/class-woocommerce.php: 903` | `delete_post_meta( $order_id, '_fishotel_shipping_date' )` | order meta delete | `FisHotel_Order_Meta::delete( $order, … )` (now 934) |
| `includes/class-ajax.php: 1357–1383` | hand-rolled CPT pass-through + `wc_get_orders( [ 'meta_query' … ] )` always passing `meta_query` + manual re-check | order query by meta | `FisHotel_Order_Meta::query( …, [clauses] )` — `meta_query` sent on HPOS only (WC 9.2+ `wc_doing_it_wrong()`s it on CPT), CPT pass-through + `get_meta()` re-check inside the helper (now 1349–1366) |
| `includes/class-ajax.php: 1610` | `wc_get_orders( [ 'status' => pending/on-hold, 'meta_query' … ] )` — **on the CPT store `meta_query` is dropped: the Unpaid Invoices list returned every pending/on-hold order on the site** | order query by meta | `FisHotel_Order_Meta::query()` (now 1592–1599) |
| `includes/class-ajax.php: 1670` | `wc_get_orders( [ 'status' => processing/completed, 'meta_query' => [ _fishotel_shipping_date = ] ] )` — same CPT bug, legacy Packing List | order query by meta | `FisHotel_Order_Meta::query()` (now 1652–1656) |
| `includes/class-shortcodes.php: 4944` | `wc_get_orders( [ 'customer_id', 'limit' => 1, 'meta_query' … ] )` — on CPT returned the customer's latest order of ANY kind, then the `_fishotel_batch` re-check made the invoice page say "no invoice" | order query by meta | `FisHotel_Order_Meta::query()` (now 4944–4952) |
| `fishotel-batch-manager.php: 1114` | `wc_get_orders( [ 'meta_key' => '_fishotel_shipping_date' … ] )` in `fishotel_maybe_run_delivery_date_migration()` (`meta_key` honoured by one store only) | order query + migration | Body moved to `FisHotel_Order_Meta::migrate_delivery_dates()`: store-agnostic `EXISTS` query, `get_meta()` read, `update()` write with one `save()` per order; the wrapper keeps the one-time option gate (now 1136–1150) |
| `fishotel-batch-manager.php: 298` | `woocommerce_process_shop_order_meta` registered with 1 arg | hook registration | registered with `10, 2` so the wc-orders screen's `WC_Order` reaches the handler; `woocommerce_checkout_create_order` added (now 322–328) |
| `includes/class-ajax.php: 1534–1537` | `$order->update_meta_data( '_fishotel_batch' / `_fishotel_order_type` / `_fishotel_fish_data` / `_fishotel_shop_items` )` + one `$order->save()` | order meta write | Already CRUD; unchanged (listed for completeness) |
| `includes/class-woocommerce.php: 316, 624, 759–761, 801–807, 837` | `$order->get_meta( '_deposit_batch_name' / '_fishotel_order_type' / '_fishotel_shipping_date' / '_fishotel_shipping_date_checked' )`, `update_meta_data` + `save_meta_data` | order meta read/write | Already CRUD; unchanged |
| `includes/class-delivery-rules.php: 638–693`, `includes/class-ship-day.php: 630–760` | `wc_get_orders` with the store-agnostic pattern + `get_meta()` re-check | order query | Already store-agnostic (the pattern `FisHotel_Order_Meta::query()` generalises); unchanged |

Sites the audit text named that turned out **not** to be order-kind: the
`WP_Query` at `fishotel-batch-manager.php: 344` queries `fish_master`; the one at
`includes/class-admin.php: 3018` queries `fish_request`; `includes/class-admin.php: 4966`
and `includes/class-hotel-program.php: 4089` are `usermeta` / `options` SQL.
There is no direct `wp_postmeta` SQL on orders anywhere in the plugin.

### (b) CPT / product / user call sites — unchanged (by file)

| File | Lines (v10.27) | Object | Keys | Status |
|---|---|---|---|---|
| `fishotel-batch-manager.php` | 315–316, 344, 416–452, 488–532 | fish_master, fish_batch, fish_request | `_master_id`, `_is_admin_order`, `_cart_items`, `_current_qty`, `_arrival_*` | unchanged |
| `includes/class-admin.php` | 1331–2022, 2626–2934, 3018–3340, 3693–4883, 4951–5059 | fish_request, fish_batch, fish_master, fishotel_notification | `_batch_name`, `_customer_id`, `_cart_items`, `_total`, `_status`, `_deposit_*`, `_is_admin_order`, `_stock`, `_scientific_name`, `_selling_price`, `_wc_product_id`, `_item_code`, `_arrival_*`, `_qt_survival_log`, `_graduation_qty` | unchanged |
| `includes/class-admin.php` | 3282–3286 | **product** (`wp_insert_post` id, no `WC_Product` in hand yet) | `_price`, `_regular_price`, `_fishotel_selling_price`, `_linked_fish_master`, `_sku` | unchanged (post meta on products is supported by WooCommerce; HPOS does not move products) |
| `includes/class-ajax.php` | 46–108, 185–353, 1028–1035, 1245, 1421–1492 | fish_batch, fish_request, fish_master, fishotel_notification | as above | unchanged |
| `includes/class-woocommerce.php` | 46–143, 356–365 | fish_request, fish_batch | `_customer_id`, `_batch_name`, `_cart_items`, `_total`, `_status` | unchanged |
| `includes/class-woocommerce.php` | 919–921, 958–982 | fish_master + **product** (`save_post_product`, `updated_post_meta` sync) | `_fishotel_selling_price`, `_price`, `_regular_price`, `_linked_fish_master`, `_wc_product_id` | unchanged except **938**: `get_post_meta( $post_id, '_fishotel_selling_price' )` → `$product->get_meta()` (the `WC_Product` was already loaded) + a `wc_get_product()` false guard |
| `includes/class-shortcodes.php` | 96–256, 1070–1126, 1887–1926, 2637–2687, 3067–3263, 3943–4025, 4728 | fish_batch, fish_request, fish_master, fishotel_notification | as above | unchanged |
| `includes/class-hotel-program.php` | 43–51, 554–600, 3543–3610, 3727–3741, 3897–3901, 4043–4075 | fish_batch, fish_request, fishotel_notification | as above + `_fh_notif_*` | unchanged |
| `includes/class-northstar.php` | 252–301, 468–620, 1216–1266 | fish_master, fish_batch (`$wpdb->posts` title lookups) | `_master_id`, `_batch_name`, `_northstar_price`, `_stock`, `_item_code`, `_sdc_import` | unchanged |
| `includes/class-helpers.php` | 47 | fish_request | — | unchanged |
| `includes/class-casino.php`, `includes/class-arcade.php` | 81–139, 419, 1072–1103, 2219–2319, 2383–2447, 2681–2700, 2943–3079, 3117–3519 | fishotel_sticker posts, `usermeta` SQL | `_sticker_*`, `_fishotel_casino_*`, `_fishotel_*` user keys | unchanged |

## 2. `FisHotel_Order_Meta` (`includes/class-order-meta.php`)

Standalone static class, loaded right after `class-delivery-rules.php`.

| Method | Behaviour |
|---|---|
| `order( $order_or_id )` | `WC_Order` or `false` (missing id, refund, product, WP_Post, plugin CPT). Never calls `get_post()`. |
| `get( $order_or_id, $key, $single = true )` | `''` for a missing order / key (`[]` when `$single = false`) so callers keep their current defaults. |
| `update( $order_or_id, $key, $value, $save = true )` | `update_meta_data()` + ONE `save()`. Pass the object and `$save = false` to batch several keys, then save once. Returns `false` for a non-order. |
| `delete( $order_or_id, $key, $save = true )` | `delete_meta_data()` + one `save()`. |
| `has( $order_or_id, $key )` | `meta_exists()` (true even for an empty stored value). |
| `edit_url( $order_or_id )` | `get_edit_order_url()` → `admin.php?page=wc-orders&action=edit&id=` on HPOS, `post.php?post=` on CPT. |
| `hpos_enabled()` | `OrderUtil::custom_orders_table_usage_is_enabled()` (via `FisHotel_Delivery_Rules`). |
| `query( $args, $meta_query )` | Store-agnostic `wc_get_orders()`: `meta_query` sent on HPOS only; on CPT the clauses ride on a private query var that the `woocommerce_order_data_store_cpt_get_orders_query` filter maps back into the final `WP_Query`; every returned order is re-checked with `matches()`. `limit` defaults to 200; `return => 'ids'` honoured. |
| `matches( $order, $clauses )` | `=` (case-insensitive, padding-insensitive like MySQL), `!=`, `EXISTS`, `NOT EXISTS`, `IN`, `NOT IN`, `LIKE`; clauses AND together. |
| `count_by_meta( $key, $value = null )` | One prepared `COUNT(DISTINCT …)` against `OrderUtil::get_table_for_order_meta()` on HPOS, `wp_postmeta` joined to `shop_order` posts on CPT. For when only a number is needed. |
| `migrate_delivery_dates( $days, $timezone )` | The v10.22 +1-day migration body: `EXISTS` query, read + write through the object, one save per order. |

Rules for new code:

- Order meta: `FisHotel_Order_Meta::get/update/delete/has`, or `$order->get_meta()` /
  `update_meta_data()` / `save()` when the object is in hand. Never
  `*_post_meta( $order_id … )`.
- Hooks that hand you only an id (`woocommerce_payment_complete`,
  `woocommerce_order_status_*`, `woocommerce_checkout_update_order_meta`):
  `FisHotel_Order_Meta::order( $order_id )` first.
- `woocommerce_process_shop_order_meta`: register with 2 args and use the second
  argument (a `WC_Order` on wc-orders, a `WP_Post` on post.php — `order()`
  handles both).
- Querying orders by meta: `FisHotel_Order_Meta::query()`. Never `WP_Query` /
  `get_posts` over `shop_order`, never raw `meta_query` to `wc_get_orders()`,
  never `limit => -1` without a status or meta filter.
- Edit links: `FisHotel_Order_Meta::edit_url()` / `$order->get_edit_order_url()`.

`tests/order-meta-test.php` enforces the first and fourth rule by scanning the
source (comments stripped) for `*_post_meta( $order_id | $oid | $wc_order_id |
$parent_order_id | $order->get_id() … )`, any `*_post_meta(…)` call naming one of
the `FH_ORDER_META_KEYS`, any post query over `shop_order`, and any string
literal naming the `wc_orders_meta` table.

## 3. Compatibility declarations (`fishotel-batch-manager.php`, `before_woocommerce_init`)

```php
FeaturesUtil::declare_compatibility( 'custom_order_tables',  FISHOTEL_PLUGIN_FILE, true  );
FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', FISHOTEL_PLUGIN_FILE, false );
```

- **`custom_order_tables` → compatible.** Everything above.
- **`cart_checkout_blocks` → incompatible.** The delivery-date field
  (`woocommerce_after_order_notes`), its validators
  (`woocommerce_checkout_process`, `woocommerce_after_checkout_validation`), the
  save (`woocommerce_checkout_create_order`) and the livestock checks hook the
  **classic** checkout only. The block checkout has no delivery-date field, so a
  fish order placed through it would carry no date and be held. There is no
  block-checkout toggle for WooCommerce to refuse (`cart_checkout_blocks` is a
  `disable_ui` feature, so it is not even listed on the Features page); the
  declaration only feeds the Checkout block's incompatible-extensions notice,
  which names FisHotel Batch Manager in the page editor when a Checkout block is
  placed (and to admins viewing that page). It does not add an "incompatible
  with Cart & Checkout Blocks" row on the Plugins page either — that row needs a
  `WC tested up to:` header this plugin does not have, and only appears on the
  `plugin_status=incompatible_with_feature` view. **Keep the checkout page on
  the classic `[woocommerce_checkout]` shortcode until a blocks integration
  exists** (not part of this change).
- **`product_block_editor` → deliberately not declared.** The "FisHotel Selling
  Price" field is added on `woocommerce_product_options_general_product_data`
  and saved on `woocommerce_process_product_meta` — classic product editor hooks
  that the block product editor never fires — so the field would silently
  disappear. Declaring compatibility would be untrue; leave the new product
  editor off.

## 4. Testing on a staging site

1. **Before enabling anything:** WooCommerce → Status → Logs should show no
   `fishotel` notices. WooCommerce → Settings → Advanced → Features: the
   **High-performance order storage** radio is enabled (not greyed out) and
   shows no "⚠ Incompatible plugin(s) detected" warning that names FisHotel
   Batch Manager, and
   `plugins.php?plugin_status=incompatible_with_feature&feature_id=custom_order_tables`
   does not list it. (The Features page never lists *compatible* plugins, and
   there is no block-checkout toggle — the only place the
   `cart_checkout_blocks` declaration shows up is the Checkout block's
   compatibility notice in the page editor. The live checkout page must still
   use the classic `[woocommerce_checkout]` shortcode.)
2. Settings → Advanced → Features → enable **High-performance order storage**
   with **"Enable compatibility mode (synchronizes orders to the posts table)"**
   checked. Let the sync finish (Status → Tools shows pending sync = 0).
3. Place a fish order on the classic checkout with a delivery date. Confirm:
   - the order is **processing** (not on-hold), no "FISHOTEL ALERT" note;
   - WooCommerce → Orders (now `admin.php?page=wc-orders`) → edit → the
     **FisHotel Delivery Date** field shows the date under the billing address.
4. On that wc-orders screen change the date and Update. Reload: the new date is
   shown; the theme's My Account "Requested delivery" and ShipTracker show the
   same date; a date that breaks a rule adds the "admin override" order note.
   Clear the field and Update: the date is gone (no on-hold, the order was
   already accepted).
5. Flip the authoritative store the other way (WordPress posts storage, sync
   still on) and repeat steps 3–4 on `post.php?post=…&action=edit`.
   Flip back to HPOS. The date on every order survives both flips.
6. Run a batch through **Invoicing**: the duplicate guard, the Unpaid Invoices
   list and the customer invoice page (`[fishotel_batch]` invoice stage) all
   find the right orders on either store. Ship Day and the delivery-rules
   booked counts are unchanged.
7. Optional: disable sync (HPOS only). Repeat step 3 — the date must still save
   and display; `wp_postmeta` is no longer written for orders at all.
8. `php tests/order-meta-test.php` (and the other two tests) pass locally and in
   CI on every push.
