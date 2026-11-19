<?php
/**
 * FisHotel North Star Stock — fetch marine fish inventory from North Star Aquatics
 * and import directly into a batch.
 */
trait FisHotel_NorthStar {

    /**
     * Per-call fetch budget for ajax_northstar_fetch (wall-clock deadline + request cap).
     * One admin-ajax call used to issue hundreds of sequential HTTP requests with no limit; a
     * web-server/proxy read timeout then killed the whole run with nothing cached. The budget
     * makes the call stop early and return partial results with a clear message instead.
     */
    private $ns_budget = [ 'deadline' => 0.0, 'max_requests' => 0, 'requests' => 0, 'exhausted' => false, 'truncated' => false ];

    private function northstar_budget_exhausted(): bool {
        if ( empty( $this->ns_budget['deadline'] ) ) return false; // no budget armed (not inside a fetch)
        if ( $this->ns_budget['exhausted'] ) return true;
        if ( microtime( true ) >= $this->ns_budget['deadline'] || $this->ns_budget['requests'] >= $this->ns_budget['max_requests'] ) {
            $this->ns_budget['exhausted'] = true;
        }
        return $this->ns_budget['exhausted'];
    }

    private function get_northstar_categories(): array {
        return [
            '4422867000000439035' => 'All Marine Fish',
            '4422867000000455120' => 'Angelfish Marine',
            '4422867000000455124' => 'Anthias',
            '4422867000000455126' => 'Basslets & Dottybacks',
            '4422867000000455128' => 'Blenny',
            '4422867000000455130' => 'Butterflyfish',
            '4422867000000455132' => 'Cardinalfish',
            '4422867000000455134' => 'Clownfish',
            '4422867000000455138' => 'Damselfish & Chromis',
            '4422867000000455140' => 'Eels Marine',
            '4422867000000455144' => 'Goby',
            '4422867000000455146' => 'Grouper',
            '4422867000000455148' => 'Hawkfish',
            '4422867000000455150' => 'Lionfish',
            '4422867000000455156' => 'Misc Marine Fish',
            '4422867000000455158' => 'Puffers Marine',
            '4422867000000455160' => 'Rabbitfish / Foxface',
            '4422867000000455164' => 'Sharks & Stingrays Marine',
            '4422867000000455166' => 'Snapper',
            '4422867000000455170' => 'Tang',
            '4422867000000455172' => 'Trigger',
            '4422867000000455174' => 'Wrasse',
        ];
    }

    private function northstar_fetch_category( string $cat_id ): array {
        $base = 'https://www.northstaraquatics.com/api/product-list';
        $products = [];
        $seen_hrefs = [];
        $page = 1;

        while ( true ) {
            $url = add_query_arg( [
                'sort_by'     => 'AtoZ',
                'is_filters'  => 'true',
                'send_facet'  => 'false',
                'type'        => 'categories',
                'send_ticket' => 'true',
                'id'          => $cat_id,
            ], $base );

            if ( $page > 1 ) {
                $url = add_query_arg( 'page_number', $page, $url );
            }

            // Stop paging once the per-call budget is used up (returns what was fetched so far) and
            // flag it, so the caller reports this category as incomplete instead of complete.
            if ( $this->northstar_budget_exhausted() ) { $this->ns_budget['truncated'] = true; break; }
            $this->ns_budget['requests']++;

            $response = wp_remote_get( $url, [
                'timeout' => 15, // was 30: a list page answers in a few seconds; a stuck one must not eat the whole budget
                'headers' => [
                    'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                    'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                ],
            ] );

            if ( is_wp_error( $response ) ) break;

            $body = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( empty( $body['payload']['product_list_content'] ) ) break;

            $html = $body['payload']['product_list_content'];
            $parsed = $this->northstar_parse_html( $html );

            if ( empty( $parsed ) ) break;

            foreach ( $parsed as $p ) {
                $key = $p['href'] ?? $p['name'];
                if ( isset( $seen_hrefs[ $key ] ) ) continue;
                $seen_hrefs[ $key ] = true;
                $products[] = $p;
            }

            if ( count( $parsed ) < 100 ) break;

            $page++;
            usleep( 500000 ); // 0.5s delay
        }

        return $products;
    }

    private function northstar_parse_html( string $html ): array {
        $products = [];
        $seen = [];

        libxml_use_internal_errors( true );
        $doc = new DOMDocument();
        $doc->loadHTML( '<html><body>' . $html . '</body></html>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
        libxml_clear_errors();

        $xpath = new DOMXPath( $doc );

        // Each product lives in a div.theme-prod-box
        $cards = $xpath->query( "//*[contains(@class, 'theme-prod-box')]" );

        foreach ( $cards as $card ) {
            // Name: inside .theme-product-name a
            $name_links = $xpath->query( ".//*[contains(@class, 'theme-product-name')]//a", $card );
            if ( $name_links->length === 0 ) continue;

            $name_el = $name_links->item( 0 );
            $name = trim( $name_el->textContent );
            $href = $name_el->getAttribute( 'href' );

            if ( empty( $name ) || empty( $href ) ) continue;

            // Deduplicate by href
            if ( isset( $seen[ $href ] ) ) continue;
            $seen[ $href ] = true;

            // Stock: .theme-ribbon-stock — if present and text contains "out of stock" → out_of_stock, otherwise → in_stock
            $stock = 'in_stock';
            $ribbons = $xpath->query( ".//*[contains(@class, 'theme-ribbon-stock')]", $card );
            if ( $ribbons->length > 0 ) {
                $ribbon_text = strtolower( trim( $ribbons->item( 0 )->textContent ) );
                if ( strpos( $ribbon_text, 'out of stock' ) !== false ) {
                    $stock = 'out_of_stock';
                }
            }

            // Price: .theme-product-price — grab first $XX.XX pattern
            $price = '';
            $price_nodes = $xpath->query( ".//*[contains(@class, 'theme-product-price')]", $card );
            if ( $price_nodes->length > 0 ) {
                $price_text = trim( $price_nodes->item( 0 )->textContent );
                if ( preg_match( '/\$[\d,]+\.?\d*/', $price_text, $m ) ) {
                    $price = $m[0];
                }
            }

            $products[] = [
                'name'   => $name,
                'href'   => $href,
                'stock'  => $stock,
                'price'  => $price,
            ];
        }

        return $products;
    }

    /**
     * Fills $products[i]['qty'] for in-stock items (one product page each).
     * Returns the number of in-stock items left WITHOUT a qty because the per-call budget ran out.
     * Those get qty = null plus qty_missing = true (never 0): a fetched-zero item and a not-fetched
     * item must stay distinguishable, because the import stores qty as the batch _stock and a 0
     * there renders the fish as "Void" on the gate page.
     */
    private function northstar_fetch_stock_quantities( array &$products ): int {
        $in_stock_indices = [];
        foreach ( $products as $i => $p ) {
            if ( $p['stock'] === 'in_stock' ) {
                $in_stock_indices[] = $i;
            } else {
                $products[ $i ]['qty'] = 0;
            }
        }

        $total = count( $in_stock_indices );
        if ( $total === 0 ) return 0;

        $done    = 0;
        $skipped = 0;
        foreach ( $in_stock_indices as $i ) {
            // Budget check before every request: leave remaining items without a qty rather than
            // run past the web-server timeout and lose everything. Mark them explicitly so the
            // table, the cache and the import can tell "not fetched" from "fetched, 0 in stock".
            if ( $this->northstar_budget_exhausted() ) {
                $products[ $i ]['qty']         = null;
                $products[ $i ]['qty_missing'] = true;
                $skipped++;
                continue;
            }
            $this->ns_budget['requests']++;

            $href = $products[ $i ]['href'];
            $url  = 'https://www.northstaraquatics.com' . $href;

            $response = wp_remote_get( $url, [
                'timeout' => 10, // was 15: one product page; keep a slow one from eating the budget
                'headers' => [
                    'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                    'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                ],
            ] );
            $done++;

            if ( is_wp_error( $response ) ) {
                $products[ $i ]['qty'] = 0;
                usleep( 300000 );
                continue;
            }

            $status_code = wp_remote_retrieve_response_code( $response );
            $body = wp_remote_retrieve_body( $response );

            if ( $status_code !== 200 ) {
                $products[ $i ]['qty'] = 0;
                usleep( 300000 );
                continue;
            }

            // Stock qty lives in JSON variant data as "stock_available":N
            if ( preg_match( '/"stock_available"\s*:\s*(\d+)/', $body, $m ) ) {
                $products[ $i ]['qty'] = intval( $m[1] );
            } else {
                $products[ $i ]['qty'] = 0;
            }

            usleep( 300000 ); // 0.3s delay
        }

        return $skipped;
    }

    // ─── Master fish lookup with size-variant stripping ────────────────

    private function northstar_find_or_create_master( string $name ): int {
        // 1. Exact match
        $master = get_posts( [
            'post_type'   => 'fish_master',
            'title'       => $name,
            'numberposts' => 1,
            'post_status' => 'publish',
        ] );
        if ( $master ) return $master[0]->ID;

        // 2. Strip size suffixes and try again
        $size_pattern = '/\s+(?:'
            . 'Half\s+Black\s+(?:XXL|XL|XS|SM|ML|S|M|L)|'  // Half Black M, etc.
            . 'Juv\s+(?:XXL|XL|XS|SM|ML|S|M|L)|'           // Juv S, Juv ML, etc.
            . 'Adult\s+(?:XXL|XL|XS|SM|ML|S|M|L)|'          // Adult S, Adult ML, etc.
            . 'Male\s+(?:XXL|XL|XS|SM|ML|S|M|L)|'           // Male M, Male S, etc.
            . 'Female\s+(?:XXL|XL|XS|SM|ML|S|M|L)|'         // Female M, Female ML, etc.
            . 'Wild\s+(?:XXL|XL|XS|SM|ML|S|M|L)|'           // Wild M, Wild S, etc.
            . 'IO\s+(?:XXL|XL|XS|SM|ML|S|M|L)|'             // IO M, IO XS, etc.
            . 'Juv|Adult|Male|Female|Wild|'                  // Standalone modifiers
            . 'Pair|Trio|'                                   // Group indicators
            . 'XXL|XL|XS|SM|ML|S|M|L'                       // Single sizes (shortest last)
            . ')$/i';
        $stripped = preg_replace( $size_pattern, '', $name );
        $stripped = trim( $stripped );

        if ( $stripped !== $name && ! empty( $stripped ) ) {
            $master = get_posts( [
                'post_type'   => 'fish_master',
                'title'       => $stripped,
                'numberposts' => 1,
                'post_status' => 'publish',
            ] );
            if ( $master ) return $master[0]->ID;
        }

        // 3. Fuzzy match — master title starts with our name, or our name starts with master title
        $lookup = ( $stripped !== $name && ! empty( $stripped ) ) ? $stripped : $name;
        if ( strlen( $lookup ) >= 8 ) {
            global $wpdb;
            // Forward: "Emperor Angel" matches "Emperor Angelfish"
            $fuzzy_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = 'fish_master' AND post_status = 'publish'
                 AND post_title LIKE %s
                 ORDER BY CHAR_LENGTH(post_title) ASC LIMIT 1",
                $wpdb->esc_like( $lookup ) . '%'
            ) );
            if ( ! $fuzzy_id ) {
                // Reverse: master "Foxface" matches our "Foxface Rabbitfish"
                $fuzzy_id = $wpdb->get_var( $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts}
                     WHERE post_type = 'fish_master' AND post_status = 'publish'
                     AND %s LIKE CONCAT(post_title, '%%')
                     AND CHAR_LENGTH(post_title) >= 8
                     ORDER BY CHAR_LENGTH(post_title) DESC LIMIT 1",
                    $lookup
                ) );
            }
            if ( $fuzzy_id ) {
                return intval( $fuzzy_id );
            }
        }

        // 4. No match — create new fish_master using the stripped name (or original if no strip)
        $create_name = ( $stripped !== $name && ! empty( $stripped ) ) ? $stripped : $name;

        $mid = wp_insert_post( [
            'post_type'   => 'fish_master',
            'post_title'  => $create_name,
            'post_status' => 'publish',
        ] );

        return ( $mid && ! is_wp_error( $mid ) ) ? $mid : 0;
    }

    // ─── AJAX: Fetch categories ─────────────────────────────────────────

    public function ajax_northstar_fetch() {
        check_ajax_referer( 'fishotel_northstar_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Access denied.' );

        $cat_ids = isset( $_POST['categories'] ) ? array_map( 'sanitize_text_field', (array) $_POST['categories'] ) : [];
        if ( empty( $cat_ids ) ) wp_send_json_error( 'No categories selected.' );

        $categories = $this->get_northstar_categories();
        $all_marine_id = '4422867000000439035';

        // Expand "All Marine Fish" into individual subcategory fetches for correct labeling
        if ( in_array( $all_marine_id, $cat_ids, true ) ) {
            $cat_ids = array_keys( $categories );
            // Remove the parent "All Marine Fish" — only fetch the 20 subcategories
            $cat_ids = array_filter( $cat_ids, function( $id ) use ( $all_marine_id ) {
                return $id !== $all_marine_id;
            } );
            $cat_ids = array_values( $cat_ids );
        }

        // Arm the per-call budget. PHP's max_execution_time does not count time blocked in curl
        // on Linux, so the real killer is the web-server/proxy read timeout (commonly 60s): stay
        // under it and return partial results instead of dying with nothing cached.
        if ( function_exists( 'wc_set_time_limit' ) ) {
            wc_set_time_limit( 0 );
        } elseif ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }
        $budget_seconds = max( 5, (int) apply_filters( 'fishotel_northstar_fetch_budget_seconds', 40 ) );
        $max_requests   = max( 1, (int) apply_filters( 'fishotel_northstar_fetch_max_requests', 200 ) );
        $this->ns_budget = [
            'deadline'     => microtime( true ) + $budget_seconds,
            'max_requests' => $max_requests,
            'requests'     => 0,
            'exhausted'    => false,
            'truncated'    => false,
        ];

        $all_products = [];
        $seen = [];
        $errors = [];
        $skipped_categories = [];

        foreach ( $cat_ids as $cid ) {
            $label = $categories[ $cid ] ?? $cid;

            if ( $this->northstar_budget_exhausted() ) {
                $skipped_categories[] = $label;
                continue;
            }

            $fetched = $this->northstar_fetch_category( $cid );

            // Paging stopped early for this category: list it as incomplete, not as fully fetched.
            if ( ! empty( $this->ns_budget['truncated'] ) ) { $skipped_categories[] = $label . ' (incomplete)'; $this->ns_budget['truncated'] = false; }

            if ( empty( $fetched ) ) {
                $errors[] = $label;
                continue;
            }

            foreach ( $fetched as $p ) {
                $key = $p['href'] ?? $p['name'];
                if ( isset( $seen[ $key ] ) ) continue;
                $seen[ $key ] = true;
                $p['category'] = $label;
                $all_products[] = $p;
            }

            if ( count( $cat_ids ) > 1 ) usleep( 500000 ); // 0.5s between categories
        }

        // Cache the category pass right away so a timeout during the (much longer) per-product
        // quantity pass still leaves the product list available on reload. This interim cache is
        // marked partial: whoever reads it (a reload after a timeout) must be told that no in-stock
        // item has a quantity yet, otherwise the table shows "—" with no explanation and an import
        // would store every fish with _stock = 0. The final write below replaces these markers.
        $fetched_at = current_time( 'mysql' );
        $in_stock_total = count( array_filter( $all_products, function( $p ) { return ( $p['stock'] ?? '' ) === 'in_stock'; } ) );
        set_transient( 'fishotel_northstar_cache', [
            'products'           => $all_products,
            'fetched_at'         => $fetched_at,
            'partial'            => true,
            'partial_message'    => 'The quantity pass did not finish (the fetch was cut off): ' . $in_stock_total . ' in-stock item(s) have no quantity yet. Re-fetch (fewer categories at a time) before importing them.',
            'skipped_categories' => $skipped_categories,
            'qty_skipped'        => $in_stock_total,
        ], HOUR_IN_SECONDS );

        // Second pass: fetch stock quantities for in-stock items only
        $qty_skipped = $this->northstar_fetch_stock_quantities( $all_products );

        $partial_notes = [];
        if ( ! empty( $skipped_categories ) ) {
            $partial_notes[] = count( $skipped_categories ) . ' categor' . ( count( $skipped_categories ) === 1 ? 'y' : 'ies' ) . ' not fetched or incomplete (' . implode( ', ', $skipped_categories ) . ')';
        }
        if ( $qty_skipped > 0 ) {
            $partial_notes[] = $qty_skipped . ' in-stock item(s) without a quantity (shown as "?", skipped by Import)';
        }
        $partial = $this->ns_budget['exhausted'];
        $partial_message = $partial
            ? 'Time budget reached (' . $budget_seconds . 's): ' . ( $partial_notes ? implode( '; ', $partial_notes ) : 'results may be incomplete' ) . '. Fetch fewer categories at a time for complete results.'
            : '';

        // Persist the partial markers with the products so a reload (or another admin) still sees
        // the warning; the status line of this one response is otherwise the only place it appears.
        $fetched_at = current_time( 'mysql' );
        $cache = [
            'products'           => $all_products,
            'fetched_at'         => $fetched_at,
            'partial'            => $partial,
            'partial_message'    => $partial_message,
            'skipped_categories' => $skipped_categories,
            'qty_skipped'        => $qty_skipped,
        ];
        set_transient( 'fishotel_northstar_cache', $cache, HOUR_IN_SECONDS );

        wp_send_json_success( [
            'products'        => $all_products,
            'fetched_at'      => $fetched_at,
            'errors'          => $errors,
            'partial'         => $partial,
            'partial_message' => $partial_message,
            'skipped_categories' => $skipped_categories,
            'qty_skipped'     => $qty_skipped,
        ] );
    }

    // ─── AJAX: Import to batch ──────────────────────────────────────────

    public function ajax_northstar_import() {
        check_ajax_referer( 'fishotel_northstar_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Access denied.' );

        $batch_name = isset( $_POST['batch_name'] ) ? sanitize_text_field( $_POST['batch_name'] ) : '';
        $items      = isset( $_POST['items'] ) ? $_POST['items'] : [];

        if ( empty( $batch_name ) ) wp_send_json_error( 'No batch selected.' );
        if ( empty( $items ) || ! is_array( $items ) ) wp_send_json_error( 'No items selected.' );

        // Get existing fish_batch posts for this batch to check duplicates
        $existing = get_posts( [
            'post_type'   => 'fish_batch',
            'numberposts' => -1,
            'meta_key'    => '_batch_name',
            'meta_value'  => $batch_name,
        ] );

        // Retroactive fix: revert any en-dash titles back to regular hyphen
        $endash = "\xE2\x80\x93";
        $fixed = 0;
        foreach ( $existing as $ep ) {
            $old_title = $ep->post_title;
            if ( strpos( $old_title, " {$endash} " ) !== false ) {
                $new_title = str_replace( " {$endash} ", ' - ', $old_title );
                wp_update_post( [ 'ID' => $ep->ID, 'post_title' => $new_title ] );
                $ep->post_title = $new_title;
                $fixed++;
            }
        }

        // Retroactive fix: find posts with batch name in title but missing _batch_name meta
        $orphans = get_posts( [
            'post_type'   => 'fish_batch',
            'numberposts' => -1,
            's'           => $batch_name,
            'meta_query'  => [
                [
                    'key'     => '_batch_name',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ] );
        $meta_fixed = 0;
        foreach ( $orphans as $op ) {
            // Confirm the batch name is actually in the title
            if ( stripos( $op->post_title, $batch_name ) !== false ) {
                update_post_meta( $op->ID, '_batch_name', $batch_name );
                update_post_meta( $op->ID, '_stock', 0 );
                $existing[] = $op;
                $meta_fixed++;
            }
        }

        $existing_names = [];
        foreach ( $existing as $ep ) {
            // Extract common name — split on en dash or hyphen
            $common = preg_replace( "/\s+[\x{2013}\x{2014}-]\s+.+$/u", '', $ep->post_title );
            $existing_names[] = strtolower( trim( $common ) );
        }

        $imported = 0;
        $skipped = 0;
        $skipped_names = [];
        $qty_missing = 0;
        $qty_missing_names = [];

        foreach ( $items as $item ) {
            $name    = sanitize_text_field( $item['name'] ?? '' );
            $price   = sanitize_text_field( $item['price'] ?? '' );
            $qty_raw = isset( $item['qty'] ) ? trim( (string) $item['qty'] ) : '';

            if ( empty( $name ) ) continue;

            if ( in_array( strtolower( $name ), $existing_names, true ) ) {
                $skipped++;
                $skipped_names[] = $name;
                continue;
            }

            // A non-numeric qty ('', 'null', ...) means the quantity was never fetched (budget ran
            // out), not that the fish is out of stock. Storing it as _stock = 0 would make the fish
            // "Void" on the gate page, so skip it and tell the admin to re-fetch instead.
            if ( ! is_numeric( $qty_raw ) ) {
                $qty_missing++;
                $qty_missing_names[] = $name;
                continue;
            }
            $qty = max( 0, intval( $qty_raw ) );

            // Find fish_master: exact match first, then try stripping size suffixes
            $master_id = $this->northstar_find_or_create_master( $name );

            $post_id = wp_insert_post( [
                'post_type'   => 'fish_batch',
                'post_title'  => $name . ' - ' . $batch_name,
                'post_status' => 'publish',
            ] );

            if ( $post_id && ! is_wp_error( $post_id ) ) {
                if ( $master_id && ! is_wp_error( $master_id ) ) {
                    update_post_meta( $post_id, '_master_id', $master_id );
                }
                update_post_meta( $post_id, '_batch_name', $batch_name );
                update_post_meta( $post_id, '_northstar_price', $price );
                update_post_meta( $post_id, '_stock', $qty );
                $imported++;
                $existing_names[] = strtolower( $name );
            }
        }

        wp_send_json_success( [
            'imported'          => $imported,
            'skipped'           => $skipped,
            'skipped_names'     => $skipped_names,
            'qty_missing'       => $qty_missing,
            'qty_missing_names' => $qty_missing_names,
            'titles_fixed'      => $fixed,
            'meta_fixed'        => $meta_fixed,
        ] );
    }

    // ─── Admin Page HTML ────────────────────────────────────────────────

    public function northstar_stock_html() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Access denied.' );

        // Auto-fix orphaned fish_batch posts missing _batch_name or _master_id meta
        $orphan_count = 0;

        // Fix missing _batch_name
        $no_batch = get_posts( [
            'post_type'   => 'fish_batch',
            'numberposts' => -1,
            'meta_query'  => [ [ 'key' => '_batch_name', 'compare' => 'NOT EXISTS' ] ],
        ] );
        foreach ( $no_batch as $op ) {
            $last_sep = strrpos( $op->post_title, ' - ' );
            if ( $last_sep !== false ) {
                $batch = trim( substr( $op->post_title, $last_sep + 3 ) );
                if ( ! empty( $batch ) ) {
                    update_post_meta( $op->ID, '_batch_name', $batch );
                    if ( ! metadata_exists( 'post', $op->ID, '_stock' ) ) {
                        update_post_meta( $op->ID, '_stock', 0 );
                    }
                    $orphan_count++;
                }
            }
        }

        // Fix missing _master_id — use smart matching with size-variant stripping
        $no_master = get_posts( [
            'post_type'   => 'fish_batch',
            'numberposts' => -1,
            'meta_query'  => [ [ 'key' => '_master_id', 'compare' => 'NOT EXISTS' ] ],
        ] );
        foreach ( $no_master as $op ) {
            $common = preg_replace( "/\s+[\x{2013}\x{2014}-]\s+.+$/u", '', $op->post_title );
            $common = trim( $common );
            if ( empty( $common ) ) continue;

            $mid = $this->northstar_find_or_create_master( $common );
            if ( $mid ) {
                update_post_meta( $op->ID, '_master_id', $mid );
                $orphan_count++;
            }
        }

        $categories = $this->get_northstar_categories();
        $cache = get_transient( 'fishotel_northstar_cache' );
        $cached_products = $cache ? $cache['products'] : [];
        $fetched_at = $cache ? $cache['fetched_at'] : '';
        // Partial-fetch markers saved with the cache (see ajax_northstar_fetch) so the warning
        // survives a reload instead of living only in one fetch response's status line.
        $cached_partial_message = ( $cache && ! empty( $cache['partial'] ) ) ? (string) ( $cache['partial_message'] ?? 'The last fetch was cut short; some quantities are missing. Re-fetch before importing.' ) : '';

        $batches_str = get_option( 'fishotel_batches', '' );
        $batches_array = array_filter( array_map( 'trim', explode( "\n", $batches_str ) ) );

        $nonce = wp_create_nonce( 'fishotel_northstar_nonce' );
        ?>
        <div class="wrap fishotel-admin">
        <h1 style="color:#2986cc;font-size:26px;font-weight:700;margin-bottom:20px;">North Star Stock</h1>
        <?php if ( $orphan_count > 0 ) : ?>
        <div style="background:#2a2a2a;border:1px solid #27ae60;border-radius:6px;padding:12px 18px;margin-bottom:16px;color:#27ae60;font-size:14px;">
            Auto-fixed <?php echo $orphan_count; ?> batch fish post<?php echo $orphan_count > 1 ? 's' : ''; ?> with missing batch name meta.
        </div>
        <?php endif; ?>

        <!-- Category Selector -->
        <div style="background:#1e1e1e;border:1px solid #444;border-radius:8px;padding:25px;margin-bottom:20px;">
            <h2 style="color:#fff;font-size:18px;margin:0 0 15px 0;">Select Categories to Fetch</h2>
            <div style="margin-bottom:12px;">
                <button type="button" id="ns-select-all" style="background:#2a2a2a;color:#2986cc;border:1px solid #555;border-radius:4px;padding:6px 14px;cursor:pointer;font-size:13px;margin-right:8px;">Select All</button>
                <button type="button" id="ns-deselect-all" style="background:#2a2a2a;color:#2986cc;border:1px solid #555;border-radius:4px;padding:6px 14px;cursor:pointer;font-size:13px;">Deselect All</button>
            </div>
            <div id="ns-categories" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px 16px;">
                <?php foreach ( $categories as $id => $label ) :
                    $is_all = ( $id === '4422867000000439035' );
                ?>
                <label style="display:flex;align-items:center;gap:8px;color:<?php echo $is_all ? '#2986cc' : '#ddd'; ?>;font-size:14px;cursor:pointer;<?php echo $is_all ? 'font-weight:700;grid-column:1/-1;border-bottom:1px solid #444;padding-bottom:10px;margin-bottom:4px;' : ''; ?>">
                    <input type="checkbox" class="ns-cat-cb" value="<?php echo esc_attr( $id ); ?>" data-label="<?php echo esc_attr( $label ); ?>" style="accent-color:#2986cc;">
                    <?php echo esc_html( $label ); ?>
                </label>
                <?php endforeach; ?>
            </div>
            <div style="margin-top:16px;">
                <button type="button" id="ns-fetch-btn" style="background:#2986cc;color:#fff;font-weight:700;border:none;border-radius:6px;padding:12px 28px;cursor:pointer;font-size:15px;">Fetch Selected Categories</button>
                <span id="ns-fetch-status" style="margin-left:14px;color:#aaa;font-size:14px;"></span>
            </div>
        </div>

        <!-- Results Section -->
        <div id="ns-results" style="background:#1e1e1e;border:1px solid #444;border-radius:8px;padding:25px;margin-bottom:20px;<?php echo empty( $cached_products ) ? 'display:none;' : ''; ?>">
            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin-bottom:16px;">
                <h2 style="color:#fff;font-size:18px;margin:0;">Results</h2>
                <span id="ns-fetched-at" style="color:#888;font-size:13px;"><?php echo $fetched_at ? 'Last fetched: ' . esc_html( $fetched_at ) : ''; ?></span>
                <button type="button" id="ns-refresh-btn" style="background:#2a2a2a;color:#2986cc;border:1px solid #555;border-radius:4px;padding:5px 12px;cursor:pointer;font-size:13px;margin-left:auto;">Refresh</button>
            </div>
            <!-- Persistent partial-fetch warning (filled from the cache on load, updated after each fetch) -->
            <div id="ns-partial-banner" style="background:#2a2a2a;border:1px solid #e67e22;border-radius:6px;padding:10px 14px;margin-bottom:14px;color:#e67e22;font-size:13px;<?php echo $cached_partial_message === '' ? 'display:none;' : ''; ?>"><strong>Incomplete fetch:</strong> <span id="ns-partial-text"><?php echo esc_html( $cached_partial_message ); ?></span></div>
            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin-bottom:14px;">
                <input type="text" id="ns-search" placeholder="Search by name..." style="background:#2a2a2a;border:1px solid #555;color:#fff;padding:8px 12px;border-radius:4px;width:260px;font-size:14px;">
                <label style="color:#ddd;font-size:14px;cursor:pointer;display:flex;align-items:center;gap:6px;">
                    <input type="checkbox" id="ns-instock-filter" checked style="accent-color:#2986cc;"> In Stock only
                </label>
                <span id="ns-count" style="color:#888;font-size:13px;margin-left:auto;"></span>
            </div>
            <div style="overflow-x:auto;">
                <table id="ns-table" style="width:100%;border-collapse:collapse;font-size:14px;">
                    <thead>
                        <tr style="border-bottom:2px solid #555;">
                            <th style="padding:10px 8px;text-align:left;color:#2986cc;width:40px;"><input type="checkbox" id="ns-select-all-rows" style="accent-color:#2986cc;"></th>
                            <th style="padding:10px 8px;text-align:left;color:#2986cc;">Common Name</th>
                            <th style="padding:10px 8px;text-align:left;color:#2986cc;">Category</th>
                            <th style="padding:10px 8px;text-align:center;color:#2986cc;">Stock</th>
                            <th style="padding:10px 8px;text-align:center;color:#2986cc;" id="ns-qty-header">Qty</th>
                            <th style="padding:10px 8px;text-align:right;color:#2986cc;">Price</th>
                        </tr>
                    </thead>
                    <tbody id="ns-tbody"></tbody>
                </table>
            </div>
        </div>

        <!-- Import Section -->
        <div id="ns-import-section" style="background:#1e1e1e;border:1px solid #444;border-radius:8px;padding:25px;<?php echo empty( $cached_products ) ? 'display:none;' : ''; ?>">
            <h2 style="color:#fff;font-size:18px;margin:0 0 15px 0;">Import to Batch</h2>
            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:12px;">
                <select id="ns-batch-select" style="background:#2a2a2a;border:1px solid #555;color:#fff;padding:8px 12px;border-radius:4px;font-size:14px;min-width:220px;">
                    <option value="">— Select Batch —</option>
                    <?php foreach ( $batches_array as $b ) : ?>
                    <option value="<?php echo esc_attr( $b ); ?>"><?php echo esc_html( $b ); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" id="ns-import-btn" style="background:#2986cc;color:#fff;font-weight:700;border:none;border-radius:6px;padding:10px 24px;cursor:pointer;font-size:14px;">Import Selected to Batch</button>
                <span id="ns-import-status" style="color:#aaa;font-size:14px;"></span>
            </div>
        </div>

        <script>
        (function(){
            var products = <?php echo wp_json_encode( $cached_products ); ?>;
            var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
            var nonce = '<?php echo esc_js( $nonce ); ?>';

            // ─── Category controls ──────────────────────────
            document.getElementById('ns-select-all').addEventListener('click', function(){
                document.querySelectorAll('.ns-cat-cb').forEach(function(cb){ cb.checked = true; });
            });
            document.getElementById('ns-deselect-all').addEventListener('click', function(){
                document.querySelectorAll('.ns-cat-cb').forEach(function(cb){ cb.checked = false; });
            });

            // In-stock item whose quantity was never fetched (budget ran out, or the quantity pass
            // was cut off before it got there). Distinct from "fetched, 0 in stock" (qty === 0).
            function qtyMissing(p){
                return p.stock === 'in_stock' && ( p.qty_missing || p.qty === null || typeof p.qty === 'undefined' );
            }

            // Show/hide the persistent partial-fetch banner.
            function setPartialBanner(msg){
                var banner = document.getElementById('ns-partial-banner');
                var text   = document.getElementById('ns-partial-text');
                if ( ! banner || ! text ) return;
                text.textContent = msg || '';
                banner.style.display = msg ? '' : 'none';
            }

            // ─── Render table ───────────────────────────────
            function renderTable(){
                var tbody = document.getElementById('ns-tbody');
                var search = document.getElementById('ns-search').value.toLowerCase();
                var inStockOnly = document.getElementById('ns-instock-filter').checked;
                var html = '';
                var shown = 0;
                var missingCount = products.filter(qtyMissing).length;

                // Qty header hint: the old "any product has a qty" test never fired after a cut-short
                // fetch (out-of-stock rows always carry qty 0), so count the rows actually missing one.
                var qtyHeader = document.getElementById('ns-qty-header');
                if ( qtyHeader ) {
                    qtyHeader.innerHTML = missingCount
                        ? 'Qty <span style="display:block;font-size:10px;font-weight:400;color:#e67e22;">(' + missingCount + ' not fetched — re-fetch)</span>'
                        : 'Qty';
                }

                for ( var i = 0; i < products.length; i++ ) {
                    var p = products[i];
                    if ( inStockOnly && p.stock !== 'in_stock' ) continue;
                    if ( search && p.name.toLowerCase().indexOf( search ) === -1 ) continue;
                    shown++;

                    var stockBadge = p.stock === 'in_stock'
                        ? '<span style="background:#27ae60;color:#fff;padding:2px 10px;border-radius:10px;font-size:12px;font-weight:600;">In Stock</span>'
                        : '<span style="background:#c0392b;color:#fff;padding:2px 10px;border-radius:10px;font-size:12px;font-weight:600;">Out of Stock</span>';

                    var qtyDisplay = '—';
                    if ( qtyMissing(p) ) {
                        // "?" not "—": this row has no quantity yet and Import will skip it.
                        qtyDisplay = '<span title="Quantity not fetched (fetch stopped early). Re-fetch before importing." style="color:#e67e22;font-weight:700;cursor:help;">?</span>';
                    } else if ( typeof p.qty !== 'undefined' && p.qty !== null ) {
                        qtyDisplay = p.stock === 'in_stock' ? (p.qty > 0 ? p.qty : '—') : '0';
                    }

                    html += '<tr style="border-bottom:1px solid #333;" data-idx="' + i + '">';
                    html += '<td style="padding:8px;"><input type="checkbox" class="ns-row-cb" data-idx="' + i + '" style="accent-color:#2986cc;"></td>';
                    html += '<td style="padding:8px;color:#fff;">' + escHtml(p.name) + '</td>';
                    html += '<td style="padding:8px;color:#aaa;">' + escHtml(p.category || '') + '</td>';
                    html += '<td style="padding:8px;text-align:center;">' + stockBadge + '</td>';
                    html += '<td style="padding:8px;text-align:center;color:#fff;">' + qtyDisplay + '</td>';
                    html += '<td style="padding:8px;text-align:right;color:#fff;">' + escHtml(p.price) + '</td>';
                    html += '</tr>';
                }

                tbody.innerHTML = html;
                document.getElementById('ns-count').textContent = shown + ' of ' + products.length + ' products';
            }

            function escHtml(s){ var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

            document.getElementById('ns-search').addEventListener('input', renderTable);
            document.getElementById('ns-instock-filter').addEventListener('change', renderTable);

            document.getElementById('ns-select-all-rows').addEventListener('change', function(){
                var checked = this.checked;
                document.querySelectorAll('.ns-row-cb').forEach(function(cb){ cb.checked = checked; });
            });

            if ( products.length ) renderTable();

            // ─── Fetch ──────────────────────────────────────
            document.getElementById('ns-fetch-btn').addEventListener('click', doFetch);
            document.getElementById('ns-refresh-btn').addEventListener('click', doFetch);

            function doFetch(){
                var checked = document.querySelectorAll('.ns-cat-cb:checked');
                if ( ! checked.length ) { alert('Select at least one category.'); return; }

                var ids = [], labels = [];
                checked.forEach(function(cb){ ids.push(cb.value); labels.push(cb.dataset.label); });

                var btn = document.getElementById('ns-fetch-btn');
                var status = document.getElementById('ns-fetch-status');
                btn.disabled = true;
                btn.textContent = 'Fetching...';
                status.textContent = 'Fetching categories: ' + labels.join(', ') + '... then stock quantities for in-stock items.';

                var fd = new FormData();
                fd.append('action', 'fishotel_northstar_fetch');
                fd.append('nonce', nonce);
                ids.forEach(function(id){ fd.append('categories[]', id); });

                fetch(ajaxUrl, { method:'POST', body: fd })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        btn.disabled = false;
                        btn.textContent = 'Fetch Selected Categories';

                        if ( res.success ) {
                            products = res.data.products;
                            document.getElementById('ns-fetched-at').textContent = 'Last fetched: ' + res.data.fetched_at;
                            document.getElementById('ns-results').style.display = '';
                            document.getElementById('ns-import-section').style.display = '';
                            renderTable();

                            var msg = products.length + ' products loaded.';
                            if ( res.data.errors && res.data.errors.length ) {
                                msg += ' Failed: ' + res.data.errors.join(', ');
                            }
                            // Server stops early once its per-call time budget is used up; say so,
                            // both in the status line and in the persistent banner above the table.
                            if ( res.data.partial && res.data.partial_message ) {
                                msg += ' ' + res.data.partial_message;
                            }
                            setPartialBanner( res.data.partial ? res.data.partial_message : '' );
                            status.textContent = msg;
                        } else {
                            status.textContent = 'Error: ' + (res.data || 'Unknown error');
                        }
                    })
                    .catch(function(e){
                        btn.disabled = false;
                        btn.textContent = 'Fetch Selected Categories';
                        status.textContent = 'Network error: ' + e.message;
                    });
            }

            // ─── Import ─────────────────────────────────────
            document.getElementById('ns-import-btn').addEventListener('click', function(){
                var batch = document.getElementById('ns-batch-select').value;
                if ( ! batch ) { alert('Select a batch first.'); return; }

                var checked = document.querySelectorAll('.ns-row-cb:checked');
                if ( ! checked.length ) { alert('Select at least one fish to import.'); return; }

                // Rows whose quantity was never fetched are left out: importing them would store
                // _stock = 0 and show the fish as "Void" on the gate page even though North Star has
                // it in stock. The admin re-fetches and imports them afterwards (duplicates are skipped).
                var items = [], missing = [];
                checked.forEach(function(cb){
                    var p = products[ parseInt(cb.dataset.idx) ];
                    if ( qtyMissing(p) ) { missing.push(p.name); return; }
                    items.push({ name: p.name, price: p.price, qty: p.qty || 0 });
                });

                if ( missing.length && ! items.length ) {
                    alert('None of the selected fish have a quantity yet (the fetch stopped early). Re-fetch fewer categories at a time, then import.');
                    return;
                }
                var question = missing.length
                    ? missing.length + ' of the selected fish have no quantity yet (the fetch stopped early) and will be SKIPPED — re-fetch to get their quantities, then import them.\n\nImport the other ' + items.length + ' into "' + batch + '" now?'
                    : 'Import ' + items.length + ' items into "' + batch + '"?';
                if ( ! confirm(question) ) return;

                var btn = document.getElementById('ns-import-btn');
                var status = document.getElementById('ns-import-status');
                btn.disabled = true;
                btn.textContent = 'Importing...';

                var fd = new FormData();
                fd.append('action', 'fishotel_northstar_import');
                fd.append('nonce', nonce);
                fd.append('batch_name', batch);
                items.forEach(function(item, i){
                    fd.append('items[' + i + '][name]', item.name);
                    fd.append('items[' + i + '][price]', item.price);
                    fd.append('items[' + i + '][qty]', item.qty);
                });

                fetch(ajaxUrl, { method:'POST', body: fd })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        btn.disabled = false;
                        btn.textContent = 'Import Selected to Batch';

                        if ( res.success ) {
                            var msg = 'Imported ' + res.data.imported + ' items';
                            if ( res.data.skipped ) {
                                msg += ', skipped ' + res.data.skipped + ' duplicates';
                                if ( res.data.skipped_names && res.data.skipped_names.length ) {
                                    msg += ' (' + res.data.skipped_names.join(', ') + ')';
                                }
                            }
                            if ( res.data.qty_missing ) {
                                msg += ', skipped ' + res.data.qty_missing + ' without a quantity (re-fetch first)';
                            }
                            if ( res.data.titles_fixed ) {
                                msg += ', fixed ' + res.data.titles_fixed + ' titles';
                            }
                            if ( res.data.meta_fixed ) {
                                msg += ', recovered ' + res.data.meta_fixed + ' orphaned posts';
                            }
                            status.textContent = msg;
                            status.style.color = '#27ae60';
                        } else {
                            status.textContent = 'Error: ' + (res.data || 'Unknown error');
                            status.style.color = '#c0392b';
                        }
                    })
                    .catch(function(e){
                        btn.disabled = false;
                        btn.textContent = 'Import Selected to Batch';
                        status.textContent = 'Network error: ' + e.message;
                        status.style.color = '#c0392b';
                    });
            });
        })();
        </script>
        </div>
        <?php
    }

    // ─── SDC CSV Import ────────────────────────────────────────────────

    public function sdc_stock_html() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Access denied.' );

        $batches_str = get_option( 'fishotel_batches', '' );
        $batches_array = array_filter( array_map( 'trim', explode( "\n", $batches_str ) ) );
        $nonce = wp_create_nonce( 'fishotel_sdc_nonce' );
        ?>
        <div class="wrap fishotel-admin">
        <h1 style="color:#2986cc;font-size:26px;font-weight:700;margin-bottom:20px;">SDC CSV Import</h1>

        <!-- Upload Section -->
        <div style="background:#1e1e1e;border:1px solid #444;border-radius:8px;padding:25px;margin-bottom:20px;">
            <p style="color:#aaa;font-size:14px;margin:0 0 16px 0;">
                Download your stock CSV from <strong style="color:#fff;">shop.seadwelling.com</strong> &rarr; Download Stock &rarr; Saltwater, then upload it here.
            </p>
            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:12px;">
                <input type="file" id="sdc-file" accept=".csv" style="background:#2a2a2a;border:1px solid #555;color:#fff;padding:8px 12px;border-radius:4px;font-size:14px;">
                <button type="button" id="sdc-preview-btn" style="background:#2986cc;color:#fff;font-weight:700;border:none;border-radius:6px;padding:10px 24px;cursor:pointer;font-size:15px;">Preview</button>
                <span id="sdc-preview-status" style="color:#aaa;font-size:14px;"></span>
            </div>
        </div>

        <!-- Results Section -->
        <div id="sdc-results" style="background:#1e1e1e;border:1px solid #444;border-radius:8px;padding:25px;margin-bottom:20px;display:none;">
            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin-bottom:14px;">
                <h2 style="color:#fff;font-size:18px;margin:0;">Preview</h2>
                <input type="text" id="sdc-search" placeholder="Search by name..." style="background:#2a2a2a;border:1px solid #555;color:#fff;padding:8px 12px;border-radius:4px;width:260px;font-size:14px;">
                <span id="sdc-count" style="color:#888;font-size:13px;margin-left:auto;"></span>
            </div>
            <div style="overflow-x:auto;">
                <table id="sdc-table" style="width:100%;border-collapse:collapse;font-size:14px;">
                    <thead>
                        <tr style="border-bottom:2px solid #555;">
                            <th style="padding:10px 8px;text-align:left;color:#2986cc;width:40px;"><input type="checkbox" id="sdc-select-all" style="accent-color:#2986cc;"></th>
                            <th style="padding:10px 8px;text-align:left;color:#2986cc;">Name</th>
                            <th style="padding:10px 8px;text-align:left;color:#2986cc;">Scientific Name</th>
                            <th style="padding:10px 8px;text-align:center;color:#2986cc;">Size</th>
                            <th style="padding:10px 8px;text-align:right;color:#2986cc;">Price</th>
                            <th style="padding:10px 8px;text-align:center;color:#2986cc;">Stock</th>
                        </tr>
                    </thead>
                    <tbody id="sdc-tbody"></tbody>
                </table>
            </div>
        </div>

        <!-- Import Section -->
        <div id="sdc-import-section" style="background:#1e1e1e;border:1px solid #444;border-radius:8px;padding:25px;display:none;">
            <h2 style="color:#fff;font-size:18px;margin:0 0 15px 0;">Import to Batch</h2>
            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:12px;">
                <select id="sdc-batch-select" style="background:#2a2a2a;border:1px solid #555;color:#fff;padding:8px 12px;border-radius:4px;font-size:14px;min-width:220px;">
                    <option value="">— Select Batch —</option>
                    <?php foreach ( $batches_array as $b ) : ?>
                    <option value="<?php echo esc_attr( $b ); ?>"><?php echo esc_html( $b ); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" id="sdc-import-btn" style="background:#2986cc;color:#fff;font-weight:700;border:none;border-radius:6px;padding:10px 24px;cursor:pointer;font-size:14px;">Import Selected to Batch</button>
                <span id="sdc-import-status" style="color:#aaa;font-size:14px;"></span>
            </div>
        </div>

        <script>
        (function(){
            var products = [];
            var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
            var nonce = '<?php echo esc_js( $nonce ); ?>';

            function escHtml(s){ var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

            // ─── CSV parser (handles quoted fields) ────────
            function parseCSV(text) {
                var lines = [];
                var current = [];
                var field = '';
                var inQuotes = false;

                for ( var i = 0; i < text.length; i++ ) {
                    var c = text[i];
                    if ( inQuotes ) {
                        if ( c === '"' ) {
                            if ( i + 1 < text.length && text[i + 1] === '"' ) {
                                field += '"';
                                i++;
                            } else {
                                inQuotes = false;
                            }
                        } else {
                            field += c;
                        }
                    } else {
                        if ( c === '"' ) {
                            inQuotes = true;
                        } else if ( c === ',' ) {
                            current.push(field);
                            field = '';
                        } else if ( c === '\n' || c === '\r' ) {
                            if ( c === '\r' && i + 1 < text.length && text[i + 1] === '\n' ) i++;
                            current.push(field);
                            field = '';
                            if ( current.length > 1 || current[0] !== '' ) lines.push(current);
                            current = [];
                        } else {
                            field += c;
                        }
                    }
                }
                current.push(field);
                if ( current.length > 1 || current[0] !== '' ) lines.push(current);

                // Skip header row
                if ( lines.length > 0 ) lines.shift();
                return lines;
            }

            // ─── Render table ──────────────────────────────
            function renderTable() {
                var tbody = document.getElementById('sdc-tbody');
                var search = document.getElementById('sdc-search').value.toLowerCase();
                var html = '';
                var shown = 0;

                for ( var i = 0; i < products.length; i++ ) {
                    var p = products[i];
                    if ( search && p.name.toLowerCase().indexOf(search) === -1 && p.sci.toLowerCase().indexOf(search) === -1 ) continue;
                    shown++;
                    html += '<tr style="border-bottom:1px solid #333;">';
                    html += '<td style="padding:8px;"><input type="checkbox" class="sdc-row-cb" data-idx="' + i + '" style="accent-color:#2986cc;"></td>';
                    html += '<td style="padding:8px;color:#fff;">' + escHtml(p.name) + '</td>';
                    html += '<td style="padding:8px;color:#aaa;font-style:italic;">' + escHtml(p.sci) + '</td>';
                    html += '<td style="padding:8px;text-align:center;color:#fff;">' + escHtml(p.size) + '</td>';
                    html += '<td style="padding:8px;text-align:right;color:#fff;">' + escHtml(p.price) + '</td>';
                    html += '<td style="padding:8px;text-align:center;color:#fff;">' + p.stock + '</td>';
                    html += '</tr>';
                }

                tbody.innerHTML = html;
                document.getElementById('sdc-count').textContent = shown + ' of ' + products.length + ' products';
            }

            document.getElementById('sdc-search').addEventListener('input', renderTable);

            document.getElementById('sdc-select-all').addEventListener('change', function(){
                var checked = this.checked;
                document.querySelectorAll('.sdc-row-cb').forEach(function(cb){ cb.checked = checked; });
            });

            // ─── Preview ───────────────────────────────────
            document.getElementById('sdc-preview-btn').addEventListener('click', function(){
                var fileInput = document.getElementById('sdc-file');
                if ( ! fileInput.files.length ) { alert('Select a CSV file first.'); return; }

                var status = document.getElementById('sdc-preview-status');
                status.textContent = 'Parsing...';

                var reader = new FileReader();
                reader.onload = function(e) {
                    var rows = parseCSV(e.target.result);
                    products = [];

                    for ( var i = 0; i < rows.length; i++ ) {
                        var row = rows[i];
                        var title = (row[0] || '').trim();
                        var sku   = (row[1] || '').trim();
                        var size  = (row[2] || '').trim();
                        var price = (row[3] || '').trim();
                        var stock = parseInt(row[4] || '0', 10);

                        if ( ! title || ! stock || stock <= 0 ) continue;
                        if ( /pack/i.test(title) ) continue;

                        // Split "Common Name - Scientific name"
                        var dash = title.indexOf(' - ');
                        var commonName = dash !== -1 ? title.substring(0, dash).trim() : title;
                        var sciName    = dash !== -1 ? title.substring(dash + 3).trim() : '';

                        products.push({ name: commonName, sci: sciName, size: size, price: price, stock: stock, sku: sku });
                    }

                    status.textContent = products.length + ' in-stock items loaded.';
                    document.getElementById('sdc-results').style.display = '';
                    document.getElementById('sdc-import-section').style.display = '';
                    renderTable();
                };
                reader.readAsText(fileInput.files[0]);
            });

            // ─── Import ────────────────────────────────────
            document.getElementById('sdc-import-btn').addEventListener('click', function(){
                var batch = document.getElementById('sdc-batch-select').value;
                if ( ! batch ) { alert('Select a batch first.'); return; }

                var checked = document.querySelectorAll('.sdc-row-cb:checked');
                if ( ! checked.length ) { alert('Select at least one fish to import.'); return; }

                var items = [];
                checked.forEach(function(cb){
                    var p = products[ parseInt(cb.dataset.idx) ];
                    items.push({ name: p.name, size: p.size, price: p.price, qty: p.stock, sku: p.sku });
                });

                if ( ! confirm('Import ' + items.length + ' items into "' + batch + '"?') ) return;

                var btn = document.getElementById('sdc-import-btn');
                var status = document.getElementById('sdc-import-status');
                btn.disabled = true;
                btn.textContent = 'Importing...';

                var fd = new FormData();
                fd.append('action', 'fishotel_sdc_import');
                fd.append('nonce', nonce);
                fd.append('batch_name', batch);
                items.forEach(function(item, i){
                    fd.append('items[' + i + '][name]', item.name);
                    fd.append('items[' + i + '][size]', item.size);
                    fd.append('items[' + i + '][price]', item.price);
                    fd.append('items[' + i + '][qty]', item.qty);
                    fd.append('items[' + i + '][sku]', item.sku);
                });

                fetch(ajaxUrl, { method:'POST', body: fd })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        btn.disabled = false;
                        btn.textContent = 'Import Selected to Batch';

                        if ( res.success ) {
                            var msg = 'Imported ' + res.data.imported + ' items';
                            if ( res.data.skipped ) {
                                msg += ', skipped ' + res.data.skipped + ' duplicates';
                                if ( res.data.skipped_names && res.data.skipped_names.length ) {
                                    msg += ' (' + res.data.skipped_names.join(', ') + ')';
                                }
                            }
                            status.textContent = msg;
                            status.style.color = '#27ae60';
                        } else {
                            status.textContent = 'Error: ' + (res.data || 'Unknown error');
                            status.style.color = '#c0392b';
                        }
                    })
                    .catch(function(e){
                        btn.disabled = false;
                        btn.textContent = 'Import Selected to Batch';
                        status.textContent = 'Network error: ' + e.message;
                        status.style.color = '#c0392b';
                    });
            });
        })();
        </script>
        </div>
        <?php
    }

    public function ajax_sdc_import() {
        check_ajax_referer( 'fishotel_sdc_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Access denied.' );

        $batch_name = isset( $_POST['batch_name'] ) ? sanitize_text_field( $_POST['batch_name'] ) : '';
        $items      = isset( $_POST['items'] ) ? $_POST['items'] : [];

        if ( empty( $batch_name ) ) wp_send_json_error( 'No batch selected.' );
        if ( empty( $items ) || ! is_array( $items ) ) wp_send_json_error( 'No items selected.' );

        // Get existing fish_batch posts for this batch to check duplicates
        $existing = get_posts( [
            'post_type'   => 'fish_batch',
            'numberposts' => -1,
            'meta_key'    => '_batch_name',
            'meta_value'  => $batch_name,
        ] );

        $existing_names = [];
        foreach ( $existing as $ep ) {
            $common = preg_replace( "/\s+[\x{2013}\x{2014}-]\s+.+$/u", '', $ep->post_title );
            $existing_names[] = strtolower( trim( $common ) );
        }

        $imported = 0;
        $skipped = 0;
        $skipped_names = [];

        foreach ( $items as $item ) {
            $common_name = sanitize_text_field( $item['name'] ?? '' );
            $size        = sanitize_text_field( $item['size'] ?? '' );
            $price       = sanitize_text_field( $item['price'] ?? '' );
            $qty         = intval( $item['qty'] ?? 0 );
            $sku         = sanitize_text_field( $item['sku'] ?? '' );

            if ( empty( $common_name ) ) continue;

            $display_name = trim( $common_name . ( $size ? ' ' . $size : '' ) );

            if ( in_array( strtolower( $display_name ), $existing_names, true ) ) {
                $skipped++;
                $skipped_names[] = $display_name;
                continue;
            }

            $master_id = $this->northstar_find_or_create_master( $display_name );

            $post_id = wp_insert_post( [
                'post_type'   => 'fish_batch',
                'post_title'  => $display_name . ' - ' . $batch_name,
                'post_status' => 'publish',
            ] );

            if ( $post_id && ! is_wp_error( $post_id ) ) {
                if ( $master_id && ! is_wp_error( $master_id ) ) {
                    update_post_meta( $post_id, '_master_id', $master_id );
                }
                update_post_meta( $post_id, '_batch_name', $batch_name );
                update_post_meta( $post_id, '_northstar_price', $price );
                update_post_meta( $post_id, '_stock', $qty );
                update_post_meta( $post_id, '_item_code', $sku );
                update_post_meta( $post_id, '_sdc_import', 1 );
                $imported++;
                $existing_names[] = strtolower( $display_name );
            }
        }

        wp_send_json_success( [
            'imported'      => $imported,
            'skipped'       => $skipped,
            'skipped_names' => $skipped_names,
        ] );
    }
}
