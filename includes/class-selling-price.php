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
 *   A COPY FOLLOWS           Most stored values are copies of Regular price (the Master
 *                            Fish Library writes both at once: its price box, the CSV
 *                            import, "Create product"). When a save changes Regular
 *                            price and the stored Selling Price was equal to the old
 *                            Regular price, it follows the new one (follow()), so the
 *                            Master Fish Library sees the new price. A value that
 *                            differed from Regular price before the save was set on
 *                            purpose and is kept.
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
     * write nothing.
     *
     * @param mixed $old_selling Stored Selling Price before the save.
     * @param mixed $old_regular Stored Regular price before the save.
     * @param mixed $new_regular Regular price after WooCommerce saved.
     * @return string|null
     */
    public static function follow( $old_selling, $old_regular, $new_regular ) {
        if ( ! self::same_price( $old_selling, $old_regular ) ) {
            return null; // no Selling Price, or one set on purpose: keep it
        }
        if ( ! self::is_price( $new_regular ) ) {
            return null; // Regular price emptied: never write a blank
        }
        if ( self::same_price( $old_selling, $new_regular ) ) {
            return null; // price unchanged
        }
        return trim( (string) $new_regular );
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
