<?php
/**
 * Ship Day's home (10.34): its own page under FisHotel Tools right after
 * Today, the old Group-order invoices tab as a pointer, the page building the
 * same pick list, and the Ship Day line on the Misc plugin's Today page.
 *
 *   php tests/ship-day-home-test.php
 *
 * Plain PHP, no PHPUnit, no WordPress: the few WordPress functions the code
 * calls are stood in for below, and the admin + AJAX traits run on a small
 * host class. Exits 1 on any failure, 0 when everything passes.
 *
 * CLI only: the plugin zip ships this directory.
 */

if ( 'cli' !== PHP_SAPI ) {
    exit;
}

if ( defined( 'WPINC' ) ) {
    echo "Runs without WordPress only (it stands in for the menu functions).\n";
    exit( 0 );
}

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

/* ── WordPress stand-ins ─────────────────────────────────────────── */

$GLOBALS['fh_filters']   = [];
$GLOBALS['fh_actions']   = [];
$GLOBALS['fh_options']   = [];
$GLOBALS['fh_can']       = true;
$GLOBALS['fh_screen']    = null;
$GLOBALS['fh_submenu_calls'] = [];

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
    foreach ( $GLOBALS['fh_filters'][ $tag ] ?? [] as $i => $registered ) {
        if ( $registered === $cb ) {
            unset( $GLOBALS['fh_filters'][ $tag ][ $i ] );
        }
    }
    return true;
}
function add_action( $tag, $cb, $priority = 10, $args = 1 ) {
    $GLOBALS['fh_actions'][ $tag ][] = $cb;
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
function esc_url( $s ) {
    return (string) $s;
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
    $GLOBALS['fh_options'][ $key ]      = $value;
    $GLOBALS['fh_option_autoload'][ $key ] = $autoload;
    return true;
}
function current_time( $type ) {
    return $GLOBALS['fh_now_mysql'] ?? '2026-11-19 09:12:00';
}
function wp_create_nonce( $action ) {
    return 'nonce-' . $action;
}
function wp_verify_nonce( $nonce, $action ) {
    return 'nonce-' . $action === $nonce ? 1 : false;
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
class FH_Die extends Exception {}
function wp_die( $msg = '' ) {
    throw new FH_Die( (string) $msg );
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
    return isset( $GLOBALS['admin_page_hooks']['fishotel-misc'] ) && 'fishotel-misc-today' === $slug ? admin_url( 'admin.php?page=' . $slug ) : '';
}
function get_current_screen() {
    return $GLOBALS['fh_screen'];
}
/** WordPress's add_submenu_page(), as far as the menu goes: files the entry when the user may open it. */
function add_submenu_page( $parent, $page_title, $menu_title, $cap, $slug, $callback = '' ) {
    global $submenu;
    $GLOBALS['fh_submenu_calls'][] = [ $parent, $menu_title, $cap, $slug ];
    if ( ! current_user_can( $cap ) ) {
        return false;
    }
    if ( null !== $parent ) {
        $submenu[ $parent ][] = [ $menu_title, $cap, $slug, $page_title ];
    }
    return 'hook_' . $slug;
}

/* ── Orders, products ─────────────────────────────────────────────── */

class FH_Home_Product {
    public $id;
    public function __construct( $id ) {
        $this->id = $id;
    }
    public function get_id() {
        return $this->id;
    }
    public function get_parent_id() {
        return 0;
    }
    public function get_description() {
        return '';
    }
}
class FH_Home_Item {
    public $id;
    public $name;
    public $qty;
    public $product;
    public function __construct( $id, $name, $qty, $product ) {
        $this->id      = $id;
        $this->name    = $name;
        $this->qty     = $qty;
        $this->product = $product;
    }
    public function get_type() {
        return 'line_item';
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
        return '';
    }
}
class FH_Home_Order {
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
    public function has_status( $s ) {
        return in_array( $this->status, (array) $s, true );
    }
    public function get_meta( $key ) {
        return $this->meta[ $key ] ?? '';
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
    public function __call( $name, $args ) {
        $map = [ 'get_shipping_first_name' => 'Pat', 'get_shipping_last_name' => 'Reefer', 'get_shipping_city' => 'Austin', 'get_shipping_state' => 'TX', 'get_shipping_postcode' => '78701' ];
        return $map[ $name ] ?? '';
    }
}
$GLOBALS['fh_orders']   = [];
$GLOBALS['fh_wc_calls'] = 0;
function wc_get_orders( $args ) {
    $GLOBALS['fh_wc_calls']++;
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
function get_the_terms( $pid, $tax ) {
    return 10 === (int) $pid ? [ (object) [ 'slug' => 'quarantined-fish' ] ] : [ (object) [ 'slug' => 'medications' ] ];
}

require_once dirname( __DIR__ ) . '/includes/class-ship-day.php';
require_once dirname( __DIR__ ) . '/includes/class-ship-day-today-line.php';
require_once dirname( __DIR__ ) . '/includes/class-ajax.php';
require_once dirname( __DIR__ ) . '/includes/class-admin.php';

/** The plugin class, as far as these pages go. */
class FH_Home_Host {
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
function fh_capture( callable $fn ) {
    ob_start();
    try {
        $fn();
    } catch ( FH_Die $e ) {
        echo '[wp_die: ' . $e->getMessage() . ']';
    }
    return (string) ob_get_clean();
}
/** The FisHotel Tools submenu as the Misc plugin 0.46.4 leaves it (its own Ship Day entry is the old tab's address). */
function fh_misc_menu( $with_today = true ) {
    $GLOBALS['admin_page_hooks'] = [ 'fishotel-misc' => 'fishotel-tools', 'fishotel-batch-hq' => 'group-orders' ];
    $entries                     = [ [ 'Dashboard', 'manage_options', 'fishotel-misc', 'Dashboard' ] ];
    if ( $with_today ) {
        $entries[] = [ 'Today', 'manage_woocommerce', 'fishotel-misc-today', 'Today' ];
        $entries[] = [ 'Ship Day', 'manage_options', 'admin.php?page=fishotel-invoicing&tab=ship-day', 'Ship Day' ];
    }
    $entries[] = [ 'Weather', 'manage_woocommerce', 'fishotel-misc-weather', 'Weather' ];
    $entries[] = [ 'Packing', 'manage_woocommerce', 'fishotel-misc-packing', 'Packing' ];
    $GLOBALS['submenu']          = [ 'fishotel-misc' => $entries ];
    $GLOBALS['fh_filters']       = [];
    $GLOBALS['fh_submenu_calls'] = [];
}
function fh_no_misc_menu() {
    $GLOBALS['admin_page_hooks'] = [ 'fishotel-batch-hq' => 'group-orders' ];
    $GLOBALS['submenu']          = [];
    $GLOBALS['fh_filters']       = [];
    $GLOBALS['fh_submenu_calls'] = [];
}
function fh_slugs( $parent ) {
    return array_map( function ( $e ) { return $e[2]; }, $GLOBALS['submenu'][ $parent ] ?? [] );
}

$host = new FH_Home_Host();

/* ── Menu ─────────────────────────────────────────────────────────── */

section( 'menu: with the Misc plugin, FisHotel Tools → Ship Day right after Today' );
fh_misc_menu();
$host->add_ship_day_menu();
check( 'registered under fishotel-misc with the page slug and manage_options', [ [ 'fishotel-misc', 'Ship Day', 'manage_options', 'fishotel-ship-day' ] ] === $GLOBALS['fh_submenu_calls'], json_encode( $GLOBALS['fh_submenu_calls'] ) );
check( 'order: Dashboard, Today, Ship Day, then the rest — the Misc entry for the old tab is gone (one Ship Day, not two)', [ 'fishotel-misc', 'fishotel-misc-today', 'fishotel-ship-day', 'fishotel-misc-weather', 'fishotel-misc-packing' ] === fh_slugs( 'fishotel-misc' ), json_encode( fh_slugs( 'fishotel-misc' ) ) );
check( 'menu keys are 0..n (WordPress walks them in order)', array_keys( $GLOBALS['submenu']['fishotel-misc'] ) === range( 0, 4 ) );
check( 'no Group Orders highlight filters — WordPress highlights FisHotel Tools → Ship Day from the submenu itself', empty( $GLOBALS['fh_filters']['parent_file'] ) && empty( $GLOBALS['fh_filters']['submenu_file'] ) );
check( 'nothing new under Group Orders', empty( $GLOBALS['submenu']['fishotel-batch-hq'] ) );

fh_misc_menu( false );
$host->add_ship_day_menu();
check( 'Today switched off: right after the Dashboard entry', [ 'fishotel-misc', 'fishotel-ship-day', 'fishotel-misc-weather', 'fishotel-misc-packing' ] === fh_slugs( 'fishotel-misc' ), json_encode( fh_slugs( 'fishotel-misc' ) ) );

fh_misc_menu();
$GLOBALS['fh_can'] = false;
$host->add_ship_day_menu();
$GLOBALS['fh_can'] = true;
check( 'a user without manage_options gets no Ship Day entry', ! in_array( 'fishotel-ship-day', fh_slugs( 'fishotel-misc' ), true ) );

check( 'place_menu_entry(): a second copy of the entry is dropped, unknown rows kept', [ 'a', 'fishotel-misc-today', 'fishotel-ship-day', 'b' ] === array_map( function ( $e ) { return $e[2]; }, FisHotel_Ship_Day::place_menu_entry( [ [ 'A', 'c', 'a' ], [ 'T', 'c', 'fishotel-misc-today' ], [ 'B', 'c', 'b' ], [ 'S', 'c', 'fishotel-ship-day' ], [ 'S2', 'c', 'fishotel-ship-day' ] ] ) ) );
check( 'is_old_tab_entry(): the old tab and its packing alias, nothing else', FisHotel_Ship_Day::is_old_tab_entry( 'admin.php?page=fishotel-invoicing&tab=ship-day' ) && FisHotel_Ship_Day::is_old_tab_entry( 'admin.php?page=fishotel-invoicing&tab=packing' ) && ! FisHotel_Ship_Day::is_old_tab_entry( 'admin.php?page=fishotel-invoicing&tab=unpaid' ) && ! FisHotel_Ship_Day::is_old_tab_entry( 'fishotel-ship-day' ) );

section( 'menu: without the Misc plugin, the page sits where Ship Day always was' );
fh_no_misc_menu();
$host->add_ship_day_menu();
check( 'a hidden page (no parent) with the same slug and capability', [ [ null, '', 'manage_options', 'fishotel-ship-day' ] ] === $GLOBALS['fh_submenu_calls'], json_encode( $GLOBALS['fh_submenu_calls'] ) );
$GLOBALS['title'] = null;
foreach ( $GLOBALS['fh_actions']['load-hook_fishotel-ship-day'] ?? [] as $cb ) {
    call_user_func( $cb );
}
check( 'its load- hook gives the hidden page a title before admin-header.php (no strip_tags(null) deprecation)', 'Ship Day' === $GLOBALS['title'] );
unset( $GLOBALS['title'] );
check( 'parent_file / submenu_file filters registered', in_array( [ 'FisHotel_Ship_Day', 'highlight_parent_file' ], $GLOBALS['fh_filters']['parent_file'] ?? [], true ) && in_array( [ 'FisHotel_Ship_Day', 'highlight_submenu_file' ], $GLOBALS['fh_filters']['submenu_file'] ?? [], true ) );
$GLOBALS['plugin_page'] = 'fishotel-ship-day';
$GLOBALS['submenu']['']  = [ [ 'Batch Requests', 'manage_options', 'fishotel-batch-orders' ], [ 'Ship Day', 'manage_options', 'fishotel-ship-day' ] ];
check( 'on the page: Group Orders → Group-order invoices is highlighted', 'fishotel-batch-hq' === apply_filters( 'parent_file', 'admin.php' ) && 'fishotel-invoicing' === apply_filters( 'submenu_file', null ) );
check( '... and its hidden entry is dropped first, so get_admin_page_parent() cannot reset the parent to \'\' (other hidden pages kept)', [ 'fishotel-batch-orders' ] === array_values( array_map( function ( $e ) { return $e[2]; }, $GLOBALS['submenu'][''] ) ) );
$GLOBALS['submenu']['']  = [ [ 'Ship Day', 'manage_options', 'fishotel-ship-day' ] ];
$GLOBALS['plugin_page'] = 'fishotel-sourcing';
check( 'on any other page the filters change nothing', 'admin.php' === apply_filters( 'parent_file', 'admin.php' ) && 'x' === apply_filters( 'submenu_file', 'x' ) && 1 === count( $GLOBALS['submenu'][''] ) );
unset( $GLOBALS['submenu'][''] );
unset( $GLOBALS['plugin_page'] );

/* ── Old tab ──────────────────────────────────────────────────────── */

section( 'Group-order invoices → Ship Day tab: a one-line pointer, no second screen' );
fh_misc_menu();
foreach ( [ 'ship-day', 'packing' ] as $tab ) {
    $_GET = [ 'page' => 'fishotel-invoicing', 'tab' => $tab ];
    $html = fh_capture( [ $host, 'render_invoicing_page' ] );
    check( "tab={$tab}: says where Ship Day went, with a link to the page", false !== strpos( $html, 'Ship Day has moved to FisHotel Tools &rarr; Ship Day. <a href="https://example.test/wp-admin/admin.php?page=fishotel-ship-day"' ) && false !== strpos( $html, 'Open Ship Day &rarr;</a>' ), $html );
    check( "tab={$tab}: no pick-list form, no build call, no date chips", false === strpos( $html, 'fh-sd-generate' ) && false === strpos( $html, 'fishotel_get_ship_day' ) && false === strpos( $html, 'fh-sd-datechip' ) && false === strpos( $html, 'id="fh-sd-output"' ) );
}
check( 'the pointer is one line', 1 === substr_count( FisHotel_Ship_Day::old_tab_pointer_html( true ), '<p' ) );
check( 'without the Misc plugin the pointer says "its own page"', false !== strpos( FisHotel_Ship_Day::old_tab_pointer_html( false ), 'Ship Day has moved to its own page.' ) );
check( 'the tabs: "Ship Day" opens the page itself; "Unpaid Invoices" is unchanged', false !== strpos( $html, '<a href="https://example.test/wp-admin/admin.php?page=fishotel-ship-day" class="nav-tab' ) && false !== strpos( $html, 'admin.php?page=fishotel-invoicing&tab=unpaid' ) );
$_GET = [ 'page' => 'fishotel-invoicing' ];
$html = fh_capture( [ $host, 'render_invoicing_page' ] );
check( 'Unpaid Invoices tab still works (default tab, its form and its script)', false !== strpos( $html, 'id="fh-inv-generate"' ) && false !== strpos( $html, 'fishotel_get_unpaid_invoices' ) && false === strpos( $html, 'Ship Day has moved' ) );

/* ── The page ─────────────────────────────────────────────────────── */

section( 'the page: FisHotel Tools → Ship Day renders the Ship Day screen itself' );
$goby  = new FH_Home_Product( 10 );
$prazi = new FH_Home_Product( 12 );
$GLOBALS['fh_orders'] = [
    101 => new FH_Home_Order( 101, 'processing', [ '_fishotel_shipping_date' => '2026-11-20' ], [ 1 => new FH_Home_Item( 1, 'Hectors Goby', 2, $goby ), 2 => new FH_Home_Item( 2, 'PraziPro', 1, $prazi ) ] ),
    102 => new FH_Home_Order( 102, 'on-hold', [ '_fishotel_shipping_date' => '2026-11-20' ], [ 1 => new FH_Home_Item( 1, 'Yellow Tang', 3, $goby ) ] ),
    103 => new FH_Home_Order( 103, 'processing', [ '_fishotel_shipping_date' => '2026-11-24' ], [ 1 => new FH_Home_Item( 1, 'Mandarin', 1, $goby ) ] ),
    104 => new FH_Home_Order( 104, 'completed', [ '_fishotel_shipping_date' => '2026-11-20' ], [ 1 => new FH_Home_Item( 1, 'Old Goby', 9, $goby ) ] ),
];
fh_misc_menu();
$_GET = [ 'page' => 'fishotel-ship-day' ];
$page = fh_capture( [ $host, 'render_ship_day_page' ] );
check( 'heading "Ship Day", no Group-order invoices tabs', false !== strpos( $page, '<h1>Ship Day</h1>' ) && false === strpos( $page, 'Group-order invoices' ) && false === strpos( $page, '<nav class="nav-tab-wrapper"' ) );
check( '"← Today" link back to Today', false !== strpos( $page, 'href="https://example.test/wp-admin/admin.php?page=fishotel-misc-today"' ) && false !== strpos( $page, '&larr; Today' ) );
check( 'the Ship Day screen: date input, slips box, Build button, output', false !== strpos( $page, 'id="fh-sd-date"' ) && false !== strpos( $page, 'id="fh-sd-slips"' ) && false !== strpos( $page, 'id="fh-sd-generate"' ) && false !== strpos( $page, 'id="fh-sd-output"' ) );
check( 'builds through the same AJAX action and nonce as before', false !== strpos( $page, "fd.append('action', 'fishotel_get_ship_day');" ) && false !== strpos( $page, "fd.append('nonce', 'nonce-fishotel_ship_day');" ) );
check( 'date chips for the open delivery dates (20th: 2 orders, 24th: 1), the next one picked', false !== strpos( $page, 'data-date="2026-11-20"' ) && false !== strpos( $page, 'data-date="2026-11-24"' ) && false !== strpos( $page, 'value="2026-11-20"' ), $page );
check( 'the print styles come along (pick list page, slips, @media print)', false !== strpos( $page, '.fh-sd-print {' ) && false !== strpos( $page, '@media print' ) && false !== strpos( $page, '.fh-packing-list {' ) );
$_GET = [ 'page' => 'fishotel-ship-day', 'date' => '2026-11-24' ];
$page = fh_capture( [ $host, 'render_ship_day_page' ] );
check( '?date=2026-11-24 opens that day', false !== strpos( $page, 'id="fh-sd-date" value="2026-11-24"' ), $page );
$_GET = [ 'page' => 'fishotel-ship-day', 'date' => '2026-02-30' ];
$page = fh_capture( [ $host, 'render_ship_day_page' ] );
check( 'a date that is not a date is ignored', false !== strpos( $page, 'id="fh-sd-date" value="2026-11-20"' ) );
fh_no_misc_menu();
$_GET = [ 'page' => 'fishotel-ship-day' ];
$page = fh_capture( [ $host, 'render_ship_day_page' ] );
check( 'without the Misc plugin: the Group-order invoices heading and tabs, Ship Day active, no "← Today"', false !== strpos( $page, '<h1>Group-order invoices</h1>' ) && false !== strpos( $page, 'page=fishotel-ship-day" class="nav-tab nav-tab-active">Ship Day' ) && false === strpos( $page, '&larr; Today' ) && false !== strpos( $page, 'id="fh-sd-generate"' ), $page );
$GLOBALS['fh_can'] = false;
check( 'a user without manage_options is turned away', '[wp_die: Unauthorized.]' === fh_capture( [ $host, 'render_ship_day_page' ] ) );
$GLOBALS['fh_can'] = true;

section( 'the pick list: the AJAX build is the same render(), and it records "built"' );
$GLOBALS['fh_options'] = [];
$_POST = [ 'nonce' => 'nonce-fishotel_ship_day', 'delivery_date' => '2026-11-20', 'include_slips' => '0' ];
try {
    $host->ajax_get_ship_day();
    $reply = null;
} catch ( FH_Json $j ) {
    $reply = $j;
}
$direct = FisHotel_Ship_Day::render( '2026-11-20', false );
check( 'success, and the HTML is exactly FisHotel_Ship_Day::render()', $reply && $reply->ok && $direct['html'] === $reply->data['html'] );
check( 'it is the pick list: 2 orders, 5 fish (completed order left out)', 2 === $reply->data['orders'] && 5 === $reply->data['fish'] && false !== strpos( $reply->data['html'], 'FisHotel Pick List' ) && false !== strpos( $reply->data['html'], '2 orders / 5 fish' ), json_encode( [ $reply->data['orders'], $reply->data['fish'] ] ) );
check( 'built recorded under the ship date (the 19th), not autoloaded', [ '2026-11-19' => [ 'delivery' => '2026-11-20', 'at' => '2026-11-19 09:12:00', 'orders' => 2, 'fish' => 5 ] ] === get_option( 'fishotel_ship_day_built' ) && false === $GLOBALS['fh_option_autoload']['fishotel_ship_day_built'], json_encode( get_option( 'fishotel_ship_day_built' ) ) );
$_POST = [ 'nonce' => 'nonce-fishotel_ship_day', 'delivery_date' => '2026-11-21', 'include_slips' => '0' ];
try {
    $host->ajax_get_ship_day();
} catch ( FH_Json $j ) {
    $reply = $j;
}
check( 'a date with no orders builds but records nothing', $reply->ok && 0 === $reply->data['orders'] && [ '2026-11-19' ] === array_keys( get_option( 'fishotel_ship_day_built' ) ) );
$_POST = [ 'nonce' => 'wrong', 'delivery_date' => '2026-11-24' ];
try {
    $host->ajax_get_ship_day();
} catch ( FH_Json $j ) {
    $reply = $j;
}
check( 'a bad nonce: refused, nothing recorded', ! $reply->ok && ! isset( get_option( 'fishotel_ship_day_built' )['2026-11-23'] ) );

section( 'built record helpers' );
$map = FisHotel_Ship_Day::built_map_with( [], '2026-11-20', '2026-11-19 08:00:00', 2, 5 );
$map = FisHotel_Ship_Day::built_map_with( $map, '2026-11-20', '2026-11-19 10:30:00', 3, 6 );
check( 'a rebuild of the same day replaces its record (latest time and counts)', [ '2026-11-19' => [ 'delivery' => '2026-11-20', 'at' => '2026-11-19 10:30:00', 'orders' => 3, 'fish' => 6 ] ] === $map );
$big = [];
for ( $d = 1; $d <= 20; $d++ ) {
    $big = FisHotel_Ship_Day::built_map_with( $big, sprintf( '2026-10-%02d', $d ), '2026-10-01 09:00:00', 1, 1 );
}
check( 'only the newest 14 ship dates are kept', 14 === count( $big ) && '2026-10-06' === array_key_first( $big ) && '2026-10-19' === array_key_last( $big ) );
check( 'junk in the option is dropped; a bad delivery date adds nothing', [] === FisHotel_Ship_Day::built_map_with( [ 'x' => 1, '2026-11-19' => 'y', '2026-11-18' => [ 'at' => '' ] ], 'nope', '2026-11-19 09:00:00', 1, 1 ) );
check( 'built_entry(): null for another day or a record without a time', null === FisHotel_Ship_Day::built_entry( $map, '2026-11-20' ) && null === FisHotel_Ship_Day::built_entry( [ '2026-11-19' => [ 'orders' => 1 ] ], '2026-11-19' ) );
check( 'time_label(): 9:12 am / 1:05 pm, junk → ""', '9:12 am' === FisHotel_Ship_Day::time_label( '2026-11-19 09:12:00' ) && '1:05 pm' === FisHotel_Ship_Day::time_label( '2026-11-19 13:05' ) && '' === FisHotel_Ship_Day::time_label( 'soon' ) );
check( 'delivery_date_for() is the inverse of ship_date_for(), across the DST change', '2026-11-02' === FisHotel_Ship_Day::delivery_date_for( '2026-11-01' ) && '2026-11-01' === FisHotel_Ship_Day::ship_date_for( '2026-11-02' ) && '2026-03-09' === FisHotel_Ship_Day::delivery_date_for( '2026-03-08' ) && '' === FisHotel_Ship_Day::delivery_date_for( 'x' ) );
check( 'today_ymd() is the site-zone date (11:30 pm Chicago is still the 19th)', '2026-11-19' === FisHotel_Ship_Day::today_ymd( new DateTimeImmutable( '2026-11-20 05:30:00', new DateTimeZone( 'UTC' ) ) ) );
check( 'page_url(): with and without a date', 'https://example.test/wp-admin/admin.php?page=fishotel-ship-day' === FisHotel_Ship_Day::page_url() && 'https://example.test/wp-admin/admin.php?page=fishotel-ship-day&date=2026-11-20' === FisHotel_Ship_Day::page_url( '2026-11-20' ) && FisHotel_Ship_Day::page_url() === FisHotel_Ship_Day::page_url( 'junk' ) );

/* ── The Today line ───────────────────────────────────────────────── */

section( 'Today line: its states' );
$url  = FisHotel_Ship_Day::page_url( '2026-11-20' );
$todo = FisHotel_Ship_Day_Today_Line::line( [ 'today' => '2026-11-19', 'delivery' => '2026-11-20', 'orders' => 6, 'fish' => 23, 'built' => null ], $url );
check( 'ship day, not built: "Ship Day: 6 orders · 23 fish go out today for Fri, Nov 20", attention tone', is_array( $todo ) && 'Ship Day: 6 orders · 23 fish go out today for Fri, Nov 20' === $todo['text'] && 'todo' === $todo['tone'] && 6 === $todo['count'], json_encode( $todo ) );
check( '... under Shipping, keyed ship-day, linking to Ship Day on that delivery date, button "Open Ship Day"', 'shipping' === $todo['group'] && 'ship-day' === $todo['key'] && $url === $todo['url'] && 'Open Ship Day' === $todo['button'] );
$one = FisHotel_Ship_Day_Today_Line::line( [ 'delivery' => '2026-11-20', 'orders' => 1, 'fish' => 1, 'built' => null ], $url );
check( 'one order reads "1 order · 1 fish"', 'Ship Day: 1 order · 1 fish go out today for Fri, Nov 20' === $one['text'] );
$built = FisHotel_Ship_Day_Today_Line::line( [ 'today' => '2026-11-19', 'delivery' => '2026-11-20', 'orders' => 0, 'fish' => 0, 'built' => [ 'delivery' => '2026-11-20', 'at' => '2026-11-19 09:12:00', 'orders' => 6, 'fish' => 23 ] ], $url );
check( 'built: calm "Ship Day built at 9:12 am — open again", what was built in the detail', is_array( $built ) && 'Ship Day built at 9:12 am — open again' === $built['text'] && 'done' === $built['tone'] && 'for Fri, Nov 20 · 6 orders · 23 fish' === $built['detail'] && 0 === $built['count'] && $url === $built['url'], json_encode( $built ) );
check( 'not a ship day: no line (Today\'s header already says "Nothing ships today. Next: …")', null === FisHotel_Ship_Day_Today_Line::line( [ 'today' => '2026-11-21', 'delivery' => '2026-11-22', 'orders' => 0, 'fish' => 0, 'built' => null ], $url ) );
$shape_ok = true;
foreach ( [ $todo, $built ] as $l ) {
    $shape_ok = $shape_ok && is_string( $l['text'] ) && strlen( $l['text'] ) <= 160 && strlen( $l['detail'] ) <= 160 && in_array( $l['tone'], [ 'todo', 'done', 'bad' ], true ) && in_array( $l['group'], [ 'fish-room', 'customers', 'shipping', 'site' ], true ) && is_int( $l['count'] ) && false === strpos( $l['text'], '<' );
}
check( 'the shape the Today section reads: text/detail plain and ≤160, a known tone and group, a whole count', $shape_ok );

section( 'Today line: state() — the ship-date rule, and no query once built' );
$GLOBALS['fh_options'] = [];
$GLOBALS['fh_wc_calls'] = 0;
$now   = new DateTimeImmutable( '2026-11-19 15:00:00', new DateTimeZone( 'America/Chicago' ) );
$state = FisHotel_Ship_Day_Today_Line::state( $now );
check( 'Thursday the 19th: delivery date is the 20th, 2 orders · 5 fish counted like the pick list', '2026-11-19' === $state['today'] && '2026-11-20' === $state['delivery'] && 2 === $state['orders'] && 5 === $state['fish'] && null === $state['built'] && $GLOBALS['fh_wc_calls'] > 0, json_encode( $state ) );
FisHotel_Ship_Day::record_built( '2026-11-24', 1, 1, '2026-11-19 08:00:00' );
check( 'a build for another day (ship the 23rd) does not count for today', null === FisHotel_Ship_Day_Today_Line::state( $now )['built'] );
check( '... and a build made days ahead of its ship date is not recorded at all', ! isset( get_option( 'fishotel_ship_day_built', [] )['2026-11-23'] ) && false === FisHotel_Ship_Day::record_built( '2026-11-24', 1, 1, '2026-11-19 08:00:00' ) );
$GLOBALS['fh_options']['fishotel_ship_day_built'] = [ '2026-11-19' => [ 'delivery' => '2026-11-20', 'at' => '2026-11-16 08:00:00', 'orders' => 1, 'fish' => 1 ] ];
check( 'an early record already in the option (opened Monday for Friday) does not silence the ship-day reminder', null === FisHotel_Ship_Day_Today_Line::state( $now )['built'] );
$GLOBALS['fh_options'] = [];
check( 'built_on_ship_day(): same day yes, another day or junk no', FisHotel_Ship_Day::built_on_ship_day( '2026-11-19 09:12:00', '2026-11-19' ) && ! FisHotel_Ship_Day::built_on_ship_day( '2026-11-18 23:59:00', '2026-11-19' ) && ! FisHotel_Ship_Day::built_on_ship_day( '', 'x' ) );
FisHotel_Ship_Day::record_built( '2026-11-20', 2, 5, '2026-11-19 09:12:00' );
$GLOBALS['fh_wc_calls'] = 0;
$state = FisHotel_Ship_Day_Today_Line::state( $now );
check( 'once today\'s list is built: the record, and no order query at all', is_array( $state['built'] ) && '2026-11-19 09:12:00' === $state['built']['at'] && 0 === $GLOBALS['fh_wc_calls'] );
$GLOBALS['fh_options'] = [];
$state = FisHotel_Ship_Day_Today_Line::state( new DateTimeImmutable( '2026-11-21 10:00:00', new DateTimeZone( 'America/Chicago' ) ) );
check( 'Saturday the 21st: nothing delivers on the 22nd → no line', null === FisHotel_Ship_Day_Today_Line::line( $state, '' ) );

section( 'Today line: the filter (capability, once per request, never doubled)' );
$calls = 0;
FisHotel_Ship_Day_Today_Line::use_source( function () use ( &$calls ) {
    $calls++;
    return [ 'today' => '2026-11-19', 'delivery' => '2026-11-20', 'orders' => 2, 'fish' => 5, 'built' => null ];
} );
$GLOBALS['fh_can'] = false;
check( 'a user without manage_options: the lines go through untouched, nothing worked out', [ [ 'key' => 'tanks' ] ] === FisHotel_Ship_Day_Today_Line::filter( [ [ 'key' => 'tanks' ] ] ) && 0 === $calls );
$GLOBALS['fh_can'] = true;
$lines = FisHotel_Ship_Day_Today_Line::filter( [ [ 'key' => 'tanks', 'text' => 'Tanks: all done' ] ] );
$lines = FisHotel_Ship_Day_Today_Line::filter( $lines ); // the page and the widget both ask
check( 'other lines kept, Ship Day added once (page + widget in one request)', 2 === count( $lines ) && 'tanks' === $lines[0]['key'] && 'ship-day' === $lines[1]['key'] && 1 === $calls, json_encode( $lines ) );
check( '... its link opens Ship Day on tomorrow\'s delivery date', 'https://example.test/wp-admin/admin.php?page=fishotel-ship-day&date=2026-11-20' === $lines[1]['url'] );
FisHotel_Ship_Day_Today_Line::use_source( function () {
    throw new RuntimeException( 'boom' );
} );
check( 'a failure adds nothing', [ [ 'key' => 'tanks' ] ] === FisHotel_Ship_Day_Today_Line::filter( [ [ 'key' => 'tanks' ] ] ) );
check( 'garbage in the filter is tolerated', [] === FisHotel_Ship_Day_Today_Line::filter( 'nope' ) );
FisHotel_Ship_Day_Today_Line::use_source( null );

section( 'Today line: hooks and the "Open Ship Day" button' );
$GLOBALS['fh_filters'] = [];
$GLOBALS['fh_actions'] = [];
FisHotel_Ship_Day_Today_Line::register();
check( 'hooks the Today section\'s fishotel_today_lines filter and admin_head — nothing else', [ [ 'FisHotel_Ship_Day_Today_Line', 'filter' ] ] === ( $GLOBALS['fh_filters']['fishotel_today_lines'] ?? null ) && [ [ 'FisHotel_Ship_Day_Today_Line', 'print_button_style' ] ] === ( $GLOBALS['fh_actions']['admin_head'] ?? null ) && 1 === count( $GLOBALS['fh_filters'] ) && 1 === count( $GLOBALS['fh_actions'] ) );
$main = (string) file_get_contents( dirname( __DIR__ ) . '/fishotel-batch-manager.php' );
check( 'the plugin registers the line in wp-admin only (never on the storefront)', 1 === preg_match( '/if \( is_admin\(\) \) \{\s*FisHotel_Ship_Day_Today_Line::register\(\);/', $main ) );
check( 'the page is registered after the Misc plugin\'s menu (admin_menu priority 20)', false !== strpos( $main, "add_action( 'admin_menu', [\$this, 'add_ship_day_menu'], 20 );" ) );
$css = FisHotel_Ship_Day_Today_Line::button_css();
check( 'button: drawn after Ship Day links inside a Today line (page and widget), labelled "Open Ship Day"', false !== strpos( $css, '.fh-today-line a[href*="page=fishotel-ship-day"]::after' ) && false !== strpos( $css, '.fh-tw-line a[href*="page=fishotel-ship-day"]::after' ) && false !== strpos( $css, 'content:"Open Ship Day"' ) );
$GLOBALS['fh_screen'] = (object) [ 'id' => 'dashboard' ];
check( 'printed on the dashboard (the widget)', false !== strpos( fh_capture( [ 'FisHotel_Ship_Day_Today_Line', 'print_button_style' ] ), 'Open Ship Day' ) );
$GLOBALS['fh_screen'] = (object) [ 'id' => 'fishotel-tools_page_fishotel-misc-today' ];
check( 'printed on the Today page', false !== strpos( fh_capture( [ 'FisHotel_Ship_Day_Today_Line', 'print_button_style' ] ), 'Open Ship Day' ) );
$GLOBALS['fh_screen'] = (object) [ 'id' => 'edit-shop_order' ];
check( 'not on other screens', '' === fh_capture( [ 'FisHotel_Ship_Day_Today_Line', 'print_button_style' ] ) );
$GLOBALS['fh_screen'] = (object) [ 'id' => 'dashboard' ];
$GLOBALS['fh_can']    = false;
check( 'not for a user who cannot open Ship Day', '' === fh_capture( [ 'FisHotel_Ship_Day_Today_Line', 'print_button_style' ] ) );
$GLOBALS['fh_can'] = true;

echo "\n{$passes} passed, {$failures} failed.\n";
exit( $failures > 0 ? 1 : 0 );
