<?php
/**
 * FisHotel_US_Dates — self-contained test.
 *
 *   php tests/us-dates-test.php
 *
 * Plain PHP, no PHPUnit, no WordPress. Covers the helper itself (showing a
 * stored date as MM-DD-YY, reading typed text, refusing impossible dates) and
 * the places in the plugin that use it: what a person reads is month-day-year,
 * what is saved or sent stays YYYY-MM-DD. Exits 1 on any failure.
 *
 * CLI only: the plugin zip ships this directory.
 */

if ( 'cli' !== PHP_SAPI ) {
    exit;
}

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

require_once dirname( __DIR__ ) . '/includes/class-us-dates.php';

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
function same( $label, $want, $got ) {
    check( $label, $want === $got, 'want ' . var_export( $want, true ) . ', got ' . var_export( $got, true ) );
}

$D = 'FisHotel_US_Dates';

/* ───────────── Showing a stored date ───────────── */
section( 'show(): a stored YYYY-MM-DD reads MM-DD-YY' );
same( '2026-11-27 → 11-27-26', '11-27-26', FisHotel_US_Dates::show( '2026-11-27' ) );
same( 'single-digit month and day keep their zero', '01-05-27', FisHotel_US_Dates::show( '2027-01-05' ) );
same( 'the year 2000', '02-29-00', FisHotel_US_Dates::show( '2000-02-29' ) );
same( 'the last day a two-digit year can name', '12-31-99', FisHotel_US_Dates::show( '2099-12-31' ) );
same( 'white space around it is ignored', '11-27-26', FisHotel_US_Dates::show( " 2026-11-27\n" ) );

section( 'show(): empty and odd values' );
same( 'empty → empty', '', FisHotel_US_Dates::show( '' ) );
same( 'null → empty', '', FisHotel_US_Dates::show( null ) );
same( 'false → empty', '', FisHotel_US_Dates::show( false ) );
same( 'not a date: shown as it is stored, never guessed', 'soon', FisHotel_US_Dates::show( 'soon' ) );
same( 'an impossible stored date is shown as it is stored', '2026-02-30', FisHotel_US_Dates::show( '2026-02-30' ) );
same( 'month 13 is shown as it is stored', '2026-13-01', FisHotel_US_Dates::show( '2026-13-01' ) );
same( 'a date already in US form is left alone', '11-27-26', FisHotel_US_Dates::show( '11-27-26' ) );

section( 'show(): a year outside 2000–2099 keeps all four digits' );
same( '1999 is not written as 99', '12-31-1999', FisHotel_US_Dates::show( '1999-12-31' ) );
same( '2100 is not written as 00', '01-01-2100', FisHotel_US_Dates::show( '2100-01-01' ) );

/* ───────────── Date-times ───────────── */
section( 'show_datetime(): the date part turns US, the time part stays' );
same( 'seconds kept', '11-27-26 14:03:22', FisHotel_US_Dates::show_datetime( '2026-11-27 14:03:22' ) );
same( 'minutes only', '11-27-26 14:03', FisHotel_US_Dates::show_datetime( '2026-11-27 14:03' ) );
same( 'a zone after the time stays', '10-17-26 14:03 EDT', FisHotel_US_Dates::show_datetime( '2026-10-17 14:03 EDT' ) );
same( 'the T of a form value becomes a space', '11-27-26 09:30', FisHotel_US_Dates::show_datetime( '2026-11-27T09:30' ) );
same( 'a date alone works too', '11-27-26', FisHotel_US_Dates::show_datetime( '2026-11-27' ) );
same( 'empty → empty', '', FisHotel_US_Dates::show_datetime( '' ) );
same( 'null → empty', '', FisHotel_US_Dates::show_datetime( null ) );
same( 'not a date-time: left as it is', 'never', FisHotel_US_Dates::show_datetime( 'never' ) );
same( 'an impossible date part: left as it is', '2026-02-30 10:00:00', FisHotel_US_Dates::show_datetime( '2026-02-30 10:00:00' ) );

section( 'show_datetime(): near midnight the day never moves' );
same( 'one second before midnight', '11-27-26 23:59:59', FisHotel_US_Dates::show_datetime( '2026-11-27 23:59:59' ) );
same( 'midnight itself', '11-28-26 00:00:00', FisHotel_US_Dates::show_datetime( '2026-11-28 00:00:00' ) );
same( 'New Year\'s Eve', '12-31-26 23:59:59', FisHotel_US_Dates::show_datetime( '2026-12-31 23:59:59' ) );
same( 'New Year', '01-01-27 00:00:00', FisHotel_US_Dates::show_datetime( '2027-01-01 00:00:00' ) );

/* ───────────── Reading typed text ───────────── */
section( 'parse(): the forms a person may type' );
same( 'MM-DD-YY', '2026-11-27', FisHotel_US_Dates::parse( '11-27-26' ) );
same( 'MM/DD/YY', '2026-11-27', FisHotel_US_Dates::parse( '11/27/26' ) );
same( 'MM-DD-YYYY', '2026-11-27', FisHotel_US_Dates::parse( '11-27-2026' ) );
same( 'MM/DD/YYYY', '2026-11-27', FisHotel_US_Dates::parse( '11/27/2026' ) );
same( 'M/D/YY', '2026-03-05', FisHotel_US_Dates::parse( '3/5/26' ) );
same( 'M-D-YY', '2026-03-05', FisHotel_US_Dates::parse( '3-5-26' ) );
same( 'M/D/YYYY', '2026-03-05', FisHotel_US_Dates::parse( '3/5/2026' ) );
same( 'YYYY-MM-DD still works', '2026-11-27', FisHotel_US_Dates::parse( '2026-11-27' ) );
same( 'white space around it is ignored', '2026-11-27', FisHotel_US_Dates::parse( "  11-27-26\t" ) );

section( 'parse(): a two-digit year means 20YY' );
same( '00 → 2000', '2000-01-01', FisHotel_US_Dates::parse( '01-01-00' ) );
same( '26 → 2026', '2026-01-01', FisHotel_US_Dates::parse( '01-01-26' ) );
same( '69 → 2069 (not 1969)', '2069-07-04', FisHotel_US_Dates::parse( '07-04-69' ) );
same( '70 → 2070 (not 1970)', '2070-07-04', FisHotel_US_Dates::parse( '07-04-70' ) );
same( '99 → 2099', '2099-12-31', FisHotel_US_Dates::parse( '12-31-99' ) );

section( 'parse(): month comes first, never day' );
same( '02-03-26 is February 3', '2026-02-03', FisHotel_US_Dates::parse( '02-03-26' ) );
same( '27-11-26 (day first) is refused, not swapped', null, FisHotel_US_Dates::parse( '27-11-26' ) );

section( 'parse(): empty' );
same( 'empty → empty (no date)', '', FisHotel_US_Dates::parse( '' ) );
same( 'spaces only → empty', '', FisHotel_US_Dates::parse( '   ' ) );
same( 'null → empty', '', FisHotel_US_Dates::parse( null ) );

section( 'parse(): impossible dates are refused, never changed' );
same( '02-30-26', null, FisHotel_US_Dates::parse( '02-30-26' ) );
same( 'month 13', null, FisHotel_US_Dates::parse( '13-01-26' ) );
same( 'month 0', null, FisHotel_US_Dates::parse( '00-10-26' ) );
same( 'day 0', null, FisHotel_US_Dates::parse( '10-00-26' ) );
same( 'day 32', null, FisHotel_US_Dates::parse( '01-32-26' ) );
same( 'April 31', null, FisHotel_US_Dates::parse( '04/31/2026' ) );
same( 'February 29 in a year that has none', null, FisHotel_US_Dates::parse( '02-29-26' ) );
same( 'February 29 in a leap year is fine', '2028-02-29', FisHotel_US_Dates::parse( '02-29-28' ) );
same( '2100 is not a leap year', null, FisHotel_US_Dates::parse( '02-29-2100' ) );
same( '2000 was a leap year', '2000-02-29', FisHotel_US_Dates::parse( '02-29-2000' ) );
same( 'year-first impossible date', null, FisHotel_US_Dates::parse( '2026-02-30' ) );
same( 'year-first month 13', null, FisHotel_US_Dates::parse( '2026-13-01' ) );
same( 'the year 0000', null, FisHotel_US_Dates::parse( '01-01-0000' ) );

section( 'parse(): text that is not a date is refused' );
foreach ( [ 'soon', '11-27', '11-27-126', '11-27-26 14:00', '11.27.26', 'Nov 27', '2026-11-27T00:00', '1-2-3', '112726', '11--27-26' ] as $junk ) {
    same( "'{$junk}'", null, FisHotel_US_Dates::parse( $junk ) );
}

section( 'refusal(): one plain sentence' );
same( 'an accepted date has no refusal', '', FisHotel_US_Dates::refusal( '11-27-26' ) );
same( 'empty has no refusal', '', FisHotel_US_Dates::refusal( '' ) );
$sentence = FisHotel_US_Dates::refusal( '02-30-26' );
same( 'the sentence names what was typed and shows the way', '"02-30-26" is not a real date — type it as month-day-year, like 11-27-26.', $sentence );
check( 'no year-first hint in the sentence', false === stripos( $sentence, 'YYYY' ) && false === strpos( $sentence, 'Y-m-d' ) );
same( 'the same sentence for month 13', '"13-01-26" is not a real date — type it as month-day-year, like 11-27-26.', FisHotel_US_Dates::refusal( '13-01-26' ) );

/* ───────────── is_ymd ───────────── */
section( 'is_ymd(): the stored shape' );
check( 'a real date', FisHotel_US_Dates::is_ymd( '2026-11-27' ) );
check( 'not without zeros', ! FisHotel_US_Dates::is_ymd( '2026-1-5' ) );
check( 'not US form', ! FisHotel_US_Dates::is_ymd( '11-27-26' ) );
check( 'not an impossible date', ! FisHotel_US_Dates::is_ymd( '2026-02-30' ) );
check( 'not empty', ! FisHotel_US_Dates::is_ymd( '' ) );
check( 'not an array', ! FisHotel_US_Dates::is_ymd( [ '2026-11-27' ] ) );

/* ───────────── A date is a calendar day ───────────── */
section( 'A date is a calendar day: no time zone can move it' );
$zones = [ 'UTC', 'America/Chicago', 'America/Los_Angeles', 'Pacific/Honolulu', 'Pacific/Kiritimati', 'Asia/Kolkata', 'Australia/Lord_Howe' ];
// Every day of 2026, plus the days the clocks change (Mar 8 and Nov 1, 2026 in the US).
$days = [];
$day  = new DateTimeImmutable( '2026-01-01', new DateTimeZone( 'UTC' ) );
for ( $i = 0; $i < 365; $i++ ) {
    $days[] = $day->modify( "+{$i} days" )->format( 'Y-m-d' );
}
$before = date_default_timezone_get();
foreach ( $zones as $zone ) {
    date_default_timezone_set( $zone );
    $bad = [];
    foreach ( $days as $ymd ) {
        $us = FisHotel_US_Dates::show( $ymd );
        if ( substr( $ymd, 5, 2 ) . '-' . substr( $ymd, 8, 2 ) . '-' . substr( $ymd, 2, 2 ) !== $us || FisHotel_US_Dates::parse( $us ) !== $ymd ) {
            $bad[] = $ymd . '→' . $us;
        }
    }
    check( "all 365 days of 2026 show and read back the same day with the server in {$zone}", [] === $bad, implode( ', ', array_slice( $bad, 0, 5 ) ) );
    same( "{$zone}: the day the clocks go forward", '03-08-26', FisHotel_US_Dates::show( '2026-03-08' ) );
    same( "{$zone}: the hour that does not exist that night is still that day", '03-08-26 02:30:00', FisHotel_US_Dates::show_datetime( '2026-03-08 02:30:00' ) );
    same( "{$zone}: the day the clocks go back", '11-01-26', FisHotel_US_Dates::show( '2026-11-01' ) );
    same( "{$zone}: the hour that happens twice that night is still that day", '11-01-26 01:30:00', FisHotel_US_Dates::show_datetime( '2026-11-01 01:30:00' ) );
    same( "{$zone}: 11:59 pm stays on its day", '03-07-26 23:59', FisHotel_US_Dates::show_datetime( '2026-03-07 23:59' ) );
    same( "{$zone}: typed 3/8/26 is March 8", '2026-03-08', FisHotel_US_Dates::parse( '3/8/26' ) );
    same( "{$zone}: typed 11/1/26 is November 1", '2026-11-01', FisHotel_US_Dates::parse( '11/1/26' ) );
}
date_default_timezone_set( $before );

section( 'Round trip: what show() writes, parse() reads back' );
foreach ( [ '2000-01-01', '2024-02-29', '2026-11-27', '2099-12-31', '1999-12-31', '2100-01-01', '1987-06-15' ] as $ymd ) {
    same( $ymd, $ymd, FisHotel_US_Dates::parse( FisHotel_US_Dates::show( $ymd ) ) );
}

section( 'The helper is pure: no clock, no time zone, no WordPress' );
$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-us-dates.php' );
// Comments and strings out, so only code is scanned.
$code = '';
foreach ( token_get_all( $src ) as $tok ) {
    if ( is_array( $tok ) && in_array( $tok[0], [ T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_INLINE_HTML ], true ) ) {
        continue;
    }
    $code .= is_array( $tok ) ? $tok[1] : $tok;
}
foreach ( [ 'strtotime', 'date', 'gmdate', 'mktime', 'time', 'wp_date', 'date_i18n', 'current_time', 'get_option', 'date_default_timezone_set', 'wp_timezone' ] as $banned ) {
    check( "no {$banned}() in the helper", 0 === preg_match( '/(?<![A-Za-z0-9_])' . $banned . '\s*\(/i', $code ) );
}
check( 'no DateTime object in the helper', 0 === preg_match( '/\bDateTime(Immutable|Zone|Interface)?\b/', $code ) );

/* ───────────── The plugin's screens use it; stored values do not change ───────────── */
section( 'Where the plugin shows a date (source scan)' );
$inc = dirname( __DIR__ ) . '/includes/';
$ship  = (string) file_get_contents( $inc . 'class-ship-day.php' );
$paper = (string) file_get_contents( $inc . 'class-ship-day-paperwork.php' );
check( 'Ship Day: no "(Y-m-d)" in a message a person reads', false === strpos( $ship, "date (Y-m-d).'" ) && false === strpos( $paper, "date (Y-m-d).'" ) );
check( 'Ship Day: the plain sentence is there', false !== strpos( $ship, "'Pick a valid delivery date.'" ) && false !== strpos( $paper, "'Pick a valid delivery date.'" ) );

$admin = (string) file_get_contents( $inc . 'class-admin.php' );
check( 'Blocked Dates: the row just added reads through the JS helper', false !== strpos( $admin, "fhUsDate(d) + ' (pending save)'" ) );
check( 'Blocked Dates: the value that is saved is still the calendar\'s own', false !== strpos( $admin, 'addVal.value = d;' ) && false !== strpos( $admin, "label.setAttribute('data-date', d);" ) );
check( 'Fish requests: the Date column goes through the helper', false !== strpos( $admin, 'FisHotel_US_Dates::show_datetime( $req->post_date )' ) );
check( 'Fish requests: no raw post_date printed', false === strpos( $admin, 'esc_html( $req->post_date )' ) );
check( 'batch export file name keeps the year-first date', false !== strpos( $admin, "gmdate( 'Y-m-d' ) . '.xlsx'" ) );

$ns = (string) file_get_contents( $inc . 'class-northstar.php' );
check( 'North Star: "Last fetched" goes through the helper', false !== strpos( $ns, "'Last fetched: ' . esc_html( FisHotel_US_Dates::show_datetime( \$fetched_at ) )" ) );
check( 'North Star: after a fetch the script shows it through the JS helper', false !== strpos( $ns, "'Last fetched: ' + fhUsDateTime(res.data.fetched_at)" ) );
check( 'North Star: the stored and sent time is still the database form', 2 === substr_count( $ns, "\$fetched_at = current_time( 'mysql' );" ) );

section( 'Nowhere is a date typed into a pop-up question or a text box' );
$own = array_merge(
    glob( $inc . '*.php' ),
    glob( dirname( __DIR__ ) . '/assists/*.js' ),
    glob( dirname( __DIR__ ) . '/assists/*/*.js' )
);
$prompts = [];
$typed   = [];
foreach ( $own as $file ) {
    if ( false !== strpos( $file, '/vendor/' ) ) {
        continue;
    }
    foreach ( explode( "\n", (string) file_get_contents( $file ) ) as $n => $line ) {
        if ( preg_match( '/(?<![A-Za-z0-9_$.>])(?:window\.)?prompt\s*\(/', $line ) ) {
            $prompts[] = basename( $file ) . ':' . ( $n + 1 );
        }
        if ( preg_match( '/placeholder="[^"]*(YYYY|yyyy|MM-DD|mm\/dd)/', $line ) ) {
            $typed[] = basename( $file ) . ':' . ( $n + 1 );
        }
    }
}
check( 'no browser prompt() in the plugin\'s own code', [] === $prompts, implode( ', ', $prompts ) );
check( 'no text box asking for a date by its pattern', [] === $typed, implode( ', ', $typed ) );
check( 'every date field of the wp-admin screens is a calendar the dark screens can show',
    6 === preg_match_all( '/<input type="(?:date|time|datetime-local)"[^\n]*background:#2a2a2a;color-scheme:dark;/', $admin ),
    'the calendar button is drawn dark on dark without color-scheme:dark'
);

/* ───────────── The order screen, run for real ───────────── */
section( 'Order screen → FisHotel Delivery Date: what is saved and what is read' );

// Stand-in WordPress, just enough for the two methods of the real trait.
$GLOBALS['fh_us_options'] = [
    'fishotel_shipping_days' => [ 'Tuesday', 'Wednesday', 'Thursday', 'Friday' ],
];
function get_option( $name, $default = false ) {
    return array_key_exists( $name, $GLOBALS['fh_us_options'] ) ? $GLOBALS['fh_us_options'][ $name ] : $default;
}
function apply_filters( $tag, $value ) { return $value; }
function add_action() {}
function add_filter() {}
function wp_timezone() { return new DateTimeZone( 'America/Chicago' ); }
function wp_nonce_field() {}
function wp_verify_nonce( $nonce, $action ) { return 'ok' === $nonce; }
function current_user_can( $cap ) { return true; }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }

/** A stand-in WC_Order: meta, notes and a count of saves. */
class FH_US_Order {
    public $meta  = [];
    public $notes = [];
    public $saves = 0;
    public function get_id() { return 501; }
    public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
    public function meta_exists( $key ) { return array_key_exists( $key, $this->meta ); }
    public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
    public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
    public function save() { $this->saves++; }
    public function add_order_note( $note ) { $this->notes[] = $note; }
}

require_once dirname( __DIR__ ) . '/includes/class-delivery-rules.php';
require_once dirname( __DIR__ ) . '/includes/class-order-meta.php';
require_once dirname( __DIR__ ) . '/includes/class-selling-price.php';
require_once dirname( __DIR__ ) . '/includes/class-woocommerce.php';

class FH_US_Host {
    use FisHotel_WooCommerce;
    public function note_rule_break( $order, $date, $context, $already_accepted = false ) {
        $this->fishotel_note_shipping_date_rule_break( $order, $date, $context, $already_accepted );
    }
}
$host = new FH_US_Host();
$save = function ( $order, $posted ) use ( $host ) {
    $_POST = [ 'fishotel_shipping_date_nonce' => 'ok', 'fishotel_shipping_date_admin' => $posted ];
    $host->fishotel_shipping_date_admin_save( 501, $order );
    $_POST = [];
};
$sunday_why = fishotel_delivery_rules( true )->why_not( '2026-11-29', null, false );
check( 'the test\'s Sunday really breaks a rule', FisHotel_Delivery_Rules::CODE_OK !== $sunday_why['code'] && '' !== $sunday_why['message'], json_encode( $sunday_why ) );

// A good day: the calendar posts YYYY-MM-DD and exactly that is stored.
$order = new FH_US_Order();
$save( $order, '2026-12-01' );
same( 'a picked day is stored exactly as the calendar posts it', '2026-12-01', $order->meta['_fishotel_shipping_date'] ?? null );
same( 'no note for a day that breaks no rule', [], $order->notes );

// A day that breaks a rule: still stored year-first, the note a person reads is month-day-year.
$save( $order, '2026-11-29' );
same( 'a rule-breaking day is still stored year-first', '2026-11-29', $order->meta['_fishotel_shipping_date'] ?? null );
same( 'one note', 1, count( $order->notes ) );
same(
    'the note reads month-day-year',
    'FISHOTEL NOTICE (admin override): delivery date set to 11-29-26, which breaks a delivery rule — ' . $sunday_why['message'] . ' Saved as entered.',
    $order->notes[0] ?? ''
);
check( 'no year-first date in the note', 0 === preg_match( '/\d{4}-\d{2}-\d{2}/', $order->notes[0] ?? '' ) );

// The field as drawn: a calendar, its value the stored date.
ob_start();
$host->fishotel_shipping_date_display( $order );
$html = (string) ob_get_clean();
check( 'the field is a calendar (type="date")', false !== strpos( $html, '<input type="date" id="fishotel_shipping_date_admin" name="fishotel_shipping_date_admin"' ) );
check( 'its value is the stored YYYY-MM-DD', false !== strpos( $html, 'value="2026-11-29"' ) );
check( 'the line under it is the date in words', false !== strpos( $html, 'Sunday, November 29, 2026' ) );

// Empty still means what it meant: the date is removed.
$save( $order, '' );
check( 'an emptied field still removes the date', ! array_key_exists( '_fishotel_shipping_date', $order->meta ) );

// Something that is not a calendar value is still not saved (unchanged), and nothing is guessed.
$order->meta['_fishotel_shipping_date'] = '2026-12-01';
$save( $order, '12-02-26' );
same( 'text that is not a calendar value leaves the stored date alone', '2026-12-01', $order->meta['_fishotel_shipping_date'] );

// A stored value that is not a date: drawn as stored, never as an invented date.
$odd = new FH_US_Order();
$odd->meta['_fishotel_shipping_date'] = '2026-02-30';
ob_start();
$host->fishotel_shipping_date_display( $odd );
$html = (string) ob_get_clean();
check( 'an impossible stored date is shown as stored under the field', false !== strpos( $html, '>2026-02-30</span>' ), $html );

section( 'Order note at checkout / status change' );
$order = new FH_US_Order();
$host->note_rule_break( $order, '2026-11-29', 'checkout' );
same(
    'the note reads month-day-year',
    'FISHOTEL NOTICE (checkout): delivery date 11-29-26 breaks a delivery rule — ' . fishotel_delivery_rules()->why_not( '2026-11-29', null, true )['message'] . ' Left as-is; review before packing.',
    $order->notes[0] ?? ''
);
same( 'the order itself is not touched', [], $order->meta );
$order = new FH_US_Order();
$host->note_rule_break( $order, 'soon', 'checkout' );
check( 'a stored value that is not a date is named as it is stored', 1 === count( $order->notes ) && 0 === strpos( $order->notes[0], 'FISHOTEL NOTICE (checkout): delivery date soon breaks a delivery rule — ' ), $order->notes[0] ?? '' );

section( 'Ship Day: what is sent and what files are called stays year-first' );
require_once dirname( __DIR__ ) . '/includes/class-ship-day.php';
require_once dirname( __DIR__ ) . '/includes/class-ship-day-paperwork.php';
same( 'the Print Watcher file names keep the year-first ship date', [ 'brother' => 'BROTHER_shipday_2026-11-26.pdf', 'rx' => 'RX106HD_shipday_2026-11-26.pdf' ], FisHotel_Ship_Day_Paperwork::file_names( '2026-11-27' ) );
check( 'a month-day-year date is not a delivery date to Ship Day (the page sends the calendar value)', ! FisHotel_Ship_Day::is_ymd( '11-27-26' ) && FisHotel_Ship_Day::is_ymd( '2026-11-27' ) );
$js = (string) file_get_contents( dirname( __DIR__ ) . '/assists/ship-day/paperwork.js' );
check( 'paperwork.js sends the calendar field\'s own value', false !== strpos( $js, "var date = dateEl ? dateEl.value : '';" ) && false !== strpos( $js, "fd.append('delivery_date', date);" ) );

echo "\n{$passes} passed, {$failures} failed.\n";
exit( $failures > 0 ? 1 : 0 );
