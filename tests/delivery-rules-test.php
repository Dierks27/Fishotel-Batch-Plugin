<?php
/**
 * FisHotel_Delivery_Rules — self-contained test.
 *
 *   php tests/delivery-rules-test.php
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
if ( ! function_exists( 'get_option' ) ) {
    $fh_stubbed = true;
    $GLOBALS['fh_test_options'] = [];
    $GLOBALS['fh_test_filters'] = [];
    function get_option( $name, $default = false ) {
        return array_key_exists( $name, $GLOBALS['fh_test_options'] ) ? $GLOBALS['fh_test_options'][ $name ] : $default;
    }
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
    function add_action() {}
    function wp_timezone() {
        return new DateTimeZone( 'America/Chicago' );
    }
}

require_once dirname( __DIR__ ) . '/includes/class-delivery-rules.php';

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

$chicago = new DateTimeZone( 'America/Chicago' );
$utc     = new DateTimeZone( 'UTC' );
$base    = [ 'days' => [ 'Monday', 'Tuesday', 'Wednesday' ], 'blacklist' => [], 'min_advance' => 1, 'max_ahead' => 30, 'max_per_day' => 6 ];

// Calendar for reference: 2026-10-10 Sat, 10-12 Mon, 10-13 Tue, 10-14 Wed, 10-15 Thu, 10-19 Mon,
// 11-01 Sun (US DST ends), 11-11 Wed (30 days after 10-12), 11-16 Mon.
$mon_morning = new DateTimeImmutable( '2026-10-12 09:00:00', $chicago );

/* ───────────────────────────── closed weekday ───────────────────────────── */
section( 'Closed weekday is rejected' );
$rules = new FisHotel_Delivery_Rules( $base, $chicago );
$why   = $rules->why_not( '2026-10-15', $mon_morning ); // Thursday
check( 'Thursday → closed_day', 'closed_day' === $why['code'], $why['code'] );
check( 'closed_day message names the enabled days', false !== strpos( $why['message'], 'Monday, Tuesday, and Wednesday' ), $why['message'] );
check( 'is_allowed(Thursday) is false', ! $rules->is_allowed( '2026-10-15', $mon_morning ) );
check( 'is_allowed(Tuesday) is true', $rules->is_allowed( '2026-10-13', $mon_morning ) );
check( 'Saturday → closed_day', 'closed_day' === $rules->why_not( '2026-10-17', $mon_morning )['code'] );

section( 'Day names compare case-insensitively against full English names' );
$rules_ci = new FisHotel_Delivery_Rules( [ 'days' => [ 'monday', 'TUESDAY', ' Wednesday ' ] ] + $base, $chicago );
$sun_morning = new DateTimeImmutable( '2026-10-11 09:00:00', $chicago );
check( 'lowercase/uppercase/padded names all count', $rules_ci->is_allowed( '2026-10-12', $sun_morning ) && $rules_ci->is_allowed( '2026-10-13', $sun_morning ) && $rules_ci->is_allowed( '2026-10-14', $sun_morning ) );
check( 'settings() returns canonical lowercase week order', [ 'monday', 'tuesday', 'wednesday' ] === $rules_ci->settings()['days'] );
$rules_abbrev = new FisHotel_Delivery_Rules( [ 'days' => [ 'Mon', 'Tue' ] ] + $base, $chicago );
check( 'abbreviations are not accepted (no days enabled)', [] === $rules_abbrev->settings()['days'] );
check( 'with no days enabled every date is closed_day', 'closed_day' === $rules_abbrev->why_not( '2026-10-13', $mon_morning )['code'] );

/* ───────────────────────────── blacklist ───────────────────────────── */
section( 'Blacklisted date is rejected' );
$rules_bl = new FisHotel_Delivery_Rules( [ 'blacklist' => [ '2026-10-13', 'not-a-date', '2026-13-40' ] ] + $base, $chicago );
check( 'invalid blacklist entries are dropped', [ '2026-10-13' ] === $rules_bl->settings()['blacklist'] );
$why = $rules_bl->why_not( '2026-10-13', $mon_morning );
check( 'blacklisted Tuesday → blacklisted', 'blacklisted' === $why['code'], $why['code'] );
check( 'the next Wednesday is still fine', $rules_bl->is_allowed( '2026-10-14', $mon_morning ) );
$dates = array_column( $rules_bl->allowed_dates( $mon_morning ), 'date' );
check( 'allowed_dates() skips the blacklisted date', ! in_array( '2026-10-13', $dates, true ) && in_array( '2026-10-14', $dates, true ) );

/* ───────────────────────────── too_soon, no cutoff hour ───────────────────────────── */
section( 'too_soon: 6:59 pm and 7:01 pm Central on the same calendar day agree (no cutoff hour)' );
$rules  = new FisHotel_Delivery_Rules( $base, $chicago );
$early  = new DateTimeImmutable( '2026-10-12 18:59:00', $chicago ); // Mon 6:59 pm CDT = 23:59 UTC
$late   = new DateTimeImmutable( '2026-10-12 19:01:00', $chicago ); // Mon 7:01 pm CDT = 00:01 UTC Tue
check( 'the two instants straddle UTC midnight', '2026-10-12' === $early->setTimezone( $utc )->format( 'Y-m-d' ) && '2026-10-13' === $late->setTimezone( $utc )->format( 'Y-m-d' ) );
check( 'today() is Monday at 6:59 pm', '2026-10-12' === $rules->today( $early )->format( 'Y-m-d' ) );
check( 'today() is still Monday at 7:01 pm', '2026-10-12' === $rules->today( $late )->format( 'Y-m-d' ), $rules->today( $late )->format( 'Y-m-d' ) );
check( 'Monday itself is too_soon at 6:59 pm', 'too_soon' === $rules->why_not( '2026-10-12', $early )['code'] );
check( 'Monday itself is too_soon at 7:01 pm', 'too_soon' === $rules->why_not( '2026-10-12', $late )['code'] );
check( 'Tuesday is ok at 6:59 pm', 'ok' === $rules->why_not( '2026-10-13', $early )['code'] );
check( 'Tuesday is still ok at 7:01 pm', 'ok' === $rules->why_not( '2026-10-13', $late )['code'], $rules->why_not( '2026-10-13', $late )['code'] );
check( 'allowed_dates() identical at 6:59 pm and 7:01 pm', $rules->allowed_dates( $early ) === $rules->allowed_dates( $late ) );
check( 'first allowed date is Tuesday both times', '2026-10-13' === $rules->allowed_dates( $late )[0]['date'] );
check( 'a past date is too_soon', 'too_soon' === $rules->why_not( '2026-10-05', $early )['code'] );
$rules0 = new FisHotel_Delivery_Rules( [ 'min_advance' => 0 ] + $base, $chicago );
check( 'min_advance=0 allows today', $rules0->is_allowed( '2026-10-12', $late ) );
$rules3 = new FisHotel_Delivery_Rules( [ 'min_advance' => 3 ] + $base, $chicago );
check( 'min_advance=3 from Monday → first date is the following Monday', '2026-10-19' === $rules3->allowed_dates( $late )[0]['date'] );
check( 'too_soon message names the earliest date', false !== strpos( $rules3->why_not( '2026-10-14', $late )['message'], 'Thursday, October 15, 2026' ), $rules3->why_not( '2026-10-14', $late )['message'] );

/* ───────────────────────────── UTC vs Chicago on a day boundary ───────────────────────────── */
section( 'The same instant evaluated in UTC vs America/Chicago on a day boundary' );
$instant_utc = new DateTimeImmutable( '2026-10-14 04:30:00', $utc );          // = 2026-10-13 23:30 CDT
$instant_chi = new DateTimeImmutable( '2026-10-13 23:30:00', $chicago );
check( 'fixture: the two objects are the same instant', $instant_utc->getTimestamp() === $instant_chi->getTimestamp() );
$rules_chi = new FisHotel_Delivery_Rules( $base, $chicago );
$rules_utc = new FisHotel_Delivery_Rules( $base, $utc );
check( 'Chicago rules: today is 2026-10-13 even when $now is handed over in UTC', '2026-10-13' === $rules_chi->today( $instant_utc )->format( 'Y-m-d' ), $rules_chi->today( $instant_utc )->format( 'Y-m-d' ) );
check( 'Chicago rules: same answer whichever zone $now is expressed in', $rules_chi->allowed_dates( $instant_utc ) === $rules_chi->allowed_dates( $instant_chi ) );
check( 'Chicago rules: Wednesday 10-14 is offered (one day of notice from Tuesday night)', '2026-10-14' === $rules_chi->allowed_dates( $instant_utc )[0]['date'] );
check( 'Chicago rules: 10-14 is ok', 'ok' === $rules_chi->why_not( '2026-10-14', $instant_utc )['code'] );
check( 'UTC rules (the bug being fixed) would say today is 10-14', '2026-10-14' === $rules_utc->today( $instant_utc )->format( 'Y-m-d' ) );
check( 'UTC rules would reject 10-14 as too_soon', 'too_soon' === $rules_utc->why_not( '2026-10-14', $instant_utc )['code'] );
check( 'UTC rules would skip to Monday 10-19', '2026-10-19' === $rules_utc->allowed_dates( $instant_utc )[0]['date'] );
check( 'now() accepts a unix timestamp too', '2026-10-13' === $rules_chi->today( $instant_utc->getTimestamp() )->format( 'Y-m-d' ) );
check( 'now() accepts a mutable DateTime', '2026-10-13' === $rules_chi->today( new DateTime( '2026-10-14 04:30:00', $utc ) )->format( 'Y-m-d' ) );
check( 'now() reads a bare string in the rules zone', '2026-10-13' === $rules_chi->today( '2026-10-13 23:30:00' )->format( 'Y-m-d' ) );
check( 'now() converts an offset-bearing string (Z) into the rules zone', '2026-10-13' === $rules_chi->today( '2026-10-14T04:30:00Z' )->format( 'Y-m-d' ), $rules_chi->today( '2026-10-14T04:30:00Z' )->format( 'Y-m-d T' ) );
check( 'now() converts an offset-bearing string (+00:00) into the rules zone', '2026-10-13' === $rules_chi->today( '2026-10-14 04:30:00 +00:00' )->format( 'Y-m-d' ) );
check( 'today() of an offset-bearing string is midnight in the rules zone', 'America/Chicago' === $rules_chi->today( '2026-10-14T04:30:00Z' )->getTimezone()->getName() );
check( 'offset-bearing string and the same instant as an object agree on allowed_dates()', $rules_chi->allowed_dates( '2026-10-14T04:30:00Z' ) === $rules_chi->allowed_dates( $instant_utc ) );
check( 'offset-bearing string: 10-14 is ok (not too_soon)', 'ok' === $rules_chi->why_not( '2026-10-14', '2026-10-14T04:30:00Z' )['code'], $rules_chi->why_not( '2026-10-14', '2026-10-14T04:30:00Z' )['code'] );

/* ───────────────────────────── max_ahead ───────────────────────────── */
section( 'max_ahead: latest date is today + max_ahead (inclusive), across the DST change' );
$rules = new FisHotel_Delivery_Rules( $base, $chicago ); // max_ahead 30
check( 'Wed 2026-11-11 (exactly 30 days out, after DST ended on 11-01) is ok', 'ok' === $rules->why_not( '2026-11-11', $mon_morning )['code'], $rules->why_not( '2026-11-11', $mon_morning )['code'] );
check( 'Mon 2026-11-16 (35 days out) → too_far', 'too_far' === $rules->why_not( '2026-11-16', $mon_morning )['code'] );
check( 'too_far message names the latest date', false !== strpos( $rules->why_not( '2026-11-16', $mon_morning )['message'], 'Wednesday, November 11, 2026' ) );
$dates = array_column( $rules->allowed_dates( $mon_morning ), 'date' );
check( 'allowed_dates() ends on 2026-11-11', '2026-11-11' === end( $dates ), (string) end( $dates ) );
// 10-13, 10-14, 10-19..21, 10-26..28, 11-02..04, 11-09..11 → 14 dates.
check( 'allowed_dates() has 14 Mon/Tue/Wed dates in the window', 14 === count( $dates ), (string) count( $dates ) );
$rules_short = new FisHotel_Delivery_Rules( [ 'max_ahead' => 1 ] + $base, $chicago );
check( 'max_ahead=1 from Monday → only Tuesday', [ '2026-10-13' ] === array_column( $rules_short->allowed_dates( $mon_morning ), 'date' ) );
$rules_tight = new FisHotel_Delivery_Rules( [ 'min_advance' => 5, 'max_ahead' => 2 ] + $base, $chicago );
check( 'min_advance > max_ahead → nothing offered, next_available null', [] === $rules_tight->allowed_dates( $mon_morning ) && null === $rules_tight->next_available( $mon_morning ) );

/* ───────────────────────────── capacity ───────────────────────────── */
section( 'Capacity: full when booked >= max_per_day' );
$counter_calls = [];
$counter = function ( $ymd ) use ( &$counter_calls ) {
    $counter_calls[] = $ymd;
    return [ '2026-10-13' => 6, '2026-10-14' => 5, '2026-10-19' => 9 ][ $ymd ] ?? 0;
};
$rules = new FisHotel_Delivery_Rules( $base, $chicago, $counter );
$why   = $rules->why_not( '2026-10-13', $mon_morning );
check( 'booked 6 of 6 → full', 'full' === $why['code'], $why['code'] );
check( 'full message says 6 of 6', false !== strpos( $why['message'], '6 of 6' ), $why['message'] );
check( 'booked 5 of 6 → ok', 'ok' === $rules->why_not( '2026-10-14', $mon_morning )['code'] );
check( 'booked 9 of 6 → full', 'full' === $rules->why_not( '2026-10-19', $mon_morning )['code'] );
check( 'is_allowed(full day) is false', ! $rules->is_allowed( '2026-10-13', $mon_morning ) );
check( 'is_allowed(full day, capacity off) is true (pure calendar rules)', $rules->is_allowed( '2026-10-13', $mon_morning, false ) );
$cap = $rules->capacity_for( '2026-10-14' );
check( 'capacity_for() reports capacity/booked/remaining/full', 6 === $cap['capacity'] && 5 === $cap['booked'] && 1 === $cap['remaining'] && false === $cap['full'] );
check( 'capacity_for(full day) remaining 0, full true', 0 === $rules->capacity_for( '2026-10-13' )['remaining'] && true === $rules->capacity_for( '2026-10-13' )['full'] );
$before = count( $counter_calls );
$rules->booked_for( '2026-10-13' );
$rules->capacity_for( '2026-10-13' );
check( 'counter is memoised per date', count( $counter_calls ) === $before );
check( 'counter is only asked for dates that passed the calendar rules', ! in_array( '2026-10-15', $counter_calls, true ) && ! in_array( '2026-10-12', $counter_calls, true ) );
$rules->flush_booked();
$rules->booked_for( '2026-10-13' );
check( 'flush_booked() re-asks the counter', count( $counter_calls ) === $before + 1 );
$rules_unlimited = new FisHotel_Delivery_Rules( [ 'max_per_day' => 0 ] + $base, $chicago, $counter );
check( 'max_per_day=0 is unlimited: 9 booked is still ok', 'ok' === $rules_unlimited->why_not( '2026-10-19', $mon_morning )['code'] );
check( 'unlimited capacity_for(): remaining null, full false', null === $rules_unlimited->capacity_for( '2026-10-19' )['remaining'] && false === $rules_unlimited->capacity_for( '2026-10-19' )['full'] );
check( 'no counter → nothing booked', 0 === ( new FisHotel_Delivery_Rules( $base, $chicago ) )->booked_for( '2026-10-13' ) );
try {
    new FisHotel_Delivery_Rules( $base, $chicago, 'definitely_not_a_function' );
    check( 'non-callable counter is rejected', false );
} catch ( InvalidArgumentException $e ) {
    check( 'non-callable counter is rejected', true );
}

/* ───────────────────────────── next_available ───────────────────────────── */
section( 'next_available() skips a full day' );
check( 'Tuesday is full → next_available is Wednesday', '2026-10-14' === $rules->next_available( $mon_morning ), (string) $rules->next_available( $mon_morning ) );
$all_full = new FisHotel_Delivery_Rules( $base, $chicago, function () { return 99; } );
check( 'everything full → next_available is null', null === $all_full->next_available( $mon_morning ) );
check( 'everything full → allowed_dates still lists the dates, all closed', 14 === count( $all_full->allowed_dates( $mon_morning ) ) && ! in_array( true, array_column( $all_full->allowed_dates( $mon_morning ), 'open' ), true ) );

/* ───────────────────────────── ordering and labels ───────────────────────────── */
section( 'allowed_dates() ordering and labels' );
$rules10 = new FisHotel_Delivery_Rules( [ 'max_ahead' => 10 ] + $base, $chicago, $counter );
$rows    = $rules10->allowed_dates( $mon_morning );
$expect  = [
    [ 'date' => '2026-10-13', 'label' => 'Tue, Oct 13', 'long_label' => 'Tuesday, October 13, 2026',   'capacity' => 6, 'booked' => 6, 'open' => false ],
    [ 'date' => '2026-10-14', 'label' => 'Wed, Oct 14', 'long_label' => 'Wednesday, October 14, 2026', 'capacity' => 6, 'booked' => 5, 'open' => true  ],
    [ 'date' => '2026-10-19', 'label' => 'Mon, Oct 19', 'long_label' => 'Monday, October 19, 2026',    'capacity' => 6, 'booked' => 9, 'open' => false ],
    [ 'date' => '2026-10-20', 'label' => 'Tue, Oct 20', 'long_label' => 'Tuesday, October 20, 2026',   'capacity' => 6, 'booked' => 0, 'open' => true  ],
    [ 'date' => '2026-10-21', 'label' => 'Wed, Oct 21', 'long_label' => 'Wednesday, October 21, 2026', 'capacity' => 6, 'booked' => 0, 'open' => true  ],
];
check( 'five rows for a 10-day window from Monday', count( $expect ) === count( $rows ), (string) count( $rows ) );
foreach ( $expect as $i => $e ) {
    $got = $rows[ $i ] ?? [];
    foreach ( $e as $k => $v ) {
        check( "row {$i} {$k} = " . var_export( $v, true ), isset( $got[ $k ] ) && $got[ $k ] === $v, var_export( $got[ $k ] ?? null, true ) );
    }
}
$dates = array_column( $rows, 'date' );
$sorted = $dates;
sort( $sorted );
check( 'rows are in ascending date order', $dates === $sorted );
check( 'every row carries the weekday name', [ 'Tuesday', 'Wednesday', 'Monday', 'Tuesday', 'Wednesday' ] === array_column( $rows, 'weekday' ) );
check( 'rows are a plain list (0..n-1 keys)', array_keys( $rows ) === range( 0, count( $rows ) - 1 ) );
check( 'format_label() uses the rules zone and the long format', 'Tuesday, October 13, 2026' === $rules10->format_label( '2026-10-13' ) && 'Tue, Oct 13' === $rules10->format_label( '2026-10-13', 'D, M j' ) );
check( 'format_label() of garbage is empty', '' === $rules10->format_label( 'nope' ) );

/* ───────────────────────────── calendar() preview ───────────────────────────── */
section( 'calendar() lists every day with a reason' );
$cal = $rules10->calendar( $mon_morning, 10 );
check( '10 rows starting today', 10 === count( $cal ) && '2026-10-12' === $cal[0]['date'] && '2026-10-21' === $cal[9]['date'] );
check( 'today is too_soon', 'too_soon' === $cal[0]['code'] && false === $cal[0]['open'] );
check( 'Tuesday is full', 'full' === $cal[1]['code'] && false === $cal[1]['open'] );
check( 'Wednesday is ok', 'ok' === $cal[2]['code'] && true === $cal[2]['open'] );
check( 'Thursday is closed_day', 'closed_day' === $cal[3]['code'] );
check( 'every row has a message', 0 === count( array_filter( $cal, function ( $r ) { return '' === $r['message']; } ) ) );

/* ───────────────────────────── invalid input ───────────────────────────── */
section( 'Invalid input' );
foreach ( [ '', 'garbage', '2026-02-30', '13/10/2026', '2026-10-1', ' 2026-10-13x' ] as $bad ) {
    check( "'{$bad}' → invalid", 'invalid' === $rules->why_not( $bad, $mon_morning )['code'] );
}
check( 'is_ymd() accepts a real date', FisHotel_Delivery_Rules::is_ymd( '2026-10-13' ) && ! FisHotel_Delivery_Rules::is_ymd( '2026-02-30' ) );
check( 'corrupt options fall back to defaults', [ 'monday', 'tuesday', 'wednesday' ] === FisHotel_Delivery_Rules::normalize_settings( [ 'days' => 'Monday' ] )['days'] );
check( 'negative/zero numbers are clamped', [ 0, 1, 0 ] === array_values( array_intersect_key( FisHotel_Delivery_Rules::normalize_settings( [ 'min_advance' => -3, 'max_ahead' => 0, 'max_per_day' => -1 ] ), [ 'min_advance' => 1, 'max_ahead' => 1, 'max_per_day' => 1 ] ) ) );
check( 'theme-style keys are accepted as aliases', [ '2026-12-25' ] === FisHotel_Delivery_Rules::normalize_settings( [ 'blocked_dates' => [ '2026-12-25' ], 'max_days_ahead' => 14 ] )['blacklist'] && 14 === FisHotel_Delivery_Rules::normalize_settings( [ 'max_days_ahead' => 14 ] )['max_ahead'] );

/* ───────────────────────────── from_options() (stubbed WP only) ───────────────────────────── */
if ( $fh_stubbed ) {
    section( 'from_options() reads the five public options, wp_timezone() and the settings filter' );
    $GLOBALS['fh_test_options'] = [
        'fishotel_shipping_days'        => [ 'Tuesday', 'Wednesday', 'Thursday' ],
        'fishotel_shipping_blacklist'   => [ '2026-10-14' ],
        'fishotel_shipping_min_advance' => '2',
        'fishotel_shipping_max_ahead'   => '21',
        'fishotel_shipping_max_per_day' => '4',
    ];
    $r = FisHotel_Delivery_Rules::from_options();
    check( 'days from option', [ 'tuesday', 'wednesday', 'thursday' ] === $r->settings()['days'] );
    check( 'blacklist from option', [ '2026-10-14' ] === $r->settings()['blacklist'] );
    check( 'numbers from option (strings cast)', 2 === $r->settings()['min_advance'] && 21 === $r->settings()['max_ahead'] && 4 === $r->settings()['max_per_day'] );
    check( 'timezone from wp_timezone()', 'America/Chicago' === $r->timezone()->getName() );
    check( 'real counter without WooCommerce counts nothing', 0 === $r->booked_for( '2026-10-15' ) );
    add_filter( 'fishotel_delivery_rules_settings', function ( $s ) { $s['days'][] = 'Friday'; $s['max_per_day'] = 2; return $s; } );
    $r2 = FisHotel_Delivery_Rules::from_options();
    check( 'fishotel_delivery_rules_settings filter is applied and re-normalised', [ 'tuesday', 'wednesday', 'thursday', 'friday' ] === $r2->settings()['days'] && 2 === $r2->settings()['max_per_day'] );
    check( 'fishotel_delivery_rules() memoises', fishotel_delivery_rules() === fishotel_delivery_rules() );
    check( 'fishotel_delivery_rules( true ) rebuilds', fishotel_delivery_rules() !== fishotel_delivery_rules( true ) );
    $old = fishotel_delivery_rules();
    FisHotel_Delivery_Rules::reset_shared();
    check( 'reset_shared() (option-update hook) rebuilds on next call', $old !== fishotel_delivery_rules() );
    check( 'count_statuses() default list', [ 'processing', 'on-hold', 'fulfillment' ] === FisHotel_Delivery_Rules::count_statuses() );
    add_filter( 'fishotel_delivery_rules_count_statuses', function ( $s ) { $s[] = 'wc-shipped'; $s[] = 'processing'; return $s; } );
    check( 'count_statuses() filter: wc- prefix stripped, duplicates dropped', [ 'processing', 'on-hold', 'fulfillment', 'shipped' ] === FisHotel_Delivery_Rules::count_statuses() );

    /* ───────────────────────────── load_booked_map() (stubbed WooCommerce) ───────────────────────────── */
    section( 'load_booked_map(): one query, meta_query only sent on HPOS, CPT pass-through adds the clause' );
    class FH_Test_Order {
        private $meta;
        public function __construct( $date, $fulfilled_by = '' ) {
            $this->meta = [ '_fishotel_shipping_date' => $date, '_fishotel_fulfilled_by_order' => $fulfilled_by ];
        }
        public function get_meta( $key ) {
            return $this->meta[ $key ] ?? '';
        }
    }
    class FH_Test_OrderUtil {
        public static $enabled = false;
        public static function custom_orders_table_usage_is_enabled() {
            return self::$enabled;
        }
    }
    $GLOBALS['fh_test_wc_calls'] = [];
    function wc_get_orders( $args ) {
        $GLOBALS['fh_test_wc_calls'][] = $args;
        // Replay the CPT store: it runs its final WP_Query args through this filter with the query vars.
        $GLOBALS['fh_test_cpt_query'] = apply_filters( 'woocommerce_order_data_store_cpt_get_orders_query', [ 'post_type' => 'shop_order' ], $args );
        return [
            new FH_Test_Order( '2026-10-13' ),
            new FH_Test_Order( '2026-10-13' ),
            new FH_Test_Order( '2026-10-13', '555' ), // combined into a fulfillment: not counted
            new FH_Test_Order( '2026-10-14' ),
            new FH_Test_Order( '' ),                  // no date (matched by a backend that ignored the filter)
            new FH_Test_Order( '10/13/2026' ),        // not Y-m-d
            'not-an-order',
        ];
    }
    $exists_clause = [ [ 'key' => '_fishotel_shipping_date', 'compare' => 'EXISTS' ] ];

    check( 'hpos_enabled() is false when OrderUtil is absent', false === FisHotel_Delivery_Rules::hpos_enabled() );
    FisHotel_Delivery_Rules::flush_booked_cache();
    $map  = FisHotel_Delivery_Rules::load_booked_map();
    $args = end( $GLOBALS['fh_test_wc_calls'] );
    check( 'CPT store: meta_query is NOT passed to wc_get_orders (WC 9.2+ would wc_doing_it_wrong() it)', is_array( $args ) && ! array_key_exists( 'meta_query', $args ), json_encode( $args ) );
    check( 'CPT store: the private query var is passed instead', 1 === ( $args['fishotel_delivery_counts'] ?? null ) );
    check( 'CPT store: pass-through filter re-adds the EXISTS clause to the final query', $exists_clause === ( $GLOBALS['fh_test_cpt_query']['meta_query'] ?? null ), json_encode( $GLOBALS['fh_test_cpt_query'] ) );
    check( 'query asks for the count statuses, all results, order objects', FisHotel_Delivery_Rules::count_statuses() === $args['status'] && -1 === $args['limit'] && 'objects' === $args['return'] && 'shop_order' === $args['type'] );
    check( 'map counts per date; combined boxes, dateless, junk and non-orders are skipped', [ '2026-10-13' => 2, '2026-10-14' => 1 ] === $map, json_encode( $map ) );
    check( 'booked_count() reads the map', 2 === FisHotel_Delivery_Rules::booked_count( '2026-10-13' ) && 0 === FisHotel_Delivery_Rules::booked_count( '2026-10-20' ) );
    $n = count( $GLOBALS['fh_test_wc_calls'] );
    FisHotel_Delivery_Rules::booked_count( '2026-10-14' );
    FisHotel_Delivery_Rules::load_booked_map();
    check( 'map is built once per request', $n === count( $GLOBALS['fh_test_wc_calls'] ) );
    check( 'pass-through filter is removed after the query', empty( $GLOBALS['fh_test_filters']['woocommerce_order_data_store_cpt_get_orders_query'] ) );
    check( 'the shared instance sees the real counts', 2 === fishotel_delivery_rules( true )->booked_for( '2026-10-13' ) );

    class_alias( 'FH_Test_OrderUtil', 'Automattic\\WooCommerce\\Utilities\\OrderUtil' );
    FH_Test_OrderUtil::$enabled = true;
    check( 'hpos_enabled() is true when the orders table is active', true === FisHotel_Delivery_Rules::hpos_enabled() );
    FisHotel_Delivery_Rules::flush_booked_cache();
    $map  = FisHotel_Delivery_Rules::load_booked_map();
    $args = end( $GLOBALS['fh_test_wc_calls'] );
    check( 'HPOS: meta_query IS passed to wc_get_orders', $exists_clause === ( $args['meta_query'] ?? null ), json_encode( $args ) );
    check( 'HPOS: same counts', [ '2026-10-13' => 2, '2026-10-14' => 1 ] === $map );
    FH_Test_OrderUtil::$enabled = false;
    check( 'hpos_enabled() is false when the orders table is disabled', false === FisHotel_Delivery_Rules::hpos_enabled() );
}

echo "\n{$passes} passed, {$failures} failed.\n";
exit( $failures > 0 ? 1 : 0 );
