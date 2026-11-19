<?php
/**
 * The Ship Day line on the Misc plugin's Today page and its dashboard widget
 * (the Today section's public `fishotel_today_lines` filter), under Shipping:
 *
 *   ship day, pick list not built   "Ship Day: 6 orders · 23 fish go out today for Thu, Nov 20"   tone todo
 *   ship day, pick list built       "Ship Day built at 9:12 am — open again"                       tone done
 *   any other day                   no line — Today's own header already says
 *                                   "Nothing ships today. Next: ship … for …"
 *
 * A ship day is a day whose next calendar day has open orders delivering
 * (FisHotel_Ship_Day::ship_date_for(): ship = delivery − 1 day), counted the
 * way the pick list counts them. "Built" is the record the Ship Day AJAX build
 * writes (FisHotel_Ship_Day::record_built()) for today's ship date. The line
 * links to Ship Day opened on tomorrow's delivery date; on the Today page and
 * the widget that link carries an "Open Ship Day" button (print_button_style()).
 *
 * Only for users with Ship Day's capability. Hooked in wp-admin only and
 * worked out once per request, lazily — the filter runs only when the Today
 * section renders, so nothing is queried when the Misc plugin or its Today
 * section is off, and never on the storefront. Once today's list is built no
 * order is queried at all.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FisHotel_Ship_Day_Today_Line {

    /** The Today section's filter, this line's key and its heading there. */
    const FILTER = 'fishotel_today_lines';
    const KEY    = 'ship-day';
    const GROUP  = 'shipping';
    const BUTTON = 'Open Ship Day';

    /**
     * Where the state comes from (null = state()); a test hands in its own.
     *
     * @var callable|null
     */
    private static $source = null;

    /**
     * The line worked out once for this request (array( line|null )).
     *
     * @var array|null
     */
    private static $cached = null;

    /** Hook the line and its button style (wp-admin only). */
    public static function register() {
        add_filter( self::FILTER, [ __CLASS__, 'filter' ], 20, 1 );
        add_action( 'admin_head', [ __CLASS__, 'print_button_style' ] );
    }

    /** Use another source for the state, and forget the cached line. */
    public static function use_source( $source = null ) {
        self::$source = is_callable( $source ) ? $source : null;
        self::$cached = null;
    }

    /**
     * The line (pure): null on a day that is not a ship day.
     *
     * @param array  $state today, delivery (today + 1), orders, fish, built (built_entry() or null).
     * @param string $url   Ship Day's page.
     * @return array|null
     */
    public static function line( array $state, $url ) {
        $built = isset( $state['built'] ) && is_array( $state['built'] ) ? $state['built'] : null;
        $base  = [
            'key'    => self::KEY,
            'group'  => self::GROUP,
            'url'    => (string) $url,
            'button' => self::BUTTON,
        ];

        if ( null !== $built ) {
            $time   = FisHotel_Ship_Day::time_label( $built['at'] ?? '' );
            $orders = (int) ( $built['orders'] ?? 0 );
            $fish   = (int) ( $built['fish'] ?? 0 );
            $for    = FisHotel_Ship_Day::label( (string) ( $built['delivery'] ?? '' ), 'D, M j' );
            return $base + [
                'text'   => 'Ship Day built' . ( '' !== $time ? ' at ' . $time : '' ) . ' — open again',
                'detail' => ( '' !== $for ? 'for ' . $for . ' · ' : '' ) . self::counts_text( $orders, $fish ),
                'count'  => 0,
                'tone'   => 'done',
            ];
        }

        $orders = max( 0, (int) ( $state['orders'] ?? 0 ) );
        if ( $orders <= 0 ) {
            return null; // not a ship day — Today's header already names the next one
        }
        $fish = max( 0, (int) ( $state['fish'] ?? 0 ) );
        $for  = FisHotel_Ship_Day::label( (string) ( $state['delivery'] ?? '' ), 'D, M j' );
        return $base + [
            'text'   => 'Ship Day: ' . self::counts_text( $orders, $fish ) . ' go out today' . ( '' !== $for ? ' for ' . $for : '' ),
            'detail' => '',
            'count'  => $orders,
            'tone'   => 'todo',
        ];
    }

    /** "6 orders · 23 fish" / "1 order · 1 fish". */
    public static function counts_text( $orders, $fish ) {
        $orders = (int) $orders;
        return $orders . ( 1 === $orders ? ' order' : ' orders' ) . ' · ' . (int) $fish . ' fish';
    }

    /**
     * Today's state: is it a ship day, and has today's pick list been built?
     * Once built, no order is queried.
     *
     * @return array{today:string,delivery:string,orders:int,fish:int,built:array|null}
     */
    public static function state( $now = null ) {
        $today    = FisHotel_Ship_Day::today_ymd( $now );
        $delivery = FisHotel_Ship_Day::delivery_date_for( $today );
        $state    = [
            'today'    => $today,
            'delivery' => $delivery,
            'orders'   => 0,
            'fish'     => 0,
            'built'    => FisHotel_Ship_Day::built_for( $today ),
        ];
        if ( null === $state['built'] ) {
            $t               = FisHotel_Ship_Day::day_totals( $delivery );
            $state['orders'] = $t['orders'];
            $state['fish']   = $t['fish'];
        }
        return $state;
    }

    /**
     * The lines with this one added (pure): a line under the same key is
     * replaced, never doubled; no line leaves the others untouched.
     */
    public static function with_line( $lines, $line ) {
        $out = [];
        foreach ( is_array( $lines ) ? $lines : [] as $existing ) {
            if ( is_array( $existing ) && self::KEY === (string) ( $existing['key'] ?? '' ) ) {
                continue;
            }
            $out[] = $existing;
        }
        if ( is_array( $line ) ) {
            $out[] = $line;
        }
        return $out;
    }

    /**
     * Filter callback: the Today lines gain Ship Day's, for a user who may
     * open Ship Day. Whatever goes wrong adds nothing.
     */
    public static function filter( $lines = [] ) {
        $lines = is_array( $lines ) ? $lines : [];
        if ( ! function_exists( 'current_user_can' ) || ! current_user_can( FisHotel_Ship_Day::CAPABILITY ) ) {
            return $lines;
        }
        if ( null === self::$cached ) {
            try {
                $state        = null !== self::$source ? call_user_func( self::$source ) : self::state();
                $state        = is_array( $state ) ? $state : [];
                self::$cached = [ self::line( $state, FisHotel_Ship_Day::page_url( (string) ( $state['delivery'] ?? '' ) ) ) ];
            } catch ( \Throwable $e ) {
                self::$cached = [ null ];
            }
        }
        return self::with_line( $lines, self::$cached[0] );
    }

    /**
     * The "Open Ship Day" button on the line. The Today section prints a
     * line's text as its link and has no button of its own, so the button is
     * drawn after that link — only links to Ship Day's page inside a Today
     * line (page: .fh-today-line, widget: .fh-tw-line) get it.
     */
    public static function button_css() {
        $sel = '.fh-today-line a[href*="page=' . FisHotel_Ship_Day::PAGE_SLUG . '"]::after, .fh-tw-line a[href*="page=' . FisHotel_Ship_Day::PAGE_SLUG . '"]::after';
        return $sel . '{content:"' . self::BUTTON . '";display:inline-block;margin-left:10px;padding:2px 12px;border-radius:4px;background:#2271b1;color:#fff;font-size:12px;font-weight:600;line-height:20px;text-decoration:none;vertical-align:middle;}';
    }

    /** admin_head: the button style, on the dashboard and the Today page only. */
    public static function print_button_style() {
        if ( ! function_exists( 'get_current_screen' ) || ! function_exists( 'current_user_can' ) || ! current_user_can( FisHotel_Ship_Day::CAPABILITY ) ) {
            return;
        }
        $screen = get_current_screen();
        $id     = is_object( $screen ) && isset( $screen->id ) ? (string) $screen->id : '';
        if ( 'dashboard' !== $id && false === strpos( $id, FisHotel_Ship_Day::MISC_TODAY_SLUG ) ) {
            return;
        }
        echo '<style id="fishotel-ship-day-today-line">' . self::button_css() . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- constant CSS
    }
}
