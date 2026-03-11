<?php
/**
 * FisHotel North Star Stock — fetch marine fish inventory from North Star Aquatics
 * and import directly into a batch.
 */
trait FisHotel_NorthStar {

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
            '4422867000000455166' => 'Tangs',
            '4422867000000455168' => 'Triggerfish',
            '4422867000000455170' => 'Wrasse',
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

            $response = wp_remote_get( $url, [ 'timeout' => 30 ] );

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

        // Find all product name links: <a> with href containing /products/
        $links = $xpath->query( "//a[contains(@href, '/products/')]" );

        foreach ( $links as $link ) {
            $text = trim( $link->textContent );
            $href = $link->getAttribute( 'href' );

            // Skip button-style links
            if ( in_array( strtolower( $text ), [ 'out of stock', 'add to cart', '' ], true ) ) continue;

            // Deduplicate by href
            if ( isset( $seen[ $href ] ) ) continue;
            $seen[ $href ] = true;

            // Find the parent product card — walk up to find a common container
            $card = $link;
            for ( $i = 0; $i < 10; $i++ ) {
                $card = $card->parentNode;
                if ( ! $card || $card->nodeName === 'body' ) break;
            }

            // Stock status: look for theme-button text within the card's context
            $stock = 'unknown';
            $buttons = $xpath->query( ".//*[contains(@class, 'theme-button')]", $card );
            foreach ( $buttons as $btn ) {
                $btn_text = strtolower( trim( $btn->textContent ) );
                if ( strpos( $btn_text, 'out of stock' ) !== false ) {
                    $stock = 'out_of_stock';
                    break;
                } elseif ( strpos( $btn_text, 'add to cart' ) !== false ) {
                    $stock = 'in_stock';
                    break;
                }
            }

            // Price: look for elements with class containing "price"
            $price = '';
            $price_nodes = $xpath->query( ".//*[contains(@class, 'price')]", $card );
            foreach ( $price_nodes as $pn ) {
                $pt = trim( $pn->textContent );
                if ( ! empty( $pt ) && strpos( $pt, '$' ) !== false ) {
                    $price = $pt;
                    break;
                }
            }

            $products[] = [
                'name'   => $text,
                'href'   => $href,
                'stock'  => $stock,
                'price'  => $price,
            ];
        }

        return $products;
    }

    // ─── AJAX: Fetch categories ─────────────────────────────────────────

    public function ajax_northstar_fetch() {
        check_ajax_referer( 'fishotel_northstar_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Access denied.' );

        $cat_ids = isset( $_POST['categories'] ) ? array_map( 'sanitize_text_field', (array) $_POST['categories'] ) : [];
        if ( empty( $cat_ids ) ) wp_send_json_error( 'No categories selected.' );

        $categories = $this->get_northstar_categories();
        $all_products = [];
        $seen = [];
        $errors = [];

        foreach ( $cat_ids as $cid ) {
            $label = $categories[ $cid ] ?? $cid;
            $fetched = $this->northstar_fetch_category( $cid );

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

        $fetched_at = current_time( 'mysql' );
        $cache = [ 'products' => $all_products, 'fetched_at' => $fetched_at ];
        set_transient( 'fishotel_northstar_cache', $cache, HOUR_IN_SECONDS );

        wp_send_json_success( [
            'products'   => $all_products,
            'fetched_at' => $fetched_at,
            'errors'     => $errors,
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

        $existing_names = [];
        foreach ( $existing as $ep ) {
            // Extract common name from "Common Name - Batch Name" title
            $parts = explode( ' - ', $ep->post_title, 2 );
            $existing_names[] = strtolower( trim( $parts[0] ) );
        }

        $imported = 0;
        $skipped = 0;
        $skipped_names = [];

        foreach ( $items as $item ) {
            $name  = sanitize_text_field( $item['name'] ?? '' );
            $price = sanitize_text_field( $item['price'] ?? '' );

            if ( empty( $name ) ) continue;

            if ( in_array( strtolower( $name ), $existing_names, true ) ) {
                $skipped++;
                $skipped_names[] = $name;
                continue;
            }

            $post_id = wp_insert_post( [
                'post_type'   => 'fish_batch',
                'post_title'  => $name . ' - ' . $batch_name,
                'post_status' => 'publish',
            ] );

            if ( $post_id && ! is_wp_error( $post_id ) ) {
                update_post_meta( $post_id, '_batch_name', $batch_name );
                update_post_meta( $post_id, '_northstar_price', $price );
                update_post_meta( $post_id, '_stock', 0 );
                $imported++;
                $existing_names[] = strtolower( $name );
            }
        }

        wp_send_json_success( [
            'imported'      => $imported,
            'skipped'       => $skipped,
            'skipped_names' => $skipped_names,
        ] );
    }

    // ─── Admin Page HTML ────────────────────────────────────────────────

    public function northstar_stock_html() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Access denied.' );

        $categories = $this->get_northstar_categories();
        $cache = get_transient( 'fishotel_northstar_cache' );
        $cached_products = $cache ? $cache['products'] : [];
        $fetched_at = $cache ? $cache['fetched_at'] : '';

        $batches_str = get_option( 'fishotel_batches', '' );
        $batches_array = array_filter( array_map( 'trim', explode( "\n", $batches_str ) ) );

        $nonce = wp_create_nonce( 'fishotel_northstar_nonce' );
        ?>
        <div class="wrap fishotel-admin">
        <h1 style="color:#b5a165;font-size:26px;font-weight:700;margin-bottom:20px;">North Star Stock</h1>

        <!-- Category Selector -->
        <div style="background:#1e1e1e;border:1px solid #444;border-radius:8px;padding:25px;margin-bottom:20px;">
            <h2 style="color:#fff;font-size:18px;margin:0 0 15px 0;">Select Categories to Fetch</h2>
            <div style="margin-bottom:12px;">
                <button type="button" id="ns-select-all" style="background:#2a2a2a;color:#b5a165;border:1px solid #555;border-radius:4px;padding:6px 14px;cursor:pointer;font-size:13px;margin-right:8px;">Select All</button>
                <button type="button" id="ns-deselect-all" style="background:#2a2a2a;color:#b5a165;border:1px solid #555;border-radius:4px;padding:6px 14px;cursor:pointer;font-size:13px;">Deselect All</button>
            </div>
            <div id="ns-categories" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px 16px;">
                <?php foreach ( $categories as $id => $label ) :
                    $is_all = ( $id === '4422867000000439035' );
                ?>
                <label style="display:flex;align-items:center;gap:8px;color:<?php echo $is_all ? '#e67e22' : '#ddd'; ?>;font-size:14px;cursor:pointer;<?php echo $is_all ? 'font-weight:700;grid-column:1/-1;border-bottom:1px solid #444;padding-bottom:10px;margin-bottom:4px;' : ''; ?>">
                    <input type="checkbox" class="ns-cat-cb" value="<?php echo esc_attr( $id ); ?>" data-label="<?php echo esc_attr( $label ); ?>" style="accent-color:#e67e22;">
                    <?php echo esc_html( $label ); ?>
                </label>
                <?php endforeach; ?>
            </div>
            <div style="margin-top:16px;">
                <button type="button" id="ns-fetch-btn" style="background:#e67e22;color:#000;font-weight:700;border:none;border-radius:6px;padding:12px 28px;cursor:pointer;font-size:15px;">Fetch Selected Categories</button>
                <span id="ns-fetch-status" style="margin-left:14px;color:#aaa;font-size:14px;"></span>
            </div>
        </div>

        <!-- Results Section -->
        <div id="ns-results" style="background:#1e1e1e;border:1px solid #444;border-radius:8px;padding:25px;margin-bottom:20px;<?php echo empty( $cached_products ) ? 'display:none;' : ''; ?>">
            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin-bottom:16px;">
                <h2 style="color:#fff;font-size:18px;margin:0;">Results</h2>
                <span id="ns-fetched-at" style="color:#888;font-size:13px;"><?php echo $fetched_at ? 'Last fetched: ' . esc_html( $fetched_at ) : ''; ?></span>
                <button type="button" id="ns-refresh-btn" style="background:#2a2a2a;color:#b5a165;border:1px solid #555;border-radius:4px;padding:5px 12px;cursor:pointer;font-size:13px;margin-left:auto;">Refresh</button>
            </div>
            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin-bottom:14px;">
                <input type="text" id="ns-search" placeholder="Search by name..." style="background:#2a2a2a;border:1px solid #555;color:#fff;padding:8px 12px;border-radius:4px;width:260px;font-size:14px;">
                <label style="color:#ddd;font-size:14px;cursor:pointer;display:flex;align-items:center;gap:6px;">
                    <input type="checkbox" id="ns-instock-filter" checked style="accent-color:#e67e22;"> In Stock only
                </label>
                <span id="ns-count" style="color:#888;font-size:13px;margin-left:auto;"></span>
            </div>
            <div style="overflow-x:auto;">
                <table id="ns-table" style="width:100%;border-collapse:collapse;font-size:14px;">
                    <thead>
                        <tr style="border-bottom:2px solid #555;">
                            <th style="padding:10px 8px;text-align:left;color:#b5a165;width:40px;"><input type="checkbox" id="ns-select-all-rows" style="accent-color:#e67e22;"></th>
                            <th style="padding:10px 8px;text-align:left;color:#b5a165;">Common Name</th>
                            <th style="padding:10px 8px;text-align:left;color:#b5a165;">Category</th>
                            <th style="padding:10px 8px;text-align:center;color:#b5a165;">Stock</th>
                            <th style="padding:10px 8px;text-align:right;color:#b5a165;">Price</th>
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
                <button type="button" id="ns-import-btn" style="background:#e67e22;color:#000;font-weight:700;border:none;border-radius:6px;padding:10px 24px;cursor:pointer;font-size:14px;">Import Selected to Batch</button>
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

            // ─── Render table ───────────────────────────────
            function renderTable(){
                var tbody = document.getElementById('ns-tbody');
                var search = document.getElementById('ns-search').value.toLowerCase();
                var inStockOnly = document.getElementById('ns-instock-filter').checked;
                var html = '';
                var shown = 0;

                for ( var i = 0; i < products.length; i++ ) {
                    var p = products[i];
                    if ( inStockOnly && p.stock !== 'in_stock' ) continue;
                    if ( search && p.name.toLowerCase().indexOf( search ) === -1 ) continue;
                    shown++;

                    var stockBadge = p.stock === 'in_stock'
                        ? '<span style="background:#27ae60;color:#fff;padding:2px 10px;border-radius:10px;font-size:12px;font-weight:600;">In Stock</span>'
                        : '<span style="background:#c0392b;color:#fff;padding:2px 10px;border-radius:10px;font-size:12px;font-weight:600;">Out of Stock</span>';

                    html += '<tr style="border-bottom:1px solid #333;" data-idx="' + i + '">';
                    html += '<td style="padding:8px;"><input type="checkbox" class="ns-row-cb" data-idx="' + i + '" style="accent-color:#e67e22;"></td>';
                    html += '<td style="padding:8px;color:#fff;">' + escHtml(p.name) + '</td>';
                    html += '<td style="padding:8px;color:#aaa;">' + escHtml(p.category || '') + '</td>';
                    html += '<td style="padding:8px;text-align:center;">' + stockBadge + '</td>';
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
                status.textContent = 'Fetching: ' + labels.join(', ') + '...';

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

                var items = [];
                checked.forEach(function(cb){
                    var p = products[ parseInt(cb.dataset.idx) ];
                    items.push({ name: p.name, price: p.price });
                });

                if ( ! confirm('Import ' + items.length + ' items into "' + batch + '"?') ) return;

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
}
