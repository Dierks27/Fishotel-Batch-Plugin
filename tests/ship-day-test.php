<?php
/**
 * FisHotel_Ship_Day — self-contained test of the pure helpers.
 *
 *   php tests/ship-day-test.php
 *
 * Plain PHP, no PHPUnit, no WordPress. Stub functions are defined only when
 * WordPress is absent, so the file also runs inside a WP bootstrap (WP-CLI).
 * Exits 1 on any failure, 0 when everything passes.
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
        private $code;
        private $message;
        public function __construct( $code = '', $message = '' ) {
            $this->code    = $code;
            $this->message = $message;
        }
        public function get_error_message() {
            return $this->message;
        }
        public function get_error_code() {
            return $this->code;
        }
    }
}

require_once dirname( __DIR__ ) . '/includes/class-ship-day.php';

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
function line( $fish, $qty, $price, $flag = '' ) {
    return [ 'fish' => $fish, 'qty' => $qty, 'price' => $price, 'flag' => $flag ];
}

$chicago = new DateTimeZone( 'America/Chicago' );

/* ───────────────────────────── parse_invoice_lines ───────────────────────────── */
section( 'parse_invoice_lines(): the skill\'s example' );
$got = FisHotel_Ship_Day::parse_invoice_lines( '<ul><li>Hectors Goby x1 — $39</li></ul>' );
check( 'one row', 1 === count( $got ), json_encode( $got ) );
check( 'fish = Hectors Goby, qty 1, price 39.0, no flag', [ line( 'Hectors Goby', 1, 39.0 ) ] === $got, json_encode( $got ) );
check( 'price is a float', isset( $got[0]['price'] ) && is_float( $got[0]['price'] ) );

section( 'parse_invoice_lines(): multi-line list' );
$html = "<ul>\n<li>Fish A x1 — \$40</li>\n<li>Fish B x2 — \$90</li>\n<li>Yellow Tang x3 — \$1,250.50</li>\n</ul>";
$got  = FisHotel_Ship_Day::parse_invoice_lines( $html );
check( 'three rows in order', [ line( 'Fish A', 1, 40.0 ), line( 'Fish B', 2, 90.0 ), line( 'Yellow Tang', 3, 1250.5 ) ] === $got, json_encode( $got ) );

section( 'parse_invoice_lines(): Credit lines and negative amounts are not fish' );
$html = '<ul><li>Lubbock\'s Fairy Wrasse x1 — $89</li><li>Credit: lost Cleaner Wrasse — -$82</li><li>credit for DOA — $-10</li><li>Refund x1 — −$5</li></ul>';
$got  = FisHotel_Ship_Day::parse_invoice_lines( $html );
check( 'only the fish line survives', [ line( "Lubbock's Fairy Wrasse", 1, 89.0 ) ] === $got, json_encode( $got ) );

section( 'parse_invoice_lines(): dash variants, ×, spacing' );
check( 'em dash —', [ line( 'Goby', 1, 39.0 ) ] === FisHotel_Ship_Day::parse_invoice_lines( '<li>Goby x1 — $39</li>' ) );
check( 'en dash –', [ line( 'Goby', 1, 39.0 ) ] === FisHotel_Ship_Day::parse_invoice_lines( '<li>Goby x1 – $39</li>' ) );
check( 'hyphen -', [ line( 'Goby', 1, 39.0 ) ] === FisHotel_Ship_Day::parse_invoice_lines( '<li>Goby x1 - $39</li>' ) );
check( 'hyphen with no spaces', [ line( 'Goby', 1, 39.0 ) ] === FisHotel_Ship_Day::parse_invoice_lines( '<li>Goby x1-$39</li>' ), json_encode( FisHotel_Ship_Day::parse_invoice_lines( '<li>Goby x1-$39</li>' ) ) );
check( 'hyphen touching $ is a separator', [ line( 'Goby', 1, 39.0 ) ] === FisHotel_Ship_Day::parse_invoice_lines( '<li>Goby x1 -$39</li>' ), json_encode( FisHotel_Ship_Day::parse_invoice_lines( '<li>Goby x1 -$39</li>' ) ) );
check( 'negative amounts still never parse as fish', [] === FisHotel_Ship_Day::parse_invoice_lines( '<ul><li>Lost wrasse x1 — -$82</li><li>DOA x1 — $-10</li><li>Refund x1 — −$5</li><li>-$20</li></ul>' ), json_encode( FisHotel_Ship_Day::parse_invoice_lines( '<ul><li>Lost wrasse x1 — -$82</li><li>DOA x1 — $-10</li><li>Refund x1 — −$5</li><li>-$20</li></ul>' ) ) );
check( 'multiplication sign ×', [ line( 'Goby', 2, 78.0 ) ] === FisHotel_Ship_Day::parse_invoice_lines( '<li>Goby ×2 — $78</li>' ) );
check( 'uppercase X', [ line( 'Goby', 2, 78.0 ) ] === FisHotel_Ship_Day::parse_invoice_lines( '<li>Goby X2 — $78</li>' ) );
check( 'spaces around x and $', [ line( 'Goby', 3, 117.0 ) ] === FisHotel_Ship_Day::parse_invoice_lines( '<li>Goby x 3 — $ 117</li>' ) );
check( 'a fish whose name contains an x (Six Line Wrasse)', [ line( 'Six Line Wrasse', 1, 25.0 ) ] === FisHotel_Ship_Day::parse_invoice_lines( '<li>Six Line Wrasse x1 — $25</li>' ) );
check( 'a fish name ending in x (Paddlefish Max)', [ line( 'Paddlefish Max', 2, 10.0 ) ] === FisHotel_Ship_Day::parse_invoice_lines( '<li>Paddlefish Max x2 — $10</li>' ), json_encode( FisHotel_Ship_Day::parse_invoice_lines( '<li>Paddlefish Max x2 — $10</li>' ) ) );

section( 'parse_invoice_lines(): missing price' );
check( 'no dash, no price → price null', [ line( 'Goby', 1, null ) ] === FisHotel_Ship_Day::parse_invoice_lines( '<li>Goby x1</li>' ) );
check( 'dash but no price → price null', [ line( 'Goby', 4, null ) ] === FisHotel_Ship_Day::parse_invoice_lines( '<li>Goby x4 —</li>' ) );

section( 'parse_invoice_lines(): nested tags and HTML entities' );
$html = '<ul><li><strong>Hectors&nbsp;Goby</strong> x2 &#8212; <span>$78</span></li><li><em>Yellow&nbsp;Tang</em>&nbsp;x1&nbsp;&ndash;&nbsp;$120</li></ul>';
$got  = FisHotel_Ship_Day::parse_invoice_lines( $html );
check( '&nbsp; / &#8212; / &ndash; decoded, tags stripped', [ line( 'Hectors Goby', 2, 78.0 ), line( 'Yellow Tang', 1, 120.0 ) ] === $got, json_encode( $got ) );
check( '<li> attributes are tolerated', [ line( 'Goby', 1, 39.0 ) ] === FisHotel_Ship_Day::parse_invoice_lines( '<ol><li class="fh">Goby x1 — $39</li></ol>' ) );

section( 'parse_invoice_lines(): no list — plain text lines' );
$got = FisHotel_Ship_Day::parse_invoice_lines( "Hectors Goby x1 — \$39\r\n\r\nYellow Tang x2 — \$120\n" );
check( 'each non-empty text line is a row', [ line( 'Hectors Goby', 1, 39.0 ), line( 'Yellow Tang', 2, 120.0 ) ] === $got, json_encode( $got ) );
$got = FisHotel_Ship_Day::parse_invoice_lines( '<p>Hectors Goby x1 — $39<br>Yellow Tang x2 — $120</p><p>Credit: DOA — -$20</p>' );
check( '<br> and </p> break lines; credit dropped', [ line( 'Hectors Goby', 1, 39.0 ), line( 'Yellow Tang', 2, 120.0 ) ] === $got, json_encode( $got ) );

section( 'parse_invoice_lines(): unparseable lines and empty input' );
$got = FisHotel_Ship_Day::parse_invoice_lines( '<ul><li>Pair of Clownfish (bonded)</li><li>Goby x1 — $39</li></ul>' );
check( 'a line without the pattern → whole text, qty 1, flag check', [ line( 'Pair of Clownfish (bonded)', 1, null, 'check' ), line( 'Goby', 1, 39.0 ) ] === $got, json_encode( $got ) );
check( 'empty description → []', [] === FisHotel_Ship_Day::parse_invoice_lines( '' ) && [] === FisHotel_Ship_Day::parse_invoice_lines( "  \n " ) );
check( 'empty list → []', [] === FisHotel_Ship_Day::parse_invoice_lines( '<ul><li></li><li>&nbsp;</li></ul>' ) );
check( 'non-string input is tolerated', [] === FisHotel_Ship_Day::parse_invoice_lines( null ) );

/* ───────────────────────────── classify ───────────────────────────── */
section( 'classify(): category slugs + name' );
check( 'quarantined-fish → fish', 'fish' === FisHotel_Ship_Day::classify( [ 'quarantined-fish' ], 'Hectors Goby' ) );
check( 'invoices category → invoice', 'invoice' === FisHotel_Ship_Day::classify( [ 'invoices' ], 'JPReef Order (7/3/26)' ) );
check( 'name with Invoice → invoice even without the category', 'invoice' === FisHotel_Ship_Day::classify( [], 'JPReef Invoice (7/3/26)' ) );
check( 'invoice beats fish when both apply', 'invoice' === FisHotel_Ship_Day::classify( [ 'quarantined-fish', 'invoices' ], 'Combo' ) );
check( 'medications → dry goods', 'dry' === FisHotel_Ship_Day::classify( [ 'medications' ], 'PraziPro 4oz' ) );
check( 'no category, plain name → dry goods', 'dry' === FisHotel_Ship_Day::classify( [], 'FisHotel Sticker' ) );
check( '\\bInvoice\\b is a whole word (Invoicer is not an invoice)', 'dry' === FisHotel_Ship_Day::classify( [], 'Invoicer Software' ) );
check( 'Invoice match is case-insensitive', 'invoice' === FisHotel_Ship_Day::classify( [], 'jpreef INVOICE' ) );
check( 'variation: the parent\'s slugs decide (caller passes the parent categories)', 'fish' === FisHotel_Ship_Day::classify( [ 'quarantined-fish' ], 'Yellow Tang - Medium' ) );
check( 'slugs compare case-insensitively and trimmed', 'fish' === FisHotel_Ship_Day::classify( [ ' Quarantined-Fish ' ], 'Goby' ) );
check( 'inverts are livestock by default (matches the checkout gates)', 'fish' === FisHotel_Ship_Day::classify( [ 'inverts' ], 'Cleaner Shrimp' ) );
check( 'explicit fish category list overrides (narrowing)', 'fish' === FisHotel_Ship_Day::classify( [ 'inverts' ], 'Cleaner Shrimp', [ 'quarantined-fish', 'inverts' ] ) && 'dry' === FisHotel_Ship_Day::classify( [ 'inverts' ], 'Cleaner Shrimp', [ 'quarantined-fish' ] ) );
if ( $fh_stubbed ) {
    add_filter( 'fishotel_ship_day_fish_categories', function ( $cats ) { $cats[] = 'inverts'; return $cats; } );
    check( 'fishotel_ship_day_fish_categories filter adds a category', 'fish' === FisHotel_Ship_Day::classify( [ 'inverts' ], 'Cleaner Shrimp' ) );
    check( 'fish_categories() is de-duplicated', [ 'quarantined-fish', 'inverts' ] === FisHotel_Ship_Day::fish_categories() );
    add_filter( 'fishotel_ship_day_statuses', function ( $s ) { $s[] = 'wc-shipped'; $s[] = 'processing'; return $s; } );
    check( 'statuses() strips wc- and de-duplicates', [ 'processing', 'on-hold', 'fulfillment', 'shipped' ] === FisHotel_Ship_Day::statuses() );
}

/* ───────────────────────────── weather_flag ───────────────────────────── */
section( 'weather_flag(): every action' );
function wmeta( $action, $extra = [] ) {
    return $extra + [
        '_fh_weather_checked_for' => '2026-10-13',
        '_fh_weather_action'      => $action,
        '_fh_weather_high'        => '76',
        '_fh_weather_low'         => '57',
        '_fh_weather_at_line'     => '',
        '_fh_weather_summary'     => 'High 76°F / Low 57°F — Sunny, 10% chance of rain',
        '_fh_weather_note'        => 'Weather for October 13, 2026 at 23314 (Carrollton, VA): High 76°F / Low 57°F — Sunny, 10% chance of rain. Nothing needed — great shipping conditions.',
    ];
}
$d = '2026-10-13';
check( 'heat_pack → (HEAT PACK)', '(HEAT PACK)' === FisHotel_Ship_Day::weather_flag( wmeta( 'heat_pack' ), $d ) );
check( 'none → empty', '' === FisHotel_Ship_Day::weather_flag( wmeta( 'none' ), $d ) );
check( 'cooling → (COOLING PACKS)', '(COOLING PACKS)' === FisHotel_Ship_Day::weather_flag( wmeta( 'cooling' ), $d ) );
check( 'ups_store_cold → (UPS STORE)', '(UPS STORE)' === FisHotel_Ship_Day::weather_flag( wmeta( 'ups_store_cold' ), $d ) );
check( 'ups_store_hot → (UPS STORE)', '(UPS STORE)' === FisHotel_Ship_Day::weather_flag( wmeta( 'ups_store_hot' ), $d ) );
check( 'hold_cold → (HOLD)', '(HOLD)' === FisHotel_Ship_Day::weather_flag( wmeta( 'hold_cold' ), $d ) );
check( 'hold_hot → (HOLD)', '(HOLD)' === FisHotel_Ship_Day::weather_flag( wmeta( 'hold_hot' ), $d ) );
check( 'at_line → (AT THE LINE — Jeff\'s call) whatever the band', '(AT THE LINE — Jeff\'s call)' === FisHotel_Ship_Day::weather_flag( wmeta( 'cooling', [ '_fh_weather_at_line' => '1' ] ), $d ) );
check( 'unavailable → (WEATHER UNAVAILABLE)', '(WEATHER UNAVAILABLE)' === FisHotel_Ship_Day::weather_flag( [ '_fh_weather_action' => 'unavailable', '_fh_weather_unavailable_for' => $d, '_fh_weather_summary' => 'api.weather.gov returned HTTP 503', '_fh_weather_high' => '', '_fh_weather_low' => '' ], $d ) );
check( 'unavailable for ANOTHER date → (NOT CHECKED)', '(NOT CHECKED)' === FisHotel_Ship_Day::weather_flag( [ '_fh_weather_action' => 'unavailable', '_fh_weather_unavailable_for' => '2026-10-06' ], $d ) );
check( 'no meta at all → (NOT CHECKED)', '(NOT CHECKED)' === FisHotel_Ship_Day::weather_flag( [], $d ) );
check( 'stale checked_for (another date) → (NOT CHECKED)', '(NOT CHECKED)' === FisHotel_Ship_Day::weather_flag( wmeta( 'heat_pack', [ '_fh_weather_checked_for' => '2026-10-06' ] ), $d ) );
check( 'unknown action string → (NOT CHECKED)', '(NOT CHECKED)' === FisHotel_Ship_Day::weather_flag( wmeta( 'banana' ), $d ) );
check( 'delivery date can ride in the meta array', '(HEAT PACK)' === FisHotel_Ship_Day::weather_flag( wmeta( 'heat_pack', [ '_fishotel_shipping_date' => $d ] ) ) );

section( 'weather_flag(): alerts appended from the note' );
$note = 'Weather for October 13, 2026 at 23314 (Carrollton, VA): High 76°F / Low 57°F — Rain. Alerts: Flood Watch, Wind Advisory. Nothing needed — great shipping conditions.';
check( 'parse_alerts() → uppercase list', [ 'FLOOD WATCH', 'WIND ADVISORY' ] === FisHotel_Ship_Day::parse_alerts( $note ) );
check( 'none + alerts → just the alerts', '(FLOOD WATCH) (WIND ADVISORY)' === FisHotel_Ship_Day::weather_flag( wmeta( 'none', [ '_fh_weather_note' => $note ] ), $d ) );
check( 'heat pack + alerts', '(HEAT PACK) (FLOOD WATCH) (WIND ADVISORY)' === FisHotel_Ship_Day::weather_flag( wmeta( 'heat_pack', [ '_fh_weather_note' => $note ] ), $d ) );
check( 'at the line + alert', '(AT THE LINE — Jeff\'s call) (FLOOD WATCH)' === FisHotel_Ship_Day::weather_flag( wmeta( 'cooling', [ '_fh_weather_at_line' => '1', '_fh_weather_note' => 'High 90°F — Sunny. Alerts: Flood Watch. AT THE LINE (90°F) — Jeff\'s call.' ] ), $d ) );
check( 'no Alerts sentence → no alert flags', [] === FisHotel_Ship_Day::parse_alerts( 'High 76°F — Sunny. Nothing needed.' ) );
check( 'alerts are not read when the note is stale', '(NOT CHECKED)' === FisHotel_Ship_Day::weather_flag( wmeta( 'none', [ '_fh_weather_note' => $note, '_fh_weather_checked_for' => '2026-10-06' ] ), $d ) );

section( 'weather_state(): numbers, conditions, next-day line' );
$s = FisHotel_Ship_Day::weather_state( wmeta( 'cooling', [ '_fh_weather_high' => '84', '_fh_weather_low' => '66', '_fh_weather_summary' => 'High 84°F / Low 66°F — Mostly Sunny, 20% chance of rain', '_fh_weather_note' => 'Weather … Cooling packs. Thu hits 98°F — do not slip delivery.' ] ), $d );
check( 'status checked, high/low ints', 'checked' === $s['status'] && 84 === $s['high'] && 66 === $s['low'] );
check( 'conditions = text after the dash', 'Mostly Sunny, 20% chance of rain' === $s['conditions'], $s['conditions'] );
check( 'next-day extreme sentence extracted', 'Thu hits 98°F — do not slip delivery.' === $s['next_day'], $s['next_day'] );
check( 'has_meta true', true === $s['has_meta'] );
$s = FisHotel_Ship_Day::weather_state( wmeta( 'heat_pack', [ '_fh_weather_low' => '' ] ), $d );
check( 'empty low → null', null === $s['low'] && 76 === $s['high'] );
check( 'has_meta false when nothing stored', false === FisHotel_Ship_Day::weather_state( [], $d )['has_meta'] );
check( 'action_label()', 'UPS Store (hot)' === FisHotel_Ship_Day::action_label( FisHotel_Ship_Day::weather_state( wmeta( 'ups_store_hot' ), $d ) ) && 'AT THE LINE · Cooling packs' === FisHotel_Ship_Day::action_label( FisHotel_Ship_Day::weather_state( wmeta( 'cooling', [ '_fh_weather_at_line' => '1' ] ), $d ) ) && 'Not checked' === FisHotel_Ship_Day::action_label( FisHotel_Ship_Day::weather_state( [], $d ) ) );
check( 'row_tone()', 'green' === FisHotel_Ship_Day::row_tone( FisHotel_Ship_Day::weather_state( wmeta( 'none' ), $d ) ) && 'blue' === FisHotel_Ship_Day::row_tone( FisHotel_Ship_Day::weather_state( wmeta( 'heat_pack' ), $d ) ) && 'orange' === FisHotel_Ship_Day::row_tone( FisHotel_Ship_Day::weather_state( wmeta( 'cooling' ), $d ) ) && 'amber' === FisHotel_Ship_Day::row_tone( FisHotel_Ship_Day::weather_state( wmeta( 'cooling', [ '_fh_weather_at_line' => '1' ] ), $d ) ) && 'red' === FisHotel_Ship_Day::row_tone( FisHotel_Ship_Day::weather_state( wmeta( 'hold_hot' ), $d ) ) && 'red' === FisHotel_Ship_Day::row_tone( FisHotel_Ship_Day::weather_state( wmeta( 'ups_store_cold' ), $d ) ) && 'grey' === FisHotel_Ship_Day::row_tone( FisHotel_Ship_Day::weather_state( [], $d ) ) );

/* ───────────────────────────── ship_date_for ───────────────────────────── */
section( 'ship_date_for(): delivery minus one day, in the site zone, across DST' );
check( '2026-03-09 → 2026-03-08 (US DST starts 03-08)', '2026-03-08' === FisHotel_Ship_Day::ship_date_for( '2026-03-09', $chicago ), FisHotel_Ship_Day::ship_date_for( '2026-03-09', $chicago ) );
check( '2026-03-08 → 2026-03-07', '2026-03-07' === FisHotel_Ship_Day::ship_date_for( '2026-03-08', $chicago ) );
check( '2026-11-02 → 2026-11-01 (DST ended 11-01)', '2026-11-01' === FisHotel_Ship_Day::ship_date_for( '2026-11-02', $chicago ) );
check( '2026-11-01 → 2026-10-31', '2026-10-31' === FisHotel_Ship_Day::ship_date_for( '2026-11-01', $chicago ) );
check( 'month and year boundaries', '2026-09-30' === FisHotel_Ship_Day::ship_date_for( '2026-10-01', $chicago ) && '2025-12-31' === FisHotel_Ship_Day::ship_date_for( '2026-01-01', $chicago ) );
check( 'default zone (wp_timezone stub = Chicago) agrees', '2026-03-08' === FisHotel_Ship_Day::ship_date_for( '2026-03-09' ) );
check( 'invalid dates → empty', '' === FisHotel_Ship_Day::ship_date_for( '2026-02-30' ) && '' === FisHotel_Ship_Day::ship_date_for( 'nope' ) && '' === FisHotel_Ship_Day::ship_date_for( '' ) );
check( 'label()', 'Tuesday, October 13' === FisHotel_Ship_Day::label( '2026-10-13', 'l, F j', $chicago ) && 'Tue, Oct 13' === FisHotel_Ship_Day::label( '2026-10-13', 'D, M j', $chicago ) && '' === FisHotel_Ship_Day::label( 'x' ) );
check( 'is_ymd()', FisHotel_Ship_Day::is_ymd( '2026-10-13' ) && ! FisHotel_Ship_Day::is_ymd( '2026-13-01' ) && ! FisHotel_Ship_Day::is_ymd( '2026-10-1' ) );

/* ───────────────────────────── sort_weather_rows ───────────────────────────── */
section( 'sort_weather_rows(): coolest → hottest, unknown last, stable' );
$rows = [
    [ 'number' => '#1', 'high' => null ],
    [ 'number' => '#2', 'high' => 84 ],
    [ 'number' => '#3', 'high' => 38 ],
    [ 'number' => '#4', 'high' => null ],
    [ 'number' => '#5', 'high' => 84 ],
    [ 'number' => '#6', 'high' => '72' ],
    [ 'number' => '#7', 'high' => 97 ],
];
$sorted = FisHotel_Ship_Day::sort_weather_rows( $rows );
check( 'order', [ '#3', '#6', '#2', '#5', '#7', '#1', '#4' ] === array_column( $sorted, 'number' ), json_encode( array_column( $sorted, 'number' ) ) );
check( 'rows are untouched otherwise', '72' === $sorted[1]['high'] );
check( 'empty input', [] === FisHotel_Ship_Day::sort_weather_rows( [] ) );
check( 'string keys are reset to a list', [ 0, 1 ] === array_keys( FisHotel_Ship_Day::sort_weather_rows( [ 'a' => [ 'high' => 5 ], 'b' => [ 'high' => 1 ] ] ) ) );

/* ───────────────────────────── totals ───────────────────────────── */
section( 'totals(): fish vs dry goods vs orders, weather counts' );
$dests = [
    [
        'rows'    => [ [ 'kind' => 'fish', 'qty' => 2 ], [ 'kind' => 'fish', 'qty' => 1 ], [ 'kind' => 'dry', 'qty' => 3 ] ],
        'weather' => FisHotel_Ship_Day::weather_state( wmeta( 'heat_pack' ), $d ),
    ],
    [
        'rows'    => [ [ 'kind' => 'dry', 'qty' => 1 ], [ 'kind' => 'ea', 'qty' => 6 ] ],
        'weather' => FisHotel_Ship_Day::weather_state( wmeta( 'cooling', [ '_fh_weather_at_line' => '1' ] ), $d ),
    ],
    [
        'rows'    => [ [ 'kind' => 'fish', 'qty' => 5 ] ],
        'weather' => FisHotel_Ship_Day::weather_state( wmeta( 'ups_store_hot' ), $d ),
    ],
    [
        'rows'    => [],
        'weather' => FisHotel_Ship_Day::weather_state( wmeta( 'hold_cold' ), $d ),
    ],
    [
        'rows'    => [ [ 'kind' => 'fish', 'qty' => 1 ] ],
        'weather' => FisHotel_Ship_Day::weather_state( [ '_fh_weather_action' => 'unavailable', '_fh_weather_unavailable_for' => $d ], $d ),
    ],
    [
        'rows'    => [ [ 'kind' => 'fish', 'qty' => 1 ] ],
        'weather' => FisHotel_Ship_Day::weather_state( [], $d ),
    ],
    [
        'rows'    => [ [ 'kind' => 'fish', 'qty' => 1 ] ],
        'weather' => FisHotel_Ship_Day::weather_state( wmeta( 'none' ), $d ),
    ],
];
$t = FisHotel_Ship_Day::totals( $dests );
check( 'orders = destinations', 7 === $t['orders'] );
check( 'fish = sum of fish qty (2+1+5+1+1+1 = 11)', 11 === $t['fish'], (string) $t['fish'] );
check( 'dry goods = sum of dry qty (4); EA-shipped rows are in neither total', 4 === $t['dry'], (string) $t['dry'] );
check( 'heat packs 1, cooling 1, ups store 1, holds 1, none 1', 1 === $t['heat_pack'] && 1 === $t['cooling'] && 1 === $t['ups_store'] && 1 === $t['hold'] && 1 === $t['none'] );
check( 'at the line 1 (also counted in its band)', 1 === $t['at_line'] );
check( 'unavailable 1, not checked 1', 1 === $t['unavailable'] && 1 === $t['not_checked'] );
check( 'empty input → zeros', 0 === FisHotel_Ship_Day::totals( [] )['orders'] && 0 === FisHotel_Ship_Day::totals( [] )['fish'] );

/* ───────────────────────────── labels ───────────────────────────── */
section( 'order_label() / place()' );
check( 'regular order', 'Order #34183' === FisHotel_Ship_Day::order_label( '34183' ) );
check( 'fulfillment with sources', 'Order #34200 (combined: #34168, #34159)' === FisHotel_Ship_Day::order_label( '34200', [ '34168', '34159' ] ) );
check( 'place()', 'Carrollton, VA 23314' === FisHotel_Ship_Day::place( 'Carrollton', 'VA', '23314' ) && 'Carrollton 23314' === FisHotel_Ship_Day::place( 'Carrollton', '', '23314' ) && 'unknown' === FisHotel_Ship_Day::place( '', '', '' ) );
check( 'clean_text() collapses whitespace and nbsp', 'A B' === FisHotel_Ship_Day::clean_text( " <b>A</b>&nbsp;\n B " ) );

/* ───────────────────────────── WooCommerce-facing path (stubbed WP only) ───────────────────────────── */
if ( $fh_stubbed ) {
    class FH_SD_Product {
        private $id;
        private $parent;
        private $desc;
        public function __construct( $id, $parent = 0, $desc = '' ) {
            $this->id     = $id;
            $this->parent = $parent;
            $this->desc   = $desc;
        }
        public function get_id() {
            return $this->id;
        }
        public function get_parent_id() {
            return $this->parent;
        }
        public function get_description() {
            return $this->desc;
        }
    }
    class FH_SD_Item {
        private $id;
        private $name;
        private $qty;
        private $product;
        private $meta;
        public function __construct( $id, $name, $qty, $product = null, $meta = [] ) {
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
        /** WC semantics: the parent product's id for a variation, the product's id otherwise. */
        public function get_product_id() {
            if ( ! is_object( $this->product ) ) {
                return 0;
            }
            return (int) $this->product->get_parent_id() > 0 ? (int) $this->product->get_parent_id() : (int) $this->product->get_id();
        }
        public function get_variation_id() {
            if ( ! is_object( $this->product ) || (int) $this->product->get_parent_id() <= 0 ) {
                return 0;
            }
            return (int) $this->product->get_id();
        }
        public function get_meta( $key ) {
            return $this->meta[ $key ] ?? '';
        }
    }
    class FH_SD_Order {
        public $id;
        public $status;
        public $meta;
        public $items;
        public $refunded = [];
        public $address  = [ 'first_name' => 'Pat', 'last_name' => 'Reefer', 'address_1' => '1 Coral Way', 'address_2' => '', 'city' => 'Carrollton', 'state' => 'VA', 'postcode' => '23314' ];
        public function __construct( $id, $status, $meta = [], $items = [] ) {
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
        public function has_status( $statuses ) {
            return in_array( $this->status, (array) $statuses, true );
        }
        public function get_meta( $key ) {
            return $this->meta[ $key ] ?? '';
        }
        public function get_items( $type = 'line_item' ) {
            return $this->items;
        }
        public function get_qty_refunded_for_item( $item_id ) {
            return $this->refunded[ $item_id ] ?? 0;
        }
        public function __call( $name, $args ) {
            if ( preg_match( '/^get_(shipping|billing)_(.+)$/', $name, $m ) ) {
                if ( 'email' === $m[2] ) {
                    return 'pat@example.test';
                }
                if ( 'phone' === $m[2] ) {
                    return '555-0100';
                }
                if ( 'shipping' === $m[1] ) {
                    return $this->address[ $m[2] ] ?? '';
                }
                return '';
            }
            return '';
        }
        public function get_customer_note() {
            return 'Leave at side door';
        }
        public function get_edit_order_url() {
            return 'https://example.test/wp-admin/post.php?post=' . $this->id . '&action=edit';
        }
    }
    class FH_SD_OrderUtil {
        public static $enabled = false;
        public static function custom_orders_table_usage_is_enabled() {
            return self::$enabled;
        }
    }
    $GLOBALS['fh_sd_orders']   = [];
    $GLOBALS['fh_sd_terms']    = [];
    $GLOBALS['fh_sd_products'] = [];
    $GLOBALS['fh_sd_wc_calls'] = [];
    $GLOBALS['fh_sd_ea']       = []; // product id => true when drop-shipped by EverythingAquatic
    /** Theme (inc/medication-store/helpers.php): true for an EA-mode product with an EA SKU. */
    function fishotel_is_ea_fulfilled_product( $product_id, $variation_id = 0 ) {
        $GLOBALS['fh_sd_ea_calls'][] = [ (int) $product_id, (int) $variation_id ];
        return ! empty( $GLOBALS['fh_sd_ea'][ (int) $product_id ] ) || ! empty( $GLOBALS['fh_sd_ea'][ (int) $variation_id ] );
    }
    function wc_get_order( $id ) {
        return $GLOBALS['fh_sd_orders'][ (int) $id ] ?? false;
    }
    function wc_get_product( $id ) {
        return $GLOBALS['fh_sd_products'][ (int) $id ] ?? false;
    }
    function get_the_terms( $pid, $tax ) {
        $slugs = $GLOBALS['fh_sd_terms'][ (int) $pid ] ?? null;
        if ( null === $slugs ) {
            return false;
        }
        return array_map( function ( $s ) { return (object) [ 'slug' => $s ]; }, $slugs );
    }
    function wc_get_orders( $args ) {
        $GLOBALS['fh_sd_wc_calls'][] = $args;
        // Replay the CPT store: it runs its final WP_Query args through this filter with the query vars.
        $GLOBALS['fh_sd_cpt_query'] = apply_filters( 'woocommerce_order_data_store_cpt_get_orders_query', [ 'post_type' => 'shop_order' ], $args );
        // A backend that ignores the meta filter entirely: every order in the asked statuses.
        $out = [];
        foreach ( $GLOBALS['fh_sd_orders'] as $o ) {
            if ( in_array( $o->status, (array) $args['status'], true ) ) {
                $out[] = $o;
            }
        }
        return $out;
    }

    section( 'rows_for_order(): line status, refunds, classification, invoice expansion' );
    $GLOBALS['fh_sd_terms']    = [ 10 => [ 'quarantined-fish' ], 11 => [ 'invoices' ], 12 => [ 'medications' ], 13 => [ 'quarantined-fish' ] ];
    $goby       = new FH_SD_Product( 10 );
    $invoice    = new FH_SD_Product( 11, 0, '<ul><li>Hectors Goby x1 — $39</li><li>Yellow Tang x2 — $120</li><li>Credit: lost wrasse — -$82</li></ul>' );
    $prazi      = new FH_SD_Product( 12 );
    $variation  = new FH_SD_Product( 130, 13 ); // variation of product 13 (quarantined-fish)
    $empty_inv  = new FH_SD_Product( 14, 0, '' );
    $GLOBALS['fh_sd_terms'][14] = [ 'invoices' ];
    $ea_med     = new FH_SD_Product( 15 ); // EA-mode medication with an EA SKU (theme drop-ships it)
    $GLOBALS['fh_sd_terms'][15] = [ 'medications' ];
    $GLOBALS['fh_sd_ea']        = [ 15 => true ];
    $order = new FH_SD_Order( 100, 'processing', [ '_fishotel_shipping_date' => '2026-10-13' ], [
        1 => new FH_SD_Item( 1, 'Hectors Goby', 2, $goby ),
        2 => new FH_SD_Item( 2, 'Shipped Med', 1, $prazi, [ '_fishotel_line_status' => 'shipped' ] ),
        3 => new FH_SD_Item( 3, 'N/A line', 1, $prazi, [ '_fishotel_line_status' => 'na' ] ),
        4 => new FH_SD_Item( 4, 'PraziPro', 1, $prazi, [ '_fishotel_line_status' => 'backordered' ] ),
        5 => new FH_SD_Item( 5, 'JPReef Invoice (7/3/26)', 2, $invoice ),
        6 => new FH_SD_Item( 6, 'Refunded Goby', 1, $goby ),
        7 => new FH_SD_Item( 7, 'Partly Refunded Goby', 3, $goby ),
        8 => new FH_SD_Item( 8, 'Yellow Tang - Medium', 1, $variation ),
        9 => new FH_SD_Item( 9, 'Old Invoice (1/1/25)', 1, null ),
        10 => new FH_SD_Item( 10, 'FisHotel Sticker', 1, null, [ '_fh_sticker' => 'free' ] ),
        11 => new FH_SD_Item( 11, 'Empty Invoice (2/2/26)', 1, $empty_inv ),
        12 => new FH_SD_Item( 12, 'Hectors Goby', 2, null, [ '_fishotel_fish_id' => 123 ] ), // batch-invoice fish line (ajax_generate_invoices: no product)
        13 => new FH_SD_Item( 13, 'Metroplex 5g', 2, $ea_med ),
    ] );
    $order->refunded = [ 6 => -1, 7 => -2 ];
    $rows  = FisHotel_Ship_Day::rows_for_order( $order );
    $names = array_column( $rows, 'name' );
    check( 'shipped and na lines are skipped', ! in_array( 'Shipped Med', $names, true ) && ! in_array( 'N/A line', $names, true ) );
    check( 'fully refunded line is skipped', ! in_array( 'Refunded Goby', $names, true ) );
    $partial = array_values( array_filter( $rows, function ( $r ) { return 'Partly Refunded Goby' === $r['name']; } ) );
    check( 'partial refund reduces qty (3 - 2 = 1)', 1 === count( $partial ) && 1 === $partial[0]['qty'] && 'fish' === $partial[0]['kind'] );
    $back = array_values( array_filter( $rows, function ( $r ) { return 'PraziPro' === $r['name']; } ) );
    check( 'backordered dry-goods line is kept and flagged', 1 === count( $back ) && 'backordered' === $back[0]['flag'] && 'dry' === $back[0]['kind'] );
    $inv = array_values( array_filter( $rows, function ( $r ) { return 'JPReef Invoice (7/3/26)' === $r['sub']; } ) );
    check( 'invoice expanded into 2 fish rows (credit dropped) with the invoice as sub-label', 2 === count( $inv ) && 'Hectors Goby' === $inv[0]['name'] && 'Yellow Tang' === $inv[1]['name'] );
    check( 'invoice qty multiplied by the line quantity (x2)', 2 === $inv[0]['qty'] && 4 === $inv[1]['qty'] );
    check( 'expanded rows count as fish, not flagged', 'fish' === $inv[0]['kind'] && '' === $inv[0]['flag'] );
    $var = array_values( array_filter( $rows, function ( $r ) { return 'Yellow Tang - Medium' === $r['name']; } ) );
    check( 'variation classified by its parent\'s categories → fish', 1 === count( $var ) && 'fish' === $var[0]['kind'] );
    $missing = array_values( array_filter( $rows, function ( $r ) { return 'Invoice — product missing' === $r['name']; } ) );
    check( 'invoice whose product is gone → one "Invoice — product missing" row flagged check', 1 === count( $missing ) && 'check' === $missing[0]['flag'] && 'Old Invoice (1/1/25)' === $missing[0]['sub'] );
    $sticker = array_values( array_filter( $rows, function ( $r ) { return 'FisHotel Sticker' === $r['name']; } ) );
    check( 'sticker line ($0, no product) is dry goods', 1 === count( $sticker ) && 'dry' === $sticker[0]['kind'] );
    $empty = array_values( array_filter( $rows, function ( $r ) { return 'Empty Invoice (2/2/26)' === $r['sub']; } ) );
    check( 'invoice with an empty description → one check row', 1 === count( $empty ) && 'check' === $empty[0]['flag'] );
    $goby_rows = array_values( array_filter( $rows, function ( $r ) { return 'Hectors Goby' === $r['name'] && '' === $r['sub']; } ) );
    check( 'plain fish line kept with its qty', 2 === count( $goby_rows ) && 2 === $goby_rows[0]['qty'] && 'fish' === $goby_rows[0]['kind'] && '' === $goby_rows[0]['flag'] );
    check( 'batch-invoice fish line (no product, _fishotel_fish_id) is a fish, not flagged', 'fish' === $goby_rows[1]['kind'] && '' === $goby_rows[1]['flag'] && 2 === $goby_rows[1]['qty'], json_encode( $goby_rows ) );
    $ea = array_values( array_filter( $rows, function ( $r ) { return 'Metroplex 5g' === $r['name']; } ) );
    check( 'EA-fulfilled med line is kind ea (listed, not boxed)', 1 === count( $ea ) && 'ea' === $ea[0]['kind'] && '' === $ea[0]['flag'] && 2 === $ea[0]['qty'], json_encode( $ea ) );
    check( 'EA predicate called with the product id', in_array( [ 15, 0 ], $GLOBALS['fh_sd_ea_calls'] ?? [], true ) );
    check( 'self-mode med (PraziPro) stays dry goods', 'dry' === $back[0]['kind'] );
    $order_totals = FisHotel_Ship_Day::totals( [ [ 'rows' => $rows, 'weather' => [] ] ] );
    check( 'totals: dry excludes the EA row (PraziPro 1 + sticker 1 = 2, not 4)', 2 === $order_totals['dry'], (string) $order_totals['dry'] );
    check( 'totals: batch-invoice fish counted as fish (goby 2 + partial 1 + invoice 2+4 + variation 1 + 2 invoice check rows + batch goby 2 = 14)', 14 === $order_totals['fish'], (string) $order_totals['fish'] );

    section( 'build_destination(): fulfillment wrapper = one destination, rows from LIVE sources only' );
    $src_a = new FH_SD_Order( 201, 'processing', [ '_fishotel_fulfilled_by_order' => '300' ], [ 1 => new FH_SD_Item( 1, 'Hectors Goby', 1, $goby ) ] );
    $src_b = new FH_SD_Order( 202, 'cancelled',  [ '_fishotel_fulfilled_by_order' => '300' ], [ 1 => new FH_SD_Item( 1, 'Cancelled Tang', 5, $goby ) ] );
    $src_c = new FH_SD_Order( 203, 'processing', [ '_fishotel_fulfilled_by_order' => '300' ], [ 1 => new FH_SD_Item( 1, 'Yellow Tang', 2, $goby ), 2 => new FH_SD_Item( 2, 'Shipped Med', 1, $prazi, [ '_fishotel_line_status' => 'shipped' ] ) ] );
    $wrapper = new FH_SD_Order( 300, 'fulfillment', [
        '_fishotel_shipping_date'       => '2026-10-13',
        '_fishotel_is_fulfillment'      => '1',
        '_fishotel_fulfillment_sources' => '201,202,203,999',
        '_fh_weather_checked_for'       => '2026-10-13',
        '_fh_weather_action'            => 'heat_pack',
        '_fh_weather_high'              => '55',
        '_fh_weather_low'               => '40',
        '_fh_weather_summary'           => 'High 55°F / Low 40°F — Cloudy',
        '_fh_weather_note'              => 'Weather … Alerts: Wind Advisory. Heat pack.',
    ], [
        // the theme's copy_line_items() duplicates every source line onto the wrapper
        1 => new FH_SD_Item( 1, 'Hectors Goby', 1, $goby, [ '_fishotel_source_order' => 201 ] ),
        2 => new FH_SD_Item( 2, 'Cancelled Tang', 5, $goby, [ '_fishotel_source_order' => 202 ] ),
        3 => new FH_SD_Item( 3, 'Yellow Tang', 2, $goby, [ '_fishotel_source_order' => 203 ] ),
        4 => new FH_SD_Item( 4, 'Shipped Med', 1, $prazi, [ '_fishotel_source_order' => 203 ] ),
    ] );
    $GLOBALS['fh_sd_orders'] = [ 201 => $src_a, 202 => $src_b, 203 => $src_c, 300 => $wrapper ];
    check( 'source_ids() parses the CSV, drops self/junk', [ 201, 202, 203, 999 ] === FisHotel_Ship_Day::source_ids( $wrapper ) );
    $dest = FisHotel_Ship_Day::build_destination( $wrapper, '2026-10-13' );
    check( 'label lists the live sources only', 'Order #300 (combined: #201, #203)' === $dest['label'], $dest['label'] );
    check( 'rows come from the live sources, not the wrapper copies (no double count)', [ 'Hectors Goby', 'Yellow Tang' ] === array_column( $dest['rows'], 'name' ), json_encode( array_column( $dest['rows'], 'name' ) ) );
    check( 'cancelled source and shipped line excluded', 3 === FisHotel_Ship_Day::totals( [ $dest ] )['fish'] );
    check( 'weather flag with alert', '(HEAT PACK) (WIND ADVISORY)' === $dest['flag'] && 55 === $dest['high'] );
    check( 'destination place from the wrapper address', 'Carrollton, VA 23314' === $dest['place'] && 'Pat Reefer' === $dest['customer'] );
    check( 'edit url carried', false !== strpos( $dest['edit_url'], 'post=300' ) );
    $orphan = new FH_SD_Order( 301, 'fulfillment', [ '_fishotel_is_fulfillment' => '1', '_fishotel_fulfillment_sources' => '999' ], [ 1 => new FH_SD_Item( 1, 'Copied Goby', 1, $goby ) ] );
    $dest_o = FisHotel_Ship_Day::build_destination( $orphan, '2026-10-13' );
    check( 'wrapper whose source ids load no order falls back to its own copied lines', [ 'Copied Goby' ] === array_column( $dest_o['rows'], 'name' ) && 'Order #301' === $dest_o['label'], json_encode( array_column( $dest_o['rows'], 'name' ) ) );
    $src_d = new FH_SD_Order( 204, 'refunded', [ '_fishotel_fulfilled_by_order' => '302' ], [ 1 => new FH_SD_Item( 1, 'Refunded Tang', 3, $goby ) ] );
    $GLOBALS['fh_sd_orders'][204] = $src_d;
    $dead = new FH_SD_Order( 302, 'fulfillment', [ '_fishotel_is_fulfillment' => '1', '_fishotel_fulfillment_sources' => '202,204' ], [
        1 => new FH_SD_Item( 1, 'Cancelled Tang', 5, $goby, [ '_fishotel_source_order' => 202 ] ),
        2 => new FH_SD_Item( 2, 'Refunded Tang', 3, $goby, [ '_fishotel_source_order' => 204 ] ),
    ] );
    $dest_d = FisHotel_Ship_Day::build_destination( $dead, '2026-10-13' );
    check( 'wrapper whose sources all load but are cancelled/refunded → single "Nothing to pick" check row, not the copies', 1 === count( $dest_d['rows'] ) && 'check' === $dest_d['rows'][0]['flag'] && 0 === $dest_d['rows'][0]['qty'] && 0 === strpos( $dest_d['rows'][0]['name'], 'Nothing to pick' ) && 'Order #302' === $dest_d['label'], json_encode( $dest_d['rows'] ) );
    check( 'dead-source wrapper contributes 0 fish', 0 === FisHotel_Ship_Day::totals( [ $dest_d ] )['fish'] );
    $regular = FisHotel_Ship_Day::build_destination( $order, '2026-10-13' );
    check( 'regular order: not a fulfillment, not checked', false === $regular['is_fulfillment'] && '(NOT CHECKED)' === $regular['flag'] );

    section( 'load_orders() / upcoming_dates(): store-agnostic query, re-check, folded sources excluded' );
    $folded = new FH_SD_Order( 400, 'processing', [ '_fishotel_shipping_date' => '2026-10-13', '_fishotel_fulfilled_by_order' => '300' ] );
    $other  = new FH_SD_Order( 401, 'on-hold',    [ '_fishotel_shipping_date' => '2026-10-14' ] );
    $done   = new FH_SD_Order( 402, 'completed',  [ '_fishotel_shipping_date' => '2026-10-13' ] );
    $GLOBALS['fh_sd_orders'] = [ 100 => $order, 300 => $wrapper, 400 => $folded, 401 => $other, 402 => $done, 201 => $src_a, 203 => $src_c ];
    $eq_clause = [ 'key' => '_fishotel_shipping_date', 'value' => '2026-10-13', 'compare' => '=' ];
    $loaded = FisHotel_Ship_Day::load_orders( '2026-10-13' );
    $args   = end( $GLOBALS['fh_sd_wc_calls'] );
    check( 'CPT store: meta_query NOT passed to wc_get_orders', is_array( $args ) && ! array_key_exists( 'meta_query', $args ), json_encode( $args ) );
    check( 'CPT store: private query var passed, pass-through adds the = clause', 1 === ( $args['fishotel_ship_day'] ?? null ) && [ $eq_clause ] === ( $GLOBALS['fh_sd_cpt_query']['meta_query'] ?? null ), json_encode( $GLOBALS['fh_sd_cpt_query'] ) );
    check( 'query asks for LIMIT+1 orders of the ship-day statuses', 201 === $args['limit'] && FisHotel_Ship_Day::statuses() === $args['status'] && 'shop_order' === $args['type'] );
    check( 'only the two open destinations for the date (folded source, other date, completed dropped)', [ 100, 300 ] === array_map( function ( $o ) { return $o->id; }, $loaded['orders'] ) && false === $loaded['truncated'], json_encode( array_map( function ( $o ) { return $o->id; }, $loaded['orders'] ) ) );
    check( 'pass-through filter removed after the query', empty( $GLOBALS['fh_test_filters']['woocommerce_order_data_store_cpt_get_orders_query'] ) );
    check( 'invalid date → nothing', [] === FisHotel_Ship_Day::load_orders( 'nope' )['orders'] );
    class_alias( 'FH_SD_OrderUtil', 'Automattic\\WooCommerce\\Utilities\\OrderUtil' );
    FH_SD_OrderUtil::$enabled = true;
    check( 'hpos_enabled() true when the orders table is active', true === FisHotel_Ship_Day::hpos_enabled() );
    FisHotel_Ship_Day::load_orders( '2026-10-13' );
    $args = end( $GLOBALS['fh_sd_wc_calls'] );
    check( 'HPOS: meta_query IS passed', [ $eq_clause ] === ( $args['meta_query'] ?? null ) );
    FH_SD_OrderUtil::$enabled = false;
    $now      = new DateTimeImmutable( '2026-10-11 09:00:00', $chicago );
    $upcoming = FisHotel_Ship_Day::upcoming_dates( 21, $now );
    $args     = end( $GLOBALS['fh_sd_wc_calls'] );
    check( 'upcoming_dates(): one BETWEEN query from today', 'BETWEEN' === ( $GLOBALS['fh_sd_cpt_query']['meta_query'][0]['compare'] ?? '' ) && [ '2026-10-11', '2026-11-01' ] === ( $GLOBALS['fh_sd_cpt_query']['meta_query'][0]['value'] ?? null ) && -1 === $args['limit'] );
    check( 'upcoming_dates(): counts per date, folded source not counted', [ '2026-10-13' => 2, '2026-10-14' => 1 ] === $upcoming, json_encode( $upcoming ) );
    check( 'default_date(): first upcoming date on/after today', '2026-10-13' === FisHotel_Ship_Day::default_date( $upcoming, '2026-10-11' ) && '2026-10-14' === FisHotel_Ship_Day::default_date( $upcoming, '2026-10-14' ) && '2026-10-20' === FisHotel_Ship_Day::default_date( $upcoming, '2026-10-20' ) );

    section( 'render(): screen + print pages' );
    $r = FisHotel_Ship_Day::render( '2026-10-13', false );
    check( 'returns html + counts', is_array( $r ) && 2 === $r['orders'] && false === $r['truncated'] );
    $html = $r['html'];
    check( 'screen block is fh-no-print and has chips + print button', false !== strpos( $html, 'fh-sd-screen fh-no-print' ) && false !== strpos( $html, 'id="fh-sd-print"' ) && false !== strpos( $html, '<strong>2</strong> Orders' ) );
    check( 'page 1 title and subtitle (ship = deliver - 1)', false !== strpos( $html, '<h1>FisHotel Pick List</h1>' ) && false !== strpos( $html, 'Ship Monday, October 12 &bull; Deliver Tuesday, October 13 &bull; 2 orders / ' . (int) $r['fish'] . ' fish' ), $html );
    check( 'pick cell is an empty bordered box, never the ☐ glyph', false !== strpos( $html, '<span class="fh-sd-box"></span>' ) && false === strpos( $html, '&#9744;' ) && false === strpos( $html, '☐' ) );
    check( 'fulfillment shown as one destination with combined label', false !== strpos( $html, 'Order #300 (combined: #201, #203)' ) );
    check( 'weather flag on the order\'s first row', false !== strpos( $html, '<strong class="fh-sd-flag">(HEAT PACK) (WIND ADVISORY)</strong>' ) );
    check( 'dry goods tagged, backordered + check flagged, invoice sub-label', false !== strpos( $html, '(dry goods)' ) && false !== strpos( $html, '(BACKORDERED)' ) && false !== strpos( $html, '(CHECK)' ) && false !== strpos( $html, 'from JPReef Invoice (7/3/26)' ) );
    check( 'page 2 title + chart subtitle', false !== strpos( $html, '<h1>Weather Overview — Delivery Tuesday, October 13</h1>' ) && false !== strpos( $html, esc_html( FisHotel_Ship_Day::CHART_SUBTITLE ) ) );
    check( 'weather rows colour-coded, unknown last', false !== strpos( $html, 'fh-sd-tone-blue' ) && false !== strpos( $html, 'fh-sd-tone-grey' ) && strpos( $html, 'fh-sd-tone-blue' ) < strpos( $html, 'fh-sd-tone-grey' ) );
    check( 'notes: alerts + WooCommerce confirmation + data source', false !== strpos( $html, 'Active NWS alerts' ) && false !== strpos( $html, 'Notes written in WooCommerce by the Weather section on' ) && false !== strpos( $html, 'National Weather Service' ) );
    check( 'needs attention lists the not-checked order with an edit link', false !== strpos( $html, 'fh-sd-attention' ) && false !== strpos( $html, 'post=100' ) && false !== strpos( $html, 'Not checked for this date' ) );
    check( 'no "weather not checked" global notice when some order has weather meta', false === strpos( $html, 'enable the Weather section' ) );
    check( 'no slips by default', false === strpos( $html, 'fh-packing-list-order' ) );
    $r2 = FisHotel_Ship_Day::render( '2026-10-13', true );
    check( 'slips appended: one card per destination, with the combined label', 2 === substr_count( $r2['html'], 'class="fh-packing-list-order"' ) && false !== strpos( $r2['html'], 'Order #300 (combined: #201, #203) &mdash; Pat Reefer' ) );
    check( 'output is escaped', false === strpos( $r2['html'], '<script' ) );
    $r3 = FisHotel_Ship_Day::render( '2026-10-14', false );
    check( 'a date whose only order has no weather meta → global notice with the Weather overview link', false !== strpos( $r3['html'], 'enable the Weather section in FisHotel Tools and run the nightly scan' ) && false !== strpos( $r3['html'], 'page=fishotel-misc-weather' ) && false !== strpos( $r3['html'], 'date=2026-10-14' ) );
    $r4 = FisHotel_Ship_Day::render( '2026-10-20', false );
    check( 'no orders → empty notice, no print block', 0 === $r4['orders'] && false !== strpos( $r4['html'], 'No open orders deliver on' ) && false === strpos( $r4['html'], 'fh-sd-print' ) );
    check( 'invalid date → WP_Error', is_wp_error( FisHotel_Ship_Day::render( '2026-02-30' ) ) );
    check( 'weather_overview_url()', 'https://example.test/wp-admin/admin.php?page=fishotel-misc-weather&date=2026-10-13' === FisHotel_Ship_Day::weather_overview_url( '2026-10-13' ) );
}

echo "\n{$passes} passed, {$failures} failed.\n";
exit( $failures > 0 ? 1 : 0 );
