<?php
/**
 * FisHotel Delivery Rules — the single source of truth for delivery-date rules.
 *
 * Every place that decides whether a customer may pick a delivery date must go
 * through this class:
 *
 *   - the checkout delivery-date dropdown and its two validators
 *     (includes/class-woocommerce.php),
 *   - the post-checkout safety net and status check,
 *   - the admin Edit Order delivery-date field (admin override + warning),
 *   - the Delivery settings page "Next 30 days" preview (includes/class-admin.php),
 *   - and, in a later PR, the theme's My Account self-serve picker:
 *     FisHotel-Theme/inc/customer/shipping-days-helper.php and
 *     inc/customer/class-fishotel-self-serve-date.php currently carry their own
 *     copy of these rules. They should delegate when the plugin is active:
 *
 *       if ( function_exists( 'fishotel_delivery_rules' ) ) {
 *           $rules = fishotel_delivery_rules();
 *           // fishotel_get_allowed_shipping_settings():
 *           $s = $rules->settings();   // keys: days, blacklist, min_advance, max_ahead, max_per_day
 *           //   theme key 'max_days_ahead' => $s['max_ahead'], 'blocked_dates' => $s['blacklist'],
 *           //   'days' => array_map( 'ucfirst', $s['days'] )
 *           // fishotel_is_valid_delivery_date( $ymd ) (pure rules, no capacity):
 *           $r = $rules->why_not( $ymd, null, false );
 *           return [ 'ok' === $r['code'], $r['message'] ];
 *           // FisHotel_Self_Serve_Date::count_orders_for_date( $ymd ):
 *           return $rules->booked_for( $ymd );
 *           // FisHotel_Self_Serve_Date::get_available_dates(): start from
 *           $rules->allowed_dates();   // then layer the theme-only 48h cutoff + "(current)" slot on top
 *       }
 *
 * RULES (all calendar math happens in the DateTimeZone handed to the constructor —
 * wp_timezone() in production — using DateTimeImmutable; never UTC, never the
 * PHP default zone):
 *
 *   today        The calendar date of "now" in that zone. There is NO cutoff hour:
 *                the previous checkout code had none, so at 7:01 pm Central on a
 *                Monday the calendar day is still Monday and the window is the same
 *                as at 6:59 pm. (The theme's self-serve picker layers its own 48-hour
 *                change cutoff on top; that is a theme concern, not a rule here.)
 *   min_advance  Earliest allowed date = today + min_advance days (0 = today allowed).
 *   max_ahead    Latest allowed date   = today + max_ahead days, inclusive — matches the
 *                option label "Max Days Ahead" and the theme validator. (The old checkout
 *                listed max_ahead dates *starting* at min_advance, i.e. one day further
 *                out per extra lead day; with the default min_advance=1 both agree.)
 *   days         Allowed weekdays. Compared case-insensitively against full English
 *                names — the Batch plugin stores ucfirst names ('Monday').
 *   blacklist    Explicitly blocked Y-m-d strings (holidays).
 *   max_per_day  Capacity per delivery date; 0 = unlimited. A date is "full" when
 *                booked >= max_per_day, where booked comes from the load counter.
 *
 * why_not() evaluates in this order and reports the first failure:
 *   invalid → too_soon → too_far → blacklisted → closed_day → full → ok
 * Pure calendar checks run first so the only DB-backed check (capacity) is skipped
 * for dates that are out of the window anyway.
 *
 * BOOKED COUNTS (the real load counter, self::booked_count()):
 *   Counts orders whose _fishotel_shipping_date equals the day and whose status is
 *   processing, on-hold or fulfillment (filter: fishotel_delivery_rules_count_statuses),
 *   excluding orders that have been combined into a fulfillment (meta
 *   _fishotel_fulfilled_by_order non-empty) so a combined box counts once — the
 *   fulfillment wrapper carries the date and is counted instead.
 *
 *   Pending (unpaid) orders NO LONGER count. The old checkout counted wc-pending,
 *   which meant abandoned and failed checkouts — orders that never ship — held
 *   capacity slots and could show a day as "full" to real customers until WooCommerce
 *   cancelled them hours later. An order only consumes a packing slot once it is
 *   paid (processing), awaiting manual payment confirmation (on-hold) or combined
 *   into a fulfillment. Completed/shipped orders are not counted either: the cap
 *   protects packing capacity and a box that has left no longer needs it. Sites that
 *   want other statuses counted can add them through the filter.
 *
 *   The count is store-agnostic: on the post-based (CPT) order store WooCommerce
 *   drops a caller-supplied 'meta_query' (WC_Data_Store_WP::get_wp_query_args() skips
 *   the key) and, since WC 9.2, WC_Order_Data_Store_CPT::query() raises a
 *   wc_doing_it_wrong() notice for it — so on CPT the meta filter is passed only as a
 *   private query var that the woocommerce_order_data_store_cpt_get_orders_query
 *   filter maps back into a meta_query, and 'meta_query' itself is sent only when HPOS
 *   (which honours it directly and ignores the private var) is the active store. Every
 *   returned order is then re-checked through $order->get_meta(), so the count is
 *   right whatever the storage backend does. All open orders are loaded with ONE query
 *   per request and cached in a per-request map, so asking for 30 dates costs the
 *   same as asking for one.
 *
 * Settings come from the five public options (names unchanged):
 *   fishotel_shipping_days, fishotel_shipping_blacklist, fishotel_shipping_min_advance,
 *   fishotel_shipping_max_ahead, fishotel_shipping_max_per_day
 * and pass through the 'fishotel_delivery_rules_settings' filter (normalised array).
 * fishotel_delivery_rules() returns a memoised instance that is rebuilt whenever one
 * of those options is added, updated or deleted.
 *
 * This file has no side effects at load time and no WordPress dependency in the
 * instance methods, so tests/delivery-rules-test.php can run it with plain php.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FisHotel_Delivery_Rules {

    const OPTION_DAYS        = 'fishotel_shipping_days';
    const OPTION_BLACKLIST   = 'fishotel_shipping_blacklist';
    const OPTION_MIN_ADVANCE = 'fishotel_shipping_min_advance';
    const OPTION_MAX_AHEAD   = 'fishotel_shipping_max_ahead';
    const OPTION_MAX_PER_DAY = 'fishotel_shipping_max_per_day';

    const OPTION_NAMES = [
        self::OPTION_DAYS,
        self::OPTION_BLACKLIST,
        self::OPTION_MIN_ADVANCE,
        self::OPTION_MAX_AHEAD,
        self::OPTION_MAX_PER_DAY,
    ];

    const DEFAULT_DAYS        = [ 'Monday', 'Tuesday', 'Wednesday' ];
    const DEFAULT_MIN_ADVANCE = 1;
    const DEFAULT_MAX_AHEAD   = 30;
    const DEFAULT_MAX_PER_DAY = 6;

    /** Canonical week order, lowercase. */
    const DAY_NAMES = [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ];

    const META_DELIVERY_DATE = '_fishotel_shipping_date';
    const META_FULFILLED_BY  = '_fishotel_fulfilled_by_order';

    const CODE_OK          = 'ok';
    const CODE_INVALID     = 'invalid';
    const CODE_TOO_SOON    = 'too_soon';
    const CODE_TOO_FAR     = 'too_far';
    const CODE_BLACKLISTED = 'blacklisted';
    const CODE_CLOSED_DAY  = 'closed_day';
    const CODE_FULL        = 'full';

    const LABEL_SHORT = 'D, M j';
    const LABEL_LONG  = 'l, F j, Y';

    /** @var string[] lowercase weekday names, canonical order */
    private $days = [];
    /** @var string[] Y-m-d */
    private $blacklist = [];
    /** @var int */
    private $min_advance = self::DEFAULT_MIN_ADVANCE;
    /** @var int */
    private $max_ahead = self::DEFAULT_MAX_AHEAD;
    /** @var int 0 = unlimited */
    private $max_per_day = self::DEFAULT_MAX_PER_DAY;
    /** @var DateTimeZone */
    private $tz;
    /** @var callable|null fn( string $ymd ): int */
    private $load_counter;
    /** @var array<string,int> per-instance memo of booked counts */
    private $booked = [];

    /** @var self|null */
    private static $shared = null;
    /** @var array<string,int>|null per-request map ymd => booked, built by load_booked_map() */
    private static $booked_map = null;

    /**
     * @param array        $settings     Any of: days, blacklist, min_advance, max_ahead, max_per_day
     *                                   (theme aliases blocked_dates / max_days_ahead are accepted).
     *                                   Missing keys fall back to the defaults.
     * @param DateTimeZone $tz           The zone every calendar computation happens in.
     * @param callable|null $load_counter fn( string $ymd ): int — booked orders for that date.
     *                                   null = nothing is ever booked (pure calendar rules only).
     */
    public function __construct( array $settings, DateTimeZone $tz, $load_counter = null ) {
        $s = self::normalize_settings( $settings );

        $this->days        = $s['days'];
        $this->blacklist   = $s['blacklist'];
        $this->min_advance = $s['min_advance'];
        $this->max_ahead   = $s['max_ahead'];
        $this->max_per_day = $s['max_per_day'];
        $this->tz          = $tz;

        if ( null !== $load_counter && ! is_callable( $load_counter ) ) {
            throw new InvalidArgumentException( 'FisHotel_Delivery_Rules: $load_counter must be callable or null.' );
        }
        $this->load_counter = $load_counter;
    }

    /* ─────────────────────────────────────────────
     *  Construction helpers
     * ───────────────────────────────────────────── */

    /**
     * Build from the five public options + wp_timezone() + the real counter.
     * The normalised settings pass through 'fishotel_delivery_rules_settings'.
     */
    public static function from_options() {
        $raw = [
            'days'        => get_option( self::OPTION_DAYS,        self::DEFAULT_DAYS ),
            'blacklist'   => get_option( self::OPTION_BLACKLIST,   [] ),
            'min_advance' => get_option( self::OPTION_MIN_ADVANCE, self::DEFAULT_MIN_ADVANCE ),
            'max_ahead'   => get_option( self::OPTION_MAX_AHEAD,   self::DEFAULT_MAX_AHEAD ),
            'max_per_day' => get_option( self::OPTION_MAX_PER_DAY, self::DEFAULT_MAX_PER_DAY ),
        ];
        $settings = self::normalize_settings( $raw );
        $filtered = apply_filters( 'fishotel_delivery_rules_settings', $settings );
        if ( is_array( $filtered ) ) {
            $settings = $filtered; // re-normalised by the constructor
        }

        $tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'America/Chicago' );

        return new self( $settings, $tz, [ __CLASS__, 'booked_count' ] );
    }

    /** Memoised instance for the request (see fishotel_delivery_rules()). */
    public static function shared( $fresh = false ) {
        if ( $fresh || ! self::$shared instanceof self ) {
            self::$shared = self::from_options();
        }
        return self::$shared;
    }

    /** Drop the memoised instance; the next shared() call re-reads the options. */
    public static function reset_shared() {
        self::$shared = null;
    }

    /** Forget cached booked counts (per-request map and the shared instance's memo). */
    public static function flush_booked_cache() {
        self::$booked_map = null;
        if ( self::$shared instanceof self ) {
            self::$shared->flush_booked();
        }
    }

    /**
     * Hook the cache resets into WordPress. Called once from the main plugin file.
     */
    public static function register_hooks() {
        if ( ! function_exists( 'add_action' ) ) {
            return;
        }
        foreach ( self::OPTION_NAMES as $option ) {
            add_action( 'add_option_' . $option,    [ __CLASS__, 'reset_shared' ] );
            add_action( 'update_option_' . $option, [ __CLASS__, 'reset_shared' ] );
            add_action( 'delete_option_' . $option, [ __CLASS__, 'reset_shared' ] );
        }
        foreach ( [ 'woocommerce_new_order', 'woocommerce_update_order', 'woocommerce_delete_order', 'woocommerce_trash_order', 'woocommerce_untrash_order', 'woocommerce_order_status_changed' ] as $hook ) {
            add_action( $hook, [ __CLASS__, 'flush_booked_cache' ] );
        }
    }

    /**
     * Normalise a raw settings array (option values, filter output, theme shapes)
     * into the canonical shape:
     *   days        string[] lowercase full English names in week order (may be empty)
     *   blacklist   string[] valid Y-m-d, unique, sorted
     *   min_advance int >= 0
     *   max_ahead   int >= 1
     *   max_per_day int >= 0 (0 = unlimited)
     */
    public static function normalize_settings( array $raw ) {
        // Theme aliases.
        if ( ! isset( $raw['blacklist'] ) && isset( $raw['blocked_dates'] ) ) {
            $raw['blacklist'] = $raw['blocked_dates'];
        }
        if ( ! isset( $raw['max_ahead'] ) && isset( $raw['max_days_ahead'] ) ) {
            $raw['max_ahead'] = $raw['max_days_ahead'];
        }

        // A non-array (corrupt option) falls back to the defaults; an empty array is a
        // legitimate "no delivery days enabled".
        $days_in = ( isset( $raw['days'] ) && is_array( $raw['days'] ) ) ? $raw['days'] : self::DEFAULT_DAYS;
        $seen    = [];
        foreach ( $days_in as $d ) {
            $d = strtolower( trim( (string) $d ) );
            if ( in_array( $d, self::DAY_NAMES, true ) ) {
                $seen[ $d ] = true;
            }
        }
        $days = [];
        foreach ( self::DAY_NAMES as $d ) {
            if ( isset( $seen[ $d ] ) ) {
                $days[] = $d;
            }
        }

        $blacklist = [];
        $bl_in     = ( isset( $raw['blacklist'] ) && is_array( $raw['blacklist'] ) ) ? $raw['blacklist'] : [];
        foreach ( $bl_in as $b ) {
            $b = trim( (string) $b );
            if ( self::is_ymd( $b ) ) {
                $blacklist[ $b ] = true;
            }
        }
        $blacklist = array_keys( $blacklist );
        sort( $blacklist );

        return [
            'days'        => $days,
            'blacklist'   => $blacklist,
            'min_advance' => max( 0, (int) ( $raw['min_advance'] ?? self::DEFAULT_MIN_ADVANCE ) ),
            'max_ahead'   => max( 1, (int) ( $raw['max_ahead']   ?? self::DEFAULT_MAX_AHEAD ) ),
            'max_per_day' => max( 0, (int) ( $raw['max_per_day'] ?? self::DEFAULT_MAX_PER_DAY ) ),
        ];
    }

    /* ─────────────────────────────────────────────
     *  Getters
     * ───────────────────────────────────────────── */

    /** Canonical settings array (see normalize_settings()). */
    public function settings() {
        return [
            'days'        => $this->days,
            'blacklist'   => $this->blacklist,
            'min_advance' => $this->min_advance,
            'max_ahead'   => $this->max_ahead,
            'max_per_day' => $this->max_per_day,
        ];
    }

    public function timezone() {
        return $this->tz;
    }

    /** "Now" as a DateTimeImmutable in the rules' zone. */
    public function now( $now = null ) {
        if ( $now instanceof DateTimeInterface ) {
            $dt = new DateTimeImmutable( '@' . $now->getTimestamp() );
            return $dt->setTimezone( $this->tz );
        }
        if ( is_int( $now ) || ( is_string( $now ) && ctype_digit( $now ) ) ) {
            $dt = new DateTimeImmutable( '@' . (int) $now );
            return $dt->setTimezone( $this->tz );
        }
        if ( is_string( $now ) && '' !== trim( $now ) ) {
            // A bare string is read in the rules' zone. When the string carries its own
            // offset/zone PHP ignores the $tz argument, so convert explicitly — otherwise
            // today() would take midnight in the string's zone (a no-op for bare strings).
            $dt = new DateTimeImmutable( $now, $this->tz );
            return $dt->setTimezone( $this->tz );
        }
        return new DateTimeImmutable( 'now', $this->tz );
    }

    /** Local midnight of the calendar day "now" falls on, in the rules' zone. */
    public function today( $now = null ) {
        return $this->now( $now )->setTime( 0, 0, 0 );
    }

    /** Pretty label for a Y-m-d ('' when not a valid date). Computed in the rules' zone. */
    public function format_label( $ymd, $format = self::LABEL_LONG ) {
        $d = $this->parse_ymd( $ymd );
        return $d ? $d->format( $format ) : '';
    }

    /* ─────────────────────────────────────────────
     *  Rules
     * ───────────────────────────────────────────── */

    /**
     * Every date in the window that passes the calendar rules, oldest first.
     * Full dates are included with open=false so callers can show them greyed out;
     * the checkout only offers rows with open=true.
     *
     * @return array<int,array{date:string,label:string,long_label:string,weekday:string,capacity:int,booked:int,open:bool}>
     */
    public function allowed_dates( $now = null ) {
        $today = $this->today( $now );
        $out   = [];
        for ( $i = $this->min_advance; $i <= $this->max_ahead; $i++ ) {
            $day = $today->modify( '+' . $i . ' days' );
            if ( ! $day || ! $this->passes_calendar( $day ) ) {
                continue;
            }
            $out[] = $this->row( $day );
        }
        return $out;
    }

    /**
     * Every calendar day from today for $days days (default 30), with the reason
     * code/message for each — for the admin "Next 30 days" preview.
     *
     * @return array<int,array{date:string,label:string,long_label:string,weekday:string,code:string,message:string,capacity:int,booked:int,open:bool}>
     */
    public function calendar( $now = null, $days = 30 ) {
        $today = $this->today( $now );
        $days  = max( 1, (int) $days );
        $out   = [];
        for ( $i = 0; $i < $days; $i++ ) {
            $day = $today->modify( '+' . $i . ' days' );
            if ( ! $day ) {
                continue;
            }
            $why            = $this->why_not( $day->format( 'Y-m-d' ), $today );
            $row            = $this->row( $day );
            $row['code']    = $why['code'];
            $row['message'] = $why['message'];
            $row['open']    = self::CODE_OK === $why['code'];
            $out[]          = $row;
        }
        return $out;
    }

    /** True when the date passes every rule (capacity included unless $check_capacity is false). */
    public function is_allowed( $ymd, $now = null, $check_capacity = true ) {
        return self::CODE_OK === $this->why_not( $ymd, $now, $check_capacity )['code'];
    }

    /**
     * Why a date cannot be chosen.
     *
     * @return array{code:string,message:string} code is one of
     *         ok | invalid | too_soon | too_far | blacklisted | closed_day | full
     */
    public function why_not( $ymd, $now = null, $check_capacity = true ) {
        $date = $this->parse_ymd( $ymd );
        if ( ! $date ) {
            return $this->reason( self::CODE_INVALID, 'That is not a valid delivery date.' );
        }

        $today    = $this->today( $now );
        $days_out = $this->days_between( $today, $date );

        if ( $days_out < 0 ) {
            return $this->reason( self::CODE_TOO_SOON, $date->format( self::LABEL_LONG ) . ' has already passed.' );
        }
        if ( $days_out < $this->min_advance ) {
            $earliest = $today->modify( '+' . $this->min_advance . ' days' );
            return $this->reason(
                self::CODE_TOO_SOON,
                sprintf(
                    'Deliveries need at least %d %s notice — the earliest date you can choose is %s.',
                    $this->min_advance,
                    1 === $this->min_advance ? 'day of' : 'days of',
                    $earliest->format( self::LABEL_LONG )
                )
            );
        }
        if ( $days_out > $this->max_ahead ) {
            $latest = $today->modify( '+' . $this->max_ahead . ' days' );
            return $this->reason(
                self::CODE_TOO_FAR,
                sprintf(
                    'We schedule deliveries up to %d days ahead — the latest date you can choose is %s.',
                    $this->max_ahead,
                    $latest->format( self::LABEL_LONG )
                )
            );
        }
        if ( in_array( $date->format( 'Y-m-d' ), $this->blacklist, true ) ) {
            return $this->reason( self::CODE_BLACKLISTED, 'We are not delivering on ' . $date->format( self::LABEL_LONG ) . '.' );
        }
        if ( ! $this->is_delivery_day( $date ) ) {
            $msg = empty( $this->days )
                ? 'No delivery days are currently enabled.'
                : sprintf( 'We don\'t deliver on %ss — delivery days are %s.', $date->format( 'l' ), $this->day_list() );
            return $this->reason( self::CODE_CLOSED_DAY, $msg );
        }
        if ( $check_capacity ) {
            $cap = $this->capacity_for( $date->format( 'Y-m-d' ) );
            if ( $cap['full'] ) {
                return $this->reason(
                    self::CODE_FULL,
                    sprintf( '%s is fully booked (%d of %d deliveries). Please choose another date.', $date->format( self::LABEL_LONG ), $cap['booked'], $cap['capacity'] )
                );
            }
        }
        return $this->reason( self::CODE_OK, 'Available.' );
    }

    /** The first open date in the window, as Y-m-d, or null when nothing is open. */
    public function next_available( $now = null ) {
        foreach ( $this->allowed_dates( $now ) as $row ) {
            if ( $row['open'] ) {
                return $row['date'];
            }
        }
        return null;
    }

    /**
     * Capacity picture for a date.
     *
     * @return array{capacity:int,booked:int,remaining:int|null,full:bool}
     *         capacity 0 = unlimited (remaining null, never full).
     */
    public function capacity_for( $ymd ) {
        $booked = $this->booked_for( $ymd );
        $cap    = $this->max_per_day;
        return [
            'capacity'  => $cap,
            'booked'    => $booked,
            'remaining' => $cap > 0 ? max( 0, $cap - $booked ) : null,
            'full'      => $cap > 0 && $booked >= $cap,
        ];
    }

    /** Booked orders for a date via the load counter (memoised per instance). */
    public function booked_for( $ymd ) {
        $ymd = trim( (string) $ymd );
        if ( ! self::is_ymd( $ymd ) ) {
            return 0;
        }
        if ( ! array_key_exists( $ymd, $this->booked ) ) {
            $this->booked[ $ymd ] = $this->load_counter ? max( 0, (int) call_user_func( $this->load_counter, $ymd ) ) : 0;
        }
        return $this->booked[ $ymd ];
    }

    /** Forget this instance's memoised counts. */
    public function flush_booked() {
        $this->booked = [];
    }

    /** Human list of the enabled delivery days: "Monday, Tuesday, and Wednesday". */
    public function day_list() {
        $names = array_map( 'ucfirst', $this->days );
        $n     = count( $names );
        if ( 0 === $n ) {
            return '';
        }
        if ( 1 === $n ) {
            return $names[0];
        }
        if ( 2 === $n ) {
            return $names[0] . ' and ' . $names[1];
        }
        $last = array_pop( $names );
        return implode( ', ', $names ) . ', and ' . $last;
    }

    /* ─────────────────────────────────────────────
     *  Internals
     * ───────────────────────────────────────────── */

    private function reason( $code, $message ) {
        return [ 'code' => $code, 'message' => $message ];
    }

    private function row( DateTimeImmutable $day ) {
        $ymd = $day->format( 'Y-m-d' );
        $cap = $this->capacity_for( $ymd );
        return [
            'date'       => $ymd,
            'label'      => $day->format( self::LABEL_SHORT ),
            'long_label' => $day->format( self::LABEL_LONG ),
            'weekday'    => $day->format( 'l' ),
            'capacity'   => $cap['capacity'],
            'booked'     => $cap['booked'],
            'open'       => ! $cap['full'],
        ];
    }

    private function passes_calendar( DateTimeImmutable $day ) {
        return ! in_array( $day->format( 'Y-m-d' ), $this->blacklist, true ) && $this->is_delivery_day( $day );
    }

    private function is_delivery_day( DateTimeImmutable $day ) {
        return in_array( strtolower( $day->format( 'l' ) ), $this->days, true );
    }

    /**
     * Whole calendar days from $from to $to (both local midnights in the rules' zone).
     * round(), not floor(): a DST change between the two midnights makes the gap
     * 23h/25h, which floor() would miscount by a day.
     */
    private function days_between( DateTimeImmutable $from, DateTimeImmutable $to ) {
        return (int) round( ( $to->getTimestamp() - $from->getTimestamp() ) / 86400 );
    }

    /** Y-m-d string → local midnight DateTimeImmutable in the rules' zone, or null. */
    private function parse_ymd( $ymd ) {
        $ymd = trim( (string) $ymd );
        if ( ! self::is_ymd( $ymd ) ) {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, $this->tz );
        if ( ! $d || $d->format( 'Y-m-d' ) !== $ymd ) {
            return null;
        }
        return $d;
    }

    /** Strict Y-m-d shape + real calendar date. */
    public static function is_ymd( $ymd ) {
        if ( ! is_string( $ymd ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m ) ) {
            return false;
        }
        return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
    }

    /* ─────────────────────────────────────────────
     *  Real load counter (WordPress / WooCommerce)
     * ───────────────────────────────────────────── */

    /** Statuses that consume a delivery slot (unprefixed), filterable. */
    public static function count_statuses() {
        $default  = [ 'processing', 'on-hold', 'fulfillment' ];
        $statuses = function_exists( 'apply_filters' )
            ? apply_filters( 'fishotel_delivery_rules_count_statuses', $default )
            : $default;
        $out = [];
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

    /** True when WooCommerce's HPOS orders table is the active order store. */
    public static function hpos_enabled() {
        $util = '\Automattic\WooCommerce\Utilities\OrderUtil';
        return class_exists( $util )
            && is_callable( [ $util, 'custom_orders_table_usage_is_enabled' ] )
            && (bool) call_user_func( [ $util, 'custom_orders_table_usage_is_enabled' ] );
    }

    /**
     * The real counter handed to from_options(): booked orders for one Y-m-d.
     * See the class docblock ("BOOKED COUNTS") for exactly what is counted and why.
     */
    public static function booked_count( $ymd ) {
        $map = self::load_booked_map();
        return isset( $map[ $ymd ] ) ? (int) $map[ $ymd ] : 0;
    }

    /**
     * Per-request map of Y-m-d => booked count for every open order, built with a
     * single store-agnostic query (see the class docblock).
     *
     * @return array<string,int>
     */
    public static function load_booked_map() {
        if ( is_array( self::$booked_map ) ) {
            return self::$booked_map;
        }
        $map = [];

        if ( function_exists( 'wc_get_orders' ) ) {
            $meta_clause = [ 'key' => self::META_DELIVERY_DATE, 'compare' => 'EXISTS' ];

            // CPT order store: re-add the meta filter that WC drops (see docblock).
            $cpt_passthrough = function ( $query, $query_vars ) use ( $meta_clause ) {
                if ( ! empty( $query_vars['fishotel_delivery_counts'] ) ) {
                    if ( ! isset( $query['meta_query'] ) || ! is_array( $query['meta_query'] ) ) {
                        $query['meta_query'] = [];
                    }
                    $query['meta_query'][] = $meta_clause;
                }
                return $query;
            };
            $args = [
                'type'                     => 'shop_order',
                'status'                   => self::count_statuses(),
                'limit'                    => -1,
                'return'                   => 'objects',
                'fishotel_delivery_counts' => 1, // consumed by the CPT pass-through above
            ];
            // 'meta_query' is honoured by HPOS only. The CPT store drops it AND, since
            // WooCommerce 9.2, flags it with wc_doing_it_wrong() on every call — which
            // would land in the error log on every checkout — so it is sent only when
            // the orders table is the active store; the pass-through filter covers CPT.
            if ( self::hpos_enabled() ) {
                $args['meta_query'] = [ $meta_clause ];
            }
            add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $cpt_passthrough, 10, 2 );
            $orders = wc_get_orders( $args );
            remove_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $cpt_passthrough, 10 );

            foreach ( (array) $orders as $order ) {
                if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
                    continue;
                }
                // Safety net: re-check the meta on the object whatever the backend returned.
                $date = trim( (string) $order->get_meta( self::META_DELIVERY_DATE ) );
                if ( ! self::is_ymd( $date ) ) {
                    continue;
                }
                if ( '' !== trim( (string) $order->get_meta( self::META_FULFILLED_BY ) ) ) {
                    continue; // combined into a fulfillment — the fulfillment wrapper is counted instead
                }
                $map[ $date ] = ( $map[ $date ] ?? 0 ) + 1;
            }
        }

        self::$booked_map = $map;
        return $map;
    }
}

if ( ! function_exists( 'fishotel_delivery_rules' ) ) {
    /**
     * The shared delivery-rules instance for this request (built from the options,
     * wp_timezone() and the real booked counter). Rebuilt automatically when one of
     * the fishotel_shipping_* options changes; pass $fresh = true to force a rebuild.
     *
     * The theme's inc/customer/shipping-days-helper.php should call this when
     * function_exists( 'fishotel_delivery_rules' ) instead of re-implementing the rules.
     *
     * @return FisHotel_Delivery_Rules
     */
    function fishotel_delivery_rules( $fresh = false ) {
        return FisHotel_Delivery_Rules::shared( $fresh );
    }
}
