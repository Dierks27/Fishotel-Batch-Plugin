<?php
/**
 * FisHotel Selling Price — the rules for the product's "_fishotel_selling_price"
 * and the Master Fish Library price it feeds (10.37).
 *
 * Until 10.36 the product screen showed a "FisHotel Selling Price" box next to
 * WooCommerce's Regular price, and every product save wrote whatever the box held
 * — a blank on every product that never had one, dry goods included. A blank
 * written over a stored price also reached the linked Master Fish (the
 * updated_post_meta sync copies the product value to its _selling_price as is).
 *
 * From 10.37 there is no box: Regular price is the one price people type.
 *
 *   SAVED ONLY WHEN POSTED   A product save writes _fishotel_selling_price only when
 *                            the form really posted it (posted(): a number is written,
 *                            a blank removes the value, anything else is ignored).
 *                            Nothing posted = nothing written, never a blank.
 *   IT FOLLOWS REGULAR       When a save changes Regular price, a stored Selling Price
 *                            follows the new one (follow()), so the Master Fish Library
 *                            and the public list show what the product page shows. With
 *                            no box on the screen, a Selling Price kept apart from
 *                            Regular price could never be seen or changed again, so it
 *                            follows too. A value that differed from the old Regular
 *                            price is not lost: it is kept as a copy under
 *                            REPLACED_META (keep_copy()). Until Regular price changes,
 *                            such a value stays and the product's price box says so
 *                            (note()).
 *   MASTER PRICE             As before: the Selling Price when it is set, otherwise the
 *                            product's price (master_price(), the old "?:"). Worked out
 *                            again after WooCommerce has saved the new prices
 *                            (needs_master_update()), so one save is enough.
 *
 * Pure PHP: no WordPress calls. The hooks live in includes/class-woocommerce.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FisHotel_Selling_Price {

    const META = '_fishotel_selling_price';

    /** Copies of Selling Prices that differed from Regular price when Regular price replaced them. */
    const REPLACED_META = '_fishotel_selling_price_replaced';

    /** Two prices closer than this are the same price ("12" = "12.00" = 12.0). */
    const CENT = 0.005;

    /**
     * What a save does with the posted form.
     *
     * @param array $post The posted form ($_POST, already unslashed).
     * @return array ['action' => 'keep'] (not posted, or not a number: write nothing),
     *               ['action' => 'delete'] (posted blank), or
     *               ['action' => 'write', 'value' => '12.50'].
     */
    public static function posted( array $post ) {
        if ( ! array_key_exists( self::META, $post ) || is_array( $post[ self::META ] ) ) {
            return [ 'action' => 'keep' ];
        }
        $raw = trim( (string) $post[ self::META ] );
        if ( '' === $raw ) {
            return [ 'action' => 'delete' ];
        }
        if ( ! self::is_price( $raw ) ) {
            return [ 'action' => 'keep' ];
        }
        return [ 'action' => 'write', 'value' => $raw ];
    }

    /** A price someone could have meant: a plain non-negative number. */
    public static function is_price( $value ) {
        if ( is_int( $value ) || is_float( $value ) ) {
            return $value >= 0;
        }
        $value = trim( (string) $value );
        return '' !== $value && 1 === preg_match( '/^\d*\.?\d+$|^\d+\.$/', $value );
    }

    /** Both are prices and they are the same to the cent. */
    public static function same_price( $a, $b ) {
        if ( ! self::is_price( $a ) || ! self::is_price( $b ) ) {
            return false;
        }
        return abs( (float) $a - (float) $b ) < self::CENT;
    }

    /**
     * The Selling Price to write after a save that did not post it, or null to
     * write nothing. When Regular price changed, a stored Selling Price follows it —
     * a copy of the old Regular price and one that differed alike (keep_copy() says
     * when to keep the old value first).
     *
     * @param mixed $old_selling Stored Selling Price before the save.
     * @param mixed $old_regular Stored Regular price before the save.
     * @param mixed $new_regular Regular price after WooCommerce saved.
     * @return string|null
     */
    public static function follow( $old_selling, $old_regular, $new_regular ) {
        if ( ! self::is_price( $old_selling ) ) {
            return null; // no Selling Price: the Master Fish uses the product price
        }
        if ( ! self::is_price( $new_regular ) ) {
            return null; // Regular price emptied: never write a blank
        }
        if ( self::same_price( $old_regular, $new_regular ) ) {
            return null; // Regular price unchanged: leave the Selling Price as it is
        }
        if ( self::same_price( $old_selling, $new_regular ) ) {
            return null; // already the new price
        }
        return trim( (string) $new_regular );
    }

    /**
     * Whether follow() is about to replace a Selling Price that differed from the
     * old Regular price — set apart on purpose once — so it is kept as a copy first.
     */
    public static function keep_copy( $old_selling, $old_regular, $next ) {
        return null !== $next && self::is_price( $old_selling ) && ! self::same_price( $old_selling, $old_regular );
    }

    /**
     * The line under Regular price when a stored Selling Price differs from it (the
     * Master Fish Library and the public list use the Selling Price), or '' when
     * there is nothing to say.
     */
    public static function note( $selling, $regular ) {
        if ( ! self::is_price( $selling ) || self::same_price( $selling, $regular ) ) {
            return '';
        }
        return sprintf(
            'The Master Fish Library and the public list show this fish at %s, set before Regular price was the one price. Change Regular price and they follow it.',
            number_format( (float) $selling, 2, '.', '' )
        );
    }

    /**
     * The Master Fish Library price for a product — unchanged from 10.36:
     * the Selling Price when set, otherwise the product's price.
     */
    public static function master_price( $selling, $product_price ) {
        return $selling ?: $product_price;
    }

    /**
     * Whether the Master Fish's stored _selling_price must be rewritten.
     * A blank or non-price result never overwrites it.
     */
    public static function needs_master_update( $stored, $price ) {
        if ( ! self::is_price( $price ) ) {
            return false;
        }
        return ! self::same_price( $stored, $price );
    }
}
