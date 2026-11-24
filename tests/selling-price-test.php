<?php
/**
 * FisHotel Selling Price (10.37) — self-contained test.
 *
 *   php tests/selling-price-test.php
 *
 * Plain PHP, no PHPUnit, no WordPress. Part 1 checks the pure rules
 * (FisHotel_Selling_Price). Part 2 runs the plugin's real hook methods
 * (the FisHotel_WooCommerce trait) through a stand-in for WordPress's post meta
 * that behaves as update_metadata() does — a value only counts as unchanged when
 * it is identical (string "12" vs float 12.0 is a change), updated_post_meta
 * fires on a change of an existing value, added_post_meta on a new one — and a
 * stand-in product save in WordPress's order: save_post_product (5, 10) →
 * save_post → woocommerce_process_product_meta (WooCommerce's own save at 10,
 * then Batch at 20); quick / bulk edit: save_post_product → WooCommerce writes
 * the prices → woocommerce_product_quick_edit_save. Part 3 scans the source.
 * Exits 1 on any failure, 0 when everything passes.
 *
 * CLI only: the plugin zip ships this directory.
 */

if ( 'cli' !== PHP_SAPI ) {
    exit;
}
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

$passes = 0;
$failures = 0;
function check( $label, $ok, $detail = '' ) {
    global $passes, $failures;
    if ( $ok ) {
        $passes++;
        echo "  ok   {$label}\n";
    } else {
        $failures++;
        echo "  FAIL {$label}" . ( '' !== $detail ? " — {$detail}" : '' ) . "\n";
    }
}

// ---------------------------------------------------------------------------
// Stand-in WordPress: post meta, posts, terms, hooks.
// ---------------------------------------------------------------------------
$GLOBALS['fh_meta']   = [];  // [post_id][key] => string
$GLOBALS['fh_types']  = [];  // [post_id] => post type
$GLOBALS['fh_terms']  = [];  // [post_id] => [slugs]
$GLOBALS['fh_posts']  = [];  // [post_id] => ['post_title' => .., 'post_excerpt' => .., 'post_content' => ..]
$GLOBALS['fh_hooks']  = [];  // [hook][priority][] => [callable, args]
$GLOBALS['fh_added']  = [];  // [post_id][key] => [values] (add_post_meta)
$GLOBALS['fh_writes'] = [];  // log of meta writes: "post_id:key=value" / "post_id:key deleted"
$GLOBALS['fh_next_id'] = 1000;

function fh_reset() {
    $GLOBALS['fh_meta'] = $GLOBALS['fh_types'] = $GLOBALS['fh_terms'] = $GLOBALS['fh_posts'] = $GLOBALS['fh_writes'] = $GLOBALS['fh_added'] = [];
}
function add_action( $hook, $cb, $priority = 10, $args = 1 ) {
    $GLOBALS['fh_hooks'][ $hook ][ $priority ][] = [ $cb, $args ];
}
function do_action( $hook, ...$a ) {
    if ( empty( $GLOBALS['fh_hooks'][ $hook ] ) ) return;
    $by = $GLOBALS['fh_hooks'][ $hook ];
    ksort( $by );
    foreach ( $by as $list ) {
        foreach ( $list as $h ) {
            call_user_func_array( $h[0], array_slice( $a, 0, $h[1] ) );
        }
    }
}
function maybe_serialize( $v ) { return is_array( $v ) || is_object( $v ) ? serialize( $v ) : $v; }
function get_post_meta( $id, $key = '', $single = false ) {
    $v = $GLOBALS['fh_meta'][ $id ][ $key ] ?? null;
    if ( $single ) return null === $v ? '' : $v;
    return null === $v ? [] : [ $v ];
}
function update_post_meta( $id, $key, $value ) {
    $exists = isset( $GLOBALS['fh_meta'][ $id ] ) && array_key_exists( $key, $GLOBALS['fh_meta'][ $id ] );
    if ( $exists && $GLOBALS['fh_meta'][ $id ][ $key ] === $value ) {
        return false; // identical: no write, no hook (update_metadata())
    }
    $GLOBALS['fh_meta'][ $id ][ $key ] = (string) maybe_serialize( $value );
    $GLOBALS['fh_writes'][] = "{$id}:{$key}=" . (string) $value;
    do_action( $exists ? 'updated_post_meta' : 'added_post_meta', 1, $id, $key, $value );
    return true;
}
function add_post_meta( $id, $key, $value, $unique = false ) {
    // Several values under one key are kept as a list (what get_post_meta( $id, $key ) returns).
    $GLOBALS['fh_added'][ $id ][ $key ][] = (string) $value;
    $GLOBALS['fh_writes'][] = "{$id}:{$key}+=" . (string) $value;
    return true;
}
function delete_post_meta( $id, $key ) {
    if ( ! isset( $GLOBALS['fh_meta'][ $id ] ) || ! array_key_exists( $key, $GLOBALS['fh_meta'][ $id ] ) ) return false;
    unset( $GLOBALS['fh_meta'][ $id ][ $key ] );
    $GLOBALS['fh_writes'][] = "{$id}:{$key} deleted";
    do_action( 'deleted_post_meta', [ 1 ], $id, $key, '' );
    return true;
}
function get_post_type( $id ) { return $GLOBALS['fh_types'][ $id ] ?? false; }
function get_post( $id ) { return isset( $GLOBALS['fh_types'][ $id ] ) ? (object) [ 'ID' => $id, 'post_type' => $GLOBALS['fh_types'][ $id ] ] : null; }
function wp_get_post_terms( $id, $tax, $args = [] ) { return $GLOBALS['fh_terms'][ $id ] ?? []; }
function wp_is_post_revision( $id ) { return false; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
function wc_clean( $v ) { return is_scalar( $v ) ? trim( strip_tags( (string) $v ) ) : $v; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function wp_update_post( $arr ) {
    foreach ( $arr as $k => $v ) if ( 'ID' !== $k ) $GLOBALS['fh_posts'][ $arr['ID'] ][ $k ] = $v;
    return $arr['ID'];
}
function wp_insert_post( $arr ) {
    $id = $GLOBALS['fh_next_id']++;
    $GLOBALS['fh_types'][ $id ] = $arr['post_type'];
    $GLOBALS['fh_posts'][ $id ] = $arr;
    return $id;
}
function get_posts( $args ) {
    $out = [];
    foreach ( $GLOBALS['fh_types'] as $id => $type ) {
        if ( $type !== $args['post_type'] ) continue;
        $ok = true;
        foreach ( $args['meta_query'] ?? [] as $mq ) {
            if ( get_post_meta( $id, $mq['key'], true ) !== $mq['value'] ) $ok = false;
        }
        if ( $ok ) $out[] = (object) [ 'ID' => $id ];
    }
    return array_slice( $out, 0, $args['numberposts'] ?? 5 );
}

/** A stand-in WC_Product reading the stand-in meta (as the CPT data store does). */
class FH_Test_Product {
    private $id;
    public function __construct( $id ) { $this->id = $id; }
    public function get_id() { return $this->id; }
    public function get_price() { return get_post_meta( $this->id, '_price', true ); }
    public function get_meta( $key ) { return get_post_meta( $this->id, $key, true ); }
    public function get_name() { return $GLOBALS['fh_posts'][ $this->id ]['post_title'] ?? ''; }
    public function get_short_description() { return $GLOBALS['fh_posts'][ $this->id ]['post_excerpt'] ?? ''; }
    public function get_description() { return $GLOBALS['fh_posts'][ $this->id ]['post_content'] ?? ''; }
}
function wc_get_product( $id ) { return 'product' === get_post_type( $id ) ? new FH_Test_Product( $id ) : false; }

require_once dirname( __DIR__ ) . '/includes/class-selling-price.php';
require_once dirname( __DIR__ ) . '/includes/class-woocommerce.php';

class FH_Test_Batch {
    use FisHotel_WooCommerce;
    private $is_syncing = false;
}

// ---------------------------------------------------------------------------
// Part 1 — the pure rules.
// ---------------------------------------------------------------------------
echo "Pure rules\n";
$S = 'FisHotel_Selling_Price';
check( 'posted(): not in the form → keep (nothing written)', [ 'action' => 'keep' ] === $S::posted( [] ) );
check( 'posted(): other fields only → keep', [ 'action' => 'keep' ] === $S::posted( [ '_regular_price' => '12' ] ) );
check( 'posted(): a number → write it', [ 'action' => 'write', 'value' => '18.50' ] === $S::posted( [ '_fishotel_selling_price' => ' 18.50 ' ] ) );
check( 'posted(): blank → delete', [ 'action' => 'delete' ] === $S::posted( [ '_fishotel_selling_price' => '' ] ) );
check( 'posted(): spaces → delete', [ 'action' => 'delete' ] === $S::posted( [ '_fishotel_selling_price' => '   ' ] ) );
check( 'posted(): not a number → keep', [ 'action' => 'keep' ] === $S::posted( [ '_fishotel_selling_price' => 'twelve' ] ) );
check( 'posted(): a negative → keep', [ 'action' => 'keep' ] === $S::posted( [ '_fishotel_selling_price' => '-3' ] ) );
check( 'posted(): an array → keep', [ 'action' => 'keep' ] === $S::posted( [ '_fishotel_selling_price' => [ '3' ] ] ) );
check( 'posted(): "0" is a price → write', [ 'action' => 'write', 'value' => '0' ] === $S::posted( [ '_fishotel_selling_price' => '0' ] ) );

foreach ( [ '12', '12.5', '12.50', '.5', '0', '12.', 12, 12.5 ] as $v ) {
    check( 'is_price(' . var_export( $v, true ) . ')', $S::is_price( $v ) );
}
foreach ( [ '', ' ', 'abc', '1,200', '-1', '12.5.1', null, false, -2 ] as $v ) {
    check( 'not is_price(' . var_export( $v, true ) . ')', ! $S::is_price( $v ) );
}
check( 'same_price: "12" = "12.00" = 12.0', $S::same_price( '12', '12.00' ) && $S::same_price( 12.0, '12' ) );
check( 'same_price: 12 ≠ 12.01', ! $S::same_price( '12', '12.01' ) );
check( 'same_price: blank is never the same', ! $S::same_price( '', '' ) && ! $S::same_price( '', '12' ) );

check( 'follow: a copy follows the new Regular price', '15' === $S::follow( '12', '12', '15' ) );
check( 'follow: "12.00" copy of "12" follows', '15.50' === $S::follow( '12.00', '12', '15.50' ) );
check( 'follow: no Selling Price → nothing written', null === $S::follow( '', '12', '15' ) );
check( 'follow: set apart (differed) → follows too, once Regular price changes', '15' === $S::follow( '20', '12', '15' ) );
check( 'follow: set apart, Regular price unchanged → kept', null === $S::follow( '20', '12', '12' ) );
check( 'keep_copy: a value that differed is kept before it is replaced', $S::keep_copy( '20', '12', '15' ) );
check( 'keep_copy: a copy of the old Regular price needs no copy', ! $S::keep_copy( '12', '12.00', '15' ) );
check( 'keep_copy: nothing replaced → nothing kept', ! $S::keep_copy( '20', '12', null ) );
check( 'keep_copy: no Selling Price → nothing kept', ! $S::keep_copy( '', '12', '15' ) );
check( 'note: a Selling Price apart from Regular price is shown', false !== strpos( $S::note( '20', '12' ), '20.00' ) );
check( 'note: same as Regular price → nothing to say', '' === $S::note( '12.00', '12' ) );
check( 'note: no Selling Price → nothing to say', '' === $S::note( '', '12' ) );
check( 'follow: Regular price unchanged → nothing written', null === $S::follow( '12', '12', '12.00' ) );
check( 'follow: Regular price emptied → never a blank', null === $S::follow( '12', '12', '' ) );
check( 'follow: no old Regular price, one typed now → follows it', '15' === $S::follow( '12', '', '15' ) );
check( 'follow: variable fish (no Regular price before or after) → nothing written', null === $S::follow( '12', '', '' ) );

check( 'master_price: Selling Price when set (as 10.36)', '20' === $S::master_price( '20', '15' ) );
check( 'master_price: product price when blank (as 10.36)', '15' === $S::master_price( '', '15' ) );
check( 'master_price: "0" falls back too (the old "?:")', '15' === $S::master_price( '0', '15' ) );
check( 'needs_master_update: different → yes', $S::needs_master_update( '12', '15' ) );
check( 'needs_master_update: same to the cent → no', ! $S::needs_master_update( '15', '15.00' ) );
check( 'needs_master_update: nothing stored → yes', $S::needs_master_update( '', '15' ) );
check( 'needs_master_update: blank price → never', ! $S::needs_master_update( '12', '' ) );

// ---------------------------------------------------------------------------
// Part 2 — the hooks, through a stand-in save.
// ---------------------------------------------------------------------------
echo "\nHooks\n";
$plugin = new FH_Test_Batch();
// Exactly what fishotel-batch-manager.php registers (Part 3 checks the file says so).
add_action( 'save_post_product', [ $plugin, 'remember_prices_before_save' ], 5 );
add_action( 'woocommerce_process_product_meta', [ $plugin, 'save_fishotel_price_field' ], 20 );
add_action( 'woocommerce_product_quick_edit_save', [ $plugin, 'selling_price_after_list_edit' ] );
add_action( 'woocommerce_product_bulk_edit_save', [ $plugin, 'selling_price_after_list_edit' ] );
add_action( 'woocommerce_product_options_pricing', [ $plugin, 'selling_price_note' ] );
add_action( 'wp_after_insert_post', [ $plugin, 'sync_wc_to_master' ], 10, 3 );
add_action( 'updated_post_meta', [ $plugin, 'sync_price_master_to_woo' ], 10, 4 );
add_action( 'updated_post_meta', [ $plugin, 'sync_price_woo_to_master' ], 10, 4 );

// WooCommerce's own product-data save (WC_Meta_Box_Product_Data::save, priority 10).
add_action( 'woocommerce_process_product_meta', function ( $id ) {
    if ( isset( $_POST['_regular_price'] ) ) {
        $regular = (string) $_POST['_regular_price'];
        $sale    = (string) ( $_POST['_sale_price'] ?? '' );
        update_post_meta( $id, '_regular_price', $regular );
        update_post_meta( $id, '_sale_price', $sale );
        update_post_meta( $id, '_price', '' !== $sale ? $sale : $regular );
    }
}, 10 );

/** The product screen's Update button. */
function fh_screen_save( $id, array $post ) {
    $_POST = $post;
    $GLOBALS['fh_writes'] = [];
    do_action( 'save_post_product', $id, get_post( $id ), true );
    do_action( 'woocommerce_process_product_meta', $id, get_post( $id ) ); // from save_post, priority 1
    do_action( 'wp_after_insert_post', $id, get_post( $id ), true ); // wp_insert_post's last word
    $_POST = [];
}
/** Quick edit (or bulk edit) in the products list. */
function fh_list_save( $id, $regular, $hook = 'woocommerce_product_quick_edit_save' ) {
    $_POST = [ '_regular_price' => $regular ];
    $GLOBALS['fh_writes'] = [];
    do_action( 'save_post_product', $id, get_post( $id ), true );
    update_post_meta( $id, '_regular_price', $regular ); // WooCommerce's quick/bulk edit save (save_post, 10)
    update_post_meta( $id, '_price', $regular );
    do_action( $hook, new FH_Test_Product( $id ) );
    do_action( 'wp_after_insert_post', $id, get_post( $id ), true );
    $_POST = [];
}
function fh_product( $id, $cats, $meta, $title = 'Yellow Tang', $excerpt = 'Zebrasoma flavescens' ) {
    $GLOBALS['fh_types'][ $id ] = 'product';
    $GLOBALS['fh_terms'][ $id ] = $cats;
    $GLOBALS['fh_posts'][ $id ] = [ 'post_title' => $title, 'post_excerpt' => $excerpt ];
    foreach ( $meta as $k => $v ) $GLOBALS['fh_meta'][ $id ][ $k ] = $v;
}
function fh_master( $id, $product_id, $price, $sci = 'Zebrasoma Flavescens' ) {
    $GLOBALS['fh_types'][ $id ] = 'fish_master';
    $GLOBALS['fh_meta'][ $id ] = [ '_scientific_name' => $sci, '_selling_price' => $price, '_wc_product_id' => (string) $product_id ];
    $GLOBALS['fh_meta'][ $product_id ]['_linked_fish_master'] = (string) $id;
}
function fh_m( $id, $key ) { return $GLOBALS['fh_meta'][ $id ][ $key ] ?? null; }
function fh_wrote( $id, $key ) {
    foreach ( $GLOBALS['fh_writes'] as $w ) if ( 0 === strpos( $w, "{$id}:{$key}" ) ) return true;
    return false;
}

// A. A dry good: nothing about the Selling Price is written.
fh_reset();
fh_product( 10, [ 'dry-goods' ], [ '_regular_price' => '8.99', '_price' => '8.99' ], 'Fish net', '' );
fh_screen_save( 10, [ '_regular_price' => '9.49' ] );
check( 'dry good: no Selling Price written (not even a blank)', ! fh_wrote( 10, '_fishotel_selling_price' ) && null === fh_m( 10, '_fishotel_selling_price' ), implode( ', ', $GLOBALS['fh_writes'] ) );
check( 'dry good: its price saved by WooCommerce', '9.49' === fh_m( 10, '_regular_price' ) );

// B. A simple fish with no Selling Price, linked to a Master Fish: price 12 → 15.
fh_reset();
fh_product( 20, [ 'quarantined-fish' ], [ '_regular_price' => '12', '_price' => '12' ] );
fh_master( 21, 20, '12' );
fh_screen_save( 20, [ '_regular_price' => '15' ] );
check( 'simple fish: no Selling Price written', ! fh_wrote( 20, '_fishotel_selling_price' ) && null === fh_m( 20, '_fishotel_selling_price' ), implode( ', ', $GLOBALS['fh_writes'] ) );
check( 'simple fish: Master Fish price = new Regular price after ONE save', '15' === fh_m( 21, '_selling_price' ), (string) fh_m( 21, '_selling_price' ) );
check( 'simple fish: Regular price is what was typed (not echoed back from the Master)', '15' === fh_m( 20, '_regular_price' ) && '15' === fh_m( 20, '_price' ) );
fh_screen_save( 20, [ '_regular_price' => '15' ] );
check( 'simple fish: a second save changes nothing on the product', ! fh_wrote( 20, '_fishotel_selling_price' ) && '15' === fh_m( 20, '_regular_price' ) && '15' === fh_m( 21, '_selling_price' ), implode( ', ', $GLOBALS['fh_writes'] ) );

// C. A fish made from the Master Fish Library (Selling = Regular = 12): price 12 → 15.
fh_reset();
fh_product( 30, [ 'quarantined-fish' ], [ '_regular_price' => '12', '_price' => '12', '_fishotel_selling_price' => '12' ] );
fh_master( 31, 30, '12' );
fh_screen_save( 30, [ '_regular_price' => '15' ] );
check( 'copy: Selling Price follows the new Regular price', '15' === fh_m( 30, '_fishotel_selling_price' ) );
check( 'copy: Master Fish price = new Regular price', '15' === fh_m( 31, '_selling_price' ), (string) fh_m( 31, '_selling_price' ) );
check( 'copy: Regular price is what was typed', '15' === fh_m( 30, '_regular_price' ) );
fh_screen_save( 30, [ '_regular_price' => '15.00' ] );
check( 'copy: "15.00" for "15" is not a change of Selling Price', '15' === fh_m( 30, '_fishotel_selling_price' ) && ! fh_wrote( 30, '_fishotel_selling_price' ) );

// D. A Selling Price set apart before 10.37 (20 while Regular is 12). Nothing on the
//    screen can change it any more, so it must not freeze the public list.
fh_reset();
fh_product( 40, [ 'quarantined-fish' ], [ '_regular_price' => '12', '_price' => '12', '_fishotel_selling_price' => '20' ] );
fh_master( 41, 40, '20' );
fh_screen_save( 40, [ '_regular_price' => '12' ] );
check( 'set apart, Regular price unchanged: Selling Price kept', '20' === fh_m( 40, '_fishotel_selling_price' ) && ! fh_wrote( 40, '_fishotel_selling_price' ) );
check( 'set apart, Regular price unchanged: Master Fish price stays 20 (as 10.36)', '20' === fh_m( 41, '_selling_price' ) );
$GLOBALS['post'] = get_post( 40 );
ob_start(); do_action( 'woocommerce_product_options_pricing' ); $note = ob_get_clean();
check( 'set apart: the price box says the public list shows 20.00', false !== strpos( $note, '20.00' ) && false !== strpos( $note, 'fishotel-selling-price-note' ), $note );
fh_screen_save( 40, [ '_regular_price' => '15' ] );
check( 'set apart, Regular price 12 → 15: Selling Price follows (the public list is not frozen)', '15' === fh_m( 40, '_fishotel_selling_price' ) );
check( 'set apart, Regular price 12 → 15: Master Fish price 15 after ONE save', '15' === fh_m( 41, '_selling_price' ), (string) fh_m( 41, '_selling_price' ) );
check( 'set apart: the old 20 is kept as a copy (nothing lost)', [ '20' ] === ( $GLOBALS['fh_added'][40]['_fishotel_selling_price_replaced'] ?? null ) );
check( 'set apart: Regular price is what was typed (not overwritten by the Selling Price)', '15' === fh_m( 40, '_regular_price' ) && '15' === fh_m( 40, '_price' ) );
ob_start(); do_action( 'woocommerce_product_options_pricing' ); $note = ob_get_clean();
check( 'set apart: once followed, the price box says nothing', '' === $note, $note );
fh_screen_save( 40, [ '_regular_price' => '16' ] );
check( 'set apart: the next change follows with no second copy', '16' === fh_m( 40, '_fishotel_selling_price' ) && 1 === count( $GLOBALS['fh_added'][40]['_fishotel_selling_price_replaced'] ) );
fh_list_save( 40, '18' );
check( 'quick edit on a value set apart: follows too', '18' === fh_m( 40, '_fishotel_selling_price' ) && '18' === fh_m( 41, '_selling_price' ) );
unset( $GLOBALS['post'] );
ob_start(); do_action( 'woocommerce_product_options_pricing' ); $note = ob_get_clean();
check( 'price box note: no product in hand → nothing printed', '' === $note );

// E. The field posted (nothing draws it now, but a form that posts it is obeyed).
fh_reset();
fh_product( 50, [ 'quarantined-fish' ], [ '_regular_price' => '12', '_price' => '12', '_fishotel_selling_price' => '12' ] );
fh_master( 51, 50, '12' );
fh_screen_save( 50, [ '_regular_price' => '12', '_fishotel_selling_price' => '18' ] );
check( 'posted 18: written', '18' === fh_m( 50, '_fishotel_selling_price' ) );
check( 'posted 18: Master Fish price 18', '18' === fh_m( 51, '_selling_price' ) );
fh_screen_save( 50, [ '_regular_price' => '12', '_fishotel_selling_price' => '' ] );
check( 'posted blank: Selling Price removed (not stored as a blank)', null === fh_m( 50, '_fishotel_selling_price' ) && fh_wrote( 50, '_fishotel_selling_price' ) );
check( 'posted blank: Master Fish price falls back to the product price (never blank)', '12' === fh_m( 51, '_selling_price' ), (string) fh_m( 51, '_selling_price' ) );
fh_screen_save( 50, [ '_regular_price' => '12', '_fishotel_selling_price' => 'abc' ] );
check( 'posted junk: nothing written', null === fh_m( 50, '_fishotel_selling_price' ) && ! fh_wrote( 50, '_fishotel_selling_price' ) );
fh_screen_save( 50, [ '_regular_price' => '12', '_fishotel_selling_price' => 'x\\\'<b>9</b>' ] );
check( 'posted with slashes/tags: cleaned, then ignored as not a number', null === fh_m( 50, '_fishotel_selling_price' ) );

// F. On sale, no Selling Price: Regular price is never overwritten by the sale price.
fh_reset();
fh_product( 60, [ 'quarantined-fish' ], [ '_regular_price' => '20', '_sale_price' => '15', '_price' => '15' ] );
fh_master( 61, 60, '20' );
fh_screen_save( 60, [ '_regular_price' => '20', '_sale_price' => '15' ] );
check( 'on sale: Regular price stays 20', '20' === fh_m( 60, '_regular_price' ) );
check( 'on sale: no Selling Price written', null === fh_m( 60, '_fishotel_selling_price' ) && ! fh_wrote( 60, '_fishotel_selling_price' ) );
check( 'on sale: Master Fish price = the product price (sale price, as 10.36\'s fallback)', '15' === fh_m( 61, '_selling_price' ) );

// G. Quick edit and bulk edit in the products list.
fh_reset();
fh_product( 70, [ 'quarantined-fish' ], [ '_regular_price' => '12', '_price' => '12', '_fishotel_selling_price' => '12' ] );
fh_master( 71, 70, '12' );
fh_list_save( 70, '14' );
check( 'quick edit: a copy follows', '14' === fh_m( 70, '_fishotel_selling_price' ) );
check( 'quick edit: Master Fish price 14', '14' === fh_m( 71, '_selling_price' ) );
fh_product( 72, [ 'quarantined-fish' ], [ '_regular_price' => '9', '_price' => '9' ], 'Flame Angel', 'Centropyge loricula' );
fh_master( 73, 72, '9', 'Centropyge Loricula' );
fh_list_save( 72, '11', 'woocommerce_product_bulk_edit_save' );
check( 'bulk edit: no Selling Price → none written', null === fh_m( 72, '_fishotel_selling_price' ) );
check( 'bulk edit: Master Fish price 11', '11' === fh_m( 73, '_selling_price' ), (string) fh_m( 73, '_selling_price' ) );
$plugin->selling_price_after_list_edit( 'not a product' );

// H. The Master Fish's own price box still pushes to the product (unchanged).
fh_reset();
fh_product( 80, [ 'quarantined-fish' ], [ '_regular_price' => '12', '_price' => '12', '_fishotel_selling_price' => '12' ] );
fh_master( 81, 80, '12' );
update_post_meta( 81, '_selling_price', 16.0 );
check( 'Master → product: Selling, Regular and price all 16', '16' === fh_m( 80, '_fishotel_selling_price' ) && '16' === fh_m( 80, '_regular_price' ) && '16' === fh_m( 80, '_price' ) );
fh_screen_save( 80, [ '_regular_price' => '17' ] );
check( 'Master → product, then the product screen: the copy follows again', '17' === fh_m( 80, '_fishotel_selling_price' ) && '17' === fh_m( 81, '_selling_price' ) );

// I. Regular price emptied: never a blank Selling Price, never a blank Master price.
fh_reset();
fh_product( 90, [ 'quarantined-fish' ], [ '_regular_price' => '12', '_price' => '12', '_fishotel_selling_price' => '12' ] );
fh_master( 91, 90, '12' );
fh_screen_save( 90, [ '_regular_price' => '' ] );
check( 'Regular emptied: Selling Price kept', '12' === fh_m( 90, '_fishotel_selling_price' ) && ! fh_wrote( 90, '_fishotel_selling_price' ) );
check( 'Regular emptied: Master Fish price kept', '12' === fh_m( 91, '_selling_price' ) );

// J. A fish no longer in Quarantined Fish: its Master Fish is left alone (as sync_wc_to_master does).
fh_reset();
fh_product( 100, [ 'graduated' ], [ '_regular_price' => '12', '_price' => '12' ] );
fh_master( 101, 100, '12' );
fh_screen_save( 100, [ '_regular_price' => '15' ] );
check( 'other category: Master Fish price untouched', '12' === fh_m( 101, '_selling_price' ) );

// K. A new fish with no Master yet: sync_wc_to_master makes one; no Selling Price written.
fh_reset();
fh_product( 110, [ 'quarantined-fish' ], [ '_regular_price' => '30', '_price' => '30' ], 'flame angel', 'centropyge loricula' );
fh_screen_save( 110, [ '_regular_price' => '30' ] );
$mid = (int) fh_m( 110, '_linked_fish_master' );
check( 'new fish: a Master Fish is made and linked', $mid > 0 && 'fish_master' === get_post_type( $mid ) );
check( 'new fish: Master price 30', '30' === fh_m( $mid, '_selling_price' ) );
check( 'new fish: no Selling Price written', null === fh_m( 110, '_fishotel_selling_price' ) );

// L. Another plugin's box writes the scientific name into the short description
//    during save_post (FisHotel Misc's Fish essentials: woocommerce_process_product_meta
//    50, through $product->save(), which does not run save_post again). The Master
//    Fish must get the new name on THAT save, not one save late.
fh_reset();
add_action( 'woocommerce_process_product_meta', function ( $id ) {
    if ( isset( $_POST['fh_test_sci'] ) ) {
        $GLOBALS['fh_posts'][ $id ]['post_excerpt'] = (string) $_POST['fh_test_sci'];
    }
}, 50 );
fh_product( 120, [ 'quarantined-fish' ], [ '_regular_price' => '40', '_price' => '40' ], 'Firefish', 'Nemateleotris fictiva' );
fh_master( 121, 120, '40', 'Nemateleotris Fictiva' );
fh_screen_save( 120, [ '_regular_price' => '40', 'fh_test_sci' => 'Nemateleotris reviewii' ] );
$mid = (int) fh_m( 120, '_linked_fish_master' );
check( 'rename: linked to a Master Fish under the NEW name after one save', 'Nemateleotris Reviewii' === fh_m( $mid, '_scientific_name' ), (string) fh_m( $mid, '_scientific_name' ) );
check( 'rename: the old Master Fish is not refiled under the old name again', 121 !== $mid && 'Nemateleotris Fictiva' === fh_m( 121, '_scientific_name' ) );
$masters = count( array_keys( $GLOBALS['fh_types'], 'fish_master', true ) );
fh_screen_save( 120, [ '_regular_price' => '40', 'fh_test_sci' => 'Nemateleotris reviewii' ] );
check( 'rename: the next save makes no further Master Fish', $masters === count( array_keys( $GLOBALS['fh_types'], 'fish_master', true ) ) && $mid === (int) fh_m( 120, '_linked_fish_master' ) );
check( 'rename: new Master Fish price 40', '40' === fh_m( $mid, '_selling_price' ) );
$plugin->sync_wc_to_master( 120, null, true ); // a caller with no post in hand: nothing happens, no error

// ---------------------------------------------------------------------------
// Part 3 — the source.
// ---------------------------------------------------------------------------
echo "\nSource\n";
$main = file_get_contents( dirname( __DIR__ ) . '/fishotel-batch-manager.php' );
$woo  = file_get_contents( dirname( __DIR__ ) . '/includes/class-woocommerce.php' );
check( 'no box on the product screen: no add_fishotel_price_field hook or method', false === strpos( $main . $woo, 'add_fishotel_price_field' ) );
check( 'the field label is gone from the product screen code', false === strpos( $woo, "'label'       => 'FisHotel Selling Price'" ) );
check( 'the unconditional blank write is gone', false === strpos( $woo, "isset( \$_POST['_fishotel_selling_price'] ) ? wc_clean( \$_POST['_fishotel_selling_price'] ) : ''" ) );
check( 'main file loads the rules', false !== strpos( $main, "require_once FISHOTEL_PLUGIN_DIR . 'includes/class-selling-price.php';" ) );
$want = [
    "add_action( 'save_post_product', [\$this, 'remember_prices_before_save'], 5 );",
    "add_action( 'woocommerce_process_product_meta', [\$this, 'save_fishotel_price_field'], 20 );",
    "add_action( 'woocommerce_product_quick_edit_save', [\$this, 'selling_price_after_list_edit'] );",
    "add_action( 'woocommerce_product_bulk_edit_save', [\$this, 'selling_price_after_list_edit'] );",
    "add_action( 'wp_after_insert_post', [\$this, 'sync_wc_to_master'], 10, 3 );",
    "add_action( 'woocommerce_product_options_pricing', [\$this, 'selling_price_note'] );",
];
foreach ( $want as $line ) {
    check( 'main file registers: ' . $line, false !== strpos( $main, $line ) );
}
check( 'sync_wc_to_master is no longer on save_post_product (ahead of every box)', false === strpos( $main, "add_action( 'save_post_product', [\$this, 'sync_wc_to_master']" ) );

echo "\n{$passes} passed, {$failures} failed.\n";
exit( $failures > 0 ? 1 : 0 );
