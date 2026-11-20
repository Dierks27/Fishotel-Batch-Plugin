<?php
/**
 * FisHotel Ship Day — "Print the day's paperwork" (10.35).
 *
 * Replaces the browser routine that clicked through every order and dropped
 * files into a print-watcher folder. For one delivery date, in the pick list's
 * box order, the button makes two PDF files the owner prints with one Print
 * each:
 *
 *   BROTHER_shipday_<ship Y-m-d>.pdf   for every box: the invoice of each live
 *       order in it (made by the "PDF Invoices & Packing Slips for WooCommerce"
 *       plugin by WP Overnight), then one acclimation sheet; at the end the
 *       pick list and the weather overview.
 *   RX106HD_shipday_<ship Y-m-d>.pdf   for every box: the shipping-labels PDF
 *       label_copies times (default 4), then a product sheet for each
 *       "Invoice" product in the box.
 *
 * HOW. The server only plans (one AJAX request, plan()): which boxes, which
 * orders' invoices, which static PDFs, which product sheets, and the pick
 * list / weather pages as data (FisHotel_Ship_Day::print_pages()). The browser
 * (assists/ship-day/paperwork.js, with the vendored pdf-lib) then fetches each
 * invoice one at a time from the invoice plugin's OWN document link — the very
 * request its Create PDF → Invoice button makes — so each invoice is that
 * plugin's own PDF byte for byte, numbered, logged and marked printed by that
 * plugin exactly as a click would, and copies its pages in. Merging in the
 * browser keeps every request small (one invoice each), so a 60-box day never
 * times out or runs a request out of memory, and the page shows progress.
 *
 * INVOICE NUMBERS. The invoice plugin gives an order its number the first
 * time the invoice is made (Invoice::init() → initiate_number(), under a
 * lock) and only when none is stored yet (wcpdf_get_document( ..., true )
 * calls init() only when ! exists(), and exists() means a stored number). So
 * printing the paperwork twice re-uses the same number; this file never
 * creates, numbers or saves an invoice itself.
 *
 * Nothing here writes an order, a note or order meta, and nothing is e-mailed.
 * The only write is this page's own option (the two Media Library PDFs and
 * the label count).
 *
 * The pure helpers (settings, file names, "Invoice" product detection, product
 * sheet lines, prices) need no WordPress, so tests/ship-day-paperwork-test.php
 * runs them with plain php.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FisHotel_Ship_Day_Paperwork {

    /** Settings: the two Media Library PDFs and the label count. */
    const OPTION         = 'fishotel_ship_day_paperwork';
    const DEFAULT_COPIES = 4;
    const MAX_COPIES     = 20;

    /** The plan request (wp_ajax_) and its nonce. */
    const PLAN_ACTION = 'fishotel_ship_day_paperwork';
    /** The settings form (admin-post.php) and its nonce. */
    const SAVE_ACTION = 'fishotel_ship_day_paperwork_settings';
    /** ?paperwork=saved after the settings form. */
    const SAVED_VAR   = 'paperwork';

    /** The invoice plugin's document type. */
    const INVOICE_DOC = 'invoice';

    const PDF_MIME       = 'application/pdf';
    const PDF_LIST_LIMIT = 100;

    const LIB_HANDLE    = 'fishotel-pdf-lib';
    const LIB_VERSION   = '1.17.1';
    const SCRIPT_HANDLE = 'fishotel-ship-day-paperwork';

    /* ─────────────────────────────────────────────
     *  Pure helpers (no WordPress)
     * ───────────────────────────────────────────── */

    /**
     * Settings with defaults and bounds: acclimation_id and labels_id are
     * attachment ids (0 = not chosen), label_copies 1..MAX_COPIES.
     *
     * @return array{acclimation_id:int,labels_id:int,label_copies:int}
     */
    public static function normalize_settings( $raw ) {
        $raw    = is_array( $raw ) ? $raw : [];
        $copies = isset( $raw['label_copies'] ) && is_numeric( $raw['label_copies'] ) ? (int) $raw['label_copies'] : self::DEFAULT_COPIES;
        return [
            'acclimation_id' => max( 0, isset( $raw['acclimation_id'] ) && is_numeric( $raw['acclimation_id'] ) ? (int) $raw['acclimation_id'] : 0 ),
            'labels_id'      => max( 0, isset( $raw['labels_id'] ) && is_numeric( $raw['labels_id'] ) ? (int) $raw['labels_id'] : 0 ),
            'label_copies'   => min( self::MAX_COPIES, max( 1, $copies ) ),
        ];
    }

    /**
     * The settings form's POST, checked: an attachment id is kept only when
     * $is_pdf says it is a PDF in the Media Library; anything else is 0.
     *
     * @param array    $post   The submitted fields.
     * @param callable $is_pdf fn( int $attachment_id ): bool
     * @return array{acclimation_id:int,labels_id:int,label_copies:int}
     */
    public static function settings_from_post( array $post, callable $is_pdf ) {
        $out = [];
        foreach ( [ 'acclimation_id', 'labels_id' ] as $key ) {
            $id          = isset( $post[ $key ] ) && is_scalar( $post[ $key ] ) ? (int) $post[ $key ] : 0;
            $out[ $key ] = ( $id > 0 && $is_pdf( $id ) ) ? $id : 0;
        }
        $out['label_copies'] = isset( $post['label_copies'] ) && is_scalar( $post['label_copies'] ) ? trim( (string) $post['label_copies'] ) : '';
        return self::normalize_settings( $out );
    }

    /**
     * The two file names for a delivery date. Named by the SHIP date (the day
     * the paperwork is printed and the boxes go out), like the routine's own
     * pick list file.
     *
     * @return array{brother:string,rx:string}|null
     */
    public static function file_names( $delivery_ymd ) {
        $ship = FisHotel_Ship_Day::ship_date_for( $delivery_ymd );
        if ( '' === $ship ) {
            return null;
        }
        return [
            'brother' => 'BROTHER_shipday_' . $ship . '.pdf',
            'rx'      => 'RX106HD_shipday_' . $ship . '.pdf',
        ];
    }

    /** Whether a product name is an "Invoice" product: the word Invoice in it, any case. */
    public static function is_invoice_name( $name ) {
        return is_scalar( $name ) && 1 === preg_match( '/\binvoice/i', (string) $name );
    }

    /**
     * The "Invoice" products one order holds, in line order, each once — the
     * products that get a product sheet. $lines are the order's line items as
     * plain arrays: name, product_name, product_id, qty (after refunds) and
     * meta (keyed by meta name). A line counts when its own name or its
     * product's name has the word Invoice, or when it is one fish of an
     * itemised invoice (theme 1.25+: _fh_invoice_fish with the invoice
     * product's id in _fh_invoice_product_id). Lines that already shipped
     * (_fishotel_line_status shipped), never ship (na) or are fully refunded
     * are skipped, as on the pick list.
     *
     * @return int[]
     */
    public static function invoice_product_ids( array $lines ) {
        $out = [];
        foreach ( $lines as $line ) {
            if ( ! is_array( $line ) ) {
                continue;
            }
            $meta   = isset( $line['meta'] ) && is_array( $line['meta'] ) ? $line['meta'] : [];
            $status = FisHotel_Ship_Day::scalar_meta( $meta[ FisHotel_Ship_Day::META_LINE_STATUS ] ?? '' );
            if ( 'shipped' === $status || 'na' === $status ) {
                continue;
            }
            if ( isset( $line['qty'] ) && (int) $line['qty'] <= 0 ) {
                continue;
            }
            $pid = 0;
            if ( FisHotel_Ship_Day::is_truthy_meta( $meta[ FisHotel_Ship_Day::META_INV_FISH ] ?? '' ) ) {
                $pid = (int) FisHotel_Ship_Day::scalar_meta( $meta[ FisHotel_Ship_Day::META_INV_PRODUCT_ID ] ?? '' );
            } elseif ( self::is_invoice_name( $line['name'] ?? '' ) || self::is_invoice_name( $line['product_name'] ?? '' ) ) {
                $pid = (int) ( $line['product_id'] ?? 0 );
            }
            if ( $pid > 0 && ! in_array( $pid, $out, true ) ) {
                $out[] = $pid;
            }
        }
        return $out;
    }

    /**
     * The description lines a product sheet lists: each <li> when the
     * description has a list, otherwise each non-empty text line; a lone
     * "Description" heading line is dropped (the sheet prints its own).
     *
     * @return string[]
     */
    public static function sheet_lines( $html ) {
        $html = (string) $html;
        if ( '' === trim( $html ) ) {
            return [];
        }
        if ( preg_match_all( '/<li\b[^>]*>(.*?)<\/li>/is', $html, $m ) && ! empty( $m[1] ) ) {
            $raw = $m[1];
        } else {
            $text = preg_replace( '/<\s*(br|\/p|\/div|\/li|\/tr|\/h[1-6])\s*\/?\s*>/i', "\n", $html );
            $raw  = preg_split( '/\r\n|\r|\n/', (string) $text );
        }
        $out = [];
        foreach ( (array) $raw as $line ) {
            $line = FisHotel_Ship_Day::clean_text( $line );
            if ( '' === $line || 'DESCRIPTION' === strtoupper( $line ) ) {
                continue;
            }
            $out[] = $line;
        }
        return $out;
    }

    /** Price HTML (wc_price()) → plain text: "$39.00". */
    public static function price_text( $html ) {
        return FisHotel_Ship_Day::clean_text( (string) $html );
    }

    /** One plain warning line per problem; never a failure. */
    public static function box_warning( $state, $label ) {
        if ( 'no_live_source' === $state ) {
            return $label . ': every source order is cancelled, refunded or failed — nothing ships, so no paperwork for this box.';
        }
        if ( 'sources_missing' === $state ) {
            return $label . ': its source orders could not be loaded, so it has no invoice (the combined box\'s own copy is never printed). Acclimation sheet and labels are included.';
        }
        return '';
    }

    /* ─────────────────────────────────────────────
     *  WordPress
     * ───────────────────────────────────────────── */

    /** The saved settings. */
    public static function settings() {
        return self::normalize_settings( function_exists( 'get_option' ) ? get_option( self::OPTION, [] ) : [] );
    }

    /** Whether an attachment is a PDF in the Media Library. */
    public static function is_pdf_attachment( $id ) {
        $id = (int) $id;
        return $id > 0 && function_exists( 'get_post_mime_type' ) && self::PDF_MIME === get_post_mime_type( $id );
    }

    /**
     * A chosen static PDF, or null when none is chosen, it is gone from the
     * Media Library or is not a PDF.
     *
     * @return array{id:int,url:string,title:string}|null
     */
    public static function static_file( $id ) {
        $id = (int) $id;
        if ( $id <= 0 || ! self::is_pdf_attachment( $id ) || ! function_exists( 'wp_get_attachment_url' ) ) {
            return null;
        }
        if ( function_exists( 'get_attached_file' ) ) {
            $path = get_attached_file( $id );
            if ( ! is_string( $path ) || '' === $path || ! file_exists( $path ) ) {
                return null;
            }
        }
        $url = (string) wp_get_attachment_url( $id );
        if ( '' === $url ) {
            return null;
        }
        $title = function_exists( 'get_the_title' ) ? (string) get_the_title( $id ) : '';
        return [ 'id' => $id, 'url' => $url, 'title' => '' !== $title ? $title : basename( (string) parse_url( $url, PHP_URL_PATH ) ) ];
    }

    /** Whether the WP Overnight invoice plugin is active (its PHP API is loaded). */
    public static function invoice_plugin_active() {
        return function_exists( 'WPO_WCPDF' ) && function_exists( 'wcpdf_get_document' );
    }

    /**
     * The invoice plugin's own document link for an order's invoice — the one
     * its Create PDF → Invoice button opens — or '' when it gives none.
     * get_document_link() returns it esc_url()'d for HTML; the browser fetches
     * it, so the entities are decoded back.
     */
    public static function invoice_url( $order ) {
        if ( ! self::invoice_plugin_active() || ! is_object( $order ) ) {
            return '';
        }
        try {
            $wpo = WPO_WCPDF();
            if ( ! is_object( $wpo ) || ! isset( $wpo->endpoint ) || ! is_object( $wpo->endpoint ) || ! method_exists( $wpo->endpoint, 'get_document_link' ) ) {
                return '';
            }
            $url = (string) $wpo->endpoint->get_document_link( $order, self::INVOICE_DOC );
        } catch ( \Throwable $e ) {
            return '';
        }
        return html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    }

    /** One order's line items as the plain arrays invoice_product_ids() reads. */
    public static function order_lines( $order ) {
        $lines = [];
        if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
            return $lines;
        }
        foreach ( (array) $order->get_items( 'line_item' ) as $item_id => $item ) {
            if ( ! is_object( $item ) ) {
                continue;
            }
            $meta = [];
            foreach ( [ FisHotel_Ship_Day::META_LINE_STATUS, FisHotel_Ship_Day::META_INV_FISH, FisHotel_Ship_Day::META_INV_PRODUCT_ID ] as $key ) {
                $v            = method_exists( $item, 'get_meta' ) ? $item->get_meta( $key ) : '';
                $meta[ $key ] = is_scalar( $v ) ? $v : '';
            }
            $qty = method_exists( $item, 'get_quantity' ) ? (int) $item->get_quantity() : 1;
            if ( method_exists( $order, 'get_qty_refunded_for_item' ) ) {
                $qty -= abs( (int) $order->get_qty_refunded_for_item( method_exists( $item, 'get_id' ) ? (int) $item->get_id() : (int) $item_id ) );
            }
            $product = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
            $lines[] = [
                'name'         => method_exists( $item, 'get_name' ) ? (string) $item->get_name() : '',
                'product_name' => is_object( $product ) && method_exists( $product, 'get_name' ) ? (string) $product->get_name() : '',
                'product_id'   => method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0,
                'qty'          => $qty,
                'meta'         => $meta,
            ];
        }
        return $lines;
    }

    /**
     * What one product sheet prints (Step 7c of the old routine): the
     * product's title, image, price and description lines. null when the
     * product is gone.
     *
     * @return array{title:string,price:string,image:string,lines:string[]}|null
     */
    public static function product_sheet( $product_id ) {
        if ( ! function_exists( 'wc_get_product' ) ) {
            return null;
        }
        $product = wc_get_product( (int) $product_id );
        if ( ! is_object( $product ) ) {
            return null;
        }
        $price = '';
        if ( method_exists( $product, 'get_price' ) && '' !== (string) $product->get_price() ) {
            $amount = function_exists( 'wc_get_price_to_display' ) ? wc_get_price_to_display( $product ) : (float) $product->get_price();
            $price  = function_exists( 'wc_price' ) ? self::price_text( wc_price( $amount ) ) : '$' . number_format( (float) $amount, 2 );
        }
        $image    = '';
        $image_id = method_exists( $product, 'get_image_id' ) ? (int) $product->get_image_id() : 0;
        if ( $image_id > 0 && function_exists( 'wp_get_attachment_image_url' ) ) {
            $image = (string) wp_get_attachment_image_url( $image_id, 'large' );
        }
        $desc = method_exists( $product, 'get_description' ) ? (string) $product->get_description() : '';
        return [
            'title' => FisHotel_Ship_Day::clean_text( method_exists( $product, 'get_name' ) ? (string) $product->get_name() : '' ),
            'price' => $price,
            'image' => $image,
            'lines' => self::sheet_lines( $desc ),
        ];
    }

    /**
     * Everything the browser needs to make the two files for a delivery date,
     * boxes in the pick list's order (FisHotel_Ship_Day::load_orders() +
     * build_destination(), exactly as the pick list builds them).
     *
     * @return array|WP_Error
     */
    public static function plan( $delivery_ymd ) {
        $ymd = trim( (string) $delivery_ymd );
        if ( ! FisHotel_Ship_Day::is_ymd( $ymd ) ) {
            return new WP_Error( 'fishotel_paperwork_date', 'Pick a valid delivery date (Y-m-d).' );
        }
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return new WP_Error( 'fishotel_paperwork_wc', 'WooCommerce is not active.' );
        }

        $settings = self::settings();
        $warnings = [];

        $wpo = self::invoice_plugin_active();
        if ( ! $wpo ) {
            $warnings[] = 'The "PDF Invoices & Packing Slips for WooCommerce" plugin is not active, so the Brother file has no invoices — only the acclimation sheets, the pick list and the weather overview.';
        }
        $acclimation = self::static_file( $settings['acclimation_id'] );
        if ( ! $acclimation ) {
            $warnings[] = 'No acclimation sheet PDF is chosen (or it is gone from the Media Library) — skipped. Choose it under Paperwork settings.';
        }
        $labels = self::static_file( $settings['labels_id'] );
        if ( ! $labels ) {
            $warnings[] = 'No shipping-labels PDF is chosen (or it is gone from the Media Library) — skipped. Choose it under Paperwork settings.';
        }

        $loaded = FisHotel_Ship_Day::load_orders( $ymd );
        if ( $loaded['truncated'] ) {
            $warnings[] = 'Only the first ' . (int) FisHotel_Ship_Day::LIMIT . ' orders of this delivery date are included — the pick list stops there too.';
        }

        $dests  = [];
        $boxes  = [];
        $sheets = [];
        foreach ( $loaded['orders'] as $order ) {
            $dest    = FisHotel_Ship_Day::build_destination( $order, $ymd );
            $dests[] = $dest;
            $in_box  = FisHotel_Ship_Day::box_orders( $order );
            $note    = self::box_warning( $in_box['state'], $dest['label'] );
            if ( '' !== $note ) {
                $warnings[] = $note;
            }
            if ( 'no_live_source' === $in_box['state'] ) {
                continue; // nothing ships in this box
            }
            $invoices = [];
            $products = [];
            foreach ( $in_box['orders'] as $o ) {
                $number = (string) $o->get_order_number();
                if ( $wpo ) {
                    $url = self::invoice_url( $o );
                    if ( '' === $url ) {
                        $warnings[] = 'Order #' . $number . ': the invoice plugin gave no invoice link — its invoice is not in the file.';
                    } else {
                        $invoices[] = [ 'order_id' => (int) $o->get_id(), 'number' => $number, 'url' => $url ];
                    }
                }
                foreach ( self::invoice_product_ids( self::order_lines( $o ) ) as $pid ) {
                    if ( ! array_key_exists( $pid, $sheets ) ) {
                        $sheets[ $pid ] = self::product_sheet( $pid );
                        if ( null === $sheets[ $pid ] ) {
                            $warnings[] = 'Order #' . $number . ': invoice product #' . $pid . ' no longer exists — no product sheet for it.';
                        }
                    }
                    if ( null !== $sheets[ $pid ] && ! in_array( $pid, $products, true ) ) {
                        $products[] = $pid;
                    }
                }
            }
            $boxes[] = [
                'label'    => $dest['label'],
                'customer' => $dest['customer'],
                'invoices' => $invoices,
                'sheets'   => $products,
            ];
        }

        $sheet_map = [];
        foreach ( $sheets as $pid => $sheet ) {
            if ( null !== $sheet ) {
                $sheet_map[ (string) $pid ] = $sheet;
            }
        }

        return [
            'delivery'       => $ymd,
            'ship'           => FisHotel_Ship_Day::ship_date_for( $ymd ),
            'files'          => self::file_names( $ymd ),
            'label_copies'   => $settings['label_copies'],
            'invoice_plugin' => $wpo,
            'acclimation'    => $acclimation,
            'labels'         => $labels,
            'boxes'          => $boxes,
            'sheets'         => (object) $sheet_map,
            'pages'          => $dests ? FisHotel_Ship_Day::print_pages( $ymd, $dests, FisHotel_Ship_Day::totals( $dests ) ) : null,
            'warnings'       => $warnings,
        ];
    }

    /** PDFs in the Media Library, newest first (attachment id => label). */
    public static function pdf_choices() {
        if ( ! function_exists( 'get_posts' ) ) {
            return [];
        }
        $posts = get_posts( [
            'post_type'      => 'attachment',
            'post_mime_type' => self::PDF_MIME,
            'post_status'    => 'inherit',
            'posts_per_page' => self::PDF_LIST_LIMIT,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );
        $out = [];
        foreach ( (array) $posts as $p ) {
            if ( is_object( $p ) && isset( $p->ID ) ) {
                $file           = function_exists( 'get_attached_file' ) ? basename( (string) get_attached_file( (int) $p->ID ) ) : '';
                $title          = isset( $p->post_title ) ? (string) $p->post_title : '';
                $out[ (int) $p->ID ] = trim( $title . ( '' !== $file && $file !== $title ? ' (' . $file . ')' : '' ) );
            }
        }
        return $out;
    }

    /** Load the PDF library and the button's script on the Ship Day page only. */
    public static function enqueue() {
        if ( ! function_exists( 'wp_enqueue_script' ) || ! function_exists( 'plugins_url' ) || ! defined( 'FISHOTEL_PLUGIN_FILE' ) ) {
            return;
        }
        wp_enqueue_script( self::LIB_HANDLE, plugins_url( 'assists/vendor/pdf-lib/pdf-lib.min.js', FISHOTEL_PLUGIN_FILE ), [], self::LIB_VERSION, true );
        wp_enqueue_script( self::SCRIPT_HANDLE, plugins_url( 'assists/ship-day/paperwork.js', FISHOTEL_PLUGIN_FILE ), [ self::LIB_HANDLE ], defined( 'FISHOTEL_VERSION' ) ? FISHOTEL_VERSION : false, true );
    }

    /** The button beside Build Ship Day. Its request carries its own nonce. */
    public static function button_html( $ajax_url ) {
        return '<button type="button" id="fh-sd-paperwork" data-ajax="' . esc_attr( $ajax_url ) . '" data-action="' . esc_attr( self::PLAN_ACTION ) . '" data-nonce="' . esc_attr( wp_create_nonce( self::PLAN_ACTION ) ) . '" style="background:#2e7d32;color:#fff;font-weight:700;border:none;border-radius:6px;padding:8px 24px;font-size:13px;cursor:pointer;">Print the day&rsquo;s paperwork</button>';
    }

    /**
     * Progress + result area, and the settings form (admin-only: the page is
     * manage_options already, and the form posts to admin-post.php with its
     * own nonce).
     *
     * @param array      $settings normalize_settings() output.
     * @param array      $choices  pdf_choices() output.
     * @param bool       $saved    Show "Saved." (after the form).
     */
    public static function panel_html( array $settings, array $choices, $saved = false ) {
        $h  = '<div id="fh-sd-paperwork-status" style="display:none;margin:4px 0 10px;font-size:13px;color:#ddd;"></div>';
        $h .= '<ul id="fh-sd-paperwork-warnings" style="display:none;margin:0 0 10px 18px;padding:0;font-size:13px;color:#f0c060;"></ul>';
        $h .= '<div id="fh-sd-paperwork-files" style="display:none;margin:0 0 12px;font-size:13px;"></div>';

        $h .= '<details style="margin-top:6px;color:#ddd;font-size:13px;"' . ( $saved ? ' open' : '' ) . '>';
        $h .= '<summary style="cursor:pointer;color:#2986cc;">Paperwork settings</summary>';
        if ( $saved ) {
            $h .= '<p style="color:#7bd88f;margin:8px 0;">Saved.</p>';
        }
        $h .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:10px;">';
        $h .= '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_ACTION ) . '">';
        $h .= wp_nonce_field( self::SAVE_ACTION, '_wpnonce', true, false );
        $h .= self::pdf_select_html( 'acclimation_id', 'Acclimation sheet (Brother, one per box)', (int) $settings['acclimation_id'], $choices );
        $h .= self::pdf_select_html( 'labels_id', 'Shipping labels (RX106HD)', (int) $settings['labels_id'], $choices );
        $h .= '<p style="margin:8px 0;"><label for="fh-sd-label-copies" style="display:block;color:#aaa;font-size:12px;margin-bottom:4px;">Shipping labels per box</label>';
        $h .= '<input type="number" id="fh-sd-label-copies" name="label_copies" min="1" max="' . (int) self::MAX_COPIES . '" value="' . (int) $settings['label_copies'] . '" style="width:80px;padding:5px 8px;border-radius:4px;border:1px solid #555;background:#2a2a2a;color:#fff;"></p>';
        $h .= '<p style="color:#aaa;font-size:12px;margin:6px 0;">Upload the PDFs once under <a href="' . esc_url( admin_url( 'media-new.php' ) ) . '" style="color:#2986cc;">Media &rarr; Add New</a>, then choose them here. A file that is not chosen is skipped with a warning.</p>';
        $h .= '<button type="submit" style="background:#1e6fa5;color:#fff;font-weight:700;border:none;border-radius:6px;padding:6px 18px;font-size:13px;cursor:pointer;">Save paperwork settings</button>';
        $h .= '</form></details>';
        return $h;
    }

    /** One "choose a PDF" select. The current choice is listed even when it is older than the list. */
    private static function pdf_select_html( $name, $label, $current, array $choices ) {
        if ( $current > 0 && ! isset( $choices[ $current ] ) ) {
            $choices = [ $current => 'Current choice (#' . $current . ')' ] + $choices;
        }
        $id  = 'fh-sd-' . str_replace( '_', '-', $name );
        $h   = '<p style="margin:8px 0;"><label for="' . esc_attr( $id ) . '" style="display:block;color:#aaa;font-size:12px;margin-bottom:4px;">' . esc_html( $label ) . '</label>';
        $h  .= '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" style="min-width:320px;padding:5px 8px;border-radius:4px;border:1px solid #555;background:#2a2a2a;color:#fff;">';
        $h  .= '<option value="0">&mdash; none &mdash;</option>';
        foreach ( $choices as $aid => $text ) {
            $h .= '<option value="' . (int) $aid . '"' . ( (int) $aid === $current ? ' selected' : '' ) . '>' . esc_html( $text ) . '</option>';
        }
        $h .= '</select></p>';
        return $h;
    }
}
