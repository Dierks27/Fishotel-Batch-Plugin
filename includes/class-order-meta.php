<?php
/**
 * FisHotel_Order_Meta — the ONE way this plugin reads, writes and queries
 * WooCommerce ORDER meta (standalone static class, not a trait).
 *
 * WHY. With High-Performance Order Storage (HPOS) the authoritative copy of an
 * order lives in wp_wc_orders / wp_wc_orders_meta, not in wp_posts / wp_postmeta.
 * A raw get_post_meta()/update_post_meta() on an order id then reads or writes a
 * copy that WooCommerce either ignores or, with sync on, overwrites on the next
 * sync — the delivery date "disappears" and the fish order lands on hold. Every
 * order-kind call site therefore goes through the WC_Order CRUD API
 * (get_meta / update_meta_data / delete_meta_data / save), which WooCommerce
 * routes to whichever store is active. Plugin CPTs (fish_master, fish_request,
 * fish_batch, notifications, stickers), products and users keep their post/user
 * meta calls — only ORDER meta is in scope here. See docs/HPOS.md.
 *
 * The meta KEY names are unchanged: the theme, ShipTracker and the Misc plugin
 * read _fishotel_shipping_date, _fishotel_fulfilled_by_order, ... through the
 * same order objects.
 *
 * tests/order-meta-test.php exercises this class with a stub WC_Order and scans
 * the plugin source so raw post meta on orders cannot come back.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FisHotel_Order_Meta {

    /** Private query var the CPT pass-through filter consumes (see query()). */
    const QUERY_VAR = 'fishotel_order_meta_query';

    /**
     * Resolve an order id or object to a WC_Order. Never touches get_post():
     * on HPOS the post row may not exist (or may be a stale placeholder).
     *
     * @param  int|object $order_or_id
     * @return object|false  WC_Order, or false when the id is not an order
     *                       (missing, a refund, a product, a plugin CPT ...).
     */
    public static function order( $order_or_id ) {
        if ( is_object( $order_or_id ) ) {
            return self::is_order( $order_or_id ) ? $order_or_id : false;
        }
        $id = (int) $order_or_id;
        if ( $id <= 0 || ! function_exists( 'wc_get_order' ) ) {
            return false;
        }
        $order = wc_get_order( $id );
        return self::is_order( $order ) ? $order : false;
    }

    /** True for a WC_Order (not a refund, not a WP_Post, not a product). */
    private static function is_order( $thing ) {
        if ( ! is_object( $thing ) ) {
            return false;
        }
        if ( class_exists( 'WC_Order' ) ) {
            return $thing instanceof WC_Order;
        }
        // Headless (tests, WooCommerce absent): duck-type the CRUD surface.
        return method_exists( $thing, 'get_meta' ) && method_exists( $thing, 'update_meta_data' ) && method_exists( $thing, 'get_id' );
    }

    /**
     * Read one order meta value.
     *
     * @param  int|object $order_or_id
     * @param  string     $key
     * @param  bool       $single  true: the value ('' when unset, like get_post_meta);
     *                             false: every value for the key, as a plain array.
     * @return mixed|array
     */
    public static function get( $order_or_id, $key, $single = true ) {
        $order = self::order( $order_or_id );
        if ( ! $order ) {
            return $single ? '' : [];
        }
        if ( $single ) {
            $value = $order->get_meta( $key, true );
            return null === $value ? '' : $value;
        }
        $out = [];
        foreach ( (array) $order->get_meta( $key, false ) as $meta ) {
            if ( is_object( $meta ) && isset( $meta->value ) ) {
                $out[] = $meta->value;
            } elseif ( ! is_object( $meta ) ) {
                $out[] = $meta;
            }
        }
        return $out;
    }

    /**
     * Write one order meta value (replaces every existing value of the key).
     *
     * Pass the order OBJECT and $save = false when writing several keys in a row,
     * then call $order->save() once.
     *
     * @return bool  false when $order_or_id is not an order.
     */
    public static function update( $order_or_id, $key, $value, $save = true ) {
        $order = self::order( $order_or_id );
        if ( ! $order ) {
            return false;
        }
        $order->update_meta_data( $key, $value );
        if ( $save ) {
            $order->save();
        }
        return true;
    }

    /**
     * Remove every value of one order meta key.
     *
     * @return bool  false when $order_or_id is not an order.
     */
    public static function delete( $order_or_id, $key, $save = true ) {
        $order = self::order( $order_or_id );
        if ( ! $order ) {
            return false;
        }
        $order->delete_meta_data( $key );
        if ( $save ) {
            $order->save();
        }
        return true;
    }

    /** True when the order carries the key at all (even with an empty value). */
    public static function has( $order_or_id, $key ) {
        $order = self::order( $order_or_id );
        if ( ! $order ) {
            return false;
        }
        if ( method_exists( $order, 'meta_exists' ) ) {
            return (bool) $order->meta_exists( $key );
        }
        return '' !== (string) $order->get_meta( $key, true );
    }

    /** The admin Edit Order URL for either store (wc-orders on HPOS, post.php on CPT). */
    public static function edit_url( $order_or_id ) {
        $order = self::order( $order_or_id );
        if ( $order && method_exists( $order, 'get_edit_order_url' ) ) {
            return (string) $order->get_edit_order_url();
        }
        $id = $order ? (int) $order->get_id() : (int) $order_or_id;
        return function_exists( 'admin_url' ) ? admin_url( 'post.php?post=' . $id . '&action=edit' ) : '';
    }

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
     * Store-agnostic order query by meta (the load_booked_map() pattern from
     * class-delivery-rules.php, shared).
     *
     * 'meta_query' is honoured by HPOS only. The CPT order store drops it — the
     * plain call would return EVERY order in the asked statuses — and, since
     * WooCommerce 9.2, flags it with wc_doing_it_wrong() on every call. So the
     * clauses are sent as 'meta_query' only when the orders table is the active
     * store; on CPT they ride on a private query var that the
     * woocommerce_order_data_store_cpt_get_orders_query filter maps back into the
     * final WP_Query. Every returned order is then re-checked with get_meta()
     * against the same clauses, so the result is right whatever the backend did.
     *
     * @param  array $args        wc_get_orders() args (status, limit, customer_id, return ...).
     *                            'limit' defaults to 200 — pass -1 only with a meta filter or status.
     * @param  array $meta_query  Flat list of clauses: [ 'key' => ..., 'value' => ..., 'compare' => '=' ].
     *                            compare: '=', '!=', 'EXISTS', 'NOT EXISTS', 'IN', 'NOT IN', 'LIKE'.
     * @return array  Order objects, or ids when $args['return'] === 'ids'; [] without WooCommerce.
     */
    public static function query( array $args, array $meta_query = [] ) {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return [];
        }
        $want_ids = isset( $args['return'] ) && 'ids' === $args['return'];
        if ( ! isset( $args['type'] ) ) {
            $args['type'] = 'shop_order';
        }
        if ( ! isset( $args['limit'] ) ) {
            $args['limit'] = 200;
        }
        $args['return'] = $want_ids ? 'ids' : 'objects';
        unset( $args['meta_query'], $args['meta_key'], $args['meta_value'], $args['meta_compare'] );

        $clauses = [];
        foreach ( $meta_query as $clause ) {
            if ( is_array( $clause ) && ! empty( $clause['key'] ) ) {
                $clauses[] = $clause;
            }
        }

        $cpt_passthrough = null;
        if ( $clauses ) {
            $args[ self::QUERY_VAR ] = 1; // consumed by the CPT pass-through below
            if ( self::hpos_enabled() ) {
                $args['meta_query'] = $clauses;
            }
            $cpt_passthrough = function ( $query, $query_vars ) use ( $clauses ) {
                if ( ! empty( $query_vars[ self::QUERY_VAR ] ) ) {
                    if ( ! isset( $query['meta_query'] ) || ! is_array( $query['meta_query'] ) ) {
                        $query['meta_query'] = [];
                    }
                    foreach ( $clauses as $clause ) {
                        $query['meta_query'][] = $clause;
                    }
                }
                return $query;
            };
            add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $cpt_passthrough, 10, 2 );
        }

        $results = wc_get_orders( $args );

        if ( $cpt_passthrough ) {
            remove_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $cpt_passthrough, 10 );
        }

        $out = [];
        foreach ( (array) $results as $result ) {
            $order = $want_ids ? self::order( (int) $result ) : ( self::is_order( $result ) ? $result : false );
            if ( ! $order ) {
                continue;
            }
            // Safety net: re-check the meta on the object whatever the backend returned.
            if ( $clauses && ! self::matches( $order, $clauses ) ) {
                continue;
            }
            $out[] = $want_ids ? (int) $order->get_id() : $order;
        }
        return $out;
    }

    /** True when the order's meta satisfies every clause (AND), mirroring the SQL. */
    public static function matches( $order, array $clauses ) {
        if ( ! self::is_order( $order ) ) {
            return false;
        }
        foreach ( $clauses as $clause ) {
            $key     = (string) $clause['key'];
            $compare = strtoupper( trim( (string) ( $clause['compare'] ?? '=' ) ) );
            $exists  = method_exists( $order, 'meta_exists' ) ? (bool) $order->meta_exists( $key ) : '' !== (string) $order->get_meta( $key, true );
            $stored  = trim( (string) $order->get_meta( $key, true ) );
            $wanted  = $clause['value'] ?? '';

            switch ( $compare ) {
                case 'EXISTS':
                    if ( ! $exists ) {
                        return false;
                    }
                    break;
                case 'NOT EXISTS':
                    if ( $exists ) {
                        return false;
                    }
                    break;
                case '!=':
                    if ( $exists && 0 === strcasecmp( $stored, trim( (string) $wanted ) ) ) {
                        return false;
                    }
                    break;
                case 'IN':
                case 'NOT IN':
                    $hit = false;
                    foreach ( (array) $wanted as $candidate ) {
                        if ( 0 === strcasecmp( $stored, trim( (string) $candidate ) ) ) {
                            $hit = true;
                            break;
                        }
                    }
                    if ( ( 'IN' === $compare ) !== $hit ) {
                        return false;
                    }
                    break;
                case 'LIKE':
                    if ( false === stripos( $stored, trim( (string) $wanted ) ) ) {
                        return false;
                    }
                    break;
                default: // '='
                    if ( ! $exists || 0 !== strcasecmp( $stored, trim( (string) $wanted ) ) ) {
                        return false;
                    }
            }
        }
        return true;
    }

    /**
     * Count orders carrying a meta key (optionally with one exact value), with
     * one prepared query against whichever meta table is authoritative:
     * OrderUtil::get_table_for_order_meta() on HPOS, wp_postmeta (joined to
     * shop_order posts) on the CPT store. Use this instead of a wc_get_orders()
     * loop when only a number is needed.
     *
     * @return int
     */
    public static function count_by_meta( $key, $value = null ) {
        global $wpdb;
        if ( ! is_object( $wpdb ) || '' === (string) $key ) {
            return 0;
        }
        $util = '\Automattic\WooCommerce\Utilities\OrderUtil';
        if ( self::hpos_enabled() && is_callable( [ $util, 'get_table_for_order_meta' ] ) ) {
            $table = (string) call_user_func( [ $util, 'get_table_for_order_meta' ] );
            $sql   = "SELECT COUNT(DISTINCT m.order_id) FROM `{$table}` m WHERE m.meta_key = %s";
            $params = [ $key ];
        } else {
            $sql    = "SELECT COUNT(DISTINCT m.post_id) FROM {$wpdb->postmeta} m
                       INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'shop_order'
                       WHERE m.meta_key = %s";
            $params = [ $key ];
        }
        if ( null !== $value ) {
            $sql     .= ' AND m.meta_value = %s';
            $params[] = (string) $value;
        }
        return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
    }

    /**
     * The v10.22 delivery-date migration body (fishotel_maybe_run_delivery_date_migration()
     * in the main file gates it with the one-time option): shift every processing
     * order's stored FisHotel date forward by $days, reading and writing through the
     * order object with a single save per order.
     *
     * @return int  Orders shifted.
     */
    public static function migrate_delivery_dates( $days = 1, $timezone = 'America/Chicago' ) {
        $orders = self::query(
            [ 'status' => 'processing', 'limit' => -1, 'return' => 'objects' ],
            [ [ 'key' => '_fishotel_shipping_date', 'compare' => 'EXISTS' ] ]
        );

        $migrated = 0;
        foreach ( $orders as $order ) {
            $old_date = (string) $order->get_meta( '_fishotel_shipping_date', true );
            if ( '' === $old_date || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $old_date ) ) {
                continue;
            }
            try {
                $dt = new DateTime( $old_date, new DateTimeZone( $timezone ) );
                $dt->modify( sprintf( '%+d day', (int) $days ) );
                $new_date = $dt->format( 'Y-m-d' );
            } catch ( Exception $e ) {
                error_log( sprintf( '[FisHotel migration] Order #%d — skipped (date parse failed: %s)', $order->get_id(), $old_date ) );
                continue;
            }
            self::update( $order, '_fishotel_shipping_date', $new_date, true );
            error_log( sprintf( '[FisHotel migration] Order #%d — %s → %s', $order->get_id(), $old_date, $new_date ) );
            $migrated++;
        }
        return $migrated;
    }
}
