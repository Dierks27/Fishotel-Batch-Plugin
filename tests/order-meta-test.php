<?php
/**
 * FisHotel_Order_Meta + HPOS regression guard.
 *
 *   php tests/order-meta-test.php
 *
 * Plain PHP, no PHPUnit, no WordPress. Stub functions/classes are defined only
 * when WordPress is absent, so the file also runs inside a WP bootstrap
 * (WP-CLI); the stub-dependent sections are skipped there. Exits 1 on any
 * failure, 0 when everything passes.
 *
 * (a) SOURCE SCAN — reads every PHP file of the plugin (tests/ excluded) and
 *     FAILS when a post-meta function is called on an order-shaped argument or
 *     with one of the known ORDER meta keys, or when a post query targets
 *     shop_order. A future edit cannot reintroduce raw post meta on orders.
 * (b) FisHotel_Order_Meta behaviour with a stub WC_Order.
 * (c) The delivery-date migration reads and writes through the order object.
 *
 * CLI only: the plugin zip ships this directory, and the file defines ABSPATH
 * itself, so without this guard it would run when requested over HTTP.
 */

if ( 'cli' !== PHP_SAPI ) {
    exit;
}

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

/** Every meta key this plugin (or its siblings) stores ON A WOOCOMMERCE ORDER. */
define( 'FH_ORDER_META_KEYS', [
    '_fishotel_shipping_date',          // delivery date (checkout, admin Edit Order, migration)
    '_fishotel_shipping_date_checked',  // status-check marker
    '_fishotel_wallet_credited',        // wallet refill credited marker
    '_fishotel_batch',                  // batch invoice: batch name
    '_fishotel_order_type',             // batch invoice: 'batch_invoice'
    '_fishotel_fish_data',              // batch invoice: fish list JSON
    '_fishotel_shop_items',             // batch invoice: shop items JSON
    '_deposit_batch_name',              // wallet deposit order: batch name
    '_fishotel_is_fulfillment',         // theme fulfillment wrapper (read)
    '_fishotel_fulfillment_sources',    // theme fulfillment wrapper (read)
    '_fishotel_fulfilled_by_order',     // theme: source folded into a wrapper (read)
    '_fishotel_source_order',           // theme: wrapper line item meta (read)
    '_fishotel_source_line_item_id',    // theme: wrapper line item meta (read)
    '_fishotel_line_status',            // theme: line status item meta (read)
    '_fishotel_fish_id',                // batch invoice line item meta
] );

$fh_stubbed = false;
if ( ! function_exists( 'apply_filters' ) ) {
    $fh_stubbed = true;
    $GLOBALS['fh_test_filters'] = [];
    function apply_filters( $tag, $value, ...$args ) {
        foreach ( $GLOBALS['fh_test_filters'][ $tag ] ?? [] as $cb ) {
            $value = $cb( $value, ...$args );
        }
        return $value;
    }
    function add_filter( $tag, $cb ) {
        $GLOBALS['fh_test_filters'][ $tag ][] = $cb;
    }
    function remove_filter( $tag, $cb ) {
        foreach ( $GLOBALS['fh_test_filters'][ $tag ] ?? [] as $i => $registered ) {
            if ( $registered === $cb ) {
                unset( $GLOBALS['fh_test_filters'][ $tag ][ $i ] );
            }
        }
    }
    function admin_url( $path = '' ) {
        return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
    }

    /** Minimal WC_Order: the CRUD surface FisHotel_Order_Meta uses, with save counters. */
    class WC_Order {
        public $id;
        public $status;
        public $customer_id;
        public $meta       = [];
        public $saves      = 0;
        public $meta_saves = 0;
        public $notes      = [];
        public function __construct( $id, $status = 'processing', $meta = [], $customer_id = 7 ) {
            $this->id          = (int) $id;
            $this->status      = $status;
            $this->meta        = $meta;
            $this->customer_id = $customer_id;
        }
        public function get_id() {
            return $this->id;
        }
        public function get_status() {
            return $this->status;
        }
        public function get_customer_id() {
            return $this->customer_id;
        }
        public function get_meta( $key, $single = true ) {
            if ( $single ) {
                return array_key_exists( $key, $this->meta ) ? $this->meta[ $key ] : '';
            }
            return array_key_exists( $key, $this->meta )
                ? [ (object) [ 'key' => $key, 'value' => $this->meta[ $key ] ] ]
                : [];
        }
        public function meta_exists( $key ) {
            return array_key_exists( $key, $this->meta );
        }
        public function update_meta_data( $key, $value ) {
            $this->meta[ $key ] = $value;
        }
        public function delete_meta_data( $key ) {
            unset( $this->meta[ $key ] );
        }
        public function save() {
            $this->saves++;
        }
        public function save_meta_data() {
            $this->meta_saves++;
        }
        public function add_order_note( $note ) {
            $this->notes[] = $note;
        }
        public function get_edit_order_url() {
            return 'https://example.test/wp-admin/admin.php?page=wc-orders&action=edit&id=' . $this->id;
        }
    }
    /** wc_get_order() also returns refunds — they are not orders for our purposes. */
    class WC_Order_Refund {
        public $id;
        public function __construct( $id ) {
            $this->id = (int) $id;
        }
        public function get_id() {
            return $this->id;
        }
        public function get_meta( $key, $single = true ) {
            return '';
        }
        public function update_meta_data( $key, $value ) {}
    }
    class FH_OM_OrderUtil {
        public static $enabled = false;
        public static function custom_orders_table_usage_is_enabled() {
            return self::$enabled;
        }
        public static function get_table_for_order_meta() {
            return 'wp_wc_orders_meta';
        }
    }
    class FH_OM_WPDB {
        public $postmeta    = 'wp_postmeta';
        public $posts       = 'wp_posts';
        public $last_sql    = '';
        public $last_params = [];
        public $last_query  = '';
        public function prepare( $sql, ...$args ) {
            $params            = ( isset( $args[0] ) && is_array( $args[0] ) ) ? $args[0] : $args;
            $this->last_sql    = $sql;
            $this->last_params = $params;
            return vsprintf( str_replace( '%s', "'%s'", $sql ), $params );
        }
        public function get_var( $sql ) {
            $this->last_query = $sql;
            return '3';
        }
    }
    $GLOBALS['fh_om_orders']   = [];
    $GLOBALS['fh_om_wc_calls'] = [];
    function wc_get_order( $id ) {
        return $GLOBALS['fh_om_orders'][ (int) $id ] ?? false;
    }
    /** A backend that IGNORES the meta filter entirely (what the CPT store does with 'meta_query'). */
    function wc_get_orders( $args ) {
        $GLOBALS['fh_om_wc_calls'][] = $args;
        $GLOBALS['fh_om_cpt_query']  = apply_filters( 'woocommerce_order_data_store_cpt_get_orders_query', [ 'post_type' => 'shop_order' ], $args );
        $out = [];
        foreach ( $GLOBALS['fh_om_orders'] as $o ) {
            if ( ! $o instanceof WC_Order ) {
                continue;
            }
            if ( isset( $args['status'] ) && ! in_array( $o->status, (array) $args['status'], true ) ) {
                continue;
            }
            if ( isset( $args['customer_id'] ) && (int) $args['customer_id'] !== (int) $o->customer_id ) {
                continue;
            }
            $out[] = ( isset( $args['return'] ) && 'ids' === $args['return'] ) ? $o->id : $o;
            if ( isset( $args['limit'] ) && (int) $args['limit'] > 0 && count( $out ) >= (int) $args['limit'] ) {
                break;
            }
        }
        return $out;
    }
    ini_set( 'error_log', 'Windows' === PHP_OS_FAMILY ? 'NUL' : '/dev/null' ); // migration logs go nowhere
}

require_once dirname( __DIR__ ) . '/includes/class-order-meta.php';

$passes   = 0;
$failures = 0;
function check( $label, $cond, $detail = '' ) {
    global $passes, $failures;
    if ( $cond ) {
        $passes++;
        echo "  ok   - {$label}\n";
    } else {
        $failures++;
        echo "  FAIL - {$label}" . ( '' !== $detail ? " [{$detail}]" : '' ) . "\n";
    }
}
function section( $title ) {
    echo "\n{$title}\n";
}

/* ───────────────────────────── (a) source scan ───────────────────────────── */

/** Blank out comments but keep the line count, so reported line numbers are real. */
function fh_strip_comments( $src ) {
    $src = preg_replace_callback( '#/\*.*?\*/#s', function ( $m ) {
        return str_repeat( "\n", substr_count( $m[0], "\n" ) );
    }, $src );
    return preg_replace( '#(^|[ \t])//[^\n]*#m', '$1', $src );
}

/**
 * @return array  [ 'file:line: rule — snippet', ... ]
 */
function fh_scan_source( $src, $label ) {
    $src      = fh_strip_comments( $src );
    $keys     = implode( '|', array_map( 'preg_quote', FH_ORDER_META_KEYS ) );
    $order_id = '\$order_id\b|\$oid\b|\$wc_order_id\b|\$parent_order_id\b|\$order_or_id\b|\$order->get_id\(\)|\$the_order->get_id\(\)';
    $rules    = [
        'post meta on an order id'    => '/\b(?:get|update|delete|add)_post_meta\(\s*(?:\(int\)\s*)?(?:' . $order_id . ')/',
        'post meta with an ORDER key' => '/\b(?:get|update|delete|add)_post_meta\([^;]*?[\'"](?:' . $keys . ')[\'"]/s',
        'post query over shop_order'  => '/\b(?:get_posts|get_post|query_posts|new\s+\\\\?WP_Query)\s*\([^;]*?[\'"]shop_order[\'"]/s',
        'raw order meta table SQL'    => '/[\'"][^\'"]*\b(?:wp_)?wc_orders_meta\b[^\'"]*[\'"]/',
    ];
    $hits = [];
    foreach ( $rules as $rule => $re ) {
        if ( preg_match_all( $re, $src, $m, PREG_OFFSET_CAPTURE ) ) {
            foreach ( $m[0] as $hit ) {
                $line   = substr_count( $src, "\n", 0, $hit[1] ) + 1;
                $hits[] = $label . ':' . $line . ': ' . $rule . ' — ' . trim( preg_replace( '/\s+/', ' ', substr( $hit[0], 0, 90 ) ) );
            }
        }
    }
    return $hits;
}

section( '(a) the scanner itself catches every forbidden shape (fixture)' );
$fixture = "<?php\n"
    . "update_post_meta( \$order_id, '_fishotel_shipping_date', \$date );\n"
    . "\$x = get_post_meta( \$order->get_id(), '_anything', true );\n"
    . "delete_post_meta( (int) \$oid, 'k' );\n"
    . "add_post_meta( \$post_id, '_fishotel_wallet_credited', 1 );\n"
    . "\$r = get_post_meta( \$id,\n    '_fishotel_batch', true );\n"
    . "\$q = new \\WP_Query( [ 'post_type' => 'shop_order' ] );\n"
    . "\$p = get_posts( [ 'post_type' => 'shop_order', 'fields' => 'ids' ] );\n"
    . "\$wpdb->get_var( \"SELECT COUNT(*) FROM wp_wc_orders_meta WHERE 1\" );\n"
    . "// get_post_meta( \$order_id, '_fishotel_batch', true ) in a comment is fine\n"
    . "/* update_post_meta( \$order_id, '_fishotel_shipping_date', 1 ) also fine */\n"
    . "update_post_meta( \$request_id, '_batch_name', \$b ); // plugin CPT: allowed\n"
    . "\$o->get_meta( '_fishotel_shipping_date' ); // CRUD: allowed\n";
$hits = fh_scan_source( $fixture, 'fixture.php' );
check( 'nine violations found, comments and allowed calls ignored', 9 === count( $hits ), implode( ' | ', $hits ) );
check( 'order-id rule hit on lines 2, 3, 4', 3 === count( preg_grep( '/^fixture\.php:(2|3|4): post meta on an order id/', $hits ) ), implode( ' | ', $hits ) );
check( 'order-key rule hit on lines 2 (date), 5 (wallet), 6 (batch, multi-line)', 3 === count( preg_grep( '/^fixture\.php:(2|5|6): post meta with an ORDER key/', $hits ) ), implode( ' | ', $hits ) );
check( 'shop_order post queries hit on lines 8 and 9 (the multi-line call above spans two lines)', 2 === count( preg_grep( '/^fixture\.php:(8|9): post query over shop_order/', $hits ) ), implode( ' | ', $hits ) );
check( 'raw wc_orders_meta SQL hit on line 10', 1 === count( preg_grep( '/^fixture\.php:10: raw order meta table SQL/', $hits ) ), implode( ' | ', $hits ) );

section( '(a) no raw post meta / post queries on WooCommerce orders anywhere in the plugin' );
$root  = dirname( __DIR__ );
$files = [];
$it    = new RecursiveIteratorIterator( new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
    function ( $current ) use ( $root ) {
        $rel = ltrim( str_replace( $root, '', $current->getPathname() ), '/\\' );
        if ( $current->isDir() ) {
            return ! in_array( $rel, [ 'tests', '.git', 'node_modules', 'vendor' ], true );
        }
        return 'php' === strtolower( $current->getExtension() );
    }
) );
foreach ( $it as $file ) {
    $files[] = $file->getPathname();
}
sort( $files );
check( 'plugin files found (main file + includes/)', count( $files ) >= 14 && in_array( $root . '/includes/class-order-meta.php', $files, true ), implode( ', ', $files ) );
$all_hits = [];
foreach ( $files as $path ) {
    $all_hits = array_merge( $all_hits, fh_scan_source( (string) file_get_contents( $path ), ltrim( str_replace( $root, '', $path ), '/\\' ) ) );
}
check( 'zero violations in ' . count( $files ) . ' files', [] === $all_hits, "\n      " . implode( "\n      ", $all_hits ) );
check( 'the main file declares HPOS compatibility and blocks-checkout incompatibility', 1 === preg_match( '/declare_compatibility\(\s*[\'"]custom_order_tables[\'"],\s*FISHOTEL_PLUGIN_FILE,\s*true\s*\)/', (string) file_get_contents( $root . '/fishotel-batch-manager.php' ) ) && 1 === preg_match( '/declare_compatibility\(\s*[\'"]cart_checkout_blocks[\'"],\s*FISHOTEL_PLUGIN_FILE,\s*false\s*\)/', (string) file_get_contents( $root . '/fishotel-batch-manager.php' ) ) );
check( 'the admin Edit Order save hook receives the WC_Order (2 args) on both stores', 1 === preg_match( '/woocommerce_process_shop_order_meta[\'"],\s*\[\$this,\s*[\'"]fishotel_shipping_date_admin_save[\'"]\],\s*10,\s*2\s*\)/', (string) file_get_contents( $root . '/fishotel-batch-manager.php' ) ) );

/* ───────────────────────── (b) FisHotel_Order_Meta ───────────────────────── */

if ( $fh_stubbed ) {
    $GLOBALS['fh_om_orders'] = [
        100 => new WC_Order( 100, 'processing', [ '_fishotel_shipping_date' => '2026-10-13', '_fishotel_batch' => 'Cebu Oct', '_fishotel_order_type' => 'batch_invoice' ] ),
        101 => new WC_Order( 101, 'processing', [ '_fishotel_shipping_date' => 'soon' ] ),
        102 => new WC_Order( 102, 'on-hold',    [ '_fishotel_shipping_date' => '2026-10-13', '_fishotel_batch' => 'cebu oct ', '_fishotel_order_type' => 'batch_invoice' ], 8 ),
        103 => new WC_Order( 103, 'processing', [] ),
        900 => new WC_Order_Refund( 900 ),
    ];

    section( '(b) order(): resolves ids and objects, never a refund, never a post' );
    check( 'id → WC_Order', $GLOBALS['fh_om_orders'][100] === FisHotel_Order_Meta::order( 100 ) );
    check( 'string id → WC_Order', $GLOBALS['fh_om_orders'][100] === FisHotel_Order_Meta::order( '100' ) );
    check( 'unknown id → false', false === FisHotel_Order_Meta::order( 999 ) );
    check( '0 / negative → false', false === FisHotel_Order_Meta::order( 0 ) && false === FisHotel_Order_Meta::order( -5 ) );
    check( 'refund id → false', false === FisHotel_Order_Meta::order( 900 ) );
    check( 'order object passes through', $GLOBALS['fh_om_orders'][100] === FisHotel_Order_Meta::order( $GLOBALS['fh_om_orders'][100] ) );
    check( 'refund object → false', false === FisHotel_Order_Meta::order( $GLOBALS['fh_om_orders'][900] ) );
    check( 'WP_Post-like object → false', false === FisHotel_Order_Meta::order( (object) [ 'ID' => 100, 'post_type' => 'shop_order' ] ) );
    check( 'null → false', false === FisHotel_Order_Meta::order( null ) );

    section( '(b) get(): current defaults for missing orders and keys' );
    check( 'value by id', '2026-10-13' === FisHotel_Order_Meta::get( 100, '_fishotel_shipping_date' ) );
    check( 'value by object', 'Cebu Oct' === FisHotel_Order_Meta::get( $GLOBALS['fh_om_orders'][100], '_fishotel_batch' ) );
    check( "missing key → ''", '' === FisHotel_Order_Meta::get( 100, '_nope' ) );
    check( "missing order → ''", '' === FisHotel_Order_Meta::get( 999, '_fishotel_shipping_date' ) );
    check( 'missing order, $single = false → []', [] === FisHotel_Order_Meta::get( 999, '_fishotel_shipping_date', false ) );
    check( '$single = false → plain array of values', [ '2026-10-13' ] === FisHotel_Order_Meta::get( 100, '_fishotel_shipping_date', false ) );
    check( '$single = false, missing key → []', [] === FisHotel_Order_Meta::get( 100, '_nope', false ) );

    section( '(b) update() / delete() / has(): one save, batching with $save = false' );
    $o = new WC_Order( 200, 'processing', [] );
    $GLOBALS['fh_om_orders'][200] = $o;
    check( 'update by id writes and saves once', true === FisHotel_Order_Meta::update( 200, '_fishotel_shipping_date', '2026-10-20' ) && '2026-10-20' === $o->meta['_fishotel_shipping_date'] && 1 === $o->saves );
    check( 'has() true after the write', true === FisHotel_Order_Meta::has( 200, '_fishotel_shipping_date' ) );
    check( 'update on a missing order → false', false === FisHotel_Order_Meta::update( 999, '_fishotel_shipping_date', 'x' ) );
    $o->saves = 0;
    FisHotel_Order_Meta::update( $o, '_fishotel_batch', 'Cebu Oct', false );
    FisHotel_Order_Meta::update( $o, '_fishotel_order_type', 'batch_invoice', false );
    FisHotel_Order_Meta::update( $o, '_fishotel_shop_items', '[]', false );
    check( 'three writes with $save = false → no save yet', 0 === $o->saves && 'batch_invoice' === $o->meta['_fishotel_order_type'] );
    $o->save();
    check( 'caller saves once', 1 === $o->saves );
    $o->saves = 0;
    check( 'delete by object removes the key and saves once', true === FisHotel_Order_Meta::delete( $o, '_fishotel_shop_items' ) && ! array_key_exists( '_fishotel_shop_items', $o->meta ) && 1 === $o->saves );
    check( 'has() false after delete', false === FisHotel_Order_Meta::has( $o, '_fishotel_shop_items' ) );
    check( 'delete with $save = false does not save', true === FisHotel_Order_Meta::delete( $o, '_fishotel_batch', false ) && 1 === $o->saves );
    check( 'delete on a missing order → false', false === FisHotel_Order_Meta::delete( 999, '_fishotel_batch' ) );
    $o->update_meta_data( '_fishotel_shipping_date_checked', '' );
    check( "has() is true for a key stored with an empty value", true === FisHotel_Order_Meta::has( $o, '_fishotel_shipping_date_checked' ) );
    check( 'has() on a missing order → false', false === FisHotel_Order_Meta::has( 999, '_fishotel_shipping_date' ) );

    section( '(b) edit_url(): wc-orders on HPOS objects, post.php fallback' );
    check( 'from the order object', 'https://example.test/wp-admin/admin.php?page=wc-orders&action=edit&id=100' === FisHotel_Order_Meta::edit_url( 100 ) );
    check( 'missing order → classic post.php link', 'https://example.test/wp-admin/post.php?post=999&action=edit' === FisHotel_Order_Meta::edit_url( 999 ) );

    section( '(b) hpos_enabled() follows OrderUtil' );
    check( 'false when OrderUtil is absent', false === FisHotel_Order_Meta::hpos_enabled() );
    class_alias( 'FH_OM_OrderUtil', 'Automattic\\WooCommerce\\Utilities\\OrderUtil' );
    FH_OM_OrderUtil::$enabled = true;
    check( 'true when the orders table is active', true === FisHotel_Order_Meta::hpos_enabled() );
    FH_OM_OrderUtil::$enabled = false;
    check( 'false when the orders table is disabled', false === FisHotel_Order_Meta::hpos_enabled() );

    section( '(b) matches(): the SQL semantics re-checked on the object' );
    $m = $GLOBALS['fh_om_orders'][100];
    check( "'=' default compare", true === FisHotel_Order_Meta::matches( $m, [ [ 'key' => '_fishotel_batch', 'value' => 'Cebu Oct' ] ] ) );
    check( "'=' is case-insensitive and ignores padding (MySQL collation)", true === FisHotel_Order_Meta::matches( $GLOBALS['fh_om_orders'][102], [ [ 'key' => '_fishotel_batch', 'value' => 'CEBU OCT', 'compare' => '=' ] ] ) );
    check( "'=' fails on a different value", false === FisHotel_Order_Meta::matches( $m, [ [ 'key' => '_fishotel_batch', 'value' => 'Bali' ] ] ) );
    check( "'=' fails on a missing key", false === FisHotel_Order_Meta::matches( $m, [ [ 'key' => '_nope', 'value' => '' ] ] ) );
    check( 'EXISTS / NOT EXISTS', true === FisHotel_Order_Meta::matches( $m, [ [ 'key' => '_fishotel_shipping_date', 'compare' => 'EXISTS' ] ] ) && false === FisHotel_Order_Meta::matches( $m, [ [ 'key' => '_fishotel_shipping_date', 'compare' => 'NOT EXISTS' ] ] ) && true === FisHotel_Order_Meta::matches( $GLOBALS['fh_om_orders'][103], [ [ 'key' => '_fishotel_shipping_date', 'compare' => 'NOT EXISTS' ] ] ) );
    check( "'!='", true === FisHotel_Order_Meta::matches( $m, [ [ 'key' => '_fishotel_batch', 'value' => 'Bali', 'compare' => '!=' ] ] ) && false === FisHotel_Order_Meta::matches( $m, [ [ 'key' => '_fishotel_batch', 'value' => 'cebu oct', 'compare' => '!=' ] ] ) );
    check( 'IN / NOT IN', true === FisHotel_Order_Meta::matches( $m, [ [ 'key' => '_fishotel_batch', 'value' => [ 'Bali', 'Cebu Oct' ], 'compare' => 'IN' ] ] ) && false === FisHotel_Order_Meta::matches( $m, [ [ 'key' => '_fishotel_batch', 'value' => [ 'Bali', 'Cebu Oct' ], 'compare' => 'NOT IN' ] ] ) );
    check( 'LIKE', true === FisHotel_Order_Meta::matches( $m, [ [ 'key' => '_fishotel_batch', 'value' => 'cebu', 'compare' => 'LIKE' ] ] ) );
    check( 'clauses AND together', false === FisHotel_Order_Meta::matches( $m, [ [ 'key' => '_fishotel_batch', 'value' => 'Cebu Oct' ], [ 'key' => '_fishotel_order_type', 'value' => 'deposit' ] ] ) );
    check( 'a refund never matches', false === FisHotel_Order_Meta::matches( $GLOBALS['fh_om_orders'][900], [ [ 'key' => '_fishotel_batch', 'compare' => 'EXISTS' ] ] ) );

    section( '(b) query(): CPT store — meta rides on the pass-through filter, results re-checked' );
    $clauses = [
        [ 'key' => '_fishotel_batch',      'value' => 'Cebu Oct',      'compare' => '=' ],
        [ 'key' => '_fishotel_order_type', 'value' => 'batch_invoice', 'compare' => '=' ],
    ];
    $GLOBALS['fh_om_wc_calls'] = [];
    $got  = FisHotel_Order_Meta::query( [ 'status' => [ 'processing', 'on-hold' ], 'limit' => -1 ], $clauses );
    $args = $GLOBALS['fh_om_wc_calls'][0] ?? null;
    check( 'meta_query NOT passed to wc_get_orders on CPT (WC 9.2+ would wc_doing_it_wrong() it)', is_array( $args ) && ! array_key_exists( 'meta_query', $args ), json_encode( $args ) );
    check( 'private query var set, type shop_order, objects', is_array( $args ) && 1 === ( $args[ FisHotel_Order_Meta::QUERY_VAR ] ?? null ) && 'shop_order' === $args['type'] && 'objects' === $args['return'] && -1 === $args['limit'], json_encode( $args ) );
    check( 'the CPT filter maps the clauses back into the final WP_Query meta_query', $clauses === ( $GLOBALS['fh_om_cpt_query']['meta_query'] ?? null ), json_encode( $GLOBALS['fh_om_cpt_query'] ) );
    check( 'filter removed afterwards', [] === array_filter( $GLOBALS['fh_test_filters']['woocommerce_order_data_store_cpt_get_orders_query'] ?? [] ) );
    check( 'a backend that ignored the meta filter is corrected by the re-check: only #100 and #102', [ 100, 102 ] === array_map( function ( $o ) { return $o->get_id(); }, $got ), json_encode( array_map( function ( $o ) { return $o->get_id(); }, $got ) ) );
    $GLOBALS['fh_om_wc_calls'] = [];
    $ids = FisHotel_Order_Meta::query( [ 'status' => [ 'processing', 'on-hold' ], 'limit' => -1, 'return' => 'ids' ], $clauses );
    check( "'return' => 'ids' → ints, re-checked through wc_get_order()", [ 100, 102 ] === $ids && 'ids' === $GLOBALS['fh_om_wc_calls'][0]['return'], json_encode( $ids ) );
    $GLOBALS['fh_om_wc_calls'] = [];
    $one = FisHotel_Order_Meta::query( [ 'customer_id' => 8, 'limit' => 1 ], $clauses );
    check( 'customer_id + limit 1 (invoice shortcode shape)', 1 === count( $one ) && 102 === $one[0]->get_id() && 8 === $GLOBALS['fh_om_wc_calls'][0]['customer_id'] );
    $GLOBALS['fh_om_wc_calls'] = [];
    FisHotel_Order_Meta::query( [ 'status' => 'processing', 'meta_query' => [ [ 'key' => 'x' ] ], 'meta_key' => 'x' ] );
    $args = $GLOBALS['fh_om_wc_calls'][0];
    check( 'no clauses: caller-supplied meta args are stripped, limit defaults to 200, no private var', ! isset( $args['meta_query'] ) && ! isset( $args['meta_key'] ) && 200 === $args['limit'] && ! isset( $args[ FisHotel_Order_Meta::QUERY_VAR ] ), json_encode( $args ) );
    check( 'no clauses: no pass-through filter left behind', [] === array_filter( $GLOBALS['fh_test_filters']['woocommerce_order_data_store_cpt_get_orders_query'] ?? [] ) );

    section( '(b) query(): HPOS — meta_query passed straight through' );
    unset( $GLOBALS['fh_om_orders'][200] ); // the batching fixture from above
    FH_OM_OrderUtil::$enabled  = true;
    $GLOBALS['fh_om_wc_calls'] = [];
    $got  = FisHotel_Order_Meta::query( [ 'status' => 'processing', 'limit' => -1 ], [ [ 'key' => '_fishotel_shipping_date', 'compare' => 'EXISTS' ] ] );
    $args = $GLOBALS['fh_om_wc_calls'][0];
    check( 'meta_query IS passed on HPOS', [ [ 'key' => '_fishotel_shipping_date', 'compare' => 'EXISTS' ] ] === ( $args['meta_query'] ?? null ), json_encode( $args ) );
    check( 'orders without the key are still dropped by the re-check (#103)', [ 100, 101 ] === array_map( function ( $o ) { return $o->get_id(); }, $got ) );
    FH_OM_OrderUtil::$enabled = false;

    section( '(b) count_by_meta(): prepared count against the authoritative meta table' );
    $GLOBALS['wpdb'] = new FH_OM_WPDB();
    $wpdb            = $GLOBALS['wpdb'];
    $n = FisHotel_Order_Meta::count_by_meta( '_fishotel_shipping_date' );
    check( 'CPT: wp_postmeta joined to shop_order posts, key as a prepared param', 3 === $n && false !== strpos( $wpdb->last_sql, 'wp_postmeta' ) && false !== strpos( $wpdb->last_sql, "post_type = 'shop_order'" ) && [ '_fishotel_shipping_date' ] === $wpdb->last_params, $wpdb->last_sql );
    $n = FisHotel_Order_Meta::count_by_meta( '_fishotel_shipping_date', '2026-10-13' );
    check( 'CPT: value is a second prepared param', 3 === $n && false !== strpos( $wpdb->last_sql, 'm.meta_value = %s' ) && [ '_fishotel_shipping_date', '2026-10-13' ] === $wpdb->last_params );
    FH_OM_OrderUtil::$enabled = true;
    $n = FisHotel_Order_Meta::count_by_meta( '_fishotel_batch', 'Cebu Oct' );
    check( 'HPOS: OrderUtil::get_table_for_order_meta() table, order_id column, no posts join', 3 === $n && false !== strpos( $wpdb->last_sql, '`wp_wc_orders_meta`' ) && false !== strpos( $wpdb->last_sql, 'm.order_id' ) && false === strpos( $wpdb->last_sql, 'wp_postmeta' ) && [ '_fishotel_batch', 'Cebu Oct' ] === $wpdb->last_params, $wpdb->last_sql );
    FH_OM_OrderUtil::$enabled = false;
    check( 'empty key → 0 without a query', 0 === FisHotel_Order_Meta::count_by_meta( '' ) );

    /* ───────────────────── (c) delivery-date migration ───────────────────── */

    section( '(c) migrate_delivery_dates(): store-agnostic query, read + write through the object, one save' );
    $GLOBALS['fh_om_orders'] = [
        100 => new WC_Order( 100, 'processing', [ '_fishotel_shipping_date' => '2026-10-13' ] ),
        101 => new WC_Order( 101, 'processing', [ '_fishotel_shipping_date' => 'soon' ] ),
        102 => new WC_Order( 102, 'on-hold',    [ '_fishotel_shipping_date' => '2026-10-13' ] ),
        103 => new WC_Order( 103, 'processing', [] ),
        104 => new WC_Order( 104, 'processing', [ '_fishotel_shipping_date' => '2026-12-31' ] ),
    ];
    $GLOBALS['fh_om_wc_calls'] = [];
    $n    = FisHotel_Order_Meta::migrate_delivery_dates( 1, 'America/Chicago' );
    $args = $GLOBALS['fh_om_wc_calls'][0] ?? null;
    check( 'two processing orders shifted', 2 === $n );
    check( '#100 2026-10-13 → 2026-10-14, saved once through the object', '2026-10-14' === $GLOBALS['fh_om_orders'][100]->meta['_fishotel_shipping_date'] && 1 === $GLOBALS['fh_om_orders'][100]->saves );
    check( '#104 crosses the year: 2026-12-31 → 2027-01-01', '2027-01-01' === $GLOBALS['fh_om_orders'][104]->meta['_fishotel_shipping_date'] && 1 === $GLOBALS['fh_om_orders'][104]->saves );
    check( '#101 (not Y-m-d) untouched, not saved', 'soon' === $GLOBALS['fh_om_orders'][101]->meta['_fishotel_shipping_date'] && 0 === $GLOBALS['fh_om_orders'][101]->saves );
    check( '#102 (on-hold) never queried', 0 === $GLOBALS['fh_om_orders'][102]->saves && '2026-10-13' === $GLOBALS['fh_om_orders'][102]->meta['_fishotel_shipping_date'] );
    check( '#103 (no date; a backend ignoring the filter still returned it) untouched', [] === $GLOBALS['fh_om_orders'][103]->meta && 0 === $GLOBALS['fh_om_orders'][103]->saves );
    check( "query: processing, limit -1, objects, no 'meta_key' arg", is_array( $args ) && 'processing' === $args['status'] && -1 === $args['limit'] && 'objects' === $args['return'] && ! isset( $args['meta_key'] ), json_encode( $args ) );
    check( 'query: CPT store got the EXISTS clause through the pass-through filter', [ [ 'key' => '_fishotel_shipping_date', 'compare' => 'EXISTS' ] ] === ( $GLOBALS['fh_om_cpt_query']['meta_query'] ?? null ) && ! isset( $args['meta_query'] ), json_encode( $GLOBALS['fh_om_cpt_query'] ) );
    FH_OM_OrderUtil::$enabled  = true;
    $GLOBALS['fh_om_wc_calls'] = [];
    FisHotel_Order_Meta::migrate_delivery_dates( 1 );
    check( 'query: HPOS store gets the EXISTS clause as meta_query', [ [ 'key' => '_fishotel_shipping_date', 'compare' => 'EXISTS' ] ] === ( $GLOBALS['fh_om_wc_calls'][0]['meta_query'] ?? null ) );
    FH_OM_OrderUtil::$enabled = false;
    check( 'the main-file wrapper delegates to migrate_delivery_dates() and gates on the one-time option', 1 === preg_match( '/function fishotel_maybe_run_delivery_date_migration\(\)\s*\{.*?fishotel_delivery_date_migration_done.*?FisHotel_Order_Meta::migrate_delivery_dates\(/s', (string) file_get_contents( $root . '/fishotel-batch-manager.php' ) ) );
}

echo "\n{$passes} passed, {$failures} failed.\n";
exit( $failures > 0 ? 1 : 0 );
