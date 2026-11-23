<?php
/**
 * Ship Day → "Print the day's paperwork" (10.35): the plan the browser turns
 * into BROTHER_shipday_<date>.pdf and RX106HD_shipday_<date>.pdf.
 *
 *   php tests/ship-day-paperwork-test.php
 *
 * Plain PHP, no PHPUnit, no WordPress: the WordPress / WooCommerce / invoice
 * plugin functions the code calls are stood in for below, and the admin +
 * AJAX traits run on a small host class. Exits 1 on any failure, 0 when
 * everything passes. The browser half (merging, drawing) is checked by
 * tests/ship-day-paperwork-js-test.js under node.
 *
 * CLI only: the plugin zip ships this directory.
 */

if ( 'cli' !== PHP_SAPI ) {
    exit;
}

if ( defined( 'WPINC' ) ) {
    echo "Runs without WordPress only (it stands in for WordPress and the invoice plugin).\n";
    exit( 0 );
}

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

/* ── WordPress stand-ins ─────────────────────────────────────────── */

$GLOBALS['fh_filters']  = [];
$GLOBALS['fh_options']  = [];
$GLOBALS['fh_can']      = true;
$GLOBALS['fh_scripts']  = [];
$GLOBALS['fh_writes']   = 0; // any order write would bump this

function apply_filters( $tag, $value, ...$args ) {
    foreach ( $GLOBALS['fh_filters'][ $tag ] ?? [] as $cb ) {
        $value = call_user_func( $cb, $value, ...$args );
    }
    return $value;
}
function add_filter( $tag, $cb, $priority = 10, $args = 1 ) {
    $GLOBALS['fh_filters'][ $tag ][] = $cb;
    return true;
}
function remove_filter( $tag, $cb, $priority = 10 ) {
    return true;
}
function add_action( $tag, $cb, $priority = 10, $args = 1 ) {
    return true;
}
function wp_timezone() {
    return new DateTimeZone( 'America/Chicago' );
}
function esc_html( $s ) {
    return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $s ) {
    return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
/** Like WordPress: & becomes &#038; (the invoice plugin's link comes back this way). */
function esc_url( $s ) {
    return str_replace( '&', '&#038;', (string) $s );
}
function esc_js( $s ) {
    return addslashes( (string) $s );
}
function admin_url( $path = '' ) {
    return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}
function add_query_arg( $key, $value, $url ) {
    return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . rawurlencode( $key ) . '=' . rawurlencode( $value );
}
function is_wp_error( $thing ) {
    return $thing instanceof WP_Error;
}
class WP_Error {
    private $message;
    public function __construct( $code = '', $message = '' ) {
        $this->message = $message;
    }
    public function get_error_message() {
        return $this->message;
    }
}
function current_user_can( $cap ) {
    return (bool) $GLOBALS['fh_can'];
}
function get_option( $key, $default = false ) {
    return array_key_exists( $key, $GLOBALS['fh_options'] ) ? $GLOBALS['fh_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
    $GLOBALS['fh_options'][ $key ]          = $value;
    $GLOBALS['fh_option_autoload'][ $key ] = $autoload;
    return true;
}
function wp_create_nonce( $action ) {
    return 'nonce-' . $action;
}
function wp_verify_nonce( $nonce, $action ) {
    return 'nonce-' . $action === $nonce ? 1 : false;
}
function wp_nonce_field( $action, $name = '_wpnonce', $referer = true, $echo = true ) {
    return '<input type="hidden" name="' . $name . '" value="nonce-' . $action . '">';
}
class FH_Die extends Exception {}
function check_admin_referer( $action ) {
    if ( ( $_POST['_wpnonce'] ?? '' ) !== 'nonce-' . $action ) {
        throw new FH_Die( 'The link you followed has expired.' );
    }
    return 1;
}
function sanitize_key( $s ) {
    return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) );
}
function sanitize_text_field( $s ) {
    return trim( strip_tags( (string) $s ) );
}
function wp_unslash( $s ) {
    return $s;
}
function wp_die( $msg = '' ) {
    throw new FH_Die( (string) $msg );
}
class FH_Redirect extends Exception {
    public $to;
    public function __construct( $to ) {
        parent::__construct( 'redirect' );
        $this->to = $to;
    }
}
function wp_safe_redirect( $to ) {
    throw new FH_Redirect( $to );
}
class FH_Json extends Exception {
    public $ok;
    public $data;
    public function __construct( $ok, $data ) {
        parent::__construct( 'json' );
        $this->ok   = $ok;
        $this->data = $data;
    }
}
function wp_send_json_success( $data = null ) {
    throw new FH_Json( true, $data );
}
function wp_send_json_error( $data = null ) {
    throw new FH_Json( false, $data );
}
function menu_page_url( $slug, $echo = true ) {
    return '';
}
function plugins_url( $path, $file ) {
    return 'https://example.test/wp-content/plugins/fishotel-batch-manager/' . $path;
}
function wp_enqueue_script( $handle, $src = '', $deps = [], $ver = false, $footer = false ) {
    $GLOBALS['fh_scripts'][ $handle ] = [ 'src' => $src, 'deps' => $deps, 'ver' => $ver, 'footer' => $footer ];
}
define( 'FISHOTEL_PLUGIN_FILE', dirname( __DIR__ ) . '/fishotel-batch-manager.php' );
define( 'FISHOTEL_VERSION', '10.36' );

/* Media Library: 501 and 502 are PDFs, 503 an image, 504 a PDF whose file is gone. */
$GLOBALS['fh_media'] = [
    501 => [ 'mime' => 'application/pdf', 'file' => __FILE__, 'title' => 'Acclimation sheet' ],
    502 => [ 'mime' => 'application/pdf', 'file' => __FILE__, 'title' => 'Shipping labels' ],
    503 => [ 'mime' => 'image/jpeg', 'file' => __FILE__, 'title' => 'A picture' ],
    504 => [ 'mime' => 'application/pdf', 'file' => '/nowhere/gone.pdf', 'title' => 'Gone' ],
];
function get_post_mime_type( $id ) {
    return $GLOBALS['fh_media'][ (int) $id ]['mime'] ?? false;
}
function get_attached_file( $id ) {
    return $GLOBALS['fh_media'][ (int) $id ]['file'] ?? false;
}
function wp_get_attachment_url( $id ) {
    return isset( $GLOBALS['fh_media'][ (int) $id ] ) ? 'https://example.test/wp-content/uploads/file-' . (int) $id . '.pdf' : false;
}
function get_the_title( $id ) {
    return $GLOBALS['fh_media'][ (int) $id ]['title'] ?? '';
}
function wp_get_attachment_image_url( $id, $size ) {
    return 'https://example.test/wp-content/uploads/img-' . (int) $id . '-' . $size . '.jpg';
}
function get_posts( $args ) {
    $out = [];
    foreach ( $GLOBALS['fh_media'] as $id => $m ) {
        if ( $m['mime'] === ( $args['post_mime_type'] ?? '' ) ) {
            $out[] = (object) [ 'ID' => $id, 'post_title' => $m['title'] ];
        }
    }
    return $out;
}

/* ── Orders, products ─────────────────────────────────────────────── */

class FH_P_Product {
    public $id;
    public $name;
    public $desc;
    public function __construct( $id, $name, $desc = '' ) {
        $this->id   = $id;
        $this->name = $name;
        $this->desc = $desc;
    }
    public function get_id() {
        return $this->id;
    }
    public function get_parent_id() {
        return 0;
    }
    public function get_name() {
        return $this->name;
    }
    public function get_description() {
        return $this->desc;
    }
    public function get_price() {
        return '125';
    }
    public function get_image_id() {
        return 900 + $this->id;
    }
}
class FH_P_Item {
    public $id;
    public $name;
    public $qty;
    public $product;
    public $meta;
    public function __construct( $id, $name, $qty, $product, $meta = [] ) {
        $this->id      = $id;
        $this->name    = $name;
        $this->qty     = $qty;
        $this->product = $product;
        $this->meta    = $meta;
    }
    public function get_id() {
        return $this->id;
    }
    public function get_name() {
        return $this->name;
    }
    public function get_quantity() {
        return $this->qty;
    }
    public function get_product() {
        return $this->product;
    }
    public function get_product_id() {
        return $this->product ? $this->product->get_id() : 0;
    }
    public function get_variation_id() {
        return 0;
    }
    public function get_meta( $key ) {
        return $this->meta[ $key ] ?? '';
    }
}
class FH_P_Order {
    public $id;
    public $status;
    public $meta;
    public $items;
    public function __construct( $id, $status, $meta, $items ) {
        $this->id     = $id;
        $this->status = $status;
        $this->meta   = $meta;
        $this->items  = $items;
    }
    public function get_id() {
        return $this->id;
    }
    public function get_order_number() {
        return (string) $this->id;
    }
    public function get_status() {
        return $this->status;
    }
    public function has_status( $s ) {
        return in_array( $this->status, (array) $s, true );
    }
    public function get_meta( $key ) {
        return $this->meta[ $key ] ?? '';
    }
    public function update_meta_data( $k, $v ) {
        $GLOBALS['fh_writes']++;
    }
    public function save() {
        $GLOBALS['fh_writes']++;
    }
    public function add_order_note( $n ) {
        $GLOBALS['fh_writes']++;
    }
    public function get_items( $types = 'line_item' ) {
        return $this->items;
    }
    public function get_qty_refunded_for_item( $id ) {
        return 0;
    }
    public function get_customer_note() {
        return '';
    }
    public function get_edit_order_url() {
        return 'https://example.test/wp-admin/post.php?post=' . $this->id;
    }
    public function get_order_key() {
        return 'wc_order_' . $this->id;
    }
    public function __call( $name, $args ) {
        $map = [ 'get_shipping_first_name' => 'Pat', 'get_shipping_last_name' => 'Reefer' . $this->id, 'get_shipping_city' => 'Austin', 'get_shipping_state' => 'TX', 'get_shipping_postcode' => '78701' ];
        return $map[ $name ] ?? '';
    }
}
$GLOBALS['fh_orders']   = [];
$GLOBALS['fh_products'] = [];
function wc_get_orders( $args ) {
    $out = [];
    foreach ( $GLOBALS['fh_orders'] as $o ) {
        if ( in_array( $o->status, (array) $args['status'], true ) ) {
            $out[] = $o;
        }
    }
    return $out;
}
function wc_get_order( $id ) {
    return $GLOBALS['fh_orders'][ (int) $id ] ?? false;
}
function wc_get_product( $id ) {
    return $GLOBALS['fh_products'][ (int) $id ] ?? false;
}
function wc_price( $amount ) {
    return '<span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">&#36;</span>' . number_format( (float) $amount, 2 ) . '</bdi></span>';
}
function get_the_terms( $pid, $tax ) {
    return 10 === (int) $pid ? [ (object) [ 'slug' => 'quarantined-fish' ] ] : ( 70 <= (int) $pid ? [ (object) [ 'slug' => 'invoices' ] ] : [ (object) [ 'slug' => 'medications' ] ] );
}

require_once dirname( __DIR__ ) . '/includes/class-ship-day.php';
require_once dirname( __DIR__ ) . '/includes/class-ship-day-paperwork.php';
require_once dirname( __DIR__ ) . '/includes/class-ship-day-today-line.php';
require_once dirname( __DIR__ ) . '/includes/class-ajax.php';
require_once dirname( __DIR__ ) . '/includes/class-admin.php';

/** The plugin class, as far as this page goes. */
class FH_Paper_Host {
    use FisHotel_Ajax;
    use FisHotel_Admin;
}

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
function fh_json( callable $fn ) {
    try {
        $fn();
    } catch ( FH_Json $j ) {
        return $j;
    }
    return null;
}
function fh_capture( callable $fn ) {
    ob_start();
    try {
        $fn();
    } catch ( FH_Die $e ) {
        echo '[wp_die: ' . $e->getMessage() . ']';
    }
    return (string) ob_get_clean();
}

/* ── Pure helpers ─────────────────────────────────────────────────── */

section( 'settings: defaults, bounds, only PDFs from the Media Library' );
check( 'nothing saved: no files, 4 labels per box', [ 'acclimation_id' => 0, 'labels_id' => 0, 'label_copies' => 4 ] === FisHotel_Ship_Day_Paperwork::normalize_settings( false ) );
check( 'label count kept between 1 and 20', 1 === FisHotel_Ship_Day_Paperwork::normalize_settings( [ 'label_copies' => 0 ] )['label_copies'] && 20 === FisHotel_Ship_Day_Paperwork::normalize_settings( [ 'label_copies' => 99 ] )['label_copies'] && 6 === FisHotel_Ship_Day_Paperwork::normalize_settings( [ 'label_copies' => '6' ] )['label_copies'] && 4 === FisHotel_Ship_Day_Paperwork::normalize_settings( [ 'label_copies' => 'lots' ] )['label_copies'] );
$is_pdf = [ 'FisHotel_Ship_Day_Paperwork', 'is_pdf_attachment' ];
check( 'the form: a PDF attachment is kept, an image or a missing id is cleared', [ 'acclimation_id' => 501, 'labels_id' => 0, 'label_copies' => 3 ] === FisHotel_Ship_Day_Paperwork::settings_from_post( [ 'acclimation_id' => '501', 'labels_id' => '503', 'label_copies' => '3' ], $is_pdf ) && 0 === FisHotel_Ship_Day_Paperwork::settings_from_post( [ 'acclimation_id' => '777' ], $is_pdf )['acclimation_id'] );
check( 'the form: junk (arrays, negatives) never gets through', [ 'acclimation_id' => 0, 'labels_id' => 0, 'label_copies' => 4 ] === FisHotel_Ship_Day_Paperwork::settings_from_post( [ 'acclimation_id' => [ 501 ], 'labels_id' => '-502', 'label_copies' => [] ], $is_pdf ) );

section( 'file names' );
check( 'named by the ship date (delivery Fri 20th → ship Thu 19th)', [ 'brother' => 'BROTHER_shipday_2026-11-19.pdf', 'rx' => 'RX106HD_shipday_2026-11-19.pdf' ] === FisHotel_Ship_Day_Paperwork::file_names( '2026-11-20' ) );
check( 'across the DST change and month ends', 'BROTHER_shipday_2026-10-31.pdf' === FisHotel_Ship_Day_Paperwork::file_names( '2026-11-01' )['brother'] && 'RX106HD_shipday_2026-03-08.pdf' === FisHotel_Ship_Day_Paperwork::file_names( '2026-03-09' )['rx'] );
check( 'no date, no names', null === FisHotel_Ship_Day_Paperwork::file_names( '2026-02-30' ) && null === FisHotel_Ship_Day_Paperwork::file_names( '' ) );

section( '"Invoice" products: which get a product sheet' );
check( 'is_invoice_name(): "Invoice" anywhere in the name, any case — also inside a word, as the old routine matched', FisHotel_Ship_Day_Paperwork::is_invoice_name( 'Invoice #3 - Sam' ) && FisHotel_Ship_Day_Paperwork::is_invoice_name( 'Group order invoice' ) && FisHotel_Ship_Day_Paperwork::is_invoice_name( 'INVOICES' ) && FisHotel_Ship_Day_Paperwork::is_invoice_name( 'FishInvoice 12' ) && ! FisHotel_Ship_Day_Paperwork::is_invoice_name( 'Hectors Goby' ) && ! FisHotel_Ship_Day_Paperwork::is_invoice_name( 'Invo ice' ) && ! FisHotel_Ship_Day_Paperwork::is_invoice_name( [ 'Invoice' ] ) );
$lines = [
    [ 'name' => 'Hectors Goby', 'product_name' => 'Hectors Goby', 'product_id' => 10, 'qty' => 1, 'meta' => [] ],
    [ 'name' => 'Invoice - Sam', 'product_name' => 'Invoice - Sam', 'product_id' => 71, 'qty' => 1, 'meta' => [] ],
    [ 'name' => 'Custom bill', 'product_name' => 'Invoice #72', 'product_id' => 72, 'qty' => 1, 'meta' => [] ],
    [ 'name' => 'Yellow Tang', 'product_name' => '', 'product_id' => 0, 'qty' => 1, 'meta' => [ '_fh_invoice_fish' => '1', '_fh_invoice_product_id' => '73' ] ],
    [ 'name' => 'Mandarin', 'product_name' => '', 'product_id' => 0, 'qty' => 1, 'meta' => [ '_fh_invoice_fish' => 'yes', '_fh_invoice_product_id' => '73' ] ],
    [ 'name' => 'Invoice - Shipped', 'product_name' => 'Invoice - Shipped', 'product_id' => 74, 'qty' => 1, 'meta' => [ '_fishotel_line_status' => 'shipped' ] ],
    [ 'name' => 'Invoice - Never', 'product_name' => 'Invoice - Never', 'product_id' => 75, 'qty' => 1, 'meta' => [ '_fishotel_line_status' => 'na' ] ],
    [ 'name' => 'Invoice - Refunded', 'product_name' => 'Invoice - Refunded', 'product_id' => 76, 'qty' => 0, 'meta' => [] ],
    [ 'name' => 'Invoice - Sam', 'product_name' => 'Invoice - Sam', 'product_id' => 71, 'qty' => 1, 'meta' => [] ],
    [ 'name' => 'Invoice - deleted product', 'product_name' => '', 'product_id' => 0, 'qty' => 1, 'meta' => [] ],
];
check( 'by line name, by product name, by the itemised-invoice meta; once each, in line order', [ 71, 72, 73 ] === FisHotel_Ship_Day_Paperwork::invoice_product_ids( $lines ), json_encode( FisHotel_Ship_Day_Paperwork::invoice_product_ids( $lines ) ) );
check( 'no "Invoice" product → no product sheet', [] === FisHotel_Ship_Day_Paperwork::invoice_product_ids( [ $lines[0] ] ) );

section( 'product sheet lines and price' );
check( 'list items, entities decoded', [ 'Hectors Goby x1 — $39', 'Yellow Tang x2 — $90' ] === FisHotel_Ship_Day_Paperwork::sheet_lines( '<ul><li>Hectors Goby x1 &#8212; $39</li><li><strong>Yellow Tang</strong> x2 &mdash; $90</li></ul>' ) );
check( 'plain lines, the "Description" heading dropped, blanks skipped', [ 'Goby x1 — $39', 'Credit — -$10' ] === FisHotel_Ship_Day_Paperwork::sheet_lines( "<p>DESCRIPTION</p><p>Goby x1 — $39<br>\n<br>Credit — -$10</p>" ) );
check( 'empty description → no lines', [] === FisHotel_Ship_Day_Paperwork::sheet_lines( '  ' ) );
check( 'price_text(): wc_price() HTML → "$125.00"', '$125.00' === FisHotel_Ship_Day_Paperwork::price_text( wc_price( 125 ) ) );

section( 'which boxes get paperwork: Processing / Fulfillment, not held, something to box' );
$fish_row = [ 'kind' => 'fish', 'qty' => 1 ];
$dry_row  = [ 'kind' => 'dry', 'qty' => 2 ];
$ok_wx    = [ 'status' => 'checked', 'action' => 'heat_pack', 'at_line' => false ];
check( 'processing / fulfillment (with or without wc-) with a fish or a dry-goods row: printed', '' === FisHotel_Ship_Day_Paperwork::box_skip( 'processing', [ 'rows' => [ $fish_row ], 'weather' => $ok_wx ] ) && '' === FisHotel_Ship_Day_Paperwork::box_skip( 'wc-fulfillment', [ 'rows' => [ $dry_row ], 'weather' => [ 'status' => 'not_checked' ] ] ) );
check( 'on-hold (or any other status the pick list is filtered to show): held', 'held' === FisHotel_Ship_Day_Paperwork::box_skip( 'on-hold', [ 'rows' => [ $fish_row ], 'weather' => $ok_wx ] ) && 'held' === FisHotel_Ship_Day_Paperwork::box_skip( 'wc-pending', [ 'rows' => [ $fish_row ] ] ) && 'held' === FisHotel_Ship_Day_Paperwork::box_skip( '', [ 'rows' => [ $fish_row ] ] ) );
check( 'weather HOLD (cold or hot): held; a high exactly on a band line stays the owner\'s call and is printed', 'weather_hold' === FisHotel_Ship_Day_Paperwork::box_skip( 'processing', [ 'rows' => [ $fish_row ], 'weather' => [ 'status' => 'checked', 'action' => 'hold_cold', 'at_line' => false ] ] ) && 'weather_hold' === FisHotel_Ship_Day_Paperwork::box_skip( 'processing', [ 'rows' => [ $fish_row ], 'weather' => [ 'status' => 'checked', 'action' => 'hold_hot' ] ] ) && '' === FisHotel_Ship_Day_Paperwork::box_skip( 'processing', [ 'rows' => [ $fish_row ], 'weather' => [ 'status' => 'checked', 'action' => 'hold_cold', 'at_line' => true ] ] ) && '' === FisHotel_Ship_Day_Paperwork::box_skip( 'processing', [ 'rows' => [ $fish_row ], 'weather' => [ 'status' => 'not_checked', 'action' => 'hold_cold' ] ] ) );
check( 'no rows, only EverythingAquatic rows, or only zero-qty "check" rows: nothing to ship', 'nothing_to_ship' === FisHotel_Ship_Day_Paperwork::box_skip( 'processing', [ 'rows' => [] ] ) && 'nothing_to_ship' === FisHotel_Ship_Day_Paperwork::box_skip( 'processing', [ 'rows' => [ [ 'kind' => 'ea', 'qty' => 3 ] ] ] ) && 'nothing_to_ship' === FisHotel_Ship_Day_Paperwork::box_skip( 'processing', [ 'rows' => [ [ 'kind' => 'dry', 'qty' => 0 ] ] ] ) && '' === FisHotel_Ship_Day_Paperwork::box_skip( 'processing', [ 'rows' => [ [ 'kind' => 'ea', 'qty' => 3 ], $dry_row ] ] ) );
check( 'each reason has a plain warning', 'Order #9: held (on hold) — no paperwork for this box.' === FisHotel_Ship_Day_Paperwork::box_warning( 'held', 'Order #9', 'wc-on-hold' ) && 0 === strpos( FisHotel_Ship_Day_Paperwork::box_warning( 'weather_hold', 'Order #9' ), 'Order #9: held for weather (HOLD)' ) && 0 === strpos( FisHotel_Ship_Day_Paperwork::box_warning( 'nothing_to_ship', 'Order #9' ), 'Order #9: nothing left to box' ) );

/* ── The plan ─────────────────────────────────────────────────────── */

$goby  = new FH_P_Product( 10, 'Hectors Goby' );
$prazi = new FH_P_Product( 12, 'PraziPro' );
$eafood = new FH_P_Product( 13, 'Coral food (EverythingAquatic)' );
/** The theme's EverythingAquatic drop-ship test: product 13 only. */
function fishotel_is_ea_fulfilled_product( $pid, $vid = 0 ) {
    return 13 === (int) $pid;
}
$inv71 = new FH_P_Product( 71, 'Invoice - Sam', '<ul><li>Hectors Goby x1 &#8212; $39</li><li>Yellow Tang x1 &#8212; $86</li></ul>' );
$GLOBALS['fh_products'] = [ 10 => $goby, 12 => $prazi, 13 => $eafood, 71 => $inv71 ];
$day = [ '_fishotel_shipping_date' => '2026-11-20' ];
$GLOBALS['fh_orders'] = [
    // pick list order is order ID: 101, 102 (on hold), 103, 104 (wrapper), 105 (dead wrapper),
    // 107 (all shipped), 108 (weather HOLD), 109 (EverythingAquatic only)
    101 => new FH_P_Order( 101, 'processing', $day, [ 1 => new FH_P_Item( 1, 'Hectors Goby', 2, $goby ) ] ),
    102 => new FH_P_Order( 102, 'on-hold', $day, [ 1 => new FH_P_Item( 1, 'Invoice - Sam', 1, $inv71 ), 2 => new FH_P_Item( 2, 'PraziPro', 1, $prazi ) ] ),
    103 => new FH_P_Order( 103, 'processing', $day, [ 1 => new FH_P_Item( 1, 'Invoice - Sam', 1, $inv71 ), 2 => new FH_P_Item( 2, 'PraziPro', 1, $prazi ) ] ),
    107 => new FH_P_Order( 107, 'processing', $day, [ 1 => new FH_P_Item( 1, 'Hectors Goby', 1, $goby, [ '_fishotel_line_status' => 'shipped' ] ), 2 => new FH_P_Item( 2, 'Invoice - Sam', 1, $inv71, [ '_fishotel_line_status' => 'shipped' ] ) ] ),
    108 => new FH_P_Order( 108, 'processing', $day + [ '_fh_weather_checked_for' => '2026-11-20', '_fh_weather_action' => 'hold_cold', '_fh_weather_high' => '24' ], [ 1 => new FH_P_Item( 1, 'Invoice - Sam', 1, $inv71 ) ] ),
    109 => new FH_P_Order( 109, 'processing', $day, [ 1 => new FH_P_Item( 1, 'Coral food (EverythingAquatic)', 1, $eafood ) ] ),
    104 => new FH_P_Order( 104, 'fulfillment', $day + [ '_fishotel_is_fulfillment' => '1', '_fishotel_fulfillment_sources' => '201,202,203' ], [ 1 => new FH_P_Item( 1, 'Copy of a fish', 1, $goby ) ] ),
    105 => new FH_P_Order( 105, 'fulfillment', $day + [ '_fishotel_is_fulfillment' => '1', '_fishotel_fulfillment_sources' => '204' ], [ 1 => new FH_P_Item( 1, 'Copy of a dead fish', 1, $goby ) ] ),
    201 => new FH_P_Order( 201, 'processing', [ '_fishotel_fulfilled_by_order' => '104' ], [ 1 => new FH_P_Item( 1, 'Hectors Goby', 1, $goby ) ] ),
    202 => new FH_P_Order( 202, 'cancelled', [ '_fishotel_fulfilled_by_order' => '104' ], [ 1 => new FH_P_Item( 1, 'Hectors Goby', 1, $goby ) ] ),
    203 => new FH_P_Order( 203, 'processing', [ '_fishotel_fulfilled_by_order' => '104' ], [ 1 => new FH_P_Item( 1, 'Invoice - Sam', 1, $inv71 ) ] ),
    204 => new FH_P_Order( 204, 'refunded', [ '_fishotel_fulfilled_by_order' => '105' ], [ 1 => new FH_P_Item( 1, 'Hectors Goby', 1, $goby ) ] ),
    106 => new FH_P_Order( 106, 'processing', [ '_fishotel_shipping_date' => '2026-11-24' ], [ 1 => new FH_P_Item( 1, 'Mandarin', 1, $goby ) ] ),
];

section( 'plan: the invoice plugin not active' );
$GLOBALS['fh_options'] = [];
$plan = FisHotel_Ship_Day_Paperwork::plan( '2026-11-20' );
check( 'a plan, named by the ship date', is_array( $plan ) && 'BROTHER_shipday_2026-11-19.pdf' === $plan['files']['brother'] && 'RX106HD_shipday_2026-11-19.pdf' === $plan['files']['rx'] );
check( 'says the plugin is off, with a plain warning — and no invoice anywhere', false === $plan['invoice_plugin'] && 1 === count( preg_grep( '/"PDF Invoices & Packing Slips for WooCommerce" plugin is not active.*acclimation sheets, the pick list and the weather overview/', $plan['warnings'] ) ) && [] === array_merge( ...array_map( function ( $b ) { return $b['invoices']; }, $plan['boxes'] ) ) );
check( 'the boxes are still there (acclimation sheets + labels per box)', 3 === count( $plan['boxes'] ) );
check( 'missing settings files: both skipped with a warning each, never a failure', null === $plan['acclimation'] && null === $plan['labels'] && 1 === count( preg_grep( '/^No acclimation sheet PDF is chosen/', $plan['warnings'] ) ) && 1 === count( preg_grep( '/^No shipping-labels PDF is chosen/', $plan['warnings'] ) ) );
check( 'label count defaults to 4', 4 === $plan['label_copies'] );

section( 'plan: with the invoice plugin (WP Overnight) active' );
$GLOBALS['fh_wpo_doc_calls']  = 0;
$GLOBALS['fh_wpo_link_calls'] = [];
if ( true ) {
    /** The invoice plugin's public API, as far as the plan reads it. */
    class FH_WPO_Endpoint {
        public function get_document_link( $order, $type ) {
            $GLOBALS['fh_wpo_link_calls'][] = [ $order->get_id(), $type ];
            if ( 103 === $order->get_id() && ! empty( $GLOBALS['fh_wpo_no_link_103'] ) ) {
                return '';
            }
            return esc_url( 'https://example.test/wp-admin/admin-ajax.php?action=generate_wpo_wcpdf&document_type=' . $type . '&order_ids=' . $order->get_id() . '&access_key=nonce-generate_wpo_wcpdf' );
        }
    }
    function WPO_WCPDF() {
        return (object) [ 'endpoint' => new FH_WPO_Endpoint() ];
    }
    function wcpdf_get_document( $type, $order, $init = false ) {
        $GLOBALS['fh_wpo_doc_calls']++; // the plan must never make (or number) a document itself
        return false;
    }
}
$GLOBALS['fh_options'] = [ 'fishotel_ship_day_paperwork' => [ 'acclimation_id' => 501, 'labels_id' => 502, 'label_copies' => 3 ] ];
$GLOBALS['fh_writes']  = 0;
$plan = FisHotel_Ship_Day_Paperwork::plan( '2026-11-20' );
$pick = FisHotel_Ship_Day::render( '2026-11-20', false )['html'];
$labels_in_pick = [];
preg_match_all( '/<td class="fh-sd-col-order"><strong>([^<]+)<\/strong>/', $pick, $m );
foreach ( $m[1] as $l ) {
    $labels_in_pick[] = html_entity_decode( $l, ENT_QUOTES );
}
$box_labels = array_map( function ( $b ) { return $b['label']; }, $plan['boxes'] );
check( 'box order = the pick list\'s order (held, nothing-to-box and dead-source boxes are on the list but get no paperwork)', [ 'Order #101', 'Order #103', 'Order #104 (combined: #201, #203)' ] === $box_labels && array_values( array_diff( $labels_in_pick, [ 'Order #102', 'Order #105', 'Order #107', 'Order #108', 'Order #109' ] ) ) === $box_labels && 8 === count( $labels_in_pick ), json_encode( [ $box_labels, $labels_in_pick ] ) );
check( 'one invoice per order, the plugin\'s own link with its &#038; decoded back to &', 'https://example.test/wp-admin/admin-ajax.php?action=generate_wpo_wcpdf&document_type=invoice&order_ids=101&access_key=nonce-generate_wpo_wcpdf' === $plan['boxes'][0]['invoices'][0]['url'] && 1 === count( $plan['boxes'][1]['invoices'] ) );
check( 'an on-hold order: no invoice (it would be numbered for good), no labels, no sheet — a plain warning', 1 === count( preg_grep( '/^Order #102: held \(on hold\) — no paperwork for this box\.$/', $plan['warnings'] ) ), json_encode( $plan['warnings'] ) );
check( 'a box the weather holds: no paperwork, a plain warning', 1 === count( preg_grep( '/^Order #108: held for weather \(HOLD\)/', $plan['warnings'] ) ) );
check( 'every line already shipped, or only EverythingAquatic lines: no paperwork, a plain warning', 1 === count( preg_grep( '/^Order #107: nothing left to box/', $plan['warnings'] ) ) && 1 === count( preg_grep( '/^Order #109: nothing left to box/', $plan['warnings'] ) ) );
check( 'combined box: each LIVE source order\'s invoice (201, 203) — never the wrapper 104, never the cancelled 202', [ 201, 203 ] === array_map( function ( $i ) { return $i['order_id']; }, $plan['boxes'][2]['invoices'] ) );
check( 'the invoice plugin was asked for links only for those orders (never 102, 107, 108, 109)', [ [ 101, 'invoice' ], [ 103, 'invoice' ], [ 201, 'invoice' ], [ 203, 'invoice' ] ] === $GLOBALS['fh_wpo_link_calls'], json_encode( $GLOBALS['fh_wpo_link_calls'] ) );
check( 'a wrapper whose every source is dead: no paperwork, a plain warning', 1 === count( preg_grep( '/^Order #105: every source order is cancelled, refunded or failed/', $plan['warnings'] ) ), json_encode( $plan['warnings'] ) );
check( 'the settings files: both found, label count 3', 501 === $plan['acclimation']['id'] && 'https://example.test/wp-content/uploads/file-501.pdf' === $plan['acclimation']['url'] && 502 === $plan['labels']['id'] && 3 === $plan['label_copies'] );
check( 'no warnings about the plugin or the files now', [] === preg_grep( '/not active|is chosen/', $plan['warnings'] ) );
check( 'product sheets: box 103 and the combined box (via source 203) each get the "Invoice - Sam" sheet; box 101 none', [] === $plan['boxes'][0]['sheets'] && [ 71 ] === $plan['boxes'][1]['sheets'] && [ 71 ] === $plan['boxes'][2]['sheets'] );
$sheet = ( (array) $plan['sheets'] )['71'] ?? null;
check( 'the sheet: title, price, picture, description lines', is_array( $sheet ) && 'Invoice - Sam' === $sheet['title'] && '$125.00' === $sheet['price'] && 'https://example.test/wp-content/uploads/img-971-large.jpg' === $sheet['image'] && [ 'Hectors Goby x1 — $39', 'Yellow Tang x1 — $86' ] === $sheet['lines'], json_encode( $sheet ) );
check( 'the plan never makes, numbers or saves an invoice, and writes no order', 0 === $GLOBALS['fh_wpo_doc_calls'] && 0 === $GLOBALS['fh_writes'] );
check( 'the plan JSON-encodes (what the AJAX reply carries)', false !== json_encode( $plan ) && false !== strpos( json_encode( $plan ), '"sheets":{"71":' ) );

section( 'plan: the print pages are the pick list and weather overview, cell for cell' );
$pages = $plan['pages'];
check( 'pick list title and line', 'FisHotel Pick List' === $pages['pick']['title'] && false !== strpos( $pick, esc_html( $pages['pick']['title'] ) ) && 'Ship Thursday, November 19 • Deliver Friday, November 20 • 8 orders / 11 fish' === $pages['pick']['sub'] && false !== strpos( $pick, '8 orders / 11 fish' ), $pages['pick']['sub'] );
$rows_text = array_map( function ( $r ) { return $r['qty'] . ' ' . $r['fish'][0]['t']; }, $pages['pick']['rows'] );
preg_match_all( '/<td class="fh-sd-col-qty">(\d+)<\/td><td>([^<]+)/', $pick, $hm, PREG_SET_ORDER );
$html_rows = array_map( function ( $x ) { return $x[1] . ' ' . html_entity_decode( trim( $x[2] ), ENT_QUOTES ); }, $hm );
check( 'every pick row in the same order with the same qty and name as the printed list', $rows_text === $html_rows, json_encode( [ $rows_text, $html_rows ] ) );
$first = array_values( array_filter( $pages['pick']['rows'], function ( $r ) { return $r['first']; } ) );
check( 'order and destination only on a box\'s first row', 8 === count( $first ) && 'Order #104 (combined: #201, #203)' === $first[6]['order'][0]['t'] && [] === $pages['pick']['rows'][2]['order'] );
check( 'the foot and legend read as printed', html_entity_decode( strip_tags( preg_replace( '/.*<p class="fh-sd-foot">(.*?)<\/p>.*/s', '$1', $pick ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) === $pages['pick']['foot'] && html_entity_decode( strip_tags( preg_replace( '/.*<p class="fh-sd-legend">(.*?)<\/p>.*/s', '$1', $pick ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) === $pages['pick']['legend'] );
check( 'weather overview: title, chart line, one row per box (the HOLD red, not checked grey), the data note', 'Weather Overview — Delivery Friday, November 20' === $pages['weather']['title'] && FisHotel_Ship_Day::CHART_SUBTITLE === $pages['weather']['sub'] && 8 === count( $pages['weather']['rows'] ) && 'red' === $pages['weather']['rows'][0]['tone'] && 'HOLD (cold)' === $pages['weather']['rows'][0]['cells'][5] && 'grey' === $pages['weather']['rows'][1]['tone'] && '#e6e6e6' === $pages['weather']['rows'][1]['fill'] && 'Not checked' === $pages['weather']['rows'][1]['cells'][5] && false !== strpos( end( $pages['weather']['notes'] ), 'National Weather Service' ) );

section( 'plan: edges' );
$GLOBALS['fh_wpo_no_link_103'] = true;
$plan2 = FisHotel_Ship_Day_Paperwork::plan( '2026-11-20' );
unset( $GLOBALS['fh_wpo_no_link_103'] );
check( 'the plugin gives no link for an order: that invoice is left out with a warning, the box stays', [] === $plan2['boxes'][1]['invoices'] && 1 === count( preg_grep( '/^Order #103: the invoice plugin gave no invoice link/', $plan2['warnings'] ) ) );
$GLOBALS['fh_options'] = [ 'fishotel_ship_day_paperwork' => [ 'acclimation_id' => 503, 'labels_id' => 504 ] ];
$plan3 = FisHotel_Ship_Day_Paperwork::plan( '2026-11-20' );
check( 'a chosen file that is not a PDF, or whose file is gone: skipped with the warning', null === $plan3['acclimation'] && null === $plan3['labels'] && 2 === count( preg_grep( '/is chosen \(or it is gone/', $plan3['warnings'] ) ) );
$big = tempnam( sys_get_temp_dir(), 'fh-big' );
$fh  = fopen( $big, 'wb' );
fseek( $fh, FisHotel_Ship_Day_Paperwork::MAX_STATIC_BYTES ); // sparse: over 20 MB on paper, nothing on disk
fwrite( $fh, '1' );
fclose( $fh );
$GLOBALS['fh_media'][505] = [ 'mime' => 'application/pdf', 'file' => $big, 'title' => 'Huge' ];
$GLOBALS['fh_options']    = [ 'fishotel_ship_day_paperwork' => [ 'acclimation_id' => 501, 'labels_id' => 505 ] ];
$plan3b = FisHotel_Ship_Day_Paperwork::plan( '2026-11-20' );
unlink( $big );
unset( $GLOBALS['fh_media'][505] );
check( 'a chosen PDF over 20 MB: skipped with a plain warning (the browser would download it whole), the other file kept', 501 === $plan3b['acclimation']['id'] && null === $plan3b['labels'] && 1 === count( preg_grep( '/^The chosen shipping-labels PDF is over 20 MB — skipped\./', $plan3b['warnings'] ) ) && [] === preg_grep( '/is chosen \(or it is gone/', $plan3b['warnings'] ), json_encode( $plan3b['warnings'] ) );
check( 'a PDF at the limit or under: kept', null !== FisHotel_Ship_Day_Paperwork::static_file( 502, $why ) && '' === $why );
$GLOBALS['fh_orders'][203]->status = 'failed';
$GLOBALS['fh_orders'][201]->status = 'cancelled';
$plan4 = FisHotel_Ship_Day_Paperwork::plan( '2026-11-20' );
check( 'the combined box loses its last live source: no paperwork for it', [ 'Order #101', 'Order #103' ] === array_map( function ( $b ) { return $b['label']; }, $plan4['boxes'] ) );
$GLOBALS['fh_orders'][203]->status = 'processing';
$GLOBALS['fh_orders'][201]->status = 'processing';
$GLOBALS['fh_orders'][104]->meta['_fishotel_fulfillment_sources'] = '901,902';
$plan5 = FisHotel_Ship_Day_Paperwork::plan( '2026-11-20' );
check( 'sources that cannot be loaded: the box is kept (labels, acclimation) but has no invoice — the wrapper\'s copy is never used', isset( $plan5['boxes'][2] ) && 0 === strpos( $plan5['boxes'][2]['label'], 'Order #104' ) && [] === $plan5['boxes'][2]['invoices'] && 1 === count( preg_grep( '/^Order #104: its source orders could not be loaded/', $plan5['warnings'] ) ) );
$GLOBALS['fh_orders'][104]->meta['_fishotel_fulfillment_sources'] = '201,202,203';
$plan6 = FisHotel_Ship_Day_Paperwork::plan( '2026-11-21' );
check( 'a day with no orders: no boxes, no pages', is_array( $plan6 ) && [] === $plan6['boxes'] && null === $plan6['pages'] );
check( 'a bad date: an error, not a plan', FisHotel_Ship_Day_Paperwork::plan( '2026-13-01' ) instanceof WP_Error );

/* ── AJAX, settings form, page ────────────────────────────────────── */

$host = new FH_Paper_Host();

section( 'AJAX: nonce and capability' );
$GLOBALS['fh_options'] = [];
$_POST = [ 'nonce' => 'nonce-fishotel_ship_day_paperwork', 'delivery_date' => '2026-11-20' ];
$reply = fh_json( [ $host, 'ajax_ship_day_paperwork' ] );
check( 'the right nonce: success, exactly plan()', $reply && $reply->ok && json_encode( FisHotel_Ship_Day_Paperwork::plan( '2026-11-20' ) ) === json_encode( $reply->data ) );
$_POST = [ 'nonce' => 'nonce-fishotel_ship_day', 'delivery_date' => '2026-11-20' ];
$reply = fh_json( [ $host, 'ajax_ship_day_paperwork' ] );
check( 'another nonce (the pick list\'s) is refused', $reply && ! $reply->ok && 'Unauthorized.' === $reply->data['message'] );
$_POST = [ 'nonce' => 'nonce-fishotel_ship_day_paperwork', 'delivery_date' => '2026-11-20' ];
$GLOBALS['fh_can'] = false;
$reply = fh_json( [ $host, 'ajax_ship_day_paperwork' ] );
$GLOBALS['fh_can'] = true;
check( 'without the Ship Day page\'s capability: refused', $reply && ! $reply->ok );
$_POST = [ 'nonce' => 'nonce-fishotel_ship_day_paperwork', 'delivery_date' => 'soon' ];
$reply = fh_json( [ $host, 'ajax_ship_day_paperwork' ] );
check( 'a bad date: a plain error', $reply && ! $reply->ok && 'Pick a valid delivery date (Y-m-d).' === $reply->data['message'] );

section( 'settings form: capability, nonce, saved, back to Ship Day' );
$GLOBALS['fh_options'] = [];
$_POST = [ '_wpnonce' => 'nonce-fishotel_ship_day_paperwork_settings', 'acclimation_id' => '501', 'labels_id' => '503', 'label_copies' => '5' ];
try {
    $host->save_ship_day_paperwork_settings();
    $to = null;
} catch ( FH_Redirect $r ) {
    $to = $r->to;
}
check( 'saved: the PDF kept, the image cleared, 5 labels; not autoloaded', [ 'acclimation_id' => 501, 'labels_id' => 0, 'label_copies' => 5 ] === get_option( 'fishotel_ship_day_paperwork' ) && false === $GLOBALS['fh_option_autoload']['fishotel_ship_day_paperwork'] );
check( '... and back to the page with "Saved."', 'https://example.test/wp-admin/admin.php?page=fishotel-ship-day&paperwork=saved' === $to );
$GLOBALS['fh_options'] = [];
$_POST['_wpnonce']     = 'wrong';
$out = fh_capture( [ $host, 'save_ship_day_paperwork_settings' ] );
check( 'a bad nonce: stopped, nothing saved', false !== strpos( $out, 'wp_die' ) || false !== strpos( $out, 'expired' ) );
check( '... nothing saved', false === get_option( 'fishotel_ship_day_paperwork' ) );
$_POST['_wpnonce'] = 'nonce-fishotel_ship_day_paperwork_settings';
$GLOBALS['fh_can'] = false;
$out = fh_capture( [ $host, 'save_ship_day_paperwork_settings' ] );
$GLOBALS['fh_can'] = true;
check( 'without the capability: turned away, nothing saved', '[wp_die: Unauthorized.]' === $out && false === get_option( 'fishotel_ship_day_paperwork' ) );

section( 'the page: the button beside Build Ship Day, the settings, the scripts' );
$GLOBALS['admin_page_hooks'] = [ 'fishotel-misc' => 'fishotel-tools' ];
$GLOBALS['fh_options']       = [ 'fishotel_ship_day_paperwork' => [ 'acclimation_id' => 501, 'labels_id' => 0, 'label_copies' => 4 ] ];
$_GET  = [ 'page' => 'fishotel-ship-day' ];
$page  = fh_capture( [ $host, 'render_ship_day_page' ] );
$build = strpos( $page, 'id="fh-sd-generate"' );
$btn   = strpos( $page, 'id="fh-sd-paperwork"' );
check( '"Print the day\'s paperwork" sits right after Build Ship Day', false !== $build && false !== $btn && $btn > $build && $btn - $build < 400 && false !== strpos( $page, 'Print the day&rsquo;s paperwork</button>' ) );
check( 'its request: admin-ajax, its own action and nonce', false !== strpos( $page, 'data-ajax="https://example.test/wp-admin/admin-ajax.php" data-action="fishotel_ship_day_paperwork" data-nonce="nonce-fishotel_ship_day_paperwork"' ) );
check( 'progress, warnings and files areas', false !== strpos( $page, 'id="fh-sd-paperwork-status"' ) && false !== strpos( $page, 'id="fh-sd-paperwork-warnings"' ) && false !== strpos( $page, 'id="fh-sd-paperwork-files"' ) );
check( 'settings form posts to admin-post.php with its action and nonce', false !== strpos( $page, 'action="https://example.test/wp-admin/admin-post.php"' ) && false !== strpos( $page, 'name="action" value="fishotel_ship_day_paperwork_settings"' ) && false !== strpos( $page, 'name="_wpnonce" value="nonce-fishotel_ship_day_paperwork_settings"' ) );
check( 'the two selects list the Media Library PDFs (not the image), the saved one picked', false !== strpos( $page, '<option value="501" selected>Acclimation sheet' ) && false !== strpos( $page, '<option value="502">Shipping labels' ) && false === strpos( $page, 'value="503"' ) && false !== strpos( $page, 'name="label_copies" min="1" max="20" value="4"' ) );
check( 'no "Saved." unless just saved', false === strpos( $page, '>Saved.<' ) );
$_GET = [ 'page' => 'fishotel-ship-day', 'paperwork' => 'saved' ];
check( '?paperwork=saved shows "Saved." with the settings open', false !== strpos( fh_capture( [ $host, 'render_ship_day_page' ] ), '<p style="color:#7bd88f;margin:8px 0;">Saved.</p>' ) );
check( 'scripts: the vendored pdf-lib and the button script, from the plugin itself (no CDN), in the footer', 'https://example.test/wp-content/plugins/fishotel-batch-manager/assists/vendor/pdf-lib/pdf-lib.min.js' === $GLOBALS['fh_scripts']['fishotel-pdf-lib']['src'] && 'https://example.test/wp-content/plugins/fishotel-batch-manager/assists/ship-day/paperwork.js' === $GLOBALS['fh_scripts']['fishotel-ship-day-paperwork']['src'] && [ 'fishotel-pdf-lib', 'fishotel-ship-day-print-watcher' ] === $GLOBALS['fh_scripts']['fishotel-ship-day-paperwork']['deps'] && true === $GLOBALS['fh_scripts']['fishotel-pdf-lib']['footer'] );
check( '"Send to the printers" (10.36): its script from the plugin itself, in the footer, loaded before the button script and versioned with the plugin', 'https://example.test/wp-content/plugins/fishotel-batch-manager/assists/ship-day/print-watcher.js' === $GLOBALS['fh_scripts']['fishotel-ship-day-print-watcher']['src'] && true === $GLOBALS['fh_scripts']['fishotel-ship-day-print-watcher']['footer'] && '10.36' === $GLOBALS['fh_scripts']['fishotel-ship-day-print-watcher']['ver'] && is_file( dirname( __DIR__ ) . '/assists/ship-day/print-watcher.js' ) );
$files_at = strpos( $page, 'id="fh-sd-paperwork-files"' );
$send_at  = strpos( $page, '<div id="fh-sd-paperwork-send" style="display:none;' );
check( '... its box right under the files, empty and hidden until the script has something to show (no button in the page itself)', false !== $send_at && $send_at > $files_at && 1 === preg_match( '#<div id="fh-sd-paperwork-send" style="display:none;[^"]*"></div>#', $page ) && false === strpos( $page, 'fh-sd-pw-send' ) );
check( '... plain words under Paperwork settings: what it does, the folder, that the Print Watcher must be running, how to change the folder, nothing saved on the website', false !== strpos( $page, '<strong>Send to the printers</strong> puts the two files straight into the Print Watcher&rsquo;s folder (Documents &rarr; FisHotel-PrintQueue)' ) && false !== strpos( $page, 'The Print Watcher must be running &mdash; the fish icon near the clock.' ) && false !== strpos( $page, '&ldquo;change&rdquo; picks another, &ldquo;forget&rdquo; stops using it' ) && false !== strpos( $page, 'Nothing about it is saved on the website' ) );
$watcher_js = (string) file_get_contents( dirname( __DIR__ ) . '/assists/ship-day/print-watcher.js' );
check( '... and the server has no part in it: the script makes no request, and the plugin has no new action, option or capability for it', 0 === preg_match( '/\bfetch\s*\(|XMLHttpRequest|sendBeacon|admin-ajax|wp-json|\.ajax\s*\(/', $watcher_js ) && 0 === preg_match( '/print[_-]?watcher/i', (string) file_get_contents( dirname( __DIR__ ) . '/fishotel-batch-manager.php' ) . (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-ajax.php' ) ) && 1 === preg_match_all( '/_option\s*\(/', (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-ship-day-paperwork.php' ) ) );
check( 'the vendored library and its MIT licence are in the plugin', is_file( dirname( __DIR__ ) . '/assists/vendor/pdf-lib/pdf-lib.min.js' ) && false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/assists/vendor/pdf-lib/LICENSE.md' ), 'MIT License' ) );
$GLOBALS['fh_can'] = false;
$GLOBALS['fh_scripts'] = [];
check( 'a user without the capability: turned away before anything loads', '[wp_die: Unauthorized.]' === fh_capture( [ $host, 'render_ship_day_page' ] ) && [] === $GLOBALS['fh_scripts'] );
$GLOBALS['fh_can'] = true;

$main = (string) file_get_contents( dirname( __DIR__ ) . '/fishotel-batch-manager.php' );
check( 'the plugin loads the class and registers the AJAX action and the settings handler (logged-in only, no nopriv)', false !== strpos( $main, "require_once FISHOTEL_PLUGIN_DIR . 'includes/class-ship-day-paperwork.php';" ) && false !== strpos( $main, "add_action( 'wp_ajax_fishotel_ship_day_paperwork'," ) && false !== strpos( $main, "add_action( 'admin_post_fishotel_ship_day_paperwork_settings'," ) && false === strpos( $main, 'nopriv_fishotel_ship_day_paperwork' ) );

echo "\n{$passes} passed, {$failures} failed.\n";
exit( $failures > 0 ? 1 : 0 );
