<?php
/**
 * FisHotel Ship Day — the Step 8 pick list + weather overview, built on the server.
 *
 * Replaces the browser-skill PDF the daily order routine used to produce. For one
 * delivery date it:
 *
 *   1. loads every open order delivering that day (store-agnostic query, bounded),
 *   2. turns each order — or each fulfillment wrapper, which is ONE destination made
 *      of several source orders — into pick rows (fish / dry goods), expanding
 *      "Invoice" products into the fish listed in their description,
 *   3. reads the weather note the Misc plugin's Weather section wrote on the order
 *      (_fh_weather_* meta) and turns it into the inline packing flag,
 *   4. renders the on-screen summary and the two print pages (pick list, weather
 *      overview), optionally followed by the per-order packing slips.
 *
 * FULFILLMENTS. The theme (FisHotel-Theme/inc/order-fulfillment/class-fishotel-
 * fulfillment.php) builds a wrapper order with _fishotel_is_fulfillment = '1',
 * status wc-fulfillment and _fishotel_fulfillment_sources = 'id,id'. The wrapper
 * DOES carry copied line items (copy_line_items() duplicates every source product
 * line with _fishotel_source_order / _fishotel_source_line_item_id meta), so the
 * pick rows are read from the LIVE source orders only — never from the wrapper's
 * copies as well, which would double count. The copies are used only as a fallback
 * when the sources meta names no loadable order. Sources whose status is cancelled,
 * refunded, failed or trash never ship and are skipped (the theme leaves them
 * listed in the wrapper's meta — same rule the Weather section's live_sources()
 * applies). Orders folded into a wrapper (_fishotel_fulfilled_by_order non-empty)
 * are excluded from the date's list because the wrapper carries them.
 *
 * LINE STATUS. _fishotel_line_status (item meta, theme Phase 1) is one of
 * pending | shipped | backordered | na. 'shipped' lines already left through
 * EverythingAquatic and are skipped. 'na' means "not applicable — this line never
 * ships": the theme's all_lines_shipped_or_na() counts an 'na' line as done when
 * deciding whether an order auto-completes, so there is nothing to pack and the
 * line is skipped too. 'backordered' lines are listed and flagged (BACKORDERED).
 * Fully refunded lines (quantity minus |qty refunded| <= 0) are skipped.
 *
 * This file has no side effects at load time. The parsing / classification /
 * flag / date / sort / totals helpers are pure static methods with no WordPress
 * dependency, so tests/ship-day-test.php can run them with plain php.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FisHotel_Ship_Day {

    const NONCE_ACTION = 'fishotel_ship_day';
    const LIMIT        = 200;
    const LOOKAHEAD    = 21;

    /** Order meta read from other FisHotel code. */
    const META_DELIVERY_DATE  = '_fishotel_shipping_date';
    const META_FULFILLED_BY   = '_fishotel_fulfilled_by_order';
    const META_IS_FULFILLMENT = '_fishotel_is_fulfillment';
    const META_SOURCES        = '_fishotel_fulfillment_sources';
    const META_LINE_STATUS    = '_fishotel_line_status';

    /** Order meta written by the Misc plugin's Weather section (class-checker.php). */
    const META_W_CHECKED_FOR     = '_fh_weather_checked_for';
    const META_W_CHECKED_AT      = '_fh_weather_checked_at';
    const META_W_HIGH            = '_fh_weather_high';
    const META_W_LOW             = '_fh_weather_low';
    const META_W_ACTION          = '_fh_weather_action';
    const META_W_AT_LINE         = '_fh_weather_at_line';
    const META_W_SUMMARY         = '_fh_weather_summary';
    const META_W_NOTE            = '_fh_weather_note';
    const META_W_UNAVAILABLE_FOR = '_fh_weather_unavailable_for';

    const WEATHER_META_KEYS = [
        self::META_W_CHECKED_FOR,
        self::META_W_CHECKED_AT,
        self::META_W_HIGH,
        self::META_W_LOW,
        self::META_W_ACTION,
        self::META_W_AT_LINE,
        self::META_W_SUMMARY,
        self::META_W_NOTE,
        self::META_W_UNAVAILABLE_FOR,
    ];

    /** The Weather section's overview page (FisHotel Tools → Weather). */
    const WEATHER_PAGE_SLUG = 'fishotel-misc-weather';

    /** Source-order statuses that never ship. */
    const DEAD_STATUSES = [ 'cancelled', 'refunded', 'failed', 'trash' ];

    const KIND_FISH = 'fish';
    const KIND_DRY  = 'dry';
    /** Drop-shipped by EverythingAquatic (theme fishotel_is_ea_fulfilled_product()) — listed, never boxed. */
    const KIND_EA   = 'ea';

    const CLASS_FISH    = 'fish';
    const CLASS_INVOICE = 'invoice';
    const CLASS_DRY     = 'dry';

    const CHART_SUBTITLE = 'by delivery-day high: <30 hold · 30–40 UPS Store · 40–70 heat pack · 70–80 nothing · 80–90 cooling · 90–95 UPS Store · >95 hold';

    /* ─────────────────────────────────────────────
     *  Settings (filterable)
     * ───────────────────────────────────────────── */

    /** Order statuses (unprefixed) that appear on the pick list. */
    public static function statuses() {
        $default  = [ 'processing', 'on-hold', 'fulfillment' ];
        $statuses = function_exists( 'apply_filters' ) ? apply_filters( 'fishotel_ship_day_statuses', $default ) : $default;
        $out      = [];
        foreach ( (array) $statuses as $s ) {
            $s = trim( (string) $s );
            if ( 0 === strpos( $s, 'wc-' ) ) {
                $s = substr( $s, 3 );
            }
            if ( '' !== $s && ! in_array( $s, $out, true ) ) {
                $out[] = $s;
            }
        }
        return $out;
    }

    /**
     * Product category slugs whose items count as fish (livestock). Matches the
     * list the checkout delivery-date gates in class-woocommerce.php use.
     */
    public static function fish_categories() {
        $default = [ 'quarantined-fish', 'inverts' ];
        $cats    = function_exists( 'apply_filters' ) ? apply_filters( 'fishotel_ship_day_fish_categories', $default ) : $default;
        $out     = [];
        foreach ( (array) $cats as $c ) {
            $c = trim( (string) $c );
            if ( '' !== $c && ! in_array( $c, $out, true ) ) {
                $out[] = $c;
            }
        }
        return $out;
    }

    /** The site zone every date computation happens in. */
    public static function timezone() {
        if ( function_exists( 'fishotel_delivery_rules' ) ) {
            $tz = fishotel_delivery_rules()->timezone();
            if ( $tz instanceof DateTimeZone ) {
                return $tz;
            }
        }
        if ( function_exists( 'wp_timezone' ) ) {
            return wp_timezone();
        }
        return new DateTimeZone( 'America/Chicago' );
    }

    /* ─────────────────────────────────────────────
     *  Pure helpers (no WordPress)
     * ───────────────────────────────────────────── */

    /** Strict Y-m-d shape + real calendar date. */
    public static function is_ymd( $ymd ) {
        if ( ! is_string( $ymd ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m ) ) {
            return false;
        }
        return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
    }

    /** Y-m-d → local midnight DateTimeImmutable in $tz (or the site zone), or null. */
    public static function parse_ymd( $ymd, $tz = null ) {
        if ( ! self::is_ymd( $ymd ) ) {
            return null;
        }
        $tz = $tz instanceof DateTimeZone ? $tz : self::timezone();
        $d  = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, $tz );
        return ( $d && $d->format( 'Y-m-d' ) === $ymd ) ? $d : null;
    }

    /**
     * Ship date = delivery date minus one calendar day, computed in the site zone
     * with DateTimeImmutable (so the day after a DST change is still one day).
     */
    public static function ship_date_for( $delivery_ymd, $tz = null ) {
        $d = self::parse_ymd( $delivery_ymd, $tz );
        if ( ! $d ) {
            return '';
        }
        $ship = $d->modify( '-1 day' );
        return $ship ? $ship->format( 'Y-m-d' ) : '';
    }

    /** Pretty label for a Y-m-d ('' when invalid). */
    public static function label( $ymd, $format = 'l, F j', $tz = null ) {
        $d = self::parse_ymd( $ymd, $tz );
        return $d ? $d->format( $format ) : '';
    }

    /**
     * Expand an Invoice product's description into fish lines.
     *
     * Each <li> is one line (when there is no list, every non-empty text line is
     * one). Entities are decoded and tags stripped, so nested markup, &#8212; and
     * &nbsp; all work. A line matching "Name xN — $price" (x / X / ×, optional
     * spaces, — / – / - dashes, missing price allowed) yields fish/qty/price; a
     * "Credit…" line or one carrying a negative amount is NOT a fish and is
     * skipped; any other line is kept whole with qty 1 and flag 'check'.
     *
     * @return array<int,array{fish:string,qty:int,price:float|null,flag:string}>
     */
    public static function parse_invoice_lines( $html ) {
        $html = (string) $html;
        if ( '' === trim( $html ) ) {
            return [];
        }

        $raw_lines = [];
        if ( preg_match_all( '/<li\b[^>]*>(.*?)<\/li>/is', $html, $m ) && ! empty( $m[1] ) ) {
            $raw_lines = $m[1];
        } else {
            $text      = preg_replace( '/<\s*(br|\/p|\/div|\/li|\/tr|\/h[1-6])\s*\/?\s*>/i', "\n", $html );
            $raw_lines = preg_split( '/\r\n|\r|\n/', (string) $text );
        }

        $out = [];
        foreach ( (array) $raw_lines as $raw ) {
            $text = self::clean_text( $raw );
            if ( '' === $text ) {
                continue;
            }
            if ( preg_match( '/^credit\b/iu', $text ) ) {
                continue; // a credit line is money, not a fish
            }

            // The fish grammar runs FIRST: its price group requires "$digits" right
            // after the dash-run, so "— -$82", "$-82" and "−$5" can never parse as
            // fish, while any dash-run touching the $ ("Goby x1 - $39", "Goby x1-$39",
            // "Goby x1 -$39") is a separator and the line is a fish.
            $pattern = '/^(?<name>.+?)\s*[x\x{00D7}X]\s*(?<qty>\d+)\s*(?:[\x{2014}\x{2013}-]+\s*(?<price>\$\s*\d[\d,]*(?:\.\d+)?)?)?\s*$/u';
            if ( preg_match( $pattern, $text, $mm ) && '' !== trim( $mm['name'] ) ) {
                $price = null;
                if ( isset( $mm['price'] ) && '' !== $mm['price'] ) {
                    $price = (float) str_replace( [ '$', ',', ' ' ], '', $mm['price'] );
                }
                $out[] = [
                    'fish'  => trim( $mm['name'] ),
                    'qty'   => max( 1, (int) $mm['qty'] ),
                    'price' => $price,
                    'flag'  => '',
                ];
                continue;
            }

            // Not a fish line. A negative amount ("— -$82", "$-82", "−$82": the sign
            // sits right against the $ and follows a space, a dash or the start of
            // the line) is money, not a fish — dropped. Anything else is kept whole
            // for a hand check.
            if ( preg_match( '/(?:^|(?<=[\s\x{2014}\x{2013}-]))(?:-|\x{2212})\$\s*\d|\$\s*(?:-|\x{2212})\s*\d/u', $text ) ) {
                continue;
            }

            $out[] = [
                'fish'  => $text,
                'qty'   => 1,
                'price' => null,
                'flag'  => 'check',
            ];
        }

        return $out;
    }

    /** Strip tags, decode entities, normalise whitespace (incl. nbsp). */
    public static function clean_text( $html ) {
        $text = strip_tags( (string) $html );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = str_replace( [ "\xC2\xA0", "\xE2\x80\xAF" ], ' ', $text );
        $text = preg_replace( '/\s+/u', ' ', $text );
        return trim( (string) $text );
    }

    /**
     * Classify a line item from its product's category slugs (parent product for
     * variations) and its name: 'fish' | 'invoice' | 'dry'.
     */
    public static function classify( array $category_slugs, $item_name, $fish_categories = null ) {
        $slugs = [];
        foreach ( $category_slugs as $s ) {
            $slugs[] = strtolower( trim( (string) $s ) );
        }
        $fish = null === $fish_categories ? self::fish_categories() : (array) $fish_categories;
        $fish = array_map( 'strtolower', array_map( 'trim', array_map( 'strval', $fish ) ) );

        if ( in_array( 'invoices', $slugs, true ) || preg_match( '/\bInvoice\b/i', (string) $item_name ) ) {
            return self::CLASS_INVOICE;
        }
        foreach ( $fish as $cat ) {
            if ( '' !== $cat && in_array( $cat, $slugs, true ) ) {
                return self::CLASS_FISH;
            }
        }
        return self::CLASS_DRY;
    }

    /** "Alerts: Flood Watch, Wind Advisory." → ['FLOOD WATCH', 'WIND ADVISORY']. */
    public static function parse_alerts( $note ) {
        $note = (string) $note;
        if ( '' === $note || ! preg_match( '/\bAlerts:\s*(.+?)\.(?:\s|$)/u', $note, $m ) ) {
            return [];
        }
        $out = [];
        foreach ( preg_split( '/\s*,\s*/u', trim( $m[1] ) ) as $alert ) {
            $alert = trim( $alert );
            if ( '' === $alert ) {
                continue;
            }
            $alert = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $alert, 'UTF-8' ) : strtoupper( $alert );
            if ( ! in_array( $alert, $out, true ) ) {
                $out[] = $alert;
            }
        }
        return $out;
    }

    /**
     * Normalise the _fh_weather_* meta of an order into one state array.
     *
     * $meta is keyed by the meta names (missing keys = never checked). The stored
     * result only counts when _fh_weather_checked_for equals the delivery date;
     * otherwise it is stale and reads as not checked. A failed lookup stores
     * action 'unavailable' (and removes checked_for), which reads as unavailable
     * while _fh_weather_unavailable_for is the delivery date.
     *
     * @return array{status:string,action:string,at_line:bool,high:int|null,low:int|null,conditions:string,summary:string,alerts:string[],next_day:string,checked_for:string,checked_at:string,has_meta:bool}
     */
    public static function weather_state( array $meta, $delivery_date = null ) {
        if ( null === $delivery_date ) {
            $delivery_date = (string) ( $meta['delivery_date'] ?? ( $meta[ self::META_DELIVERY_DATE ] ?? '' ) );
        }
        $delivery_date   = trim( (string) $delivery_date );
        $action          = trim( (string) ( $meta[ self::META_W_ACTION ] ?? '' ) );
        $checked_for     = trim( (string) ( $meta[ self::META_W_CHECKED_FOR ] ?? '' ) );
        $unavailable_for = trim( (string) ( $meta[ self::META_W_UNAVAILABLE_FOR ] ?? '' ) );
        $note            = (string) ( $meta[ self::META_W_NOTE ] ?? '' );
        $summary         = trim( (string) ( $meta[ self::META_W_SUMMARY ] ?? '' ) );

        $has_meta = false;
        foreach ( self::WEATHER_META_KEYS as $key ) {
            if ( isset( $meta[ $key ] ) && '' !== trim( (string) $meta[ $key ] ) ) {
                $has_meta = true;
                break;
            }
        }

        $current = ( '' === $delivery_date ) ? ( '' !== $checked_for ) : ( $checked_for === $delivery_date );

        $state = [
            'status'      => 'not_checked',
            'action'      => '',
            'at_line'     => false,
            'high'        => null,
            'low'         => null,
            'conditions'  => '',
            'summary'     => $summary,
            'alerts'      => [],
            'next_day'    => '',
            'checked_for' => $checked_for,
            'checked_at'  => trim( (string) ( $meta[ self::META_W_CHECKED_AT ] ?? '' ) ),
            'has_meta'    => $has_meta,
        ];

        if ( 'unavailable' === $action ) {
            $for_this_date = ( '' === $unavailable_for ) || ( '' === $delivery_date ) || ( $unavailable_for === $delivery_date );
            if ( $for_this_date ) {
                $state['status'] = 'unavailable';
                $state['action'] = 'unavailable';
            }
            return $state;
        }

        if ( '' === $action || ! $current ) {
            return $state;
        }

        $high = $meta[ self::META_W_HIGH ] ?? null;
        $low  = $meta[ self::META_W_LOW ] ?? null;

        $state['status']   = 'checked';
        $state['action']   = $action;
        $state['at_line']  = '1' === (string) ( $meta[ self::META_W_AT_LINE ] ?? '' );
        $state['high']     = is_numeric( $high ) ? (int) $high : null;
        $state['low']      = is_numeric( $low ) ? (int) $low : null;
        $state['alerts']   = self::parse_alerts( $note );
        $state['next_day'] = preg_match( '/[^.]*do not slip delivery\./u', $note, $m ) ? trim( $m[0] ) : '';

        // Summary is "High 76°F / Low 57°F — Sunny, 10% chance of rain".
        $parts               = preg_split( '/\s[\x{2014}\x{2013}-]\s/u', $summary, 2 );
        $state['conditions'] = isset( $parts[1] ) ? trim( $parts[1] ) : $summary;

        return $state;
    }

    /** Inline flag text for a normalised weather state. */
    public static function flag_for_state( array $state ) {
        $status = (string) ( $state['status'] ?? 'not_checked' );
        if ( 'unavailable' === $status ) {
            return '(WEATHER UNAVAILABLE)';
        }
        if ( 'checked' !== $status ) {
            return '(NOT CHECKED)';
        }

        if ( ! empty( $state['at_line'] ) ) {
            $flag = '(AT THE LINE — Jeff\'s call)';
        } else {
            switch ( (string) ( $state['action'] ?? '' ) ) {
                case 'heat_pack':
                    $flag = '(HEAT PACK)';
                    break;
                case 'cooling':
                    $flag = '(COOLING PACKS)';
                    break;
                case 'ups_store_cold':
                case 'ups_store_hot':
                    $flag = '(UPS STORE)';
                    break;
                case 'hold_cold':
                case 'hold_hot':
                    $flag = '(HOLD)';
                    break;
                case 'none':
                    $flag = '';
                    break;
                default:
                    $flag = '(NOT CHECKED)';
            }
        }

        foreach ( (array) ( $state['alerts'] ?? [] ) as $alert ) {
            $flag .= ( '' === $flag ? '' : ' ' ) . '(' . $alert . ')';
        }
        return $flag;
    }

    /**
     * Inline flag straight from the order meta: '(HEAT PACK)', '(COOLING PACKS)',
     * '(UPS STORE)', '(HOLD)', '' for none, '(AT THE LINE — Jeff's call)',
     * '(WEATHER UNAVAILABLE)', '(NOT CHECKED)' — alerts appended as '(FLOOD WATCH)'.
     */
    public static function weather_flag( array $meta, $delivery_date = null ) {
        return self::flag_for_state( self::weather_state( $meta, $delivery_date ) );
    }

    /** Human action label for the overview table. */
    public static function action_label( array $state ) {
        $status = (string) ( $state['status'] ?? '' );
        if ( 'unavailable' === $status ) {
            return 'Unavailable — check manually';
        }
        if ( 'checked' !== $status ) {
            return 'Not checked';
        }
        $labels = [
            'heat_pack'      => 'Heat pack',
            'none'           => 'Nothing needed',
            'cooling'        => 'Cooling packs',
            'ups_store_cold' => 'UPS Store (cold)',
            'ups_store_hot'  => 'UPS Store (hot)',
            'hold_cold'      => 'HOLD (cold)',
            'hold_hot'       => 'HOLD (hot)',
        ];
        $action = (string) ( $state['action'] ?? '' );
        $label  = $labels[ $action ] ?? $action;
        return ! empty( $state['at_line'] ) ? 'AT THE LINE · ' . $label : $label;
    }

    /** Colour class for an overview row. */
    public static function row_tone( array $state ) {
        $status = (string) ( $state['status'] ?? '' );
        if ( 'checked' !== $status ) {
            return 'grey';
        }
        if ( ! empty( $state['at_line'] ) ) {
            return 'amber';
        }
        switch ( (string) ( $state['action'] ?? '' ) ) {
            case 'none':
                return 'green';
            case 'heat_pack':
                return 'blue';
            case 'cooling':
                return 'orange';
            case 'ups_store_cold':
            case 'ups_store_hot':
            case 'hold_cold':
            case 'hold_hot':
                return 'red';
        }
        return 'grey';
    }

    /**
     * Sort overview rows coolest → hottest by 'high'; unknown highs last. Stable:
     * equal highs keep their input order.
     */
    public static function sort_weather_rows( array $rows ) {
        $indexed = [];
        foreach ( array_values( $rows ) as $i => $row ) {
            $indexed[] = [ $i, $row ];
        }
        usort( $indexed, function ( $a, $b ) {
            $ha = $a[1]['high'] ?? null;
            $hb = $b[1]['high'] ?? null;
            $na = is_numeric( $ha );
            $nb = is_numeric( $hb );
            if ( $na && $nb ) {
                if ( (int) $ha !== (int) $hb ) {
                    return (int) $ha < (int) $hb ? -1 : 1;
                }
            } elseif ( $na !== $nb ) {
                return $na ? -1 : 1; // known highs first
            }
            return $a[0] <=> $b[0];
        } );
        $out = [];
        foreach ( $indexed as $pair ) {
            $out[] = $pair[1];
        }
        return $out;
    }

    /**
     * Totals across destinations: each has 'rows' (kind + qty) and 'weather'
     * (a weather_state() array). 'fish' sums KIND_FISH rows, 'dry' sums KIND_DRY
     * rows; KIND_EA rows (drop-shipped by EverythingAquatic) are in neither.
     */
    public static function totals( array $destinations ) {
        $t = [
            'orders'      => 0,
            'fish'        => 0,
            'dry'         => 0,
            'heat_pack'   => 0,
            'cooling'     => 0,
            'ups_store'   => 0,
            'hold'        => 0,
            'none'        => 0,
            'at_line'     => 0,
            'unavailable' => 0,
            'not_checked' => 0,
        ];
        foreach ( $destinations as $dest ) {
            $t['orders']++;
            foreach ( (array) ( $dest['rows'] ?? [] ) as $row ) {
                $qty = max( 0, (int) ( $row['qty'] ?? 0 ) );
                $kind = (string) ( $row['kind'] ?? '' );
                if ( self::KIND_FISH === $kind ) {
                    $t['fish'] += $qty;
                } elseif ( self::KIND_DRY === $kind ) {
                    $t['dry'] += $qty;
                }
            }
            $w      = is_array( $dest['weather'] ?? null ) ? $dest['weather'] : [];
            $status = (string) ( $w['status'] ?? 'not_checked' );
            if ( 'unavailable' === $status ) {
                $t['unavailable']++;
                continue;
            }
            if ( 'checked' !== $status ) {
                $t['not_checked']++;
                continue;
            }
            if ( ! empty( $w['at_line'] ) ) {
                $t['at_line']++;
            }
            switch ( (string) ( $w['action'] ?? '' ) ) {
                case 'heat_pack':
                    $t['heat_pack']++;
                    break;
                case 'cooling':
                    $t['cooling']++;
                    break;
                case 'ups_store_cold':
                case 'ups_store_hot':
                    $t['ups_store']++;
                    break;
                case 'hold_cold':
                case 'hold_hot':
                    $t['hold']++;
                    break;
                case 'none':
                    $t['none']++;
                    break;
            }
        }
        return $t;
    }

    /** "Order #W (combined: #a, #b)" for a fulfillment, "Order #N" otherwise. */
    public static function order_label( $number, array $source_numbers = [] ) {
        $label = 'Order #' . $number;
        if ( $source_numbers ) {
            $label .= ' (combined: #' . implode( ', #', $source_numbers ) . ')';
        }
        return $label;
    }

    /** "City, ST ZIP" from address parts (missing parts dropped). */
    public static function place( $city, $state, $zip ) {
        $city  = trim( (string) $city );
        $state = trim( (string) $state );
        $zip   = trim( (string) $zip );
        $head  = implode( ', ', array_values( array_filter( [ $city, $state ], 'strlen' ) ) );
        $out   = trim( $head . ' ' . $zip );
        return '' !== $out ? $out : 'unknown';
    }

    /* ─────────────────────────────────────────────
     *  Orders (WordPress / WooCommerce)
     * ───────────────────────────────────────────── */

    /** True when WooCommerce's HPOS orders table is the active order store. */
    public static function hpos_enabled() {
        if ( class_exists( 'FisHotel_Delivery_Rules' ) ) {
            return FisHotel_Delivery_Rules::hpos_enabled();
        }
        $util = '\Automattic\WooCommerce\Utilities\OrderUtil';
        return class_exists( $util )
            && is_callable( [ $util, 'custom_orders_table_usage_is_enabled' ] )
            && (bool) call_user_func( [ $util, 'custom_orders_table_usage_is_enabled' ] );
    }

    /**
     * Store-agnostic order query (the load_booked_map() pattern from
     * class-delivery-rules.php): 'meta_query' is honoured by HPOS only — the CPT
     * store drops it and, since WC 9.2, flags it with wc_doing_it_wrong() — so on
     * CPT the clause rides on a private query var that the
     * woocommerce_order_data_store_cpt_get_orders_query filter maps back into the
     * final WP_Query. Every returned order is re-checked through get_meta() by the
     * callers, so the result is right whatever the backend did.
     *
     * @return object[]
     */
    private static function query_orders( array $args, array $meta_clause ) {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return [];
        }
        $args['type']             = 'shop_order';
        $args['return']           = 'objects';
        $args['fishotel_ship_day'] = 1; // consumed by the CPT pass-through below

        $cpt_passthrough = function ( $query, $query_vars ) use ( $meta_clause ) {
            if ( ! empty( $query_vars['fishotel_ship_day'] ) ) {
                if ( ! isset( $query['meta_query'] ) || ! is_array( $query['meta_query'] ) ) {
                    $query['meta_query'] = [];
                }
                $query['meta_query'][] = $meta_clause;
            }
            return $query;
        };
        if ( self::hpos_enabled() ) {
            $args['meta_query'] = [ $meta_clause ];
        }

        add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $cpt_passthrough, 10, 2 );
        $orders = wc_get_orders( $args );
        remove_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $cpt_passthrough, 10 );

        $out = [];
        foreach ( (array) $orders as $order ) {
            if ( is_object( $order ) && method_exists( $order, 'get_meta' ) ) {
                $out[] = $order;
            }
        }
        return $out;
    }

    /**
     * Open orders delivering on $ymd (folded sources excluded), bounded to LIMIT.
     *
     * @return array{orders:object[],truncated:bool}
     */
    public static function load_orders( $ymd ) {
        if ( ! self::is_ymd( $ymd ) ) {
            return [ 'orders' => [], 'truncated' => false ];
        }
        $rows = self::query_orders(
            [
                'status'  => self::statuses(),
                'limit'   => self::LIMIT + 1,
                'orderby' => 'ID',
                'order'   => 'ASC',
            ],
            [ 'key' => self::META_DELIVERY_DATE, 'value' => $ymd, 'compare' => '=' ]
        );
        $truncated = count( $rows ) > self::LIMIT;
        if ( $truncated ) {
            $rows = array_slice( $rows, 0, self::LIMIT );
        }
        $orders = [];
        foreach ( $rows as $order ) {
            if ( trim( (string) $order->get_meta( self::META_DELIVERY_DATE ) ) !== $ymd ) {
                continue; // backend ignored the filter
            }
            if ( '' !== trim( (string) $order->get_meta( self::META_FULFILLED_BY ) ) ) {
                continue; // folded into a fulfillment — the wrapper carries it
            }
            $orders[] = $order;
        }
        return [ 'orders' => $orders, 'truncated' => $truncated ];
    }

    /**
     * Upcoming delivery dates (today .. today + $days) that have open orders, with
     * counts, from ONE store-agnostic query. Folded sources are not counted.
     *
     * @return array<string,int> Y-m-d => count, ascending
     */
    public static function upcoming_dates( $days = self::LOOKAHEAD, $now = null ) {
        $tz    = self::timezone();
        $today = ( $now instanceof DateTimeInterface )
            ? ( new DateTimeImmutable( '@' . $now->getTimestamp() ) )->setTimezone( $tz )->setTime( 0, 0, 0 )
            : ( new DateTimeImmutable( 'now', $tz ) )->setTime( 0, 0, 0 );
        $from  = $today->format( 'Y-m-d' );
        $to    = $today->modify( '+' . max( 0, (int) $days ) . ' days' )->format( 'Y-m-d' );

        $rows = self::query_orders(
            [ 'status' => self::statuses(), 'limit' => -1 ],
            [ 'key' => self::META_DELIVERY_DATE, 'value' => [ $from, $to ], 'compare' => 'BETWEEN' ]
        );
        $counts = [];
        foreach ( $rows as $order ) {
            $date = trim( (string) $order->get_meta( self::META_DELIVERY_DATE ) );
            if ( ! self::is_ymd( $date ) || $date < $from || $date > $to ) {
                continue;
            }
            if ( '' !== trim( (string) $order->get_meta( self::META_FULFILLED_BY ) ) ) {
                continue;
            }
            $counts[ $date ] = ( $counts[ $date ] ?? 0 ) + 1;
        }
        ksort( $counts );
        return $counts;
    }

    /** The first upcoming date with orders, else today (site zone). */
    public static function default_date( array $upcoming, $today = '' ) {
        if ( '' === $today ) {
            $today = ( new DateTimeImmutable( 'now', self::timezone() ) )->format( 'Y-m-d' );
        }
        foreach ( array_keys( $upcoming ) as $date ) {
            if ( $date >= $today ) {
                return $date;
            }
        }
        return $today;
    }

    /* ─────────────────────────────────────────────
     *  Destinations
     * ───────────────────────────────────────────── */

    private static function is_fulfillment( $order ) {
        return '1' === (string) $order->get_meta( self::META_IS_FULFILLMENT );
    }

    /** Source order IDs listed on a wrapper. */
    public static function source_ids( $order ) {
        $raw = $order->get_meta( self::META_SOURCES );
        $ids = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
        $out = [];
        $own = method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
        foreach ( $ids as $id ) {
            $id = (int) trim( (string) $id );
            if ( $id > 0 && $id !== $own && ! in_array( $id, $out, true ) ) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /**
     * Live source orders of a wrapper (dead statuses skipped). $resolved counts
     * every listed source id that loaded as an order, dead or alive, so the
     * caller can tell "no source could be loaded" from "all sources are dead".
     */
    private static function live_sources( $order, &$resolved = 0 ) {
        $resolved = 0;
        if ( ! function_exists( 'wc_get_order' ) ) {
            return [];
        }
        $out = [];
        foreach ( self::source_ids( $order ) as $sid ) {
            $src = wc_get_order( $sid );
            if ( ! is_object( $src ) || ! method_exists( $src, 'get_items' ) ) {
                continue;
            }
            $resolved++;
            if ( method_exists( $src, 'has_status' ) && $src->has_status( self::DEAD_STATUSES ) ) {
                continue;
            }
            $out[ $sid ] = $src;
        }
        return $out;
    }

    /** Shipping address parts, billing when there is no shipping address. */
    private static function destination_parts( $order ) {
        $use_shipping = '' !== trim( (string) $order->get_shipping_postcode() ) || '' !== trim( (string) $order->get_shipping_city() );
        $p            = $use_shipping ? 'shipping' : 'billing';
        $name         = trim( (string) $order->{"get_{$p}_first_name"}() . ' ' . (string) $order->{"get_{$p}_last_name"}() );
        if ( '' === $name ) {
            $name = trim( (string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name() );
        }
        if ( '' === $name ) {
            $name = 'Guest';
        }
        return [
            'name'      => $name,
            'address_1' => trim( (string) $order->{"get_{$p}_address_1"}() ),
            'address_2' => trim( (string) $order->{"get_{$p}_address_2"}() ),
            'city'      => trim( (string) $order->{"get_{$p}_city"}() ),
            'state'     => trim( (string) $order->{"get_{$p}_state"}() ),
            'postcode'  => trim( (string) $order->{"get_{$p}_postcode"}() ),
        ];
    }

    /** All _fh_weather_* meta of an order as an array keyed by meta name. */
    private static function weather_meta( $order ) {
        $meta = [];
        foreach ( self::WEATHER_META_KEYS as $key ) {
            $v = $order->get_meta( $key );
            if ( is_scalar( $v ) ) {
                $meta[ $key ] = (string) $v;
            }
        }
        return $meta;
    }

    /** Category slugs of a product (its parent for variations). */
    private static function product_category_slugs( $product ) {
        if ( ! is_object( $product ) || ! function_exists( 'get_the_terms' ) ) {
            return [];
        }
        $pid = (int) $product->get_id();
        if ( method_exists( $product, 'get_parent_id' ) && (int) $product->get_parent_id() > 0 ) {
            $pid = (int) $product->get_parent_id();
        }
        $terms = get_the_terms( $pid, 'product_cat' );
        if ( ! is_array( $terms ) ) {
            return [];
        }
        $slugs = [];
        foreach ( $terms as $term ) {
            if ( is_object( $term ) && isset( $term->slug ) ) {
                $slugs[] = (string) $term->slug;
            }
        }
        return $slugs;
    }

    /** Description HTML of a product (the parent's when a variation has none). */
    private static function product_description( $product ) {
        $desc = method_exists( $product, 'get_description' ) ? (string) $product->get_description() : '';
        if ( '' === trim( $desc ) && method_exists( $product, 'get_parent_id' ) && (int) $product->get_parent_id() > 0 && function_exists( 'wc_get_product' ) ) {
            $parent = wc_get_product( (int) $product->get_parent_id() );
            if ( is_object( $parent ) && method_exists( $parent, 'get_description' ) ) {
                $desc = (string) $parent->get_description();
            }
        }
        return $desc;
    }

    /**
     * Pick rows for one order's still-to-ship product lines.
     *
     * @return array<int,array{kind:string,qty:int,name:string,sub:string,flag:string}>
     */
    public static function rows_for_order( $order ) {
        $rows = [];
        if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
            return $rows;
        }
        foreach ( (array) $order->get_items( 'line_item' ) as $item_id => $item ) {
            if ( ! is_object( $item ) || ! method_exists( $item, 'get_quantity' ) ) {
                continue;
            }
            $status = method_exists( $item, 'get_meta' ) ? (string) $item->get_meta( self::META_LINE_STATUS ) : '';
            if ( 'shipped' === $status ) {
                continue; // already left through EA
            }
            if ( 'na' === $status ) {
                continue; // theme: 'na' = never ships (counted as done by all_lines_shipped_or_na) — nothing to pack
            }
            $qty = (int) $item->get_quantity();
            if ( method_exists( $order, 'get_qty_refunded_for_item' ) ) {
                $item_id_int = method_exists( $item, 'get_id' ) ? (int) $item->get_id() : (int) $item_id;
                $qty        -= abs( (int) $order->get_qty_refunded_for_item( $item_id_int ) ); // WC reports refunds as negatives
            }
            if ( $qty <= 0 ) {
                continue; // fully refunded
            }
            $flag = 'backordered' === $status ? 'backordered' : '';
            $name = method_exists( $item, 'get_name' ) ? trim( (string) $item->get_name() ) : '';

            $product = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
            $product = is_object( $product ) ? $product : null;
            $class   = self::classify( self::product_category_slugs( $product ), $name );

            // This plugin's batch-invoice orders (ajax_generate_invoices) add each fish
            // as a product-less line named after the fish, carrying _fishotel_fish_id.
            // No product means no categories, so classify() cannot see it is a fish.
            $fish_id = method_exists( $item, 'get_meta' ) ? trim( (string) $item->get_meta( '_fishotel_fish_id' ) ) : '';
            $is_batch_fish = ( null === $product && '' !== $fish_id && self::CLASS_INVOICE !== $class );
            if ( $is_batch_fish ) {
                $class = self::CLASS_FISH;
            }

            // Lines drop-shipped by EverythingAquatic (theme medication store) are
            // listed so the order reads whole, but Jeff never boxes them.
            $is_ea = false;
            if ( self::CLASS_DRY === $class && null !== $product && function_exists( 'fishotel_is_ea_fulfilled_product' ) ) {
                $pid = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
                $vid = method_exists( $item, 'get_variation_id' ) ? (int) $item->get_variation_id() : 0;
                $is_ea = $pid > 0 && (bool) fishotel_is_ea_fulfilled_product( $pid, $vid );
            }

            if ( self::CLASS_INVOICE === $class ) {
                if ( ! $product ) {
                    $rows[] = [ 'kind' => self::KIND_FISH, 'qty' => $qty, 'name' => 'Invoice — product missing', 'sub' => $name, 'flag' => 'check' ];
                    continue;
                }
                $lines = self::parse_invoice_lines( self::product_description( $product ) );
                if ( empty( $lines ) ) {
                    $rows[] = [ 'kind' => self::KIND_FISH, 'qty' => $qty, 'name' => $name . ' — no fish lines in description', 'sub' => $name, 'flag' => 'check' ];
                    continue;
                }
                foreach ( $lines as $line ) {
                    $rows[] = [
                        'kind' => self::KIND_FISH,
                        'qty'  => max( 1, (int) $line['qty'] ) * $qty,
                        'name' => $line['fish'],
                        'sub'  => $name,
                        'flag' => '' !== $flag ? $flag : $line['flag'],
                    ];
                }
                continue;
            }

            if ( self::CLASS_FISH === $class ) {
                $kind = self::KIND_FISH;
            } else {
                $kind = $is_ea ? self::KIND_EA : self::KIND_DRY;
            }
            $rows[] = [
                'kind' => $kind,
                'qty'  => $qty,
                'name' => '' !== $name ? $name : '(unnamed item)',
                'sub'  => '',
                'flag' => ( '' === $flag && ! $product && ! $is_batch_fish ) ? 'check' : $flag,
            ];
        }
        return $rows;
    }

    /**
     * One destination for the pick list: a regular order, or a fulfillment wrapper
     * with the rows of its live sources.
     */
    public static function build_destination( $order, $ymd ) {
        $is_ff   = self::is_fulfillment( $order );
        $number  = (string) $order->get_order_number();
        $sources = [];
        $rows    = [];

        if ( $is_ff ) {
            $resolved = 0;
            $live     = self::live_sources( $order, $resolved );
            foreach ( $live as $sid => $src ) {
                $sources[] = (string) $src->get_order_number();
                foreach ( self::rows_for_order( $src ) as $row ) {
                    $rows[] = $row;
                }
            }
            if ( empty( $live ) ) {
                if ( 0 === $resolved ) {
                    // No source id loads as an order: fall back to the wrapper's own
                    // copied lines so the box is still listed rather than silently dropped.
                    $rows = self::rows_for_order( $order );
                }
                if ( empty( $rows ) ) {
                    // Every listed source loaded but is cancelled/refunded/failed/trashed
                    // (the theme leaves such a wrapper open): the copies on the wrapper
                    // are those dead orders' fish, so nothing is listed to pick.
                    $rows[] = [ 'kind' => self::KIND_DRY, 'qty' => 0, 'name' => 'Nothing to pick — every source order is cancelled/refunded. Check the fulfillment.', 'sub' => '', 'flag' => 'check' ];
                }
            }
        } else {
            $rows = self::rows_for_order( $order );
        }

        $parts   = self::destination_parts( $order );
        $weather = self::weather_state( self::weather_meta( $order ), $ymd );

        return [
            'order_id'       => (int) $order->get_id(),
            'number'         => $number,
            'label'          => self::order_label( $number, $sources ),
            'is_fulfillment' => $is_ff,
            'sources'        => $sources,
            'customer'       => $parts['name'],
            'place'          => self::place( $parts['city'], $parts['state'], $parts['postcode'] ),
            'address'        => $parts,
            'edit_url'       => method_exists( $order, 'get_edit_order_url' ) ? (string) $order->get_edit_order_url() : '',
            'email'          => (string) $order->get_billing_email(),
            'phone'          => (string) $order->get_billing_phone(),
            'note'           => (string) $order->get_customer_note(),
            'rows'           => $rows,
            'weather'        => $weather,
            'flag'           => self::flag_for_state( $weather ),
            'high'           => $weather['high'],
        ];
    }

    /* ─────────────────────────────────────────────
     *  Rendering
     * ───────────────────────────────────────────── */

    /**
     * Build the whole Ship Day block for a delivery date.
     *
     * @return array|WP_Error {html, orders, fish, truncated}
     */
    public static function render( $ymd, $include_slips = false ) {
        $ymd = trim( (string) $ymd );
        if ( ! self::is_ymd( $ymd ) ) {
            return new WP_Error( 'fishotel_ship_day_date', 'Pick a valid delivery date (Y-m-d).' );
        }
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return new WP_Error( 'fishotel_ship_day_wc', 'WooCommerce is not active.' );
        }

        $loaded = self::load_orders( $ymd );
        $dests  = [];
        foreach ( $loaded['orders'] as $order ) {
            $dests[] = self::build_destination( $order, $ymd );
        }
        $totals = self::totals( $dests );

        $html  = '<div class="fh-sd">';
        $html .= self::render_screen( $ymd, $dests, $totals, $loaded['truncated'] );
        if ( $dests ) {
            $html .= '<div class="fh-sd-print">';
            $html .= self::render_pick_list( $ymd, $dests, $totals );
            $html .= self::render_weather_page( $ymd, $dests );
            if ( $include_slips ) {
                $html .= self::render_slips( $ymd, $dests );
            }
            $html .= '</div>';
        }
        $html .= '</div>';

        return [
            'html'      => $html,
            'orders'    => $totals['orders'],
            'fish'      => $totals['fish'],
            'truncated' => $loaded['truncated'],
        ];
    }

    /** Link to the Weather section's overview for a date. */
    public static function weather_overview_url( $ymd ) {
        if ( ! function_exists( 'admin_url' ) ) {
            return '';
        }
        $url = admin_url( 'admin.php?page=' . self::WEATHER_PAGE_SLUG );
        return function_exists( 'add_query_arg' ) ? add_query_arg( 'date', $ymd, $url ) : $url . '&date=' . rawurlencode( $ymd );
    }

    private static function render_screen( $ymd, array $dests, array $totals, $truncated ) {
        $h  = '<div class="fh-sd-screen fh-no-print">';
        $h .= '<div class="fh-sd-head"><h3>Deliver ' . esc_html( self::label( $ymd ) ) . ' — ship ' . esc_html( self::label( self::ship_date_for( $ymd ) ) ) . '</h3>';
        if ( $dests ) {
            $h .= '<button type="button" class="fh-sd-print-btn" id="fh-sd-print">&#128438; Print</button>';
        }
        $h .= '</div>';

        if ( $truncated ) {
            $h .= '<p class="fh-sd-warn">Showing the first ' . (int) self::LIMIT . ' orders only — this delivery date has more. Split the day or narrow the statuses with the fishotel_ship_day_statuses filter.</p>';
        }
        if ( ! $dests ) {
            $h .= '<p class="fh-sd-empty">No open orders deliver on ' . esc_html( self::label( $ymd, 'l, F j, Y' ) ) . '.</p></div>';
            return $h;
        }

        $chips = [
            [ 'Orders', $totals['orders'], '' ],
            [ 'Fish', $totals['fish'], '' ],
            [ 'Dry goods', $totals['dry'], '' ],
            [ 'Heat packs', $totals['heat_pack'], 'blue' ],
            [ 'Cooling', $totals['cooling'], 'orange' ],
            [ 'UPS Store', $totals['ups_store'], 'red' ],
            [ 'Holds', $totals['hold'], 'red' ],
            [ 'At the line', $totals['at_line'], 'amber' ],
            [ 'Unavailable', $totals['unavailable'], 'grey' ],
            [ 'Not checked', $totals['not_checked'], 'grey' ],
        ];
        $h .= '<div class="fh-sd-chips">';
        foreach ( $chips as $chip ) {
            $h .= '<span class="fh-sd-chip' . ( '' !== $chip[2] ? ' fh-sd-chip--' . esc_attr( $chip[2] ) : '' ) . '"><strong>' . (int) $chip[1] . '</strong> ' . esc_html( $chip[0] ) . '</span>';
        }
        $h .= '</div>';

        $any_meta = false;
        foreach ( $dests as $d ) {
            if ( ! empty( $d['weather']['has_meta'] ) ) {
                $any_meta = true;
                break;
            }
        }
        if ( ! $any_meta ) {
            $h .= '<p class="fh-sd-warn">Weather not checked — enable the Weather section in FisHotel Tools and run the nightly scan. <a href="' . esc_url( self::weather_overview_url( $ymd ) ) . '">Open the Weather overview for ' . esc_html( self::label( $ymd, 'D, M j' ) ) . '</a>.</p>';
        }

        $attention = [];
        foreach ( $dests as $d ) {
            $w      = $d['weather'];
            $status = $w['status'];
            $action = $w['action'];
            $why    = '';
            if ( 'unavailable' === $status ) {
                $why = 'Weather unavailable — check manually' . ( '' !== $w['summary'] ? ' (' . $w['summary'] . ')' : '' );
            } elseif ( 'checked' !== $status ) {
                $why = 'Not checked for this date';
            } elseif ( in_array( $action, [ 'hold_cold', 'hold_hot' ], true ) ) {
                $why = self::action_label( $w ) . ( null !== $w['high'] ? ' — high ' . $w['high'] . '°F' : '' );
            } elseif ( in_array( $action, [ 'ups_store_cold', 'ups_store_hot' ], true ) ) {
                $why = self::action_label( $w ) . ( null !== $w['high'] ? ' — high ' . $w['high'] . '°F' : '' ) . ' · the customer email draft is in the order\'s Weather meta box';
            }
            if ( '' !== $why ) {
                $attention[] = [ $d, $why ];
            }
        }
        if ( $attention ) {
            $h .= '<h4>Needs attention</h4><ul class="fh-sd-attention">';
            foreach ( $attention as $pair ) {
                list( $d, $why ) = $pair;
                $link = '' !== $d['edit_url'] ? '<a href="' . esc_url( $d['edit_url'] ) . '" target="_blank" rel="noopener">' . esc_html( $d['label'] ) . '</a>' : esc_html( $d['label'] );
                $h   .= '<li>' . $link . ' — ' . esc_html( $d['customer'] ) . ', ' . esc_html( $d['place'] ) . ': <strong>' . esc_html( $why ) . '</strong></li>';
            }
            $h .= '</ul>';
        }
        $h .= '</div>';
        return $h;
    }

    private static function render_pick_list( $ymd, array $dests, array $totals ) {
        $ship = self::ship_date_for( $ymd );
        $h    = '<section class="fh-sd-page fh-sd-page--pick">';
        $h   .= '<h1>FisHotel Pick List</h1>';
        $h   .= '<p class="fh-sd-sub">Ship ' . esc_html( self::label( $ship ) ) . ' &bull; Deliver ' . esc_html( self::label( $ymd ) ) . ' &bull; ' . (int) $totals['orders'] . ' orders / ' . (int) $totals['fish'] . ' fish</p>';
        $h   .= '<table class="fh-sd-table fh-sd-table--pick"><thead><tr><th class="fh-sd-col-pick">Pick</th><th class="fh-sd-col-qty">Qty</th><th>Fish</th><th class="fh-sd-col-order">Order</th><th class="fh-sd-col-dest">Destination</th></tr></thead><tbody>';

        foreach ( $dests as $d ) {
            $first = true;
            $rows  = $d['rows'];
            if ( empty( $rows ) ) {
                $rows = [ [ 'kind' => self::KIND_DRY, 'qty' => 0, 'name' => 'Nothing left to ship on this order', 'sub' => '', 'flag' => 'check' ] ];
            }
            foreach ( $rows as $row ) {
                $name = esc_html( $row['name'] );
                if ( self::KIND_DRY === $row['kind'] && 0 !== (int) $row['qty'] ) {
                    $name .= ' <span class="fh-sd-tag">(dry goods)</span>';
                } elseif ( self::KIND_EA === $row['kind'] ) {
                    $name .= ' <span class="fh-sd-tag">(EA SHIPS)</span>';
                }
                if ( 'backordered' === $row['flag'] ) {
                    $name .= ' <span class="fh-sd-tag fh-sd-tag--warn">(BACKORDERED)</span>';
                } elseif ( 'check' === $row['flag'] ) {
                    $name .= ' <span class="fh-sd-tag fh-sd-tag--warn">(CHECK)</span>';
                }
                if ( '' !== $row['sub'] ) {
                    $name .= '<br><span class="fh-sd-sublabel">from ' . esc_html( $row['sub'] ) . '</span>';
                }
                $h .= '<tr class="' . ( $first ? 'fh-sd-row-first' : 'fh-sd-row' ) . '">';
                $h .= '<td class="fh-sd-col-pick"><span class="fh-sd-box"></span></td>';
                $h .= '<td class="fh-sd-col-qty">' . (int) $row['qty'] . '</td>';
                $h .= '<td>' . $name . '</td>';
                if ( $first ) {
                    $h .= '<td class="fh-sd-col-order"><strong>' . esc_html( $d['label'] ) . '</strong><br>' . esc_html( $d['customer'] ) . '</td>';
                    $h .= '<td class="fh-sd-col-dest">' . esc_html( $d['place'] ) . ( '' !== $d['flag'] ? '<br><strong class="fh-sd-flag">' . esc_html( $d['flag'] ) . '</strong>' : '' ) . '</td>';
                } else {
                    $h .= '<td class="fh-sd-col-order"></td><td class="fh-sd-col-dest"></td>';
                }
                $h    .= '</tr>';
                $first = false;
            }
        }
        $h .= '</tbody></table>';
        $h .= '<p class="fh-sd-foot"><strong>' . (int) $totals['fish'] . ' fish &middot; ' . (int) $totals['dry'] . ' dry goods &middot; ' . (int) $totals['orders'] . ' orders</strong></p>';
        $h .= '<p class="fh-sd-legend">Legend: (HEAT PACK) heat pack in the box &middot; (COOLING PACKS) cooling packs &middot; (UPS STORE) ship to a UPS Store, email draft in the order\'s Weather box &middot; (HOLD) do not ship &middot; (AT THE LINE — Jeff\'s call) high exactly on a band line &middot; (NOT CHECKED) no weather note for this date &middot; (dry goods) box it, not a fish &middot; (EA SHIPS) drop-shipped by EverythingAquatic, not in this box &middot; (BACKORDERED) not in stock &middot; (CHECK) verify by hand</p>';
        $h .= '</section>';
        return $h;
    }

    private static function render_weather_page( $ymd, array $dests ) {
        $rows = [];
        foreach ( $dests as $d ) {
            $rows[] = [
                'number' => $d['label'],
                'place'  => $d['place'],
                'high'   => $d['weather']['high'],
                'low'    => $d['weather']['low'],
                'cond'   => 'checked' === $d['weather']['status'] ? $d['weather']['conditions'] : ( 'unavailable' === $d['weather']['status'] ? $d['weather']['summary'] : '' ),
                'action' => self::action_label( $d['weather'] ),
                'tone'   => self::row_tone( $d['weather'] ),
                'dest'   => $d,
            ];
        }
        $rows = self::sort_weather_rows( $rows );

        $h  = '<section class="fh-sd-page fh-sd-page--weather">';
        $h .= '<h1>Weather Overview — Delivery ' . esc_html( self::label( $ymd ) ) . '</h1>';
        $h .= '<p class="fh-sd-sub">' . esc_html( self::CHART_SUBTITLE ) . '</p>';
        $h .= '<table class="fh-sd-table fh-sd-table--weather"><thead><tr><th>Order</th><th>Destination</th><th class="fh-sd-col-num">High</th><th class="fh-sd-col-num">Low</th><th>Conditions</th><th>Action</th></tr></thead><tbody>';
        foreach ( $rows as $r ) {
            $h .= '<tr class="fh-sd-tone-' . esc_attr( $r['tone'] ) . '">';
            $h .= '<td>' . esc_html( $r['number'] ) . '</td>';
            $h .= '<td>' . esc_html( $r['place'] ) . '</td>';
            $h .= '<td class="fh-sd-col-num">' . ( null === $r['high'] ? '—' : (int) $r['high'] . '°F' ) . '</td>';
            $h .= '<td class="fh-sd-col-num">' . ( null === $r['low'] ? '—' : (int) $r['low'] . '°F' ) . '</td>';
            $h .= '<td>' . esc_html( $r['cond'] ) . '</td>';
            $h .= '<td>' . esc_html( $r['action'] ) . '</td>';
            $h .= '</tr>';
        }
        $h .= '</tbody></table>';

        $notes      = [];
        $alerts     = [];
        $next_day   = [];
        $checked_at = '';
        $any_checked = false;
        foreach ( $dests as $d ) {
            $w = $d['weather'];
            if ( 'checked' === $w['status'] ) {
                $any_checked = true;
                if ( $w['checked_at'] > $checked_at ) {
                    $checked_at = $w['checked_at'];
                }
            }
            if ( ! empty( $w['alerts'] ) ) {
                $alerts[] = $d['label'] . ' (' . $d['place'] . '): ' . implode( ', ', $w['alerts'] );
            }
            if ( '' !== $w['next_day'] ) {
                $next_day[] = $d['label'] . ' (' . $d['place'] . '): ' . $w['next_day'];
            }
        }
        if ( $alerts ) {
            $notes[] = 'Active NWS alerts — ' . implode( '; ', $alerts );
        }
        if ( $next_day ) {
            $notes[] = 'Next-day extremes, do not slip delivery — ' . implode( '; ', $next_day );
        }
        if ( $any_checked ) {
            $when    = '' !== $checked_at ? self::datetime_label( $checked_at ) : self::label( $ymd );
            $notes[] = 'Notes written in WooCommerce by the Weather section on ' . $when;
        }
        $notes[] = 'Data: National Weather Service (api.weather.gov) via the FisHotel Tools Weather section nightly scan' . ( '' !== $checked_at ? '; last pulled ' . self::datetime_label( $checked_at ) : '' );

        $h .= '<ul class="fh-sd-notes">';
        foreach ( $notes as $n ) {
            $h .= '<li>' . esc_html( $n ) . '</li>';
        }
        $h .= '</ul></section>';
        return $h;
    }

    /** 'Y-m-d H:i:s' (site-local, as the Weather section stores it) → 'l, F j, g:i a'. */
    public static function datetime_label( $mysql ) {
        $mysql = trim( (string) $mysql );
        if ( '' === $mysql ) {
            return '';
        }
        try {
            $d = new DateTimeImmutable( $mysql, self::timezone() );
            return $d->format( 'l, F j, g:i a' );
        } catch ( Exception $e ) {
            return $mysql;
        }
    }

    /** Per-destination packing slips — the old Packing Lists card markup, one card per box. */
    private static function render_slips( $ymd, array $dests ) {
        $h  = '<div class="fh-packing-list fh-sd-slips">';
        $h .= '<div class="fh-packing-list-header"><h1>FisHotel Packing List</h1><h2>Delivery Date: ' . esc_html( self::label( $ymd, 'l, F j, Y' ) ) . '</h2><p>Total Orders: ' . count( $dests ) . '</p></div>';
        foreach ( $dests as $d ) {
            $a  = $d['address'];
            $h .= '<div class="fh-packing-list-order">';
            $h .= '<div class="fh-packing-order-header"><h3>' . esc_html( $d['label'] ) . ' &mdash; ' . esc_html( $d['customer'] ) . '</h3>';
            if ( '' !== $d['flag'] ) {
                $h .= '<p><strong>Weather:</strong> ' . esc_html( $d['flag'] ) . '</p>';
            }
            $h .= '<p><strong>Email:</strong> ' . esc_html( $d['email'] ) . '</p><p><strong>Phone:</strong> ' . esc_html( $d['phone'] ) . '</p></div>';
            $h .= '<div class="fh-packing-address"><h4>Shipping Address</h4><p>' . esc_html( $a['name'] ) . '<br>' . esc_html( $a['address_1'] ) . '<br>' . ( '' !== $a['address_2'] ? esc_html( $a['address_2'] ) . '<br>' : '' ) . esc_html( $d['place'] ) . '</p></div>';
            $h .= '<div class="fh-packing-items"><h4>Fish to Pack</h4><table class="fh-packing-table"><thead><tr><th>Species</th><th>Qty</th><th style="width:60px;">Packed</th></tr></thead><tbody>';
            foreach ( $d['rows'] as $row ) {
                $name = esc_html( $row['name'] );
                if ( self::KIND_DRY === $row['kind'] && 0 !== (int) $row['qty'] ) {
                    $name .= ' (dry goods)';
                } elseif ( self::KIND_EA === $row['kind'] ) {
                    $name .= ' (EA SHIPS)';
                }
                if ( 'backordered' === $row['flag'] ) {
                    $name .= ' (BACKORDERED)';
                } elseif ( 'check' === $row['flag'] ) {
                    $name .= ' (CHECK)';
                }
                if ( '' !== $row['sub'] ) {
                    $name .= ' <em>— from ' . esc_html( $row['sub'] ) . '</em>';
                }
                $h .= '<tr><td>' . $name . '</td><td>' . (int) $row['qty'] . '</td><td><span class="fh-sd-box"></span></td></tr>';
            }
            $h .= '</tbody></table></div>';
            $h .= '<div class="fh-packing-notes"><h4>Customer Notes</h4><p>' . ( '' !== trim( $d['note'] ) ? esc_html( $d['note'] ) : '<em>None</em>' ) . '</p></div>';
            $h .= '<div class="fh-packing-signature"><p>Packed by: ______________________ &nbsp;&nbsp; Date: ______________________</p></div>';
            $h .= '</div><div class="fh-packing-page-break"></div>';
        }
        $h .= '</div>';
        return $h;
    }
}
