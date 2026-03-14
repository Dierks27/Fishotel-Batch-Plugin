<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait FisHotel_HotelProgram {

    /* ─────────────────────────────────────────────
     *  BOOTSTRAP
     * ───────────────────────────────────────────── */

    public function hotel_program_init() {
        add_action( 'admin_init', [ $this, 'hotel_migrate_tank_ids_v1' ] );
        add_shortcode( 'fishotel_hotel_postcard', [ $this, 'hotel_postcard_shortcode' ] );
        add_action( 'update_option_fishotel_batch_statuses', [ $this, 'hotel_maybe_init_schedule' ], 10, 2 );
        add_action( 'admin_post_fishotel_hotel_save_category',   [ $this, 'hotel_save_category' ] );
        add_action( 'admin_post_fishotel_hotel_delete_category', [ $this, 'hotel_delete_category' ] );
        add_action( 'admin_post_fishotel_hotel_save_activity',   [ $this, 'hotel_save_activity' ] );
        add_action( 'admin_post_fishotel_hotel_delete_activity', [ $this, 'hotel_delete_activity' ] );
        add_action( 'admin_post_fishotel_hotel_save_schedule',   [ $this, 'hotel_save_schedule' ] );
        add_action( 'admin_post_fishotel_hotel_init_schedule',   [ $this, 'hotel_init_schedule_handler' ] );
        add_action( 'admin_post_fishotel_hotel_save_graduation', [ $this, 'hotel_save_graduation_handler' ] );
        $this->hotel_seed_defaults();
    }

    /* ─────────────────────────────────────────────
     *  TANK ID MIGRATION v1
     *  Old: Floor 1 = 101-104 (20g), Floor 2 = 201-203 (40g)
     *  New: Floor 1 = 201-204 (20g), Floor 2 = 101-103 (40g)
     * ───────────────────────────────────────────── */

    public function hotel_migrate_tank_ids_v1() {
        if ( get_option( 'fishotel_tank_migration_v1' ) ) return;

        $map = [
            '101' => '201',
            '102' => '202',
            '103' => '203',
            '104' => '204',
        ];

        foreach ( $map as $old_tank => $new_tank ) {
            $posts = get_posts( [
                'post_type'      => 'fish_batch',
                'posts_per_page' => -1,
                'meta_key'       => '_arrival_tank',
                'meta_value'     => $old_tank,
                'fields'         => 'ids',
            ] );
            foreach ( $posts as $pid ) {
                update_post_meta( $pid, '_arrival_tank', $new_tank );
            }
        }

        update_option( 'fishotel_tank_migration_v1', true );
    }

    /* ─────────────────────────────────────────────
     *  SEED DEFAULTS
     * ───────────────────────────────────────────── */

    private function hotel_seed_defaults() {
        $cats = get_option( 'fishotel_hotel_categories', [] );
        if ( ! empty( $cats ) ) return;

        $default_cats = [
            [ 'id' => 'cat_arrival',    'name' => 'ARRIVAL',    'color' => '#1a3a5c', 'exclude_random' => true,  'label' => 'ARRIVAL' ],
            [ 'id' => 'cat_graduation', 'name' => 'GRADUATION', 'color' => '#96885f', 'exclude_random' => true,  'label' => 'GRAD' ],
            [ 'id' => 'cat_medication', 'name' => 'MEDICATION', 'color' => '#8b0000', 'exclude_random' => true,  'label' => 'MED' ],
            [ 'id' => 'cat_pool',       'name' => 'POOL & SPA', 'color' => '#2a7fba', 'exclude_random' => false, 'label' => 'SPA' ],
            [ 'id' => 'cat_dining',     'name' => 'DINING',     'color' => '#4a7c3f', 'exclude_random' => false, 'label' => 'DINING' ],
            [ 'id' => 'cat_rest',       'name' => 'REST',       'color' => '#6b5a3e', 'exclude_random' => false, 'label' => 'REST' ],
            [ 'id' => 'cat_social',     'name' => 'SOCIAL',     'color' => '#b5651d', 'exclude_random' => false, 'label' => 'SOCIAL' ],
        ];
        update_option( 'fishotel_hotel_categories', $default_cats );

        $default_acts = [
            [
                'id'               => 'act_welcome',
                'name'             => 'Welcome Reception',
                'category_id'      => 'cat_arrival',
                'time_of_day'      => 'morning',
                'scene_type'       => 'lobby',
                'scene_number'     => '01',
                'postcard_message' => 'Your guests have arrived and are being personally escorted to their accommodations. Welcome to The FisHotel.',
                'postmark_city'    => 'CHAMPLIN, MN',
                'description'      => 'First day welcome reception at the lobby.',
            ],
            [
                'id'               => 'act_checkout',
                'name'             => 'Checkout Day',
                'category_id'      => 'cat_graduation',
                'time_of_day'      => 'morning',
                'scene_type'       => 'graduation',
                'scene_number'     => '01',
                'postcard_message' => 'After an exceptional stay, your fish have been cleared for departure. It has been our honor to host them.',
                'postmark_city'    => 'CHAMPLIN, MN',
                'description'      => 'Graduation day checkout.',
            ],
            [
                'id'               => 'act_pool_afternoon',
                'name'             => 'Afternoon Poolside Lounging',
                'category_id'      => 'cat_pool',
                'time_of_day'      => 'afternoon',
                'scene_type'       => 'pool',
                'scene_number'     => '04',
                'postcard_message' => 'Your fish are currently enjoying the thermal pools. Do not disturb.',
                'postmark_city'    => 'CHAMPLIN, MN',
                'description'      => 'Afternoon poolside relaxation with ocean breezes.',
            ],
            [
                'id'               => 'act_pool_evening',
                'name'             => 'Evening at the Pool',
                'category_id'      => 'cat_pool',
                'time_of_day'      => 'evening',
                'scene_type'       => 'pool',
                'scene_number'     => '01',
                'postcard_message' => 'As the sun sets over the FisHotel grounds, your guests are taking a final evening swim before dinner.',
                'postmark_city'    => 'CHAMPLIN, MN',
                'description'      => 'Evening swim session.',
            ],
        ];
        update_option( 'fishotel_hotel_activities', $default_acts );
    }

    /* ─────────────────────────────────────────────
     *  HELPERS — categories & activities
     * ───────────────────────────────────────────── */

    private function hotel_get_categories() {
        return get_option( 'fishotel_hotel_categories', [] );
    }

    private function hotel_get_activities() {
        return get_option( 'fishotel_hotel_activities', [] );
    }

    private function hotel_get_category_by_id( $id ) {
        foreach ( $this->hotel_get_categories() as $c ) {
            if ( $c['id'] === $id ) return $c;
        }
        return null;
    }

    private function hotel_get_activity_by_id( $id ) {
        foreach ( $this->hotel_get_activities() as $a ) {
            if ( $a['id'] === $id ) return $a;
        }
        return null;
    }

    private function hotel_schedule_option_key( $batch_name ) {
        return 'fishotel_hotel_schedule_' . sanitize_key( $batch_name );
    }

    private function hotel_get_schedule( $batch_name ) {
        return get_option( $this->hotel_schedule_option_key( $batch_name ), [] );
    }

    /* ─────────────────────────────────────────────
     *  SCENE IMAGE RESOLUTION
     * ───────────────────────────────────────────── */

    private function hotel_scene_url( $scene_type, $scene_number ) {
        $base     = sanitize_key( $scene_type );
        $dir      = plugin_dir_path( FISHOTEL_PLUGIN_FILE ) . 'assists/scene/';
        $url_base = 'assists/scene/';
        $bands    = [ 'morning', 'afternoon', 'sunset', 'night' ];
        foreach ( $bands as $band ) {
            $fn = 'hotel-' . $base . '-scene-' . $scene_number . '-' . $band . '.jpg';
            if ( file_exists( $dir . $fn ) ) {
                return [
                    'url'  => plugins_url( $url_base . $fn, FISHOTEL_PLUGIN_FILE ),
                    'band' => $band,
                ];
            }
        }
        $fn = 'hotel-' . $base . '-scene-' . $scene_number . '.jpg';
        if ( file_exists( $dir . $fn ) ) {
            return [
                'url'  => plugins_url( $url_base . $fn, FISHOTEL_PLUGIN_FILE ),
                'band' => null,
            ];
        }
        return false;
    }

    private function hotel_scene_urls_by_band( $scene_type, $scene_number ) {
        $base     = sanitize_key( $scene_type );
        $dir      = plugin_dir_path( FISHOTEL_PLUGIN_FILE ) . 'assists/scene/';
        $url_base = 'assists/scene/';
        $bands    = [ 'morning', 'afternoon', 'sunset', 'night' ];
        $map      = [];
        foreach ( $bands as $band ) {
            $fn = 'hotel-' . $base . '-scene-' . $scene_number . '-' . $band . '.jpg';
            if ( file_exists( $dir . $fn ) ) {
                $map[ $band ] = plugins_url( $url_base . $fn, FISHOTEL_PLUGIN_FILE );
            }
        }
        $fn_fallback = 'hotel-' . $base . '-scene-' . $scene_number . '.jpg';
        if ( file_exists( $dir . $fn_fallback ) ) {
            $map['_fallback'] = plugins_url( $url_base . $fn_fallback, FISHOTEL_PLUGIN_FILE );
        }
        return $map;
    }

    private function hotel_seed_layer_defaults() {
        $existing = get_option( 'fishotel_layer_configs' );
        if ( $existing === false ) {
            $defaults = [
                'pool-scene-01' => [
                    [ 'id' => 'pool_light_shaft', 'asset' => 'light-shaft.png', 'label' => 'Light Shaft', 'x' => '45', 'y' => '0', 'width' => '25', 'blend' => 'screen', 'opacity' => '0.4', 'animation' => 'shimmer', 'speed' => '20', 'pause' => '0', 'z' => '5', 'show_on' => [ 'afternoon', 'sunset' ] ],
                    [ 'id' => 'pool_bubble_stream', 'asset' => 'bubble-stream.png', 'label' => 'Bubble Stream', 'x' => '20', 'y' => '55', 'width' => '8', 'blend' => 'screen', 'opacity' => '0.5', 'animation' => 'drift-up', 'speed' => '8', 'pause' => '12', 'z' => '10', 'show_on' => [ 'all' ] ],
                    [ 'id' => 'pool_glow', 'asset' => 'pool-glow.png', 'label' => 'Pool Glow', 'x' => '0', 'y' => '60', 'width' => '100', 'blend' => 'overlay', 'opacity' => '0.3', 'animation' => 'pulse', 'speed' => '4', 'pause' => '0', 'z' => '3', 'show_on' => [ 'sunset', 'night' ] ],
                ],
                'lobby-scene-01' => [
                    [ 'id' => 'lobby_dust_motes', 'asset' => 'dust-motes.png', 'label' => 'Dust Motes', 'x' => '30', 'y' => '5', 'width' => '40', 'blend' => 'screen', 'opacity' => '0.3', 'animation' => 'drift-left-right', 'speed' => '25', 'pause' => '0', 'z' => '5', 'show_on' => [ 'morning', 'afternoon' ] ],
                    [ 'id' => 'lobby_light_shaft', 'asset' => 'light-shaft.png', 'label' => 'Light Shaft', 'x' => '55', 'y' => '0', 'width' => '20', 'blend' => 'screen', 'opacity' => '0.35', 'animation' => 'shimmer', 'speed' => '18', 'pause' => '0', 'z' => '4', 'show_on' => [ 'morning', 'afternoon', 'sunset' ] ],
                ],
            ];
            update_option( 'fishotel_layer_configs', $defaults );
            return;
        }
        // Migration: move old scene-type keys (e.g. "pool") to scene-slug keys (e.g. "pool-scene-01")
        $migrated = false;
        foreach ( $existing as $key => $layers ) {
            if ( ! empty( $layers ) && strpos( $key, '-scene-' ) === false ) {
                $new_key = $key . '-scene-01';
                if ( ! isset( $existing[ $new_key ] ) ) {
                    $existing[ $new_key ] = $layers;
                }
                unset( $existing[ $key ] );
                $migrated = true;
            }
        }
        if ( $migrated ) {
            update_option( 'fishotel_layer_configs', $existing );
        }
    }

    private function hotel_get_layer_config( $scene_slug ) {
        $this->hotel_seed_layer_defaults();
        $all_configs = get_option( 'fishotel_layer_configs', [] );
        $layers      = $all_configs[ $scene_slug ] ?? [];
        $base_dir    = plugin_dir_path( FISHOTEL_PLUGIN_FILE ) . 'assists/scene-layers/';
        $base_url    = plugins_url( 'assists/scene-layers/', FISHOTEL_PLUGIN_FILE );
        $valid       = [];
        foreach ( $layers as $layer ) {
            if ( ! empty( $layer['asset'] ) && file_exists( $base_dir . $layer['asset'] ) ) {
                $layer['url']   = $base_url . $layer['asset'];
                $layer['x']     = ( $layer['x'] ?? '0' ) . '%';
                $layer['y']     = ( $layer['y'] ?? '0' ) . '%';
                $layer['width'] = ( $layer['width'] ?? '100' ) . '%';
                $layer['opacity'] = floatval( $layer['opacity'] ?? 1 );
                $layer['speed']   = intval( $layer['speed'] ?? 10 );
                $layer['pause']   = intval( $layer['pause'] ?? 0 );
                $layer['z']       = intval( $layer['z'] ?? 1 );
                $valid[]          = $layer;
            }
        }
        return $valid;
    }

    /* ─────────────────────────────────────────────
     *  ACTIVITY RESOLUTION
     * ───────────────────────────────────────────── */

    public function hotel_get_resolved_activity( $batch_name, $day_number ) {
        $schedule = $this->hotel_get_schedule( $batch_name );
        $days     = $schedule['days'] ?? [];
        $slot     = $days[ $day_number ] ?? [ 'assignment_type' => 'random' ];
        $type     = $slot['assignment_type'] ?? 'random';

        // Built-in first day
        if ( $type === 'first_day' ) {
            return [
                'name'             => 'Welcome Reception',
                'category_id'      => 'cat_arrival',
                'time_of_day'      => 'morning',
                'scene_type'       => 'lobby',
                'scene_number'     => '01',
                'postcard_message' => 'Your guests have arrived and are being personally escorted to their accommodations. Welcome to The FisHotel.',
                'postmark_city'    => 'CHAMPLIN, MN',
                'description'      => 'Check-in day.',
            ];
        }

        // Built-in graduation
        if ( $type === 'graduation' ) {
            return [
                'name'             => 'Checkout Day',
                'category_id'      => 'cat_graduation',
                'time_of_day'      => 'morning',
                'scene_type'       => 'graduation',
                'scene_number'     => '01',
                'postcard_message' => 'After an exceptional stay, your fish have been cleared for departure. It has been our honor to host them.',
                'postmark_city'    => 'CHAMPLIN, MN',
                'description'      => 'Graduation day.',
            ];
        }

        // Specific activity
        if ( $type === 'activity' && ! empty( $slot['activity_id'] ) ) {
            $act = $this->hotel_get_activity_by_id( $slot['activity_id'] );
            if ( $act ) return $act;
        }

        // Category-seeded random
        if ( $type === 'category' && ! empty( $slot['category_id'] ) ) {
            $acts = array_values( array_filter( $this->hotel_get_activities(), function( $a ) use ( $slot ) {
                return $a['category_id'] === $slot['category_id'];
            } ) );
            if ( ! empty( $acts ) ) {
                $seed = crc32( $batch_name . $day_number );
                return $acts[ abs( $seed ) % count( $acts ) ];
            }
        }

        // Random from all non-excluded categories
        $cats        = $this->hotel_get_categories();
        $excluded    = [];
        foreach ( $cats as $c ) {
            if ( ! empty( $c['exclude_random'] ) ) $excluded[] = $c['id'];
        }
        $pool = array_values( array_filter( $this->hotel_get_activities(), function( $a ) use ( $excluded ) {
            return ! in_array( $a['category_id'], $excluded, true );
        } ) );
        if ( ! empty( $pool ) ) {
            $seed = crc32( $batch_name . $day_number );
            return $pool[ abs( $seed ) % count( $pool ) ];
        }

        // Graceful fallback — no activities exist yet
        return [
            'name'             => 'Settling In',
            'category_id'      => '',
            'time_of_day'      => 'morning',
            'scene_type'       => 'lobby',
            'scene_number'     => '01',
            'postcard_message' => 'Your guests are settling into their rooms at The FisHotel. More activities coming soon!',
            'postmark_city'    => 'CHAMPLIN, MN',
            'description'      => 'Default placeholder.',
        ];
    }

    /* ─────────────────────────────────────────────
     *  STAGE TRANSITION HOOK
     * ───────────────────────────────────────────── */

    public function hotel_maybe_init_schedule( $old_value, $new_value ) {
        if ( ! is_array( $new_value ) ) return;
        $old_value = is_array( $old_value ) ? $old_value : [];

        foreach ( $new_value as $batch_name => $stage ) {
            $prev = $old_value[ $batch_name ] ?? '';
            if ( $stage === 'in_quarantine' && $prev !== 'in_quarantine' ) {
                $existing = $this->hotel_get_schedule( $batch_name );
                if ( empty( $existing ) ) {
                    $this->hotel_create_default_schedule( $batch_name );
                }
            }
        }
    }

    private function hotel_create_default_schedule( $batch_name ) {
        $days = [];
        for ( $d = 1; $d <= 21; $d++ ) {
            $days[ $d ] = [
                'assignment_type' => ( $d === 1 ) ? 'first_day' : 'random',
                'category_id'     => null,
                'activity_id'     => null,
            ];
        }
        $schedule = [
            'batch_name'      => $batch_name,
            'stage4_start'    => current_time( 'Y-m-d' ),
            'graduation_date' => '',
            'days'            => $days,
        ];
        update_option( $this->hotel_schedule_option_key( $batch_name ), $schedule );
    }

    /* ─────────────────────────────────────────────
     *  SHORTCODE — [fishotel_hotel_postcard]
     * ───────────────────────────────────────────── */

    public function hotel_postcard_shortcode( $batch_name = null ) {
        if ( is_admin() ) return '';

        if ( ! $batch_name ) {
            $current_slug = get_post_field( 'post_name', get_the_ID() );
            $assignments  = get_option( 'fishotel_batch_page_assignments', [] );
            $batch_name   = array_search( $current_slug, $assignments );
        }

        if ( ! $batch_name ) return '';
        $statuses = get_option( 'fishotel_batch_statuses', [] );
        $status   = $statuses[ $batch_name ] ?? '';
        if ( $status !== 'in_quarantine' ) return '';

        $schedule = $this->hotel_get_schedule( $batch_name );
        if ( empty( $schedule ) ) return '';

        $start_ts   = strtotime( $schedule['stage4_start'] ?? 'today' );
        $now_ts     = strtotime( current_time( 'Y-m-d' ) );
        $day_number = max( 1, min( 21, (int) floor( ( $now_ts - $start_ts ) / 86400 ) + 1 ) );

        $activity   = $this->hotel_get_resolved_activity( $batch_name, $day_number );
        $scene_type = $activity['scene_type'] ?? 'lobby';
        $scene_num  = $activity['scene_number'] ?? '01';
        $scene_data = $this->hotel_scene_url( $scene_type, $scene_num );
        $scene_url  = $scene_data ? $scene_data['url'] : false;
        $scene_urls_by_band = $this->hotel_scene_urls_by_band( $scene_type, $scene_num );
        $scene_slug   = $scene_type . '-scene-' . $scene_num;
        $layer_config = $this->hotel_get_layer_config( $scene_slug );

        $postcard_message = esc_html( $activity['postcard_message'] ?? '' );
        $activity_name    = esc_html( $activity['name'] ?? '' );
        $postmark_city    = esc_html( $activity['postmark_city'] ?? 'CHAMPLIN, MN' );
        $postmark_date    = strtoupper( date_i18n( 'M j, Y' ) );

        // Building data — all rooms keyed by tank number
        $all_room_ids = [ '201', '202', '203', '204', '101', '102', '103', '301', '302' ];
        $room_map     = array_fill_keys( $all_room_ids, [] ); // empty array = unassigned

        $batch_fish = get_posts( [
            'post_type'   => 'fish_batch',
            'numberposts' => -1,
            'post_status' => 'any',
            'meta_key'    => '_batch_name',
            'meta_value'  => $batch_name,
        ] );
        foreach ( $batch_fish as $bf ) {
            $tank = (string) get_post_meta( $bf->ID, '_arrival_tank', true );
            if ( $tank === '' || ! array_key_exists( $tank, $room_map ) ) continue;
            $arr_status = get_post_meta( $bf->ID, '_arrival_status', true );
            $cq         = get_post_meta( $bf->ID, '_current_qty', true );
            $qty_recv   = ( $cq !== '' && $cq !== false ) ? intval( $cq ) : intval( get_post_meta( $bf->ID, '_arrival_qty_received', true ) );
            $qty_doa    = intval( get_post_meta( $bf->ID, '_arrival_qty_doa', true ) );
            $master_id  = get_post_meta( $bf->ID, '_master_id', true );
            $common     = $master_id ? get_the_title( $master_id ) : $bf->post_title;
            $sci_name   = $master_id ? get_post_meta( $master_id, '_scientific_name', true ) : '';
            $room_map[ $tank ][] = [
                'fish_id'    => $bf->ID,
                'species'    => $common,
                'sci_name'   => $sci_name,
                'qty'        => $qty_recv,
                'qty_doa'    => $qty_doa,
                'status'     => $arr_status,
                'master_id'  => $master_id,
            ];
        }

        // Determine which rooms belong to logged-in customer
        $logged_in      = is_user_logged_in();
        $customer_rooms = [];
        $my_batch_ids   = [];
        if ( $logged_in ) {
            $uid      = get_current_user_id();
            $my_reqs  = get_posts( [
                'post_type'   => 'fish_request',
                'numberposts' => -1,
                'post_status' => 'any',
                'meta_query'  => [
                    'relation' => 'AND',
                    [ 'key' => '_customer_id', 'value' => $uid,        'compare' => '=' ],
                    [ 'key' => '_batch_name',  'value' => $batch_name, 'compare' => '=' ],
                ],
            ] );
            $my_batch_ids = [];
            foreach ( $my_reqs as $req ) {
                $items = json_decode( get_post_meta( $req->ID, '_cart_items', true ), true ) ?: [];
                foreach ( $items as $item ) {
                    $bid = intval( $item['batch_id'] ?? 0 );
                    if ( $bid ) $my_batch_ids[ $bid ] = true;
                }
            }
            foreach ( $room_map as $tank => $fish_list ) {
                foreach ( $fish_list as $data ) {
                    if ( isset( $my_batch_ids[ $data['fish_id'] ] ) ) {
                        $customer_rooms[ $tank ] = true;
                        break;
                    }
                }
            }
        }

        $hotel_img_url = plugins_url( 'assists/hotel/', FISHOTEL_PLUGIN_FILE );
        ob_start();
        ?>
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;600;700&family=Klee+One&family=Special+Elite&family=Righteous&display=swap" rel="stylesheet">
<style>
/* ── FisHotel Postcard ─────────────────────────────────── */
.fh-hotel-postcard-wrap{max-width:900px;width:100%;margin:0 auto;font-family:'Oswald',sans-serif;-webkit-font-smoothing:antialiased}
.fh-hotel-postcard-wrap *{box-sizing:border-box}

/* Card container */
.fh-hotel-card{width:100%;aspect-ratio:3/2;perspective:1200px;margin:0 auto;cursor:pointer}
.fh-hotel-card-inner{position:relative;width:100%;height:100%;transition:transform 0.6s ease;transform-style:preserve-3d}
.fh-hotel-card[data-flipped="true"] .fh-hotel-card-inner{transform:rotateY(180deg)}
.fh-hotel-postcard-front,.fh-hotel-postcard-back{position:absolute;top:0;left:0;width:100%;height:100%;-webkit-backface-visibility:hidden;backface-visibility:hidden;border-radius:6px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.4)}

/* FRONT */
.fh-hotel-postcard-front{background:#f5f0e8 !important}
.fh-hotel-postcard-scene{width:100%;height:75%;background-size:cover !important;background-position:center !important;background-color:#2e2418 !important;position:relative;transition:background-image 2s ease}
.fh-hotel-postcard-scene-placeholder{width:100%;height:75%;display:flex;align-items:center;justify-content:center;background:#f5f0e8 !important;color:#96885f !important;font-family:'Oswald',sans-serif;font-size:18px;letter-spacing:0.05em;border-bottom:1px solid #d6cfc2;position:relative}
.fh-hotel-postcard-day-badge{position:absolute;top:12px;left:12px;background:#1a3a5c !important;color:#fff !important;font-family:'Oswald',sans-serif;font-size:13px;font-weight:700;padding:4px 12px;letter-spacing:0.15em;border-radius:2px}
/* LAYER SYSTEM */
.fh-hotel-postcard-layer-wrap{position:absolute !important;inset:0 !important;overflow:hidden !important;pointer-events:none !important;z-index:2 !important}
.fh-postcard-layer{position:absolute !important;display:block !important;pointer-events:none !important}
@keyframes fh-drift-left-right{0%,100%{transform:translateX(-8px)}50%{transform:translateX(8px)}}
@keyframes fh-sway{0%,100%{transform:rotate(-3deg)}50%{transform:rotate(3deg)}}
@keyframes fh-shimmer{0%,100%{opacity:0.2}50%{opacity:0.8}}
@keyframes fh-pulse{0%,100%{transform:scale(1)}50%{transform:scale(1.06)}}
@keyframes fh-float{0%,100%{transform:translateY(-6px)}50%{transform:translateY(6px)}}
@keyframes fh-drift-up{0%{transform:translateY(0);opacity:1}100%{transform:translateY(-40px);opacity:0}}
@media(prefers-reduced-motion:reduce){.fh-postcard-layer{animation:none !important}}

.fh-hotel-postcard-front-strip{height:25%;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:8px 16px;background:#f5f0e8 !important;position:relative !important;z-index:3 !important}
.fh-hotel-postcard-hotel-name{font-family:'Oswald',sans-serif;font-size:16px;font-weight:700;color:#96885f !important;letter-spacing:0.2em;text-transform:uppercase}
.fh-hotel-postcard-activity-name{font-family:'Oswald',sans-serif;font-size:13px;color:#2e2418 !important;margin-top:4px;letter-spacing:0.05em}
.fh-hotel-flip-btn{background:#1a3a5c !important;color:#fff !important;font-family:'Oswald',sans-serif;font-size:12px;letter-spacing:0.15em;padding:6px 16px;border:none;border-radius:20px;cursor:pointer;margin-top:8px}
.fh-hotel-flip-btn:hover{background:#2a5a8c !important}

/* BACK */
.fh-hotel-postcard-back{transform:rotateY(180deg);background:#fdf8f0 !important;display:flex;flex-direction:row;position:relative;overflow:hidden;
    box-shadow:inset 0 0 40px rgba(139,90,43,0.08),inset 0 0 80px rgba(139,90,43,0.04)}
.fh-hotel-postcard-back::before{content:'';position:absolute;inset:0;opacity:0.05;pointer-events:none;z-index:1;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='1'/%3E%3C/svg%3E")}
.fh-hotel-postcard-back-left{flex:2;padding:28px 24px;display:flex;flex-direction:column;border-right:3px double #8b6914;position:relative;z-index:2}
.fh-hotel-postcard-back-correspondence{font-family:'Courier New',monospace;font-size:12px;color:#8b6914 !important;letter-spacing:3px;text-transform:uppercase;margin-bottom:14px}
.fh-hotel-postcard-back-message{font-family:'Special Elite',cursive;font-size:16px;color:#2e2418 !important;line-height:1.8;flex:1}
.fh-hotel-postcard-back-signature{font-family:'Klee One',cursive;font-size:15px;color:#8b6914 !important;margin-top:16px}
.fh-hotel-postcard-back-right{width:240px;padding:20px;display:flex;flex-direction:column;position:relative;z-index:2}
.fh-hotel-postcard-stamp-area{display:flex;justify-content:flex-end}
.fh-hotel-postcard-stamp{width:100px;height:120px;border:2px dashed #8b6914;display:flex;align-items:center;justify-content:center;font-size:28px;background:#faf7f0 !important;flex-direction:column;gap:2px}
.fh-hotel-postcard-stamp-text{font-family:'Courier New',monospace;font-size:10px;color:#8b6914 !important;letter-spacing:1px;text-transform:uppercase;text-align:center;line-height:1.2}
.fh-hotel-postcard-postmark{margin-top:12px;width:90px;height:90px;border:2px solid #8b0000;border-radius:50%;display:flex;flex-direction:column;align-items:center;justify-content:center;transform:rotate(-12deg);opacity:0.8}
.fh-hotel-postcard-postmark-city{font-family:'Courier New',monospace;font-size:10px;font-weight:700;color:#8b0000 !important;letter-spacing:0.08em;text-align:center}
.fh-hotel-postcard-postmark-date{font-family:'Courier New',monospace;font-size:9px;color:#8b0000 !important;margin-top:2px}
.fh-hotel-postcard-address-lines{margin-top:auto}
.fh-hotel-postcard-address-label{font-family:'Courier New',monospace;font-size:12px;color:#8b6914 !important;margin-bottom:8px;letter-spacing:2px;text-transform:uppercase}
.fh-hotel-postcard-address-line{height:1px;background:#8b6914 !important;margin:10px 0;opacity:0.4}
.fh-hotel-postcard-vertical-text{position:absolute;right:6px;top:50%;transform:rotate(90deg) translateX(-50%);transform-origin:center;font-family:'Courier New',monospace;font-size:11px;color:#8b6914 !important;letter-spacing:4px;text-transform:uppercase;white-space:nowrap;opacity:0.6}

/* HOTEL BUILDING — image-based cutaway */
.fh-hotel-building{position:relative !important;background-size:100% 100% !important;background-repeat:no-repeat !important;aspect-ratio:1360/768 !important;width:100% !important;max-width:1000px !important;margin:0 auto !important;padding:0 !important;border:none !important;box-shadow:0 8px 48px rgba(0,0,0,0.8) !important;overflow:hidden !important;border-radius:4px !important}
.fh-hotel-building-roof,.fh-hotel-building-base,.fh-hotel-building-sign{display:none !important}
.fh-hotel-floor{display:contents !important}
/* Room base — absolute windows over illustration */
.fh-hotel-room{position:absolute !important;background:transparent !important;border:none !important;margin:0 !important;padding-bottom:6px !important;box-sizing:border-box !important;overflow:hidden !important;display:flex !important;flex-direction:column !important;align-items:center !important;justify-content:flex-end !important;cursor:pointer !important;transition:box-shadow 0.2s ease !important;border-radius:2px !important;will-change:transform !important}
.fh-hotel-room::before{display:none !important}
.fh-hotel-room::after{display:none !important}
.fh-hotel-room:not(.fh-hotel-room--mine):hover::after{content:'' !important;display:block !important;position:absolute !important;inset:0 !important;background:rgba(255,255,255,0.10) !important;pointer-events:none !important;z-index:3 !important}
/* Dim overlay for occupied-not-mine rooms */
.fh-hotel-room--occupied:not(.fh-hotel-room--mine)::before{content:'' !important;display:block !important;position:absolute !important;inset:0 !important;background:rgba(0,0,0,0.45) !important;pointer-events:none !important;z-index:1 !important}
.fh-hotel-room--mine::before{display:none !important}
/* Inner content above overlays */
.fh-hotel-room-yours,.fh-hotel-room-fish,.fh-hotel-room-species,.fh-hotel-room-qty{position:relative !important;z-index:2 !important}
/* Room positions — Floor 1 (20 gallon, top) */
.fh-hotel-room[data-room="201"]{left:calc(198/1360*100%) !important;top:calc(216/768*100%) !important;width:calc(211/1360*100%) !important;height:calc(203/768*100%) !important}
.fh-hotel-room[data-room="202"]{left:calc(409/1360*100%) !important;top:calc(216/768*100%) !important;width:calc(269/1360*100%) !important;height:calc(203/768*100%) !important}
.fh-hotel-room[data-room="203"]{left:calc(678/1360*100%) !important;top:calc(216/768*100%) !important;width:calc(254/1360*100%) !important;height:calc(203/768*100%) !important}
.fh-hotel-room[data-room="204"]{left:calc(932/1360*100%) !important;top:calc(216/768*100%) !important;width:calc(229/1360*100%) !important;height:calc(203/768*100%) !important}
/* Room positions — Floor 2 (40 gallon, bottom) */
.fh-hotel-room[data-room="101"]{left:calc(198/1360*100%) !important;top:calc(435/768*100%) !important;width:calc(323/1360*100%) !important;height:calc(218/768*100%) !important}
.fh-hotel-room[data-room="102"]{left:calc(521/1360*100%) !important;top:calc(435/768*100%) !important;width:calc(316/1360*100%) !important;height:calc(218/768*100%) !important}
.fh-hotel-room[data-room="103"]{left:calc(837/1360*100%) !important;top:calc(435/768*100%) !important;width:calc(324/1360*100%) !important;height:calc(218/768*100%) !important}
/* Occupied/customer rooms — individual lit room images */
.fh-hotel-room--occupied,.fh-hotel-room--mine{background-size:cover !important;background-position:center !important;background-repeat:no-repeat !important}
.fh-hotel-room--occupied[data-room="201"],.fh-hotel-room--mine[data-room="201"]{background-image:url('<?php echo esc_url( $hotel_img_url ); ?>rooms/room-201-on.jpg') !important}
.fh-hotel-room--occupied[data-room="202"],.fh-hotel-room--mine[data-room="202"]{background-image:url('<?php echo esc_url( $hotel_img_url ); ?>rooms/room-202-on.jpg') !important}
.fh-hotel-room--occupied[data-room="203"],.fh-hotel-room--mine[data-room="203"]{background-image:url('<?php echo esc_url( $hotel_img_url ); ?>rooms/room-203-on.jpg') !important}
.fh-hotel-room--occupied[data-room="204"],.fh-hotel-room--mine[data-room="204"]{background-image:url('<?php echo esc_url( $hotel_img_url ); ?>rooms/room-204-on.jpg') !important}
.fh-hotel-room--occupied[data-room="101"],.fh-hotel-room--mine[data-room="101"]{background-image:url('<?php echo esc_url( $hotel_img_url ); ?>rooms/room-101-on.jpg') !important}
.fh-hotel-room--occupied[data-room="102"],.fh-hotel-room--mine[data-room="102"]{background-image:url('<?php echo esc_url( $hotel_img_url ); ?>rooms/room-102-on.jpg') !important}
.fh-hotel-room--occupied[data-room="103"],.fh-hotel-room--mine[data-room="103"]{background-image:url('<?php echo esc_url( $hotel_img_url ); ?>rooms/room-103-on.jpg') !important}
.fh-hotel-room--occupied[data-room="301"],.fh-hotel-room--mine[data-room="301"]{background-image:url('<?php echo esc_url( $hotel_img_url ); ?>rooms/room-301-on.jpg') !important}
.fh-hotel-room--occupied[data-room="302"],.fh-hotel-room--mine[data-room="302"]{background-image:url('<?php echo esc_url( $hotel_img_url ); ?>rooms/room-302-on.jpg') !important}
/* States — unassigned (transparent, dark building shows through) */
.fh-hotel-room--unassigned{background-color:transparent !important;box-shadow:none !important}
.fh-hotel-room--unassigned .fh-hotel-room-species{display:none !important}
/* States — no arrival (red tint overlay) */
.fh-hotel-room--noarrival{background-color:transparent !important;box-shadow:none !important}
.fh-hotel-room--noarrival::before{content:'' !important;display:block !important;position:absolute !important;inset:0 !important;background:rgba(80,0,0,0.4) !important;pointer-events:none !important;z-index:1 !important}
.fh-hotel-room--noarrival .fh-hotel-room-species{color:#ff6666 !important;font-size:10px !important;z-index:2 !important;position:relative !important}
/* States — customer room (gold glow) */
.fh-hotel-room--mine{box-shadow:none !important;border:1.5px solid rgba(150,136,95,0.7) !important;z-index:2 !important}
/* Self-hosted font stub — uncomment when .woff2 is provided
@font-face {
  font-family: 'FisHotelCustom';
  src: url('<?php echo esc_url( $hotel_img_url ); ?>../fonts/custom-font.woff2') format('woff2');
  font-weight: normal;
  font-style: normal;
  font-display: swap;
}
*/
/* Text content — Righteous font, white with navy border */
.fh-hotel-room-number{display:none !important}
.fh-hotel-room-yours{font-family:'Righteous',sans-serif !important;font-size:9px !important;letter-spacing:0.15em !important;color:#ffffff !important;text-shadow:-1px -1px 0 #00008b,1px -1px 0 #00008b,-1px 1px 0 #00008b,1px 1px 0 #00008b,0 2px 4px rgba(0,0,30,0.7) !important;text-transform:uppercase !important;display:block !important;margin-top:auto !important;margin-bottom:4px !important;position:relative !important;z-index:2 !important}
/* Sign glow effect */
.fh-hotel-sign-glow{position:absolute !important;top:0 !important;left:10% !important;width:80% !important;height:40% !important;pointer-events:none !important;z-index:2 !important;opacity:0 !important;background:radial-gradient(ellipse 60% 50% at 50% 60%,rgba(255,220,120,0.22) 0%,rgba(255,180,60,0.10) 40%,transparent 70%) !important;transition:opacity 0.5s ease !important}
.fh-hotel-building[data-band="sunset"] .fh-hotel-sign-glow,.fh-hotel-building[data-band="night"] .fh-hotel-sign-glow{opacity:1 !important}
.fh-hotel-building[data-band="night"] .fh-hotel-sign-glow{background:radial-gradient(ellipse 60% 50% at 50% 60%,rgba(255,230,140,0.28) 0%,rgba(255,190,80,0.14) 40%,transparent 70%) !important}
/* Slider for multiple buildings */
.fh-hotel-slider{position:relative;max-width:1000px;margin:32px auto 0}
.fh-hotel-table-view th{cursor:pointer;user-select:none}
.fh-hotel-table-view th:hover{color:#c9a84c}
.fh-hotel-table-view th.fh-sort-active{color:#c9a84c}
.fh-hotel-table-view .fh-sort-arrow{font-size:10px;margin-left:4px;opacity:0.5}
.fh-hotel-table-view th.fh-sort-active .fh-sort-arrow{opacity:1}
.fh-hotel-slides{overflow:hidden}
.fh-hotel-slide{display:none}
.fh-hotel-slide--active{display:block}
.fh-hotel-slide-label{text-align:center;font-family:'Oswald',sans-serif;font-size:11px;letter-spacing:0.15em;color:#666;padding:6px 0 0}
.fh-hotel-prev,.fh-hotel-next{position:absolute;top:45%;transform:translateY(-50%);background:rgba(0,0,0,0.5);border:1px solid #444;color:#c9a84c;font-size:28px;line-height:1;padding:8px 14px;cursor:pointer;z-index:10;border-radius:3px}
.fh-hotel-prev{left:-44px}
.fh-hotel-next{right:-44px}
.fh-hotel-prev:hover,.fh-hotel-next:hover{background:rgba(26,58,92,0.8)}
.fh-hotel-dots{text-align:center;padding:10px 0 0}
.fh-hotel-dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#444;margin:0 4px;cursor:pointer}
.fh-hotel-dot--active{background:#c9a84c}
/* Building 2 — Beach Hut illustration */
.fh-hotel-building-2{position:relative !important;background-size:100% 100% !important;background-repeat:no-repeat !important;aspect-ratio:1360/768 !important;width:100% !important;max-width:1000px !important;margin:0 auto !important;padding:0 !important;border:none !important;box-shadow:0 8px 48px rgba(0,0,0,0.8) !important;overflow:hidden !important;border-radius:4px !important}
.fh-hotel-building-2 .fh-hotel-room{position:absolute !important}
.fh-hotel-building-2 .fh-hotel-room::after{content:'' !important;position:absolute !important;inset:0 !important;box-shadow:inset 0 0 18px 6px rgba(0,0,0,0.65) !important;pointer-events:none !important;z-index:3 !important;border-radius:inherit !important}
.fh-hotel-building-2 .fh-hotel-room[data-room="301"]{left:15.05% !important;top:57.70% !important;width:22.45% !important;height:24.74% !important}
.fh-hotel-building-2 .fh-hotel-room[data-room="302"]{left:63.94% !important;top:57.39% !important;width:21.57% !important;height:24.87% !important}
.fh-hotel-building,.fh-hotel-building-2{position:relative !important;overflow:hidden !important;cursor:default !important;transform-origin:0 0 !important}
.fh-hotel-building .fh-hotel-sign-glow{transition:none !important}
.fh-hotel-room--occupied,.fh-hotel-room--mine{cursor:pointer !important}
/* Room detail expand — outside building container */
.fh-hotel-room-detail{display:none;position:relative;width:100%;max-width:1000px;margin:12px auto 0;box-sizing:border-box;background:#1a1a1a !important;border:1px solid #333;border-radius:4px;padding:16px 20px}
.fh-hotel-room-detail--open{display:block}
.fh-hotel-room-detail,.fh-hotel-room-detail-name,.fh-hotel-room-detail-sci,.fh-hotel-room-detail-meta,.fh-hotel-room-detail-yours,.fh-hotel-room-detail-close{font-family:'Righteous',sans-serif !important;letter-spacing:0.05em !important}
.fh-hotel-room-detail-name{font-size:20px;color:#e1e1e1 !important;font-weight:600}
.fh-hotel-room-detail-sci{font-size:13px;color:#888 !important;font-style:italic;margin-top:2px}
.fh-hotel-room-detail-meta{font-size:12px;color:#aaa !important;margin-top:10px;line-height:1.8}
.fh-hotel-room-detail-yours{font-size:13px;color:#c8a96e !important;margin-top:10px}
.fh-hotel-room-detail-close{position:absolute;top:12px;right:16px;background:none;border:none;color:#666;font-size:20px;cursor:pointer}
.fh-hotel-room-detail-close:hover{color:#fff}

/* BUILDING ZOOM */
.fh-zoom-backdrop{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:99;opacity:0;transition:opacity 0.3s ease;cursor:pointer}
.fh-zoom-backdrop--visible{opacity:1}
.fh-room-card{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(420px,88vw);background:#111;border:1px solid rgba(150,136,95,0.35);border-radius:8px;padding:28px 32px;box-sizing:border-box;z-index:10001;opacity:0;transition:opacity 200ms ease;font-family:'Oswald',sans-serif;color:#f5f0e8;text-align:center}
.fh-room--zooming .fh-hotel-room-yours{display:none !important}
.fh-room-card-room{font-size:12px;font-variant:small-caps;letter-spacing:2px;color:#96885f;margin-bottom:6px}
.fh-room-card-rule{height:1px;background:rgba(150,136,95,0.25);margin:8px 0}
.fh-room-card-list{display:flex;flex-direction:column;gap:6px}
.fh-room-card--scroll .fh-room-card-list{max-height:60vh;overflow-y:auto}
.fh-room-card-row{display:flex;align-items:center;gap:8px}
.fh-room-card-row-name{flex:1;font-size:15px;color:#f5f0e8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.fh-room-card-row-qty{font-size:12px;color:#888;white-space:nowrap}
.fh-room-card-star{color:#96885f;font-size:12px}
.fh-room-card-badge{display:inline-block;font-size:10px;padding:2px 8px;border-radius:8px;letter-spacing:0.1em;white-space:nowrap}
.fh-room-card-badge--qt{background:#2d5a2d;color:#fff}
.fh-room-card-badge--pending{background:#8b6914;color:#fff}
.fh-room-card-badge--doa{background:#8b1414;color:#fff}
.fh-room-card-badge--none{background:#888;color:#fff}
.fh-room-card-summary{font-size:11px;color:#777;letter-spacing:0.5px}

/* VIEW TOGGLE */
.fh-hotel-view-toggle{text-align:right;margin-top:10px;margin-bottom:6px;font-family:'Oswald',sans-serif;font-size:12px;letter-spacing:1px}
.fh-toggle-opt{cursor:pointer;color:rgba(225,225,225,0.35)}
.fh-toggle-opt.fh-toggle-active{color:#96885f}
.fh-toggle-sep{color:rgba(225,225,225,0.2);margin:0 8px}

/* TABLE VIEW */
.fh-hotel-table-view{width:100%;font-family:'Oswald',sans-serif}
.fh-hotel-table-view table{width:100%;border-collapse:collapse}
.fh-hotel-table-view th{background:#1a1a1a;color:#96885f;font-size:12px;letter-spacing:1.5px;padding:10px 12px;text-align:left;border-bottom:1px solid rgba(150,136,95,0.3)}
.fh-hotel-table-view td{padding:9px 12px;font-size:14px;color:rgb(225,225,225);border-bottom:1px solid rgba(255,255,255,0.06)}
.fh-hotel-table-view tr:nth-child(even) td{background:rgba(255,255,255,0.02)}
.fh-hotel-table-view tr:hover td{background:rgba(150,136,95,0.07)}
.fh-hotel-table-view tr.fh-table-mine td{color:#f5f0e8}
.fh-hotel-table-view tr.fh-table-mine td:first-child::before{content:'\2605 ';color:#96885f;font-size:11px}

/* RESPONSIVE */
@media(max-width:640px){
    .fh-hotel-postcard-wrap{width:100%}
    .fh-hotel-card{width:100%;border-radius:12px !important;overflow:hidden !important}
    .fh-hotel-card-inner{transform:none !important;border-radius:12px !important;overflow:hidden !important}
    .fh-hotel-postcard-front,.fh-hotel-postcard-back{backface-visibility:visible;-webkit-backface-visibility:visible;transform:none !important}
    .fh-hotel-postcard-front{position:relative !important;height:auto !important}
    .fh-hotel-postcard-back{position:relative !important;height:auto !important;min-height:300px;width:100%}
    .fh-hotel-postcard-back{display:none;margin-top:0;border-radius:0 0 6px 6px;padding-bottom:20px !important;overflow:hidden !important}
    .fh-hotel-postcard-scene{display:block !important;height:200px !important}
    .fh-hotel-postcard-front-strip{display:flex !important;height:auto !important;padding:10px 12px !important}
    .fh-hotel-flip-btn{position:absolute !important;bottom:12px !important;right:12px !important;margin:0 !important;font-size:10px !important;padding:4px 10px !important;letter-spacing:1px !important;line-height:1.4 !important}
    .fh-hotel-card[data-flipped="true"] .fh-hotel-postcard-front .fh-hotel-postcard-scene,
    .fh-hotel-card[data-flipped="true"] .fh-hotel-postcard-front .fh-hotel-postcard-front-strip{display:none}
    .fh-hotel-card[data-flipped="true"] .fh-hotel-postcard-back{display:flex;box-shadow:0 4px 20px rgba(0,0,0,0.4)}
    .fh-hotel-postcard-back-right{width:160px;padding:8px !important}
    .fh-hotel-postcard-back-left{padding:8px !important}
    .fh-hotel-postcard-stamp-area{width:60px !important;height:60px !important}
    .fh-hotel-postcard-stamp{width:50px !important;height:50px !important}
    .fh-hotel-postcard-stamp-text{font-size:7px !important}
    .fh-hotel-postcard-postmark{width:48px !important;height:48px !important;margin:4px 0 !important}
    .fh-hotel-postcard-postmark-city,.fh-hotel-postcard-postmark-date{font-size:7px !important}
    .fh-hotel-postcard-back-message{font-size:13px !important;line-height:1.3 !important}
    .fh-hotel-postcard-back-correspondence{font-size:8px !important;margin-bottom:2px !important}
    .fh-hotel-postcard-back-signature{font-size:11px !important;margin-top:4px !important}
    .fh-hotel-postcard-address-label{font-size:7px !important}
    .fh-hotel-postcard-address-line{font-size:9px !important;margin-bottom:2px !important}
    .fh-hotel-postcard-vertical-text{display:none}
    .fh-hotel-slider{margin-top:24px !important}
    .fh-hotel-room-species{font-size:9px !important}
    .fh-hotel-room-qty{font-size:8px !important}
    .fh-hotel-room-fish{font-size:18px !important}
    .fh-hotel-room--occupied,.fh-hotel-room--mine{cursor:pointer !important}
}
</style>

<div class="fh-hotel-postcard-wrap">
    <div class="fh-hotel-card" data-flipped="false" onclick="fishotelHotelFlipCard(this)">
        <div class="fh-hotel-card-inner">
            <!-- FRONT -->
            <div class="fh-hotel-postcard-front"
                 data-layers="<?php echo esc_attr( wp_json_encode( $layer_config ) ); ?>"
                 data-scene-urls="<?php echo esc_attr( wp_json_encode( $scene_urls_by_band ) ); ?>"
                 data-scene-fallback="<?php echo $scene_url ? esc_url( $scene_url ) : ''; ?>">
                <?php if ( $scene_url ) : ?>
                    <div class="fh-hotel-postcard-scene" style="background-image:url('<?php echo esc_url( $scene_url ); ?>');">
                        <div class="fh-hotel-postcard-day-badge">DAY <?php echo intval( $day_number ); ?></div>
                        <div class="fh-hotel-postcard-layer-wrap"></div>
                    </div>
                <?php else : ?>
                    <div class="fh-hotel-postcard-scene-placeholder">
                        <div class="fh-hotel-postcard-day-badge">DAY <?php echo intval( $day_number ); ?></div>
                        <span>Scene Coming Soon</span>
                        <div class="fh-hotel-postcard-layer-wrap"></div>
                    </div>
                <?php endif; ?>
                <div class="fh-hotel-postcard-front-strip">
                    <div class="fh-hotel-postcard-hotel-name">THE FISHOTEL</div>
                    <div class="fh-hotel-postcard-activity-name"><?php echo $activity_name; ?></div>
                    <button class="fh-hotel-flip-btn">TURN OVER</button>
                </div>
            </div>

            <!-- BACK -->
            <div class="fh-hotel-postcard-back">
                <div class="fh-hotel-postcard-back-left">
                    <div class="fh-hotel-postcard-back-correspondence">CORRESPONDENCE</div>
                    <div class="fh-hotel-postcard-back-message"><?php echo $postcard_message; ?></div>
                    <div class="fh-hotel-postcard-postmark">
                        <div class="fh-hotel-postcard-postmark-city"><?php echo $postmark_city; ?></div>
                        <div class="fh-hotel-postcard-postmark-date"><?php echo esc_html( $postmark_date ); ?></div>
                    </div>
                    <div class="fh-hotel-postcard-back-signature">— The FisHotel Concierge</div>
                </div>
                <div class="fh-hotel-postcard-back-right">
                    <div class="fh-hotel-postcard-stamp-area">
                        <div class="fh-hotel-postcard-stamp">
                            <span class="fh-hotel-postcard-stamp-text">PLACE<br>STAMP<br>HERE</span>

                        </div>
                    </div>
                    <div class="fh-hotel-postcard-address-lines">
                        <div class="fh-hotel-postcard-address-label">TO: OUR VALUED GUEST</div>
                        <div class="fh-hotel-postcard-address-line"></div>
                        <div class="fh-hotel-postcard-address-line"></div>
                        <div class="fh-hotel-postcard-address-line"></div>
                    </div>
                    <button class="fh-hotel-flip-btn">TURN OVER</button>
                    <div class="fh-hotel-postcard-vertical-text">POST CARD</div>
                </div>
            </div>
        </div>
    </div>

    <!-- HOTEL BUILDING SLIDER -->
    <?php
    // Time-band building image swap
    $hour = (int) current_time( 'H' );
    $img_path = plugin_dir_path( dirname( __FILE__ ) ) . 'assists/hotel/';
    if ( $hour >= 5 && $hour < 16 ) {
        $band = 'day';
        $building_file = 'Hotel-Day.png';
    } elseif ( $hour >= 16 && $hour < 20 ) {
        $band = 'sunset';
        $building_file = 'Hotel-Sunset.png';
    } else {
        $band = 'night';
        $building_file = 'Hotel-Dark.png';
    }
    if ( ! file_exists( $img_path . $building_file ) ) {
        $building_file = 'Hotel-Dark.png';
    }
    $building_bg_url = $hotel_img_url . $building_file;

    $floors = [
        1 => [ '201', '202', '203', '204' ],
        2 => [ '101', '102', '103' ],
    ];
    $floor_labels = [ 1 => '20 Gallon', 2 => '40 Gallon', 3 => 'QT Annex — 40 Gallon' ];
    $qt_rooms = [ '301', '302' ];
    ?>
    <div class="fh-hotel-view-toggle">
      <span class="fh-toggle-opt fh-toggle-building fh-toggle-active" data-view="building">&#8862; Building</span>
      <span class="fh-toggle-sep">&middot;</span>
      <span class="fh-toggle-opt fh-toggle-table" data-view="table">&#8801; Table View</span>
    </div>
    <div class="fh-hotel-table-view" style="display:none"></div>
    <div class="fh-hotel-slider">
      <div class="fh-hotel-slides">
        <!-- Slide 1: Main Building -->
        <div class="fh-hotel-slide fh-hotel-slide--active">
          <div class="fh-hotel-building" style="background-image:url('<?php echo esc_url( $building_bg_url ); ?>');" data-band="<?php echo esc_attr( $band ); ?>">
            <div class="fh-hotel-sign-glow"></div>
            <div class="fh-hotel-building-roof">
                <div class="fh-hotel-building-sign">THE FISHOTEL</div>
            </div>
            <?php foreach ( $floors as $fn => $floor_rooms ) : ?>
            <div class="fh-hotel-floor fh-floor-<?php echo $fn; ?>">
                <?php foreach ( $floor_rooms as $tank_id ) :
                    $fish_list = $room_map[ $tank_id ];
                    $is_mine   = $logged_in && isset( $customer_rooms[ $tank_id ] );
                    $state     = 'unassigned';
                    if ( ! empty( $fish_list ) ) {
                        $all_no_arrival = true;
                        foreach ( $fish_list as $fd ) {
                            if ( $fd['status'] !== 'no_arrival' ) { $all_no_arrival = false; break; }
                        }
                        $state = $all_no_arrival ? 'noarrival' : 'occupied';
                    }
                    $cls = 'fh-hotel-room fh-hotel-room--' . $state;
                    if ( $is_mine ) $cls .= ' fh-hotel-room--mine';
                    $tank_fish = [];
                    foreach ( $fish_list as $fd ) {
                        $tank_fish[] = [
                            'species' => $fd['species'],
                            'qty'     => (int) $fd['qty'],
                            'status'  => $fd['status'],
                            'mine'    => isset( $my_batch_ids[ $fd['fish_id'] ] ),
                        ];
                    }
                ?>
                    <div class="<?php echo esc_attr( $cls ); ?>" data-room="<?php echo esc_attr( $tank_id ); ?>" data-room-state="<?php echo esc_attr( $state ); ?>" data-room-fish="<?php echo esc_attr( wp_json_encode( $tank_fish ) ); ?>" data-room-mine="<?php echo $is_mine ? '1' : '0'; ?>" onclick="fishotelHotelToggleRoom('<?php echo esc_js( $tank_id ); ?>', this)">
                        <?php if ( $is_mine ) : ?><div class="fh-hotel-room-yours">YOUR ROOM</div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
            <div class="fh-hotel-building-base"></div>
          </div>
          <div class="fh-hotel-slide-label">MAIN BUILDING &middot; TANKS 101&ndash;204</div>
        </div>
        <!-- Slide 2: QT Annex — Beach Huts -->
        <?php
        // Beach Hut time-band image swap
        $b2_band = 'day';
        $b2_file = 'Beach-Hut-Day.png';
        if ( $hour >= 5 && $hour < 16 ) {
            $b2_band = 'day';
            $b2_file = 'Beach-Hut-Day.png';
        } elseif ( $hour >= 16 && $hour < 20 ) {
            $b2_band = 'sunset';
            $b2_file = 'Beach-Hut-Sunset.png';
        } else {
            $b2_band = 'night';
            $b2_file = 'Beach-Hut-Night.png';
        }
        if ( ! file_exists( $img_path . $b2_file ) ) {
            $b2_file = 'Beach-Hut-Night.png';
        }
        $b2_bg_url = $hotel_img_url . $b2_file;
        ?>
        <div class="fh-hotel-slide">
          <div class="fh-hotel-building-2" style="background-image:url('<?php echo esc_url( $b2_bg_url ); ?>');" data-band="<?php echo esc_attr( $b2_band ); ?>">
            <?php foreach ( $qt_rooms as $tank_id ) :
                $fish_list = $room_map[ $tank_id ];
                $is_mine   = $logged_in && isset( $customer_rooms[ $tank_id ] );
                $state     = 'unassigned';
                if ( ! empty( $fish_list ) ) {
                    $all_no_arrival = true;
                    foreach ( $fish_list as $fd ) {
                        if ( $fd['status'] !== 'no_arrival' ) { $all_no_arrival = false; break; }
                    }
                    $state = $all_no_arrival ? 'noarrival' : 'occupied';
                }
                $cls = 'fh-hotel-room fh-hotel-room--' . $state;
                if ( $is_mine ) $cls .= ' fh-hotel-room--mine';
                $tank_fish = [];
                foreach ( $fish_list as $fd ) {
                    $tank_fish[] = [
                        'species' => $fd['species'],
                        'qty'     => (int) $fd['qty'],
                        'status'  => $fd['status'],
                        'mine'    => isset( $my_batch_ids[ $fd['fish_id'] ] ),
                    ];
                }
            ?>
                <div class="<?php echo esc_attr( $cls ); ?>" data-room="<?php echo esc_attr( $tank_id ); ?>" data-room-state="<?php echo esc_attr( $state ); ?>" data-room-fish="<?php echo esc_attr( wp_json_encode( $tank_fish ) ); ?>" data-room-mine="<?php echo $is_mine ? '1' : '0'; ?>" onclick="fishotelHotelToggleRoom('<?php echo esc_js( $tank_id ); ?>', this)">
                    <?php if ( $is_mine ) : ?><div class="fh-hotel-room-yours">YOUR ROOM</div><?php endif; ?>
                </div>
            <?php endforeach; ?>
          </div>
          <div class="fh-hotel-slide-label">QT ANNEX &middot; BEACH HUTS 301&ndash;302</div>
        </div>
      </div>
      <button class="fh-hotel-prev" aria-label="Previous">&#8249;</button>
      <button class="fh-hotel-next" aria-label="Next">&#8250;</button>
      <div class="fh-hotel-dots">
        <span class="fh-hotel-dot fh-hotel-dot--active" data-slide="0"></span>
        <span class="fh-hotel-dot" data-slide="1"></span>
      </div>
    </div>

    <?php /* Room detail panels — OUTSIDE the slider container */ ?>
    <?php foreach ( $all_room_ids as $tank_id ) :
        $fish_list = $room_map[ $tank_id ];
        $is_mine   = isset( $customer_rooms[ $tank_id ] );
        if ( $tank_id[0] === '3' ) {
            $floor_lbl = $floor_labels[3];
        } elseif ( $tank_id[0] === '1' ) {
            $floor_lbl = 'Floor 2 — ' . $floor_labels[2];
        } else {
            $floor_lbl = 'Floor 1 — ' . $floor_labels[1];
        }
    ?>
    <div class="fh-hotel-room-detail" id="fh-room-detail-<?php echo esc_attr( $tank_id ); ?>">
        <button class="fh-hotel-room-detail-close" onclick="fishotelHotelToggleRoom('<?php echo esc_js( $tank_id ); ?>')">&times;</button>
        <?php if ( ! empty( $fish_list ) ) : ?>
            <?php foreach ( $fish_list as $fi => $rd ) : ?>
                <?php if ( $fi > 0 ) : ?><hr style="border:none;border-top:1px solid #3a3a3a;margin:12px 0;"><?php endif; ?>
                <div class="fh-hotel-room-detail-name"><?php echo esc_html( $rd['species'] ); ?></div>
                <?php if ( ! empty( $rd['sci_name'] ) ) : ?>
                    <div class="fh-hotel-room-detail-sci"><?php echo esc_html( $rd['sci_name'] ); ?></div>
                <?php endif; ?>
                <div class="fh-hotel-room-detail-meta">
                    Tank: <?php echo esc_html( $tank_id ); ?> (<?php echo esc_html( $floor_lbl ); ?>)<br>
                    Received: <?php echo intval( $rd['qty'] ); ?> &bull; DOA: <?php echo intval( $rd['qty_doa'] ); ?><br>
                    Status: <?php echo esc_html( ucwords( str_replace( '_', ' ', $rd['status'] ) ) ); ?>
                </div>
            <?php endforeach; ?>
            <?php if ( $is_mine ) : ?>
                <div class="fh-hotel-room-detail-yours">Your fish are staying in Room <?php echo esc_html( $tank_id ); ?></div>
            <?php endif; ?>
        <?php else : ?>
            <div class="fh-hotel-room-detail-name">Room <?php echo esc_html( $tank_id ); ?></div>
            <div class="fh-hotel-room-detail-meta"><?php echo esc_html( $floor_lbl ); ?><br>No guest assigned.</div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <script>
    /* ── Room toggle + Building zoom ───────────────── */
    var fishotelHotelOpenRoom = null;
    var _fhZoomOpen = false;
    var _fhZoomBuilding = null;
    var _fhZoomRoom = null;
    var _fhZoomEscHandler = null;

    function fhBuildCardHtml(id, roomEl) {
        var fishList = [];
        try { fishList = JSON.parse(roomEl.dataset.roomFish || '[]'); } catch(e) {}

        function fhBadge(st) {
            var lbl, cls;
            if (st === 'in_qt' || st === 'in_quarantine') { lbl = 'IN QT'; cls = 'fh-room-card-badge--qt'; }
            else if (st === 'pending') { lbl = 'PENDING'; cls = 'fh-room-card-badge--pending'; }
            else if (st === 'doa') { lbl = 'DOA'; cls = 'fh-room-card-badge--doa'; }
            else { lbl = st.replace(/_/g, ' ').toUpperCase(); cls = 'fh-room-card-badge--none'; }
            return '<span class="fh-room-card-badge ' + cls + '">' + lbl + '</span>';
        }

        var rowsHtml = '';
        for (var i = 0; i < fishList.length; i++) {
            var f = fishList[i];
            var star = f.mine ? '<span class="fh-room-card-star">\u2605</span> ' : '';
            rowsHtml += '<div class="fh-room-card-row">' +
                '<span class="fh-room-card-row-name">' + star + f.species + '</span>' +
                '<span class="fh-room-card-row-qty">Qty: ' + f.qty + '</span>' +
                fhBadge(f.status) +
            '</div>';
        }

        var card = document.createElement('div');
        card.className = 'fh-room-card';
        if (fishList.length > 6) card.classList.add('fh-room-card--scroll');
        card.innerHTML =
            '<div class="fh-room-card-room">ROOM ' + id + '</div>' +
            '<div class="fh-room-card-rule"></div>' +
            '<div class="fh-room-card-list">' + rowsHtml + '</div>' +
            '<div class="fh-room-card-rule"></div>' +
            '<div class="fh-room-card-summary">' + fishList.length + ' species &middot; Tank ' + id + '</div>';
        return card;
    }

    function fishotelZoomClose() {
        if (!_fhZoomOpen) return;
        _fhZoomOpen = false;
        if (_fhZoomRoom) { _fhZoomRoom.classList.remove('fh-room--zooming'); _fhZoomRoom = null; }
        /* Fade card out */
        var card = document.querySelector('.fh-room-card');
        if (card) {
            card.style.opacity = '0';
            card.addEventListener('transitionend', function() { card.remove(); }, { once: true });
        }
        /* Unzoom building (desktop only) */
        if (_fhZoomBuilding) {
            _fhZoomBuilding.style.transform = 'translate(0px, 0px) scale(1)';
            _fhZoomBuilding.addEventListener('transitionend', function onUnzoom(e) {
                if (e.propertyName !== 'transform') return;
                _fhZoomBuilding.removeEventListener('transitionend', onUnzoom);
                _fhZoomBuilding.style.transform = '';
                _fhZoomBuilding.style.transition = '';
                _fhZoomBuilding = null;
            });
        }
        /* Fade backdrop */
        var bd = document.querySelector('.fh-zoom-backdrop');
        if (bd) {
            bd.classList.remove('fh-zoom-backdrop--visible');
            bd.addEventListener('transitionend', function() { bd.remove(); }, { once: true });
        }
        if (_fhZoomEscHandler) {
            document.removeEventListener('keydown', _fhZoomEscHandler);
            _fhZoomEscHandler = null;
        }
    }

    function fishotelHotelToggleRoom(id, roomEl) {
        if (!roomEl) roomEl = document.querySelector('[data-room="' + id + '"]');
        if (!roomEl) return;

        /* Only open for occupied rooms */
        var state = roomEl.dataset.roomState || 'unassigned';
        if (state !== 'occupied') return;

        /* Close any existing zoom/card first */
        if (_fhZoomOpen) { fishotelZoomClose(); return; }

        var isMobile = window.innerWidth <= 640;
        var building = roomEl.closest('.fh-hotel-building, .fh-hotel-building-2');

        _fhZoomRoom = roomEl;
        roomEl.classList.add('fh-room--zooming');

        /* Backdrop */
        var backdrop = document.createElement('div');
        backdrop.className = 'fh-zoom-backdrop';
        if (isMobile) backdrop.style.background = 'rgba(0,0,0,0.5)';
        backdrop.addEventListener('click', fishotelZoomClose);
        document.body.appendChild(backdrop);
        requestAnimationFrame(function() { backdrop.classList.add('fh-zoom-backdrop--visible'); });

        if (!isMobile && building) {
            /* ── Desktop: building zoom ── */
            _fhZoomBuilding = building;

            var bRect = building.getBoundingClientRect();
            var rRect = roomEl.getBoundingClientRect();
            var rCx = rRect.left - bRect.left + rRect.width / 2;
            var rCy = rRect.top - bRect.top + rRect.height / 2;
            var scale = Math.min(bRect.width / rRect.width, bRect.height / rRect.height) * 0.88;
            var tx = bRect.width / 2 - rCx * scale;
            var ty = bRect.height / 2 - rCy * scale;

            building.style.transformOrigin = '0 0';
            building.style.transition = 'transform 420ms cubic-bezier(0.25, 0.46, 0.45, 0.94)';
            building.style.transform = 'translate(' + tx + 'px, ' + ty + 'px) scale(' + scale + ')';
        }

        /* Build card and show after delay */
        var card = fhBuildCardHtml(id, roomEl);
        setTimeout(function() {
            document.body.appendChild(card);
            requestAnimationFrame(function() { card.style.opacity = '1'; });
        }, isMobile ? 0 : 300);

        /* Escape key */
        _fhZoomEscHandler = function(e) {
            if (e.key === 'Escape') fishotelZoomClose();
        };
        document.addEventListener('keydown', _fhZoomEscHandler);

        _fhZoomOpen = true;
    }

    /* ── Building slider ────────────────────────────── */
    document.querySelectorAll('.fh-hotel-slider').forEach(function(slider) {
        var slides = slider.querySelectorAll('.fh-hotel-slide');
        var dots = slider.querySelectorAll('.fh-hotel-dot');
        var current = 0;
        function goTo(n) {
            fishotelZoomClose();
            slides[current].classList.remove('fh-hotel-slide--active');
            dots[current].classList.remove('fh-hotel-dot--active');
            current = n;
            slides[current].classList.add('fh-hotel-slide--active');
            dots[current].classList.add('fh-hotel-dot--active');
        }
        slider.querySelector('.fh-hotel-prev').addEventListener('click', function() {
            goTo(current === 0 ? slides.length - 1 : current - 1);
        });
        slider.querySelector('.fh-hotel-next').addEventListener('click', function() {
            goTo(current === slides.length - 1 ? 0 : current + 1);
        });
        dots.forEach(function(dot) {
            dot.addEventListener('click', function() {
                goTo(parseInt(dot.dataset.slide));
            });
        });
    });

    /* ── View toggle (Building / Table) ──────────────── */
    (function() {
        var KEY = 'fh_hotel_view';
        var slider = document.querySelector('.fh-hotel-slider');
        var tableWrap = document.querySelector('.fh-hotel-table-view');
        var toggles = document.querySelectorAll('.fh-toggle-opt');
        if (!slider || !tableWrap || !toggles.length) return;

        var statusLabel = {
            'in_quarantine': 'In Quarantine',
            'pending': 'Pending',
            'doa': 'DOA',
            'no_arrival': 'No Arrival',
            'qt': 'In Quarantine'
        };
        var columns = [
            { key: 'room',    label: 'ROOM' },
            { key: 'species', label: 'SPECIES' },
            { key: 'qty',     label: 'QTY' },
            { key: 'status',  label: 'STATUS' }
        ];
        var sortCol = 'room';
        var sortDir = 'asc'; /* asc, desc, or null (default=room asc) */
        var allRows = [];

        function gatherRows() {
            allRows = [];
            document.querySelectorAll('[data-room-fish]').forEach(function(r) {
                var room = r.getAttribute('data-room');
                var fish;
                try { fish = JSON.parse(r.getAttribute('data-room-fish')); } catch(e) { return; }
                if (!fish || !fish.length) return;
                fish.forEach(function(f) {
                    allRows.push({ room: room, species: f.species, qty: f.qty, status: f.status, mine: f.mine });
                });
            });
        }

        function sortRows() {
            var key = sortCol;
            var dir = sortDir === 'desc' ? -1 : 1;
            allRows.sort(function(a, b) {
                var av = a[key], bv = b[key];
                if (key === 'qty') return (av - bv) * dir;
                if (key === 'room') return a.room.localeCompare(b.room, undefined, {numeric: true}) * dir;
                var al = (statusLabel[av] || av).toLowerCase();
                var bl = (statusLabel[bv] || bv).toLowerCase();
                if (typeof av === 'string') return al.localeCompare(bl) * dir;
                return 0;
            });
        }

        function renderTable() {
            var html = '<table><thead><tr>';
            columns.forEach(function(c) {
                var active = sortCol === c.key;
                var arrow = !active ? '&#x21C5;' : (sortDir === 'asc' ? '&#x2191;' : '&#x2193;');
                var cls = active ? ' class="fh-sort-active"' : '';
                html += '<th' + cls + ' data-sort="' + c.key + '">' + c.label + '<span class="fh-sort-arrow">' + arrow + '</span></th>';
            });
            html += '</tr></thead><tbody>';
            allRows.forEach(function(r) {
                var cls = r.mine ? ' class="fh-table-mine"' : '';
                var display = statusLabel[r.status] || r.status.replace(/_/g, ' ').replace(/\b\w/g, function(c) { return c.toUpperCase(); });
                html += '<tr' + cls + '><td>' + r.room + '</td><td>' + r.species + '</td><td>' + r.qty + '</td><td>' + display + '</td></tr>';
            });
            html += '</tbody></table>';
            tableWrap.innerHTML = html;

            tableWrap.querySelectorAll('th[data-sort]').forEach(function(th) {
                th.addEventListener('click', function() {
                    var col = th.getAttribute('data-sort');
                    if (sortCol === col) {
                        if (sortDir === 'asc') { sortDir = 'desc'; }
                        else { sortCol = 'room'; sortDir = 'asc'; } /* reset to default */
                    } else {
                        sortCol = col;
                        sortDir = 'asc';
                    }
                    sortRows();
                    renderTable();
                });
            });
        }

        function buildTableView() {
            gatherRows();
            sortRows();
            renderTable();
        }

        function showView(view) {
            if (view === 'table') {
                buildTableView();
                slider.style.display = 'none';
                tableWrap.style.display = 'block';
            } else {
                slider.style.display = '';
                tableWrap.style.display = 'none';
            }
            toggles.forEach(function(t) {
                t.classList.toggle('fh-toggle-active', t.getAttribute('data-view') === view);
            });
            try { sessionStorage.setItem(KEY, view); } catch(e) {}
        }

        toggles.forEach(function(t) {
            t.addEventListener('click', function() { showView(t.getAttribute('data-view')); });
        });

        var saved = null;
        try { saved = sessionStorage.getItem(KEY); } catch(e) {}
        if (saved === 'table') showView('table');
    })();

    /* ── Flip handler with layer pause/resume ──────── */
    function fishotelHotelFlipCard(card) {
        var flipped = card.dataset.flipped === 'true' ? 'false' : 'true';
        card.dataset.flipped = flipped;
        var front = card.querySelector('.fh-hotel-postcard-front');
        var back  = card.querySelector('.fh-hotel-postcard-back');
        if (flipped === 'true') {
            front.style.display = 'none';
            back.style.display  = 'flex';
        } else {
            front.style.display = 'block';
            back.style.display  = 'none';
        }
        var layers = card.querySelectorAll('.fh-postcard-layer');
        var state = flipped === 'true' ? 'paused' : 'running';
        for (var i = 0; i < layers.length; i++) {
            layers[i].style.animationPlayState = state;
        }
    }

    /* ── Time-of-day + Layer system ────────────────── */
    (function(){
        var h = new Date().getHours();
        var band;
        if (h >= 5 && h < 11) band = 'morning';
        else if (h >= 11 && h < 16) band = 'afternoon';
        else if (h >= 16 && h < 20) band = 'sunset';
        else band = 'night';

        var wrap = document.querySelector('.fh-hotel-postcard-wrap');
        if (!wrap) return;
        wrap.setAttribute('data-time-of-day', band);
        wrap.setAttribute('data-hour', String(h));

        /* Swap scene background for time-of-day variant */
        var front = wrap.querySelector('.fh-hotel-postcard-front');
        if (!front) return;
        var sceneUrls = {};
        try { sceneUrls = JSON.parse(front.getAttribute('data-scene-urls') || '{}'); } catch(e){}
        var sceneEl = front.querySelector('.fh-hotel-postcard-scene');
        if (sceneEl) {
            var todUrl = sceneUrls[band] || sceneUrls['_fallback'] || null;
            if (todUrl) {
                sceneEl.style.backgroundImage = "url('" + todUrl + "')";
            }
        }

        /* Build layers */
        var layers = [];
        try { layers = JSON.parse(front.getAttribute('data-layers') || '[]'); } catch(e){}
        if (!layers.length) return;

        var container = front.querySelector('.fh-hotel-postcard-layer-wrap');
        if (!container) return;

        var animMap = {
            'drift-left-right': 'fh-drift-left-right',
            'drift-up':         'fh-drift-up',
            'sway':             'fh-sway',
            'shimmer':          'fh-shimmer',
            'pulse':            'fh-pulse',
            'float':            'fh-float'
        };

        for (var i = 0; i < layers.length; i++) {
            var L = layers[i];
            /* Check show_on */
            var show = L.show_on || ['all'];
            if (show.indexOf('all') === -1 && show.indexOf(band) === -1) continue;
            if (!L.url) continue;

            var img = document.createElement('img');
            img.className = 'fh-postcard-layer';
            img.src = L.url;
            img.alt = '';
            img.draggable = false;
            img.style.left = L.x || '0%';
            img.style.top = L.y || '0%';
            img.style.width = L.width || 'auto';
            img.style.height = 'auto';
            img.style.zIndex = String(L.z || 1);
            img.style.opacity = String(L.opacity != null ? L.opacity : 1);
            img.style.mixBlendMode = L.blend || 'normal';

            var animName = animMap[L.animation] || '';
            var speed = L.speed || 10;
            var pause = L.pause || 0;

            if (animName) {
                if (pause > 0) {
                    /* Pause between loops: use longer duration with keyframe hold */
                    var cycle = speed + pause;
                    var pct = Math.round((speed / cycle) * 100);
                    var kfName = 'fh-pause-' + L.animation + '-' + i;
                    var kfBase = '';
                    if (L.animation === 'drift-up') {
                        kfBase = '0%{transform:translateY(0);opacity:1}' + pct + '%{transform:translateY(-40px);opacity:0}' + (pct+1) + '%,100%{transform:translateY(0);opacity:0}';
                    } else if (L.animation === 'shimmer') {
                        var half = Math.round(pct/2);
                        kfBase = '0%,' + pct + '%{opacity:0.2}' + half + '%{opacity:0.8}' + (pct+1) + '%,100%{opacity:0.2}';
                    } else if (L.animation === 'pulse') {
                        var half = Math.round(pct/2);
                        kfBase = '0%,' + pct + '%{transform:scale(1)}' + half + '%{transform:scale(1.06)}' + (pct+1) + '%,100%{transform:scale(1)}';
                    } else if (L.animation === 'drift-left-right') {
                        var half = Math.round(pct/2);
                        kfBase = '0%,' + pct + '%{transform:translateX(-8px)}' + half + '%{transform:translateX(8px)}' + (pct+1) + '%,100%{transform:translateX(-8px)}';
                    } else if (L.animation === 'sway') {
                        var half = Math.round(pct/2);
                        kfBase = '0%,' + pct + '%{transform:rotate(-3deg)}' + half + '%{transform:rotate(3deg)}' + (pct+1) + '%,100%{transform:rotate(-3deg)}';
                    } else if (L.animation === 'float') {
                        var half = Math.round(pct/2);
                        kfBase = '0%,' + pct + '%{transform:translateY(-6px)}' + half + '%{transform:translateY(6px)}' + (pct+1) + '%,100%{transform:translateY(-6px)}';
                    }
                    if (kfBase) {
                        var sheet = document.createElement('style');
                        sheet.textContent = '@keyframes ' + kfName + '{' + kfBase + '}';
                        document.head.appendChild(sheet);
                        img.style.animation = kfName + ' ' + cycle + 's linear infinite';
                    }
                } else {
                    var timing = 'ease-in-out';
                    var dir = 'alternate';
                    if (L.animation === 'drift-up') { timing = 'linear'; dir = 'normal'; }
                    img.style.animation = animName + ' ' + speed + 's ' + timing + ' ' + dir + ' infinite';
                }
                if (L.animation === 'sway') {
                    img.style.transformOrigin = 'bottom center';
                }
                img.style.animationDelay = (i * 0.5) + 's';
            }

            container.appendChild(img);
        }
    })();
    </script>
</div>
        <?php
        return ob_get_clean();
    }

    /* ─────────────────────────────────────────────
     *  ADMIN — Hotel Program page render
     * ───────────────────────────────────────────── */

    public function hotel_program_html() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }

        $tab = sanitize_key( $_GET['tab'] ?? 'categories' );
        $tabs = [
            'categories' => 'Categories',
            'activities' => 'Activities',
            'schedule'   => 'Schedule',
            'layers'     => 'Layers',
            'assets'     => 'Assets',
        ];
        ?>
        <div class="wrap" style="max-width:1100px;">
            <h1 style="font-size:24px;margin-bottom:4px;">Hotel Program</h1>
            <p style="color:#aaa;margin-top:0;">Manage the FisHotel guest experience — activities, categories &amp; daily schedule.</p>

            <nav class="nav-tab-wrapper" style="margin-bottom:20px;">
                <?php foreach ( $tabs as $key => $label ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=fishotel-hotel-program&tab=' . $key ) ); ?>"
                       class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
                <?php endforeach; ?>
            </nav>

            <?php
            if ( $tab === 'categories' ) {
                $this->hotel_tab_categories();
            } elseif ( $tab === 'activities' ) {
                $this->hotel_tab_activities();
            } elseif ( $tab === 'schedule' ) {
                $this->hotel_tab_schedule();
            } elseif ( $tab === 'layers' ) {
                $this->hotel_tab_layers();
            } elseif ( $tab === 'assets' ) {
                $this->hotel_tab_assets();
            }
            ?>
        </div>
        <?php
    }

    /* ─────────────────────────────────────────────
     *  TAB: Categories
     * ───────────────────────────────────────────── */

    private function hotel_tab_categories() {
        $categories = $this->hotel_get_categories();
        $activities = $this->hotel_get_activities();
        $editing_id = sanitize_text_field( $_GET['edit_cat'] ?? '' );
        $editing    = null;
        if ( $editing_id ) {
            $editing = $this->hotel_get_category_by_id( $editing_id );
        }

        // Success / error notices
        if ( isset( $_GET['cat_saved'] ) ) echo '<div class="notice notice-success"><p>Category saved.</p></div>';
        if ( isset( $_GET['cat_deleted'] ) ) echo '<div class="notice notice-success"><p>Category deleted.</p></div>';
        if ( isset( $_GET['cat_error'] ) ) echo '<div class="notice notice-error"><p>' . esc_html( urldecode( $_GET['cat_error'] ) ) . '</p></div>';
        ?>
        <table class="widefat striped" style="max-width:800px;">
            <thead>
                <tr>
                    <th style="width:40px;">Color</th>
                    <th>Name</th>
                    <th>Label</th>
                    <th>Exclude Random</th>
                    <th>Activities</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $categories ) ) : ?>
                    <tr><td colspan="6" style="color:#aaa;text-align:center;padding:20px;">No categories yet. Add one below.</td></tr>
                <?php endif; ?>
                <?php foreach ( $categories as $cat ) :
                    $act_count = count( array_filter( $activities, function( $a ) use ( $cat ) { return $a['category_id'] === $cat['id']; } ) );
                ?>
                    <tr>
                        <td><span style="display:inline-block;width:20px;height:20px;border-radius:3px;background:<?php echo esc_attr( $cat['color'] ); ?>;"></span></td>
                        <td><?php echo esc_html( $cat['name'] ); ?></td>
                        <td><code><?php echo esc_html( $cat['label'] ); ?></code></td>
                        <td><?php echo ! empty( $cat['exclude_random'] ) ? 'Yes' : '—'; ?></td>
                        <td><?php echo intval( $act_count ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=fishotel-hotel-program&tab=categories&edit_cat=' . $cat['id'] ) ); ?>">Edit</a>
                            <?php if ( $act_count === 0 ) : ?>
                                &nbsp;|&nbsp;
                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fishotel_hotel_delete_category&cat_id=' . $cat['id'] ), 'fishotel_hotel_delete_category' ) ); ?>"
                                   onclick="return confirm('Delete this category?')" style="color:#a00;">Delete</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h3 style="margin-top:24px;"><?php echo $editing ? 'Edit Category' : 'Add Category'; ?></h3>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:500px;">
            <?php wp_nonce_field( 'fishotel_hotel_save_category' ); ?>
            <input type="hidden" name="action" value="fishotel_hotel_save_category">
            <input type="hidden" name="cat_id" value="<?php echo esc_attr( $editing['id'] ?? '' ); ?>">
            <table class="form-table">
                <tr><th><label>Name</label></th>
                    <td><input type="text" name="cat_name" value="<?php echo esc_attr( $editing['name'] ?? '' ); ?>" class="regular-text" required></td></tr>
                <tr><th><label>Short Label</label></th>
                    <td><input type="text" name="cat_label" value="<?php echo esc_attr( $editing['label'] ?? '' ); ?>" maxlength="10" style="width:120px;" required>
                        <p class="description">Max 10 chars — shown on schedule calendar.</p></td></tr>
                <tr><th><label>Color</label></th>
                    <td><input type="color" name="cat_color" value="<?php echo esc_attr( $editing['color'] ?? '#2a7fba' ); ?>"></td></tr>
                <tr><th><label>Exclude from Random</label></th>
                    <td><label><input type="checkbox" name="cat_exclude_random" value="1" <?php checked( ! empty( $editing['exclude_random'] ) ); ?>>
                        If checked, activities in this category will never be selected by the random scheduler.</label></td></tr>
            </table>
            <?php submit_button( $editing ? 'Update Category' : 'Add Category' ); ?>
        </form>
        <?php
    }

    /* ─────────────────────────────────────────────
     *  TAB: Activities
     * ───────────────────────────────────────────── */

    private function hotel_tab_activities() {
        $activities  = $this->hotel_get_activities();
        $categories  = $this->hotel_get_categories();
        $editing_id  = sanitize_text_field( $_GET['edit_act'] ?? '' );
        $editing     = null;
        if ( $editing_id ) {
            $editing = $this->hotel_get_activity_by_id( $editing_id );
        }

        if ( isset( $_GET['act_saved'] ) ) echo '<div class="notice notice-success"><p>Activity saved.</p></div>';
        if ( isset( $_GET['act_deleted'] ) ) echo '<div class="notice notice-success"><p>Activity deleted.</p></div>';

        $scene_types = [ 'pool', 'spa', 'dining', 'lobby', 'beach', 'suite', 'bar', 'graduation' ];
        $times       = [ 'morning' => 'Morning', 'afternoon' => 'Afternoon', 'evening' => 'Evening', 'night' => 'Night' ];

        $plugin_base_url = plugins_url( 'assists/scene/', FISHOTEL_PLUGIN_FILE );
        ?>
        <table class="widefat striped" style="max-width:1000px;">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Category</th>
                    <th>Time</th>
                    <th>Scene</th>
                    <th>Message</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $activities ) ) : ?>
                    <tr><td colspan="6" style="color:#aaa;text-align:center;padding:20px;">No activities yet. Add one below.</td></tr>
                <?php endif; ?>
                <?php foreach ( $activities as $act ) :
                    $cat = $this->hotel_get_category_by_id( $act['category_id'] );
                ?>
                    <tr>
                        <td><?php echo esc_html( $act['name'] ); ?></td>
                        <td><?php if ( $cat ) : ?><span style="display:inline-block;padding:2px 8px;border-radius:3px;background:<?php echo esc_attr( $cat['color'] ); ?>;color:#fff;font-size:11px;"><?php echo esc_html( $cat['label'] ); ?></span><?php else : ?>—<?php endif; ?></td>
                        <td><?php echo esc_html( ucfirst( $act['time_of_day'] ?? '' ) ); ?></td>
                        <td><code><?php echo esc_html( ( $act['scene_type'] ?? '' ) . '-' . ( $act['scene_number'] ?? '' ) ); ?></code></td>
                        <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html( $act['postcard_message'] ?? '' ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=fishotel-hotel-program&tab=activities&edit_act=' . $act['id'] ) ); ?>">Edit</a>
                            &nbsp;|&nbsp;
                            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fishotel_hotel_delete_activity&act_id=' . $act['id'] ), 'fishotel_hotel_delete_activity' ) ); ?>"
                               onclick="return confirm('Delete this activity?')" style="color:#a00;">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h3 style="margin-top:24px;"><?php echo $editing ? 'Edit Activity' : 'Add Activity'; ?></h3>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:600px;">
            <?php wp_nonce_field( 'fishotel_hotel_save_activity' ); ?>
            <input type="hidden" name="action" value="fishotel_hotel_save_activity">
            <input type="hidden" name="act_id" value="<?php echo esc_attr( $editing['id'] ?? '' ); ?>">
            <table class="form-table">
                <tr><th><label>Activity Name</label></th>
                    <td><input type="text" name="act_name" value="<?php echo esc_attr( $editing['name'] ?? '' ); ?>" class="regular-text" required></td></tr>
                <tr><th><label>Category</label></th>
                    <td><select name="act_category_id" required>
                        <option value="">— select —</option>
                        <?php foreach ( $categories as $c ) : ?>
                            <option value="<?php echo esc_attr( $c['id'] ); ?>" <?php selected( ( $editing['category_id'] ?? '' ), $c['id'] ); ?>><?php echo esc_html( $c['name'] ); ?></option>
                        <?php endforeach; ?>
                    </select></td></tr>
                <tr><th><label>Time of Day</label></th>
                    <td><select name="act_time_of_day">
                        <?php foreach ( $times as $k => $v ) : ?>
                            <option value="<?php echo esc_attr( $k ); ?>" <?php selected( ( $editing['time_of_day'] ?? '' ), $k ); ?>><?php echo esc_html( $v ); ?></option>
                        <?php endforeach; ?>
                    </select></td></tr>
                <tr><th><label>Scene Type</label></th>
                    <td><select name="act_scene_type" id="fh-hotel-scene-type" onchange="fishotelHotelPreviewScene()">
                        <?php foreach ( $scene_types as $st ) : ?>
                            <option value="<?php echo esc_attr( $st ); ?>" <?php selected( ( $editing['scene_type'] ?? '' ), $st ); ?>><?php echo esc_html( ucfirst( $st ) ); ?></option>
                        <?php endforeach; ?>
                    </select></td></tr>
                <tr><th><label>Scene Number</label></th>
                    <td><input type="text" name="act_scene_number" id="fh-hotel-scene-num" value="<?php echo esc_attr( $editing['scene_number'] ?? '01' ); ?>" style="width:60px;" oninput="fishotelHotelPreviewScene()">
                        <p class="description">Two-digit number matching the scene filename (e.g. 01, 03).</p></td></tr>
                <tr><th><label>Scene Preview</label></th>
                    <td><div id="fh-hotel-scene-preview" style="width:200px;height:133px;border:1px solid #ccc;background:#f5f0e8;display:flex;align-items:center;justify-content:center;color:#999;font-size:12px;overflow:hidden;">Loading...</div></td></tr>
                <tr><th><label>Postcard Message</label></th>
                    <td><textarea name="act_postcard_message" rows="3" class="large-text"><?php echo esc_textarea( $editing['postcard_message'] ?? '' ); ?></textarea>
                        <p class="description">Written in hotel concierge voice. Shown on the back of the customer postcard.</p></td></tr>
                <tr><th><label>Postmark City</label></th>
                    <td><input type="text" name="act_postmark_city" value="<?php echo esc_attr( $editing['postmark_city'] ?? 'CHAMPLIN, MN' ); ?>" class="regular-text"></td></tr>
                <tr><th><label>Description</label></th>
                    <td><input type="text" name="act_description" value="<?php echo esc_attr( $editing['description'] ?? '' ); ?>" class="regular-text">
                        <p class="description">Admin reference only — not shown to customers.</p></td></tr>
            </table>
            <?php submit_button( $editing ? 'Update Activity' : 'Add Activity' ); ?>
        </form>

        <script>
        var fishotelHotelSceneBase = <?php echo wp_json_encode( $plugin_base_url ); ?>;
        function fishotelHotelPreviewScene() {
            var type = document.getElementById('fh-hotel-scene-type').value;
            var num  = document.getElementById('fh-hotel-scene-num').value.padStart(2, '0');
            var url  = fishotelHotelSceneBase + 'hotel-' + type + '-scene-' + num + '.jpg';
            var el   = document.getElementById('fh-hotel-scene-preview');
            var img  = new Image();
            img.onload = function() { el.innerHTML = ''; el.style.backgroundImage = 'url(' + url + ')'; el.style.backgroundSize = 'cover'; el.style.backgroundPosition = 'center'; };
            img.onerror = function() { el.style.backgroundImage = 'none'; el.innerHTML = '<span style="color:#999;font-size:12px;">No image found for that filename.</span>'; };
            img.src = url;
        }
        fishotelHotelPreviewScene();
        </script>
        <?php
    }

    /* ─────────────────────────────────────────────
     *  TAB: Schedule
     * ───────────────────────────────────────────── */

    private function hotel_tab_schedule() {
        $statuses = get_option( 'fishotel_batch_statuses', [] );
        $batches  = array_filter( array_map( 'trim', explode( "\n", get_option( 'fishotel_batches', '' ) ) ) );

        $selected = sanitize_text_field( $_GET['batch'] ?? '' );
        if ( ! $selected && ! empty( $batches ) ) {
            // Default to first graduation batch, or first batch
            foreach ( $batches as $b ) {
                if ( ( $statuses[ $b ] ?? '' ) === 'in_quarantine' ) { $selected = $b; break; }
            }
            if ( ! $selected ) $selected = $batches[0];
        }

        if ( isset( $_GET['schedule_saved'] ) ) echo '<div class="notice notice-success"><p>Schedule saved.</p></div>';
        if ( isset( $_GET['schedule_init'] ) ) echo '<div class="notice notice-success"><p>Schedule initialized.</p></div>';
        if ( isset( $_GET['grad_saved'] ) ) echo '<div class="notice notice-success"><p>Graduation date updated.</p></div>';

        $valid_stages = [
            'open_ordering' => 'Open Ordering', 'orders_closed' => 'Orders Closed',
            'arrived' => 'Arrived', 'in_quarantine' => 'In QT',
            'graduation' => 'Graduation', 'verification' => 'Verification',
            'draft' => 'Draft', 'invoicing' => 'Invoicing',
        ];
        ?>
        <form method="get" style="margin-bottom:16px;">
            <input type="hidden" name="page" value="fishotel-hotel-program">
            <input type="hidden" name="tab" value="schedule">
            <label><strong>Batch:</strong>&nbsp;
                <select name="batch" onchange="this.form.submit()">
                    <?php foreach ( $batches as $b ) :
                        $st = $statuses[ $b ] ?? '';
                        $badge = isset( $valid_stages[ $st ] ) ? ' [' . $valid_stages[ $st ] . ']' : '';
                    ?>
                        <option value="<?php echo esc_attr( $b ); ?>" <?php selected( $selected, $b ); ?>><?php echo esc_html( $b . $badge ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>

        <?php
        if ( ! $selected ) {
            echo '<p style="color:#aaa;">No batches found.</p>';
            return;
        }

        $schedule = $this->hotel_get_schedule( $selected );

        if ( empty( $schedule ) ) {
            ?>
            <div style="background:#f0f0f1;border:1px solid #c3c4c7;padding:20px 24px;max-width:500px;border-radius:4px;">
                <p style="margin-top:0;">Stage 4 has not been initialized for <strong><?php echo esc_html( $selected ); ?></strong>.</p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'fishotel_hotel_init_schedule' ); ?>
                    <input type="hidden" name="action" value="fishotel_hotel_init_schedule">
                    <input type="hidden" name="batch_name" value="<?php echo esc_attr( $selected ); ?>">
                    <?php submit_button( 'Initialize Schedule', 'primary', 'submit', false ); ?>
                </form>
            </div>
            <?php
            return;
        }

        $start_date = $schedule['stage4_start'] ?? current_time( 'Y-m-d' );
        $grad_date  = $schedule['graduation_date'] ?? '';
        $days       = $schedule['days'] ?? [];
        $categories = $this->hotel_get_categories();
        $activities = $this->hotel_get_activities();

        // Build calendar grid
        $start_ts    = strtotime( $start_date );
        $start_dow   = (int) date( 'N', $start_ts ); // 1=Mon … 7=Sun
        $day_labels  = [ 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun' ];
        ?>

        <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;">
            <!-- Left: schedule info -->
            <div style="flex:0 0 280px;">
                <p><strong>Stage 4 Start:</strong> <?php echo esc_html( date_i18n( 'M j, Y', $start_ts ) ); ?></p>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:16px;">
                    <?php wp_nonce_field( 'fishotel_hotel_save_graduation' ); ?>
                    <input type="hidden" name="action" value="fishotel_hotel_save_graduation">
                    <input type="hidden" name="batch_name" value="<?php echo esc_attr( $selected ); ?>">
                    <label><strong>Graduation Date:</strong><br>
                        <input type="date" name="graduation_date" value="<?php echo esc_attr( $grad_date ); ?>"
                               min="<?php echo esc_attr( date( 'Y-m-d', strtotime( $start_date . ' +1 day' ) ) ); ?>"
                               max="<?php echo esc_attr( date( 'Y-m-d', strtotime( $start_date . ' +21 days' ) ) ); ?>">
                    </label>
                    <p class="description">QT ends on weekdays only.</p>
                    <?php submit_button( 'Set Graduation Date', 'secondary', 'submit', false ); ?>
                </form>
            </div>

            <!-- Right: calendar grid -->
            <div style="flex:1;min-width:0;overflow-x:auto;">
                <table class="widefat" style="min-width:630px;border-collapse:separate;border-spacing:2px;" id="fh-hotel-schedule-grid">
                    <thead>
                        <tr>
                            <?php foreach ( $day_labels as $dl ) : ?>
                                <th style="text-align:center;padding:6px;font-size:12px;width:14.28%;"><?php echo $dl; ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $col = 1;
                        echo '<tr>';
                        // Blank cells before Day 1
                        for ( $blank = 1; $blank < $start_dow; $blank++ ) {
                            echo '<td style="background:#f9f9f9;"></td>';
                            $col++;
                        }

                        for ( $d = 1; $d <= 21; $d++ ) {
                            if ( $col > 7 ) {
                                echo '</tr><tr>';
                                $col = 1;
                            }
                            $cell_date   = date( 'Y-m-d', strtotime( $start_date . ' +' . ( $d - 1 ) . ' days' ) );
                            $cell_dow    = (int) date( 'N', strtotime( $cell_date ) );
                            $is_weekend  = ( $cell_dow >= 6 );
                            $slot        = $days[ $d ] ?? [ 'assignment_type' => 'random' ];
                            $type        = $slot['assignment_type'] ?? 'random';
                            $is_grad_day = ( $grad_date && $cell_date === $grad_date );
                            $is_locked   = ( $d === 1 || $is_grad_day );

                            // Chip display
                            $chip_label = 'RANDOM';
                            $chip_color = '#888';
                            if ( $type === 'first_day' ) {
                                $chip_label = 'FIRST DAY';
                                $chip_color = '#1a3a5c';
                            } elseif ( $type === 'graduation' || $is_grad_day ) {
                                $chip_label = 'GRADUATION';
                                $chip_color = '#96885f';
                            } elseif ( $type === 'category' && ! empty( $slot['category_id'] ) ) {
                                $cat = $this->hotel_get_category_by_id( $slot['category_id'] );
                                if ( $cat ) { $chip_label = $cat['label']; $chip_color = $cat['color']; }
                            } elseif ( $type === 'activity' && ! empty( $slot['activity_id'] ) ) {
                                $act = $this->hotel_get_activity_by_id( $slot['activity_id'] );
                                if ( $act ) {
                                    $chip_label = substr( $act['name'], 0, 12 );
                                    $cat = $this->hotel_get_category_by_id( $act['category_id'] );
                                    if ( $cat ) $chip_color = $cat['color'];
                                }
                            }

                            $bg = $is_weekend ? '#f5f5f5' : '#fff';
                            $border = 'border:1px solid #ddd;';
                            ?>
                            <td style="<?php echo $border; ?>background:<?php echo $bg; ?>;padding:6px;vertical-align:top;cursor:<?php echo $is_locked ? 'default' : 'pointer'; ?>;min-height:70px;"
                                <?php if ( ! $is_locked ) : ?>onclick="fishotelHotelEditDay(<?php echo $d; ?>)"<?php endif; ?>>
                                <div style="font-size:10px;color:#999;"><?php echo esc_html( date( 'M j', strtotime( $cell_date ) ) ); ?></div>
                                <div style="font-size:12px;font-weight:600;margin:2px 0;">Day <?php echo $d; ?></div>
                                <span style="display:inline-block;padding:2px 6px;border-radius:3px;background:<?php echo esc_attr( $chip_color ); ?>;color:#fff;font-size:10px;font-weight:600;"><?php echo esc_html( $chip_label ); ?></span>
                                <?php if ( $is_locked ) : ?>
                                    <div style="font-size:9px;color:#bbb;margin-top:2px;">locked</div>
                                <?php endif; ?>
                            </td>
                            <?php
                            $col++;
                        }
                        // Fill remaining cells
                        while ( $col <= 7 ) {
                            echo '<td style="background:#f9f9f9;"></td>';
                            $col++;
                        }
                        echo '</tr>';
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Inline editor -->
        <div id="fh-hotel-day-editor" style="display:none;margin-top:16px;padding:16px 20px;background:#f0f0f1;border:1px solid #c3c4c7;border-radius:4px;max-width:600px;">
            <h4 style="margin-top:0;">Edit Day <span id="fh-hotel-day-num"></span></h4>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'fishotel_hotel_save_schedule' ); ?>
                <input type="hidden" name="action" value="fishotel_hotel_save_schedule">
                <input type="hidden" name="batch_name" value="<?php echo esc_attr( $selected ); ?>">
                <input type="hidden" name="day_number" id="fh-hotel-day-input" value="">

                <fieldset style="margin-bottom:12px;">
                    <label style="display:block;margin:4px 0;"><input type="radio" name="assignment_type" value="random" checked onchange="fishotelHotelToggleAssign()"> Random</label>
                    <label style="display:block;margin:4px 0;"><input type="radio" name="assignment_type" value="category" onchange="fishotelHotelToggleAssign()"> Category</label>
                    <label style="display:block;margin:4px 0;"><input type="radio" name="assignment_type" value="activity" onchange="fishotelHotelToggleAssign()"> Specific Activity</label>
                </fieldset>

                <div id="fh-hotel-assign-category" style="display:none;margin-bottom:12px;">
                    <select name="category_id">
                        <?php foreach ( $categories as $c ) : ?>
                            <option value="<?php echo esc_attr( $c['id'] ); ?>"><?php echo esc_html( $c['name'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="fh-hotel-assign-activity" style="display:none;margin-bottom:12px;">
                    <select name="activity_id">
                        <?php
                        $grouped = [];
                        foreach ( $activities as $a ) {
                            $cat = $this->hotel_get_category_by_id( $a['category_id'] );
                            $group = $cat ? $cat['name'] : 'Uncategorized';
                            $grouped[ $group ][] = $a;
                        }
                        foreach ( $grouped as $group => $acts ) :
                        ?>
                            <optgroup label="<?php echo esc_attr( $group ); ?>">
                                <?php foreach ( $acts as $a ) : ?>
                                    <option value="<?php echo esc_attr( $a['id'] ); ?>"><?php echo esc_html( $a['name'] ); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php submit_button( 'Save Day', 'primary', 'submit', false ); ?>
                <button type="button" class="button" onclick="document.getElementById('fh-hotel-day-editor').style.display='none'">Cancel</button>
            </form>
        </div>

        <script>
        function fishotelHotelEditDay(dayNum) {
            var editor = document.getElementById('fh-hotel-day-editor');
            editor.style.display = 'block';
            document.getElementById('fh-hotel-day-num').textContent = dayNum;
            document.getElementById('fh-hotel-day-input').value = dayNum;
            // Reset to random
            var radios = editor.querySelectorAll('input[name="assignment_type"]');
            radios[0].checked = true;
            fishotelHotelToggleAssign();
            editor.scrollIntoView({behavior:'smooth', block:'nearest'});
        }
        function fishotelHotelToggleAssign() {
            var val = document.querySelector('input[name="assignment_type"]:checked').value;
            document.getElementById('fh-hotel-assign-category').style.display = (val === 'category') ? 'block' : 'none';
            document.getElementById('fh-hotel-assign-activity').style.display = (val === 'activity') ? 'block' : 'none';
        }
        </script>
        <?php
    }

    /* ─────────────────────────────────────────────
     *  ADMIN POST HANDLERS
     * ───────────────────────────────────────────── */

    public function hotel_save_category() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'fishotel_hotel_save_category' ) ) {
            wp_die( 'Security check failed.' );
        }
        $cats   = $this->hotel_get_categories();
        $id     = sanitize_text_field( $_POST['cat_id'] ?? '' );
        $data   = [
            'id'             => $id ?: 'cat_' . uniqid(),
            'name'           => sanitize_text_field( $_POST['cat_name'] ?? '' ),
            'label'          => strtoupper( sanitize_text_field( $_POST['cat_label'] ?? '' ) ),
            'color'          => sanitize_hex_color( $_POST['cat_color'] ?? '#2a7fba' ),
            'exclude_random' => ! empty( $_POST['cat_exclude_random'] ),
        ];

        if ( $id ) {
            foreach ( $cats as &$c ) {
                if ( $c['id'] === $id ) { $c = $data; break; }
            }
            unset( $c );
        } else {
            $cats[] = $data;
        }

        update_option( 'fishotel_hotel_categories', $cats );
        wp_redirect( admin_url( 'admin.php?page=fishotel-hotel-program&tab=categories&cat_saved=1' ) );
        exit;
    }

    public function hotel_delete_category() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'fishotel_hotel_delete_category' ) ) {
            wp_die( 'Security check failed.' );
        }
        $cat_id     = sanitize_text_field( $_GET['cat_id'] ?? '' );
        $activities = $this->hotel_get_activities();
        $has_acts   = false;
        foreach ( $activities as $a ) {
            if ( $a['category_id'] === $cat_id ) { $has_acts = true; break; }
        }
        if ( $has_acts ) {
            wp_redirect( admin_url( 'admin.php?page=fishotel-hotel-program&tab=categories&cat_error=' . urlencode( 'Cannot delete — category has activities assigned.' ) ) );
            exit;
        }
        $cats = array_values( array_filter( $this->hotel_get_categories(), function( $c ) use ( $cat_id ) {
            return $c['id'] !== $cat_id;
        } ) );
        update_option( 'fishotel_hotel_categories', $cats );
        wp_redirect( admin_url( 'admin.php?page=fishotel-hotel-program&tab=categories&cat_deleted=1' ) );
        exit;
    }

    public function hotel_save_activity() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'fishotel_hotel_save_activity' ) ) {
            wp_die( 'Security check failed.' );
        }
        $acts = $this->hotel_get_activities();
        $id   = sanitize_text_field( $_POST['act_id'] ?? '' );
        $data = [
            'id'               => $id ?: 'act_' . uniqid(),
            'name'             => sanitize_text_field( $_POST['act_name'] ?? '' ),
            'category_id'      => sanitize_text_field( $_POST['act_category_id'] ?? '' ),
            'time_of_day'      => sanitize_key( $_POST['act_time_of_day'] ?? 'morning' ),
            'scene_type'       => sanitize_key( $_POST['act_scene_type'] ?? 'pool' ),
            'scene_number'     => str_pad( absint( $_POST['act_scene_number'] ?? 1 ), 2, '0', STR_PAD_LEFT ),
            'postcard_message' => sanitize_textarea_field( $_POST['act_postcard_message'] ?? '' ),
            'postmark_city'    => strtoupper( sanitize_text_field( $_POST['act_postmark_city'] ?? 'CHAMPLIN, MN' ) ),
            'description'      => sanitize_text_field( $_POST['act_description'] ?? '' ),
        ];

        if ( $id ) {
            foreach ( $acts as &$a ) {
                if ( $a['id'] === $id ) { $a = $data; break; }
            }
            unset( $a );
        } else {
            $acts[] = $data;
        }

        update_option( 'fishotel_hotel_activities', $acts );
        wp_redirect( admin_url( 'admin.php?page=fishotel-hotel-program&tab=activities&act_saved=1' ) );
        exit;
    }

    public function hotel_delete_activity() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'fishotel_hotel_delete_activity' ) ) {
            wp_die( 'Security check failed.' );
        }
        $act_id = sanitize_text_field( $_GET['act_id'] ?? '' );
        $acts   = array_values( array_filter( $this->hotel_get_activities(), function( $a ) use ( $act_id ) {
            return $a['id'] !== $act_id;
        } ) );
        update_option( 'fishotel_hotel_activities', $acts );
        wp_redirect( admin_url( 'admin.php?page=fishotel-hotel-program&tab=activities&act_deleted=1' ) );
        exit;
    }

    public function hotel_init_schedule_handler() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'fishotel_hotel_init_schedule' ) ) {
            wp_die( 'Security check failed.' );
        }
        $batch = sanitize_text_field( $_POST['batch_name'] ?? '' );
        if ( $batch ) {
            $this->hotel_create_default_schedule( $batch );
        }
        wp_redirect( admin_url( 'admin.php?page=fishotel-hotel-program&tab=schedule&batch=' . urlencode( $batch ) . '&schedule_init=1' ) );
        exit;
    }

    public function hotel_save_graduation_handler() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'fishotel_hotel_save_graduation' ) ) {
            wp_die( 'Security check failed.' );
        }
        $batch    = sanitize_text_field( $_POST['batch_name'] ?? '' );
        $new_grad = sanitize_text_field( $_POST['graduation_date'] ?? '' );
        $schedule = $this->hotel_get_schedule( $batch );
        if ( empty( $schedule ) ) { wp_die( 'No schedule found.' ); }

        $start  = $schedule['stage4_start'];
        $old_grad = $schedule['graduation_date'] ?? '';

        // Revert old graduation day to random
        if ( $old_grad ) {
            $old_day = (int) floor( ( strtotime( $old_grad ) - strtotime( $start ) ) / 86400 ) + 1;
            if ( isset( $schedule['days'][ $old_day ] ) && $schedule['days'][ $old_day ]['assignment_type'] === 'graduation' ) {
                $schedule['days'][ $old_day ]['assignment_type'] = 'random';
                $schedule['days'][ $old_day ]['category_id'] = null;
                $schedule['days'][ $old_day ]['activity_id'] = null;
            }
        }

        // Set new graduation day
        $schedule['graduation_date'] = $new_grad;
        if ( $new_grad ) {
            $new_day = (int) floor( ( strtotime( $new_grad ) - strtotime( $start ) ) / 86400 ) + 1;
            if ( $new_day >= 1 && $new_day <= 21 ) {
                $schedule['days'][ $new_day ] = [
                    'assignment_type' => 'graduation',
                    'category_id'     => null,
                    'activity_id'     => null,
                ];
            }
        }

        update_option( $this->hotel_schedule_option_key( $batch ), $schedule );
        wp_redirect( admin_url( 'admin.php?page=fishotel-hotel-program&tab=schedule&batch=' . urlencode( $batch ) . '&grad_saved=1' ) );
        exit;
    }

    public function hotel_save_schedule() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'fishotel_hotel_save_schedule' ) ) {
            wp_die( 'Security check failed.' );
        }
        $batch    = sanitize_text_field( $_POST['batch_name'] ?? '' );
        $day_num  = intval( $_POST['day_number'] ?? 0 );
        $schedule = $this->hotel_get_schedule( $batch );
        if ( empty( $schedule ) || $day_num < 2 || $day_num > 21 ) { wp_die( 'Invalid parameters.' ); }

        $assign = sanitize_key( $_POST['assignment_type'] ?? 'random' );
        $slot   = [
            'assignment_type' => $assign,
            'category_id'     => ( $assign === 'category' ) ? sanitize_text_field( $_POST['category_id'] ?? '' ) : null,
            'activity_id'     => ( $assign === 'activity' ) ? sanitize_text_field( $_POST['activity_id'] ?? '' ) : null,
        ];

        $schedule['days'][ $day_num ] = $slot;
        update_option( $this->hotel_schedule_option_key( $batch ), $schedule );
        wp_redirect( admin_url( 'admin.php?page=fishotel-hotel-program&tab=schedule&batch=' . urlencode( $batch ) . '&schedule_saved=1' ) );
        exit;
    }

    /* ─────────────────────────────────────────────
     *  TAB: Layers (Layer Designer)
     * ───────────────────────────────────────────── */

    private function hotel_tab_layers() {
        $this->hotel_seed_layer_defaults();
        $all_configs = get_option( 'fishotel_layer_configs', [] );
        $scene_types = $this->hotel_get_scene_types();

        // Scan assists/scene/ for background images and build scene slugs per category
        $scene_dir    = plugin_dir_path( FISHOTEL_PLUGIN_FILE ) . 'assists/scene/';
        $scene_slugs  = []; // category => [ 'pool-scene-01', 'pool-scene-02', ... ]
        foreach ( $scene_types as $st ) {
            $scene_slugs[ $st ] = [];
        }
        if ( is_dir( $scene_dir ) ) {
            foreach ( glob( $scene_dir . 'hotel-*-scene-*.jpg' ) as $f ) {
                $bn = basename( $f, '.jpg' );
                // Parse: hotel-{type}-scene-{num}[-{band}]
                if ( preg_match( '/^hotel-(.+)-scene-(\d+)/', $bn, $m ) ) {
                    $cat  = $m[1];
                    $num  = $m[2];
                    $slug = $cat . '-scene-' . $num;
                    if ( isset( $scene_slugs[ $cat ] ) && ! in_array( $slug, $scene_slugs[ $cat ], true ) ) {
                        $scene_slugs[ $cat ][] = $slug;
                    }
                }
            }
        }
        foreach ( $scene_slugs as &$slugs ) {
            sort( $slugs );
        }
        unset( $slugs );

        // Also include any slugs that have layer configs but no background yet
        foreach ( $all_configs as $key => $layers_arr ) {
            if ( strpos( $key, '-scene-' ) !== false && ! empty( $layers_arr ) ) {
                $parts = explode( '-scene-', $key );
                $cat = $parts[0];
                if ( isset( $scene_slugs[ $cat ] ) && ! in_array( $key, $scene_slugs[ $cat ], true ) ) {
                    $scene_slugs[ $cat ][] = $key;
                    sort( $scene_slugs[ $cat ] );
                }
            }
        }

        $current_cat  = sanitize_key( $_GET['scene_type'] ?? $scene_types[0] ?? 'pool' );
        if ( ! in_array( $current_cat, $scene_types, true ) ) $current_cat = $scene_types[0] ?? 'pool';
        $current_slug = sanitize_text_field( $_GET['scene_slug'] ?? '' );
        if ( $current_slug && strpos( $current_slug, $current_cat ) !== 0 ) $current_slug = '';
        if ( ! $current_slug && ! empty( $scene_slugs[ $current_cat ] ) ) {
            $current_slug = $scene_slugs[ $current_cat ][0];
        }
        $layers = $current_slug ? ( $all_configs[ $current_slug ] ?? [] ) : [];

        $assets_dir  = plugin_dir_path( FISHOTEL_PLUGIN_FILE ) . 'assists/scene-layers/';
        $assets_url  = plugins_url( 'assists/scene-layers/', FISHOTEL_PLUGIN_FILE );
        $assets      = [];
        if ( is_dir( $assets_dir ) ) {
            foreach ( glob( $assets_dir . '*.png' ) as $f ) {
                $assets[] = basename( $f );
            }
            sort( $assets );
        }

        $blend_modes = [ 'normal', 'screen', 'overlay', 'multiply', 'soft-light', 'hard-light', 'lighten', 'color-dodge' ];
        $animations  = [ 'none', 'drift-left-right', 'drift-up', 'sway', 'shimmer', 'pulse', 'float' ];
        $time_bands  = [ 'morning', 'afternoon', 'sunset', 'night', 'all' ];

        $nonce       = wp_create_nonce( 'fishotel_layer_admin' );
        $asset_nonce = wp_create_nonce( 'fishotel_asset_library' );
        ?>
        <style>
        .fh-layer-scene-tabs{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:20px}
        .fh-layer-scene-tabs a{padding:6px 14px;background:#f0f0f0;border:1px solid #ccc;border-radius:4px;text-decoration:none;color:#333;font-size:13px;text-transform:uppercase;letter-spacing:0.05em}
        .fh-layer-scene-tabs a.active{background:#1a3a5c;color:#fff;border-color:#1a3a5c}
        .fh-layer-card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:14px 16px;margin-bottom:10px;display:flex;align-items:center;gap:14px;position:relative}
        .fh-layer-thumb{width:60px;height:60px;border-radius:4px;background:#f0f0f0;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;border:1px solid #e0e0e0}
        .fh-layer-thumb img{width:100%;height:100%;object-fit:cover}
        .fh-layer-thumb-placeholder{font-size:10px;color:#999;text-align:center;word-break:break-all;padding:4px}
        .fh-layer-info{flex:1;min-width:0}
        .fh-layer-label{font-weight:600;font-size:14px;color:#1a3a5c}
        .fh-layer-summary{font-size:12px;color:#888;margin-top:2px}
        .fh-layer-actions{display:flex;gap:6px;align-items:center;flex-shrink:0}
        .fh-layer-actions button{background:none;border:none;cursor:pointer;padding:4px;color:#666;font-size:16px}
        .fh-layer-actions button:hover{color:#1a3a5c}
        .fh-layer-edit-form{background:#f9f9f9;border:1px solid #e0e0e0;border-radius:6px;padding:16px 20px;margin:8px 0 10px;display:none}
        .fh-layer-edit-form.open{display:block}
        .fh-layer-edit-form table.form-table th{width:160px;padding:8px 10px 8px 0;font-size:13px}
        .fh-layer-edit-form table.form-table td{padding:6px 0}
        .fh-layer-edit-form .regular-text{width:220px}
        .fh-layer-edit-form .small-text{width:80px}
        .fh-layer-show-on label{margin-right:10px;font-size:12px}
        .fh-asset-grid{display:flex;flex-wrap:wrap;gap:10px;margin-top:12px}
        .fh-asset-item{width:90px;text-align:center;background:#fff;border:1px solid #ddd;border-radius:6px;padding:8px 4px;position:relative}
        .fh-asset-item img{width:60px;height:60px;object-fit:cover;border-radius:3px}
        .fh-asset-item .fh-asset-name{font-size:10px;color:#666;margin-top:4px;word-break:break-all}
        .fh-asset-item .fh-asset-del{position:absolute;top:2px;right:4px;background:none;border:none;color:#a00;cursor:pointer;font-size:14px}
        .fh-layer-notice{padding:8px 14px;border-radius:4px;margin-bottom:12px;display:none;font-size:13px}
        .fh-layer-notice.success{display:block;background:#ecf7ed;border:1px solid #46b450;color:#2e7d32}
        .fh-layer-notice.error{display:block;background:#fbeaea;border:1px solid #dc3232;color:#8b0000}
        .fh-st-manager{background:#f9f9f9;border:1px solid #e0e0e0;border-radius:6px;padding:14px 18px;margin-bottom:18px;display:none}
        .fh-st-manager.open{display:block}
        .fh-st-toggle{font-size:12px;color:#1a3a5c;cursor:pointer;margin-bottom:10px;display:inline-block}
        .fh-st-toggle:hover{text-decoration:underline}
        .fh-st-list{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px}
        .fh-st-tag{display:inline-flex;align-items:center;gap:4px;background:#e8f0f8;color:#1a3a5c;padding:4px 10px;border-radius:14px;font-size:12px}
        .fh-st-tag button{background:none;border:none;color:#a00;cursor:pointer;font-size:14px;padding:0 2px;line-height:1}
        .fh-st-tag button:hover{color:#d00}
        .fh-st-add{display:flex;gap:6px;align-items:center;margin-bottom:10px}
        .fh-st-add input{padding:5px 8px;border:1px solid #ccc;border-radius:4px;font-size:12px;width:180px}
        </style>

        <!-- Scene Types Manager -->
        <a class="fh-st-toggle" onclick="this.nextElementSibling.classList.toggle('open');this.textContent=this.nextElementSibling.classList.contains('open')?'▾ Hide Scene Types':'▸ Manage Scene Types'">▸ Manage Scene Types</a>
        <div class="fh-st-manager" id="fh-st-manager" data-nonce="<?php echo esc_attr( wp_create_nonce( 'fishotel_scene_types' ) ); ?>">
            <div class="fh-st-list" id="fh-st-list">
                <?php foreach ( $scene_types as $st ) : ?>
                    <span class="fh-st-tag" data-type="<?php echo esc_attr( $st ); ?>">
                        <?php echo esc_html( $st ); ?>
                        <button type="button" onclick="fhStRemove(this)" title="Remove">&times;</button>
                    </span>
                <?php endforeach; ?>
            </div>
            <div class="fh-st-add">
                <input type="text" id="fh-st-new" placeholder="new-scene-type" pattern="[a-z0-9\-]+">
                <button type="button" class="button button-small" onclick="fhStAdd()">Add Scene Type</button>
            </div>
            <button type="button" class="button button-primary button-small" onclick="fhStSave()">Save Scene Types</button>
            <span id="fh-st-status" style="margin-left:10px;font-size:12px;color:#46b450;display:none;"></span>
        </div>

        <div class="fh-layer-scene-tabs">
            <?php foreach ( $scene_types as $st ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=fishotel-hotel-program&tab=layers&scene_type=' . $st ) ); ?>"
                   class="<?php echo $st === $current_cat ? 'active' : ''; ?>"><?php echo esc_html( ucfirst( $st ) ); ?></a>
            <?php endforeach; ?>
        </div>

        <?php if ( empty( $scene_slugs[ $current_cat ] ) ) : ?>
            <p style="color:#999;">No scene backgrounds uploaded yet for <strong><?php echo esc_html( ucfirst( $current_cat ) ); ?></strong>. Upload backgrounds in the <a href="<?php echo esc_url( admin_url( 'admin.php?page=fishotel-hotel-program&tab=assets' ) ); ?>">Assets tab</a> first.</p>
        <?php else : ?>

        <div class="fh-layer-scene-tabs" style="margin-bottom:14px;">
            <?php foreach ( $scene_slugs[ $current_cat ] as $slug ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=fishotel-hotel-program&tab=layers&scene_type=' . $current_cat . '&scene_slug=' . $slug ) ); ?>"
                   class="<?php echo $slug === $current_slug ? 'active' : ''; ?>" style="font-size:12px;padding:4px 10px;"><?php echo esc_html( $slug ); ?></a>
            <?php endforeach; ?>
        </div>

        <div id="fh-layer-notice" class="fh-layer-notice"></div>

        <h3 style="margin-top:0;">Layers for <em><?php echo esc_html( $current_slug ); ?></em></h3>

        <div id="fh-layer-list" data-scene-type="<?php echo esc_attr( $current_slug ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
        <?php if ( empty( $layers ) ) : ?>
            <p id="fh-layer-empty" style="color:#999;">No layers configured for this scene type. Click "Add Layer" to create one.</p>
        <?php else : ?>
            <p id="fh-layer-empty" style="color:#999;display:none;">No layers configured for this scene type. Click "Add Layer" to create one.</p>
        <?php endif; ?>
        <?php foreach ( $layers as $idx => $L ) :
            $asset_exists = ! empty( $L['asset'] ) && file_exists( $assets_dir . $L['asset'] );
            $show_on_str  = implode( ', ', $L['show_on'] ?? [] );
        ?>
            <div class="fh-layer-card" data-index="<?php echo $idx; ?>">
                <div class="fh-layer-thumb">
                    <?php if ( $asset_exists ) : ?>
                        <img src="<?php echo esc_url( $assets_url . $L['asset'] ); ?>" alt="">
                    <?php else : ?>
                        <div class="fh-layer-thumb-placeholder"><?php echo esc_html( $L['asset'] ?? '—' ); ?></div>
                    <?php endif; ?>
                </div>
                <div class="fh-layer-info">
                    <div class="fh-layer-label"><?php echo esc_html( $L['label'] ?? $L['asset'] ?? 'Untitled' ); ?></div>
                    <div class="fh-layer-summary">
                        <?php echo esc_html( ( $L['animation'] ?? 'none' ) . ' @ ' . ( $L['speed'] ?? '10' ) . 's | blend:' . ( $L['blend'] ?? 'normal' ) . ' | opacity:' . ( $L['opacity'] ?? '1' ) . ' | show:' . $show_on_str ); ?>
                    </div>
                </div>
                <div class="fh-layer-actions">
                    <button type="button" onclick="fhLayerMove(this,-1)" title="Move up"><span class="dashicons dashicons-arrow-up-alt2"></span></button>
                    <button type="button" onclick="fhLayerMove(this,1)" title="Move down"><span class="dashicons dashicons-arrow-down-alt2"></span></button>
                    <button type="button" onclick="fhLayerToggleEdit(this)" title="Edit"><span class="dashicons dashicons-edit"></span></button>
                    <button type="button" onclick="fhLayerDelete(this)" title="Delete" style="color:#a00"><span class="dashicons dashicons-trash"></span></button>
                </div>
            </div>
            <?php echo $this->hotel_layer_edit_form_html( $L, $idx, $assets, $blend_modes, $animations, $time_bands ); ?>
        <?php endforeach; ?>
        </div>

        <p><button type="button" class="button button-primary" onclick="fhLayerAdd()"><span class="dashicons dashicons-plus-alt2" style="margin-top:3px;"></span> Add Layer</button></p>

        <p><button type="button" class="button button-primary" onclick="fhLayerSaveAll()" style="margin-top:8px;">Save Layer Config</button></p>

        <!-- Hidden template for new layers -->
        <script type="text/html" id="fh-layer-edit-template">
        <?php echo $this->hotel_layer_edit_form_html( [], '__INDEX__', $assets, $blend_modes, $animations, $time_bands ); ?>
        </script>

        <hr style="margin:30px 0;">
        <p style="color:#666;font-size:13px;">To upload and manage assets, visit the <a href="<?php echo esc_url( admin_url( 'admin.php?page=fishotel-hotel-program&tab=assets' ) ); ?>">Assets tab</a>.</p>

        <script>
        (function(){
            var ajaxurl    = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
            var nonce      = '<?php echo esc_js( $nonce ); ?>';
            var assetNonce = '<?php echo esc_js( $asset_nonce ); ?>';
            var sceneType  = '<?php echo esc_js( $current_slug ); ?>';

            function notice(msg, type) {
                var el = document.getElementById('fh-layer-notice');
                el.textContent = msg;
                el.className = 'fh-layer-notice ' + type;
                setTimeout(function(){ el.className = 'fh-layer-notice'; }, 4000);
            }

            function collectLayers() {
                var list = document.getElementById('fh-layer-list');
                var forms = list.querySelectorAll('.fh-layer-edit-form');
                var layers = [];
                for (var i = 0; i < forms.length; i++) {
                    var f = forms[i];
                    var showOn = [];
                    f.querySelectorAll('.fh-show-on-cb:checked').forEach(function(cb){ showOn.push(cb.value); });
                    layers.push({
                        id:        f.querySelector('[name=layer_id]').value || ('layer_' + Date.now() + '_' + i),
                        asset:     f.querySelector('[name=layer_asset]').value,
                        label:     f.querySelector('[name=layer_label]').value,
                        x:         f.querySelector('[name=layer_x]').value,
                        y:         f.querySelector('[name=layer_y]').value,
                        width:     f.querySelector('[name=layer_width]').value,
                        blend:     f.querySelector('[name=layer_blend]').value,
                        opacity:   f.querySelector('[name=layer_opacity]').value,
                        animation: f.querySelector('[name=layer_animation]').value,
                        speed:     f.querySelector('[name=layer_speed]').value,
                        pause:     f.querySelector('[name=layer_pause]').value,
                        z:         f.querySelector('[name=layer_z]').value,
                        show_on:   showOn
                    });
                }
                return layers;
            }

            window.fhLayerSaveAll = function() {
                var layers = collectLayers();
                var fd = new FormData();
                fd.append('action', 'fishotel_save_layer_config');
                fd.append('nonce', nonce);
                fd.append('scene_type', sceneType);
                fd.append('layers', JSON.stringify(layers));
                fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(r){
                        if (r.success) notice('Layer config saved.', 'success');
                        else notice(r.data && r.data.message || 'Save failed.', 'error');
                    })
                    .catch(function(){ notice('Network error.', 'error'); });
            };

            window.fhLayerToggleEdit = function(btn) {
                var card = btn.closest('.fh-layer-card');
                var form = card.nextElementSibling;
                if (form && form.classList.contains('fh-layer-edit-form')) {
                    form.classList.toggle('open');
                }
            };

            window.fhLayerDelete = function(btn) {
                if (!confirm('Delete this layer?')) return;
                var card = btn.closest('.fh-layer-card');
                var form = card.nextElementSibling;
                if (form && form.classList.contains('fh-layer-edit-form')) form.remove();
                card.remove();
                fhLayerSaveAll();
                var remaining = document.querySelectorAll('#fh-layer-list .fh-layer-card');
                if (!remaining.length) document.getElementById('fh-layer-empty').style.display = '';
            };

            window.fhLayerMove = function(btn, dir) {
                var card = btn.closest('.fh-layer-card');
                var form = card.nextElementSibling;
                var list = document.getElementById('fh-layer-list');
                var cards = Array.from(list.querySelectorAll('.fh-layer-card'));
                var idx = cards.indexOf(card);
                var target = idx + dir;
                if (target < 0 || target >= cards.length) return;
                var targetCard = cards[target];
                var targetForm = targetCard.nextElementSibling;
                if (dir === -1) {
                    list.insertBefore(card, targetCard);
                    list.insertBefore(form, targetCard);
                } else {
                    if (targetForm && targetForm.classList.contains('fh-layer-edit-form')) {
                        list.insertBefore(card, targetForm.nextSibling);
                        list.insertBefore(form, card.nextSibling === form ? card.nextSibling : card.nextSibling);
                    } else {
                        list.insertBefore(card, targetCard.nextSibling);
                        list.insertBefore(form, card.nextSibling);
                    }
                }
                fhLayerSaveAll();
            };

            window.fhLayerAdd = function() {
                document.getElementById('fh-layer-empty').style.display = 'none';
                var list = document.getElementById('fh-layer-list');
                var tpl  = document.getElementById('fh-layer-edit-template').innerHTML;
                var idx  = Date.now();
                tpl = tpl.replace(/__INDEX__/g, idx);

                /* Card */
                var card = document.createElement('div');
                card.className = 'fh-layer-card';
                card.dataset.index = idx;
                card.innerHTML = '<div class="fh-layer-thumb"><div class="fh-layer-thumb-placeholder">New</div></div>'
                    + '<div class="fh-layer-info"><div class="fh-layer-label">New Layer</div><div class="fh-layer-summary">Configure below</div></div>'
                    + '<div class="fh-layer-actions">'
                    + '<button type="button" onclick="fhLayerMove(this,-1)" title="Move up"><span class="dashicons dashicons-arrow-up-alt2"></span></button>'
                    + '<button type="button" onclick="fhLayerMove(this,1)" title="Move down"><span class="dashicons dashicons-arrow-down-alt2"></span></button>'
                    + '<button type="button" onclick="fhLayerToggleEdit(this)" title="Edit"><span class="dashicons dashicons-edit"></span></button>'
                    + '<button type="button" onclick="fhLayerDelete(this)" title="Delete" style="color:#a00"><span class="dashicons dashicons-trash"></span></button>'
                    + '</div>';
                list.appendChild(card);

                var wrapper = document.createElement('div');
                wrapper.innerHTML = tpl;
                var formEl = wrapper.firstElementChild;
                formEl.classList.add('open');
                list.appendChild(formEl);
            };

            /* ── Scene Types manager ── */
            window.fhStRemove = function(btn) {
                var tag = btn.closest('.fh-st-tag');
                var type = tag.dataset.type;
                var configs = <?php echo wp_json_encode( $all_configs ); ?>;
                if (configs[type] && configs[type].length) {
                    if (!confirm('Scene type "' + type + '" has ' + configs[type].length + ' layer(s). Remove anyway?')) return;
                }
                tag.remove();
            };

            window.fhStAdd = function() {
                var inp = document.getElementById('fh-st-new');
                var val = inp.value.trim().toLowerCase().replace(/[^a-z0-9\-]/g, '');
                if (!val) { alert('Enter a valid scene type (lowercase, alphanumeric, hyphens).'); return; }
                var existing = Array.from(document.querySelectorAll('.fh-st-tag')).map(function(t){ return t.dataset.type; });
                if (existing.indexOf(val) !== -1) { alert('Scene type "' + val + '" already exists.'); return; }
                var list = document.getElementById('fh-st-list');
                var span = document.createElement('span');
                span.className = 'fh-st-tag';
                span.dataset.type = val;
                span.innerHTML = val + ' <button type="button" onclick="fhStRemove(this)" title="Remove">&times;</button>';
                list.appendChild(span);
                inp.value = '';
            };

            window.fhStSave = function() {
                var types = Array.from(document.querySelectorAll('.fh-st-tag')).map(function(t){ return t.dataset.type; });
                if (!types.length) { alert('Must have at least one scene type.'); return; }
                var fd = new FormData();
                fd.append('action', 'fishotel_save_scene_types');
                fd.append('nonce', document.getElementById('fh-st-manager').dataset.nonce);
                fd.append('scene_types', JSON.stringify(types));
                fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(r){
                        if (r.success) {
                            var st = document.getElementById('fh-st-status');
                            st.textContent = 'Saved! Reloading…';
                            st.style.display = '';
                            setTimeout(function(){ window.location.reload(); }, 800);
                        } else {
                            alert(r.data && r.data.message || 'Save failed.');
                        }
                    });
            };

            window.fhLayerShowOnAll = function(cb) {
                var form = cb.closest('.fh-layer-edit-form');
                var boxes = form.querySelectorAll('.fh-show-on-cb');
                boxes.forEach(function(b){ b.checked = cb.checked; });
            };

            window.fhLayerOpacitySync = function(range) {
                var span = range.parentNode.querySelector('.fh-opacity-val');
                if (span) span.textContent = range.value;
            };

            /* ── Browse Library modal for asset selection ── */
            var _browseTarget = null;

            window.fhLayerBrowseLibrary = function(btn) {
                _browseTarget = btn.closest('td');
                var modal = document.getElementById('fh-browse-modal');
                modal.classList.add('open');
                fhBrowseLoad();
            };

            function fhBrowseLoad(search) {
                var fd = new FormData();
                fd.append('action', 'fishotel_get_assets');
                fd.append('nonce', assetNonce);
                fd.append('category', 'layer');
                if (search) fd.append('search', search);
                fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(r){
                        if (!r.success) return;
                        var grid = document.getElementById('fh-browse-grid');
                        if (!r.data.assets.length) {
                            grid.innerHTML = '<div style="color:#999;text-align:center;padding:40px;grid-column:1/-1;">No layer assets found. Upload assets in the Assets tab first.</div>';
                            return;
                        }
                        var html = '';
                        r.data.assets.forEach(function(a){
                            html += '<div class="fha-modal-card" onclick="fhBrowseSelect(\'' + a.filename.replace(/'/g,"\\'") + '\')">'
                                + '<div class="fha-modal-thumb"><img src="' + a.url + '" alt="" loading="lazy"></div>'
                                + '<div class="fha-modal-label">' + (a.label || a.filename) + '</div>'
                                + '<div class="fha-modal-fname">' + a.filename + '</div>'
                                + '</div>';
                        });
                        grid.innerHTML = html;
                    });
            }

            window.fhBrowseSelect = function(filename) {
                if (_browseTarget) {
                    _browseTarget.querySelector('input[name=layer_asset]').value = filename;
                    _browseTarget.querySelector('.fh-layer-asset-display').textContent = filename;
                }
                document.getElementById('fh-browse-modal').classList.remove('open');
                _browseTarget = null;
            };

            window.fhBrowseClose = function() {
                document.getElementById('fh-browse-modal').classList.remove('open');
                _browseTarget = null;
            };

            /* ── Prefill from Assets tab ── */
            (function(){
                var params = new URLSearchParams(window.location.search);
                var prefill = params.get('prefill_asset');
                if (prefill) {
                    /* Click "Add Layer" and set the asset */
                    fhLayerAdd();
                    var forms = document.querySelectorAll('.fh-layer-edit-form');
                    var last = forms[forms.length - 1];
                    if (last) {
                        last.classList.add('open');
                        var inp = last.querySelector('input[name=layer_asset]');
                        if (inp) inp.value = prefill;
                        var disp = last.querySelector('.fh-layer-asset-display');
                        if (disp) disp.textContent = prefill;
                    }
                }
            })();
        })();
        </script>

        <!-- Browse Library Modal -->
        <div class="fha-modal-overlay" id="fh-browse-modal">
            <div class="fha-modal">
                <div class="fha-modal-header">
                    <h3><span class="dashicons dashicons-images-alt2" style="margin-right:6px;"></span> Asset Library — Layers</h3>
                    <button class="fha-modal-close" onclick="fhBrowseClose()">&times;</button>
                </div>
                <div class="fha-modal-filters">
                    <input type="text" placeholder="Search…" style="padding:5px 8px;border:1px solid #ccc;border-radius:4px;font-size:12px;width:200px;" oninput="clearTimeout(this._t);var v=this.value;this._t=setTimeout(function(){fhBrowseLoad(v)},300)">
                </div>
                <div class="fha-modal-grid" id="fh-browse-grid">
                    <div style="color:#999;text-align:center;padding:40px;grid-column:1/-1;">Loading…</div>
                </div>
            </div>
        </div>

        <style>
        .fha-modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;align-items:center;justify-content:center}
        .fha-modal-overlay.open{display:flex}
        .fha-modal{background:#fff;border-radius:10px;width:90%;max-width:900px;max-height:80vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.3)}
        .fha-modal-header{padding:14px 20px;border-bottom:1px solid #ddd;display:flex;align-items:center;justify-content:space-between}
        .fha-modal-header h3{margin:0;font-size:16px;color:#1a3a5c}
        .fha-modal-close{background:none;border:none;font-size:24px;cursor:pointer;color:#666;padding:0 4px}
        .fha-modal-close:hover{color:#a00}
        .fha-modal-filters{padding:10px 20px;border-bottom:1px solid #eee;display:flex;gap:10px;flex-wrap:wrap;align-items:center}
        .fha-modal-grid{padding:20px;overflow-y:auto;flex:1;display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:12px}
        .fha-modal-card{background:#fff;border:1px solid #ddd;border-radius:6px;padding:8px;cursor:pointer;transition:border-color .15s,box-shadow .15s;text-align:center}
        .fha-modal-card:hover{border-color:#1a3a5c;box-shadow:0 2px 8px rgba(0,0,0,.1)}
        .fha-modal-thumb{width:80px;height:80px;margin:0 auto 6px;display:flex;align-items:center;justify-content:center;border-radius:4px;overflow:hidden;
            background-image:linear-gradient(45deg,#ccc 25%,transparent 25%),linear-gradient(-45deg,#ccc 25%,transparent 25%),linear-gradient(45deg,transparent 75%,#ccc 75%),linear-gradient(-45deg,transparent 75%,#ccc 75%);
            background-size:10px 10px;background-position:0 0,0 5px,5px -5px,-5px 0}
        .fha-modal-thumb img{max-width:100%;max-height:100%;object-fit:contain}
        .fha-modal-fname{font-size:10px;color:#666;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .fha-modal-label{font-size:12px;font-weight:600;color:#1a3a5c;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        </style>
        <?php endif; // end: scene_slugs not empty ?>
        <?php
    }

    private function hotel_layer_edit_form_html( $L, $idx, $assets, $blend_modes, $animations, $time_bands ) {
        $id    = $L['id'] ?? '';
        $asset = $L['asset'] ?? '';
        $label = $L['label'] ?? '';
        $x     = $L['x'] ?? '0';
        $y     = $L['y'] ?? '0';
        $w     = $L['width'] ?? '100';
        $blend = $L['blend'] ?? 'normal';
        $opa   = $L['opacity'] ?? '1';
        $anim  = $L['animation'] ?? 'none';
        $speed = $L['speed'] ?? '10';
        $pause = $L['pause'] ?? '0';
        $z     = $L['z'] ?? '1';
        $show  = $L['show_on'] ?? [];
        ob_start();
        ?>
        <div class="fh-layer-edit-form" data-index="<?php echo esc_attr( $idx ); ?>">
            <input type="hidden" name="layer_id" value="<?php echo esc_attr( $id ); ?>">
            <table class="form-table">
                <tr><th>Asset</th><td>
                    <input type="hidden" name="layer_asset" value="<?php echo esc_attr( $asset ); ?>">
                    <span class="fh-layer-asset-display"><?php echo $asset ? esc_html( $asset ) : '<em style="color:#999;">None selected</em>'; ?></span>
                    <button type="button" class="button button-small" onclick="fhLayerBrowseLibrary(this)" style="margin-left:8px;">
                        <span class="dashicons dashicons-images-alt2" style="margin-top:3px;font-size:14px;"></span> Browse Library
                    </button>
                </td></tr>
                <tr><th>Label</th><td><input type="text" name="layer_label" value="<?php echo esc_attr( $label ); ?>" class="regular-text" placeholder="e.g. Light Shaft"></td></tr>
                <tr><th>X position (%)</th><td><input type="number" name="layer_x" value="<?php echo esc_attr( $x ); ?>" min="0" max="100" class="small-text"></td></tr>
                <tr><th>Y position (%)</th><td><input type="number" name="layer_y" value="<?php echo esc_attr( $y ); ?>" min="0" max="100" class="small-text"></td></tr>
                <tr><th>Width (%)</th><td><input type="number" name="layer_width" value="<?php echo esc_attr( $w ); ?>" min="1" max="100" class="small-text"></td></tr>
                <tr><th>Blend mode</th><td>
                    <select name="layer_blend">
                        <?php foreach ( $blend_modes as $bm ) : ?>
                            <option value="<?php echo esc_attr( $bm ); ?>" <?php selected( $blend, $bm ); ?>><?php echo esc_html( $bm ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td></tr>
                <tr><th>Opacity</th><td>
                    <input type="range" name="layer_opacity" value="<?php echo esc_attr( $opa ); ?>" min="0" max="1" step="0.05" oninput="fhLayerOpacitySync(this)">
                    <span class="fh-opacity-val"><?php echo esc_html( $opa ); ?></span>
                </td></tr>
                <tr><th>Animation</th><td>
                    <select name="layer_animation">
                        <?php foreach ( $animations as $an ) : ?>
                            <option value="<?php echo esc_attr( $an ); ?>" <?php selected( $anim, $an ); ?>><?php echo esc_html( $an ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td></tr>
                <tr><th>Speed (seconds)</th><td><input type="number" name="layer_speed" value="<?php echo esc_attr( $speed ); ?>" min="1" max="60" class="small-text"></td></tr>
                <tr><th>Pause between loops (s)</th><td><input type="number" name="layer_pause" value="<?php echo esc_attr( $pause ); ?>" min="0" max="30" class="small-text"></td></tr>
                <tr><th>Z-index</th><td><input type="number" name="layer_z" value="<?php echo esc_attr( $z ); ?>" min="1" max="20" class="small-text"></td></tr>
                <tr><th>Show on</th><td class="fh-layer-show-on">
                    <?php foreach ( $time_bands as $band ) :
                        $checked = in_array( $band, $show, true );
                        $extra   = $band === 'all' ? ' onchange="fhLayerShowOnAll(this)"' : '';
                    ?>
                        <label><input type="checkbox" class="fh-show-on-cb" value="<?php echo esc_attr( $band ); ?>" <?php checked( $checked ); ?><?php echo $extra; ?>> <?php echo esc_html( $band ); ?></label>
                    <?php endforeach; ?>
                </td></tr>
            </table>
            <button type="button" class="button" onclick="this.closest('.fh-layer-edit-form').classList.remove('open')">Close</button>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ─────────────────────────────────────────────
     *  Scene Types (dynamic, stored in WP option)
     * ───────────────────────────────────────────── */

    private function hotel_get_scene_types() {
        $defaults = [ 'pool', 'lobby', 'spa', 'dining', 'beach', 'bar', 'suite', 'graduation', 'morning', 'night' ];
        $types = get_option( 'fishotel_scene_types', $defaults );
        if ( ! is_array( $types ) || empty( $types ) ) {
            return $defaults;
        }
        return array_values( $types );
    }

    /* ═════════════════════════════════════════════
     *  TAB: Assets  (Asset Library)
     * ═════════════════════════════════════════════ */

    private function hotel_tab_assets() {
        // Auto-scan on first load
        $library = get_option( 'fishotel_asset_library', null );
        if ( $library === null ) {
            // First-time: trigger scan inline
            $this->hotel_asset_auto_scan();
            $library = get_option( 'fishotel_asset_library', [ 'assets' => [] ] );
        }

        $nonce       = wp_create_nonce( 'fishotel_asset_library' );
        $scene_types = $this->hotel_get_scene_types();
        $time_bands  = [ 'morning', 'afternoon', 'sunset', 'night' ];
        $categories  = [ 'layer' => 'Layers', 'background' => 'Backgrounds', 'stamp' => 'Stamps' ];
        $folder_map  = [ 'layer' => 'scene-layers', 'background' => 'scene-backgrounds', 'stamp' => 'stamps' ];
        $folder_labels = [ 'scene-layers' => 'assists/scene-layers/', 'scene-backgrounds' => 'assists/scene/', 'stamps' => 'assists/stamps/' ];
        ?>
        <style>
        /* ── Asset Library layout ── */
        .fha-wrap{display:flex;gap:24px;min-height:600px}
        .fha-main{flex:1;min-width:0}
        .fha-side{width:320px;flex-shrink:0;position:relative}

        /* ── Filter bar ── */
        .fha-filters{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:18px}
        .fha-search{padding:6px 10px;border:1px solid #ccc;border-radius:4px;width:220px;font-size:13px}
        .fha-pills{display:flex;gap:4px}
        .fha-pill{padding:5px 14px;background:#f0f0f0;border:1px solid #ccc;border-radius:20px;cursor:pointer;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#555;transition:all .15s}
        .fha-pill:hover{background:#e4e4e4}
        .fha-pill.active{background:#1a3a5c;color:#fff;border-color:#1a3a5c}
        .fha-filter-select{padding:5px 8px;border:1px solid #ccc;border-radius:4px;font-size:12px}

        /* ── Asset grid ── */
        .fha-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:14px}
        .fha-card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:10px;position:relative;transition:box-shadow .2s,transform .15s;cursor:default}
        .fha-card:hover{box-shadow:0 4px 12px rgba(0,0,0,.1);transform:translateY(-1px)}
        .fha-card.selected{border-color:#1a3a5c;box-shadow:0 0 0 2px rgba(26,58,92,.3)}
        .fha-card-cb{position:absolute;top:6px;left:6px;z-index:2}
        .fha-thumb{width:120px;height:120px;margin:0 auto 8px;display:flex;align-items:center;justify-content:center;border-radius:4px;overflow:hidden;
            background-image:linear-gradient(45deg,#ccc 25%,transparent 25%),linear-gradient(-45deg,#ccc 25%,transparent 25%),linear-gradient(45deg,transparent 75%,#ccc 75%),linear-gradient(-45deg,transparent 75%,#ccc 75%);
            background-size:10px 10px;background-position:0 0,0 5px,5px -5px,-5px 0}
        .fha-thumb img{max-width:100%;max-height:100%;object-fit:contain}
        .fha-fname{font-size:11px;color:#666;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;text-align:center}
        .fha-label{font-size:13px;font-weight:600;color:#1a3a5c;text-align:center;margin:4px 0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:text;min-height:20px}
        .fha-label-input{width:100%;text-align:center;font-size:13px;font-weight:600;color:#1a3a5c;border:1px solid #1a3a5c;border-radius:3px;padding:2px 4px;box-sizing:border-box}
        .fha-chips{display:flex;flex-wrap:wrap;gap:3px;justify-content:center;margin-top:4px;min-height:20px}
        .fha-chip{font-size:9px;background:#e8f0f8;color:#1a3a5c;padding:2px 6px;border-radius:10px;white-space:nowrap}
        .fha-chip.time{background:#fdf3e7;color:#b45309}
        .fha-card-actions{display:flex;gap:4px;justify-content:center;margin-top:6px;opacity:0;transition:opacity .15s}
        .fha-card:hover .fha-card-actions{opacity:1}
        .fha-card-btn{background:none;border:none;cursor:pointer;color:#666;padding:2px 4px;font-size:16px}
        .fha-card-btn:hover{color:#1a3a5c}
        .fha-card-btn.delete:hover{color:#a00}
        .fha-meta-hover{display:none;position:absolute;bottom:100%;left:50%;transform:translateX(-50%);background:#333;color:#fff;padding:4px 8px;border-radius:4px;font-size:10px;white-space:nowrap;z-index:10}
        .fha-card:hover .fha-meta-hover{display:block}
        .fha-empty{color:#999;text-align:center;padding:40px;grid-column:1/-1}

        /* ── Bulk actions bar ── */
        .fha-bulk{display:none;align-items:center;gap:10px;margin-bottom:14px;padding:8px 14px;background:#f0f6ff;border:1px solid #b8d4f0;border-radius:6px;font-size:13px}
        .fha-bulk.visible{display:flex}
        .fha-bulk-count{font-weight:600;color:#1a3a5c}

        /* ── Side panel (upload / detail) ── */
        .fha-panel{background:#fff;border:1px solid #ddd;border-radius:8px;padding:18px;position:sticky;top:40px}
        .fha-panel h3{margin-top:0;font-size:15px;color:#1a3a5c}

        /* Upload panel */
        .fha-dropzone{border:2px dashed #bbb;border-radius:8px;padding:30px 16px;text-align:center;color:#888;cursor:pointer;transition:border-color .2s,background .2s;margin-bottom:14px}
        .fha-dropzone:hover,.fha-dropzone.dragover{border-color:#1a3a5c;background:#f0f6ff;color:#1a3a5c}
        .fha-dropzone .dashicons{font-size:32px;width:32px;height:32px;display:block;margin:0 auto 8px}
        .fha-folder-radios label{display:block;margin:4px 0;font-size:13px}
        .fha-pending-list{margin-top:14px;max-height:400px;overflow-y:auto}
        .fha-pending-item{background:#f9f9f9;border:1px solid #e0e0e0;border-radius:6px;padding:10px;margin-bottom:8px;font-size:12px}
        .fha-pending-item .fname{font-weight:600;margin-bottom:6px}
        .fha-pending-item input[type=text]{width:100%;margin:4px 0;padding:4px 6px;border:1px solid #ccc;border-radius:3px;font-size:12px;box-sizing:border-box}
        .fha-progress{height:4px;background:#e0e0e0;border-radius:2px;margin-top:6px;overflow:hidden}
        .fha-progress-bar{height:100%;background:#1a3a5c;width:0%;transition:width .3s}
        .fha-pending-item.done{opacity:.5}
        .fha-pending-item.error{border-color:#dc3232}

        /* Detail panel */
        .fha-detail{display:none}
        .fha-detail.open{display:block}
        .fha-detail-preview{width:200px;height:200px;margin:0 auto 14px;display:flex;align-items:center;justify-content:center;border-radius:6px;overflow:hidden;
            background-image:linear-gradient(45deg,#ccc 25%,transparent 25%),linear-gradient(-45deg,#ccc 25%,transparent 25%),linear-gradient(45deg,transparent 75%,#ccc 75%),linear-gradient(-45deg,transparent 75%,#ccc 75%);
            background-size:10px 10px;background-position:0 0,0 5px,5px -5px,-5px 0}
        .fha-detail-preview img{max-width:100%;max-height:100%;object-fit:contain}
        .fha-detail table{width:100%;font-size:13px}
        .fha-detail table th{text-align:left;padding:5px 8px 5px 0;color:#555;width:90px;vertical-align:top;font-weight:normal}
        .fha-detail table td{padding:4px 0}
        .fha-detail input[type=text],.fha-detail select{width:100%;padding:4px 6px;border:1px solid #ccc;border-radius:3px;font-size:12px;box-sizing:border-box}
        .fha-detail .fha-cb-group{display:flex;flex-wrap:wrap;gap:2px}
        .fha-detail .fha-cb-group label{font-size:11px;white-space:nowrap}
        .fha-detail-meta{font-size:11px;color:#888;margin-top:8px}
        .fha-detail-actions{display:flex;gap:8px;margin-top:14px}

        /* ── Asset Library modal (for Layer Designer) ── */
        .fha-modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;align-items:center;justify-content:center}
        .fha-modal-overlay.open{display:flex}
        .fha-modal{background:#fff;border-radius:10px;width:90%;max-width:900px;max-height:80vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.3)}
        .fha-modal-header{padding:14px 20px;border-bottom:1px solid #ddd;display:flex;align-items:center;justify-content:space-between}
        .fha-modal-header h3{margin:0;font-size:16px;color:#1a3a5c}
        .fha-modal-close{background:none;border:none;font-size:24px;cursor:pointer;color:#666;padding:0 4px}
        .fha-modal-close:hover{color:#a00}
        .fha-modal-filters{padding:10px 20px;border-bottom:1px solid #eee;display:flex;gap:10px;flex-wrap:wrap;align-items:center}
        .fha-modal-grid{padding:20px;overflow-y:auto;flex:1;display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:12px}
        .fha-modal-card{background:#fff;border:1px solid #ddd;border-radius:6px;padding:8px;cursor:pointer;transition:border-color .15s,box-shadow .15s;text-align:center}
        .fha-modal-card:hover{border-color:#1a3a5c;box-shadow:0 2px 8px rgba(0,0,0,.1)}
        .fha-modal-thumb{width:80px;height:80px;margin:0 auto 6px;display:flex;align-items:center;justify-content:center;border-radius:4px;overflow:hidden;
            background-image:linear-gradient(45deg,#ccc 25%,transparent 25%),linear-gradient(-45deg,#ccc 25%,transparent 25%),linear-gradient(45deg,transparent 75%,#ccc 75%),linear-gradient(-45deg,transparent 75%,#ccc 75%);
            background-size:10px 10px;background-position:0 0,0 5px,5px -5px,-5px 0}
        .fha-modal-thumb img{max-width:100%;max-height:100%;object-fit:contain}
        .fha-modal-fname{font-size:10px;color:#666;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .fha-modal-label{font-size:12px;font-weight:600;color:#1a3a5c;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

        /* ── Re-scan button ── */
        .fha-rescan{float:right}
        </style>

        <div class="fha-wrap">
            <!-- LEFT: Main grid area -->
            <div class="fha-main">
                <!-- Filter bar -->
                <div class="fha-filters">
                    <input type="text" class="fha-search" id="fha-search" placeholder="Search filename or label…">
                    <div class="fha-pills" id="fha-cat-pills">
                        <span class="fha-pill active" data-cat="">All</span>
                        <?php foreach ( $categories as $key => $lbl ) : ?>
                            <span class="fha-pill" data-cat="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $lbl ); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <select class="fha-filter-select" id="fha-scene-filter">
                        <option value="">All Scenes</option>
                        <?php foreach ( $scene_types as $st ) : ?>
                            <option value="<?php echo esc_attr( $st ); ?>"><?php echo esc_html( ucfirst( $st ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select class="fha-filter-select" id="fha-band-filter">
                        <option value="">All Times</option>
                        <?php foreach ( $time_bands as $tb ) : ?>
                            <option value="<?php echo esc_attr( $tb ); ?>"><?php echo esc_html( ucfirst( $tb ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="button fha-rescan" onclick="fhaRescan()"><span class="dashicons dashicons-update" style="margin-top:3px;"></span> Re-scan Folders</button>
                </div>

                <!-- Bulk actions bar -->
                <div class="fha-bulk" id="fha-bulk">
                    <input type="checkbox" id="fha-select-all" onchange="fhaSelectAll(this.checked)">
                    <span class="fha-bulk-count" id="fha-bulk-count">0 selected</span>
                    <select id="fha-bulk-action" class="fha-filter-select">
                        <option value="">Bulk Actions…</option>
                        <option value="set_scene_types">Set Scene Types</option>
                        <option value="set_time_bands">Set Time Bands</option>
                        <option value="move_folder">Move Folder</option>
                        <option value="delete">Delete</option>
                    </select>
                    <button type="button" class="button" onclick="fhaBulkApply()">Apply</button>
                </div>

                <!-- Asset grid -->
                <div class="fha-grid" id="fha-grid">
                    <div class="fha-empty" id="fha-empty">Loading assets…</div>
                </div>
            </div>

            <!-- RIGHT: Side panel -->
            <div class="fha-side">
                <!-- Upload panel -->
                <div class="fha-panel" id="fha-upload-panel">
                    <h3><span class="dashicons dashicons-upload" style="margin-right:4px;"></span> Upload Assets</h3>
                    <div class="fha-dropzone" id="fha-dropzone">
                        <span class="dashicons dashicons-cloud-upload"></span>
                        Drop images here<br>or click to browse
                        <input type="file" id="fha-file-input" multiple accept="image/*" style="display:none">
                    </div>
                    <div class="fha-folder-radios">
                        <strong style="font-size:12px;color:#555;">Upload to:</strong>
                        <label><input type="radio" name="fha_folder" value="scene-layers" checked> Layer Asset</label>
                        <label><input type="radio" name="fha_folder" value="scene-backgrounds"> Scene Background</label>
                        <label><input type="radio" name="fha_folder" value="stamps"> Stamp</label>
                    </div>
                    <div class="fha-pending-list" id="fha-pending-list"></div>
                    <button type="button" class="button button-primary" id="fha-upload-all-btn" style="display:none;margin-top:10px;width:100%;" onclick="fhaUploadAll()">
                        <span class="dashicons dashicons-upload" style="margin-top:3px;"></span> Upload All
                    </button>
                </div>

                <!-- Detail panel (hidden by default) -->
                <div class="fha-panel fha-detail" id="fha-detail-panel">
                    <h3><span class="dashicons dashicons-edit" style="margin-right:4px;"></span> Edit Asset</h3>
                    <div class="fha-detail-preview" id="fha-detail-preview"><img id="fha-detail-img" src="" alt=""></div>
                    <table>
                        <tr><th>File</th><td id="fha-detail-filename" style="font-size:12px;color:#666;word-break:break-all;"></td></tr>
                        <tr><th>Label</th><td><input type="text" id="fha-detail-label"></td></tr>
                        <tr><th>Category</th><td>
                            <select id="fha-detail-category">
                                <option value="layer">Layer</option>
                                <option value="background">Background</option>
                                <option value="stamp">Stamp</option>
                            </select>
                        </td></tr>
                        <tr><th>Scenes</th><td class="fha-cb-group" id="fha-detail-scenes">
                            <?php foreach ( $scene_types as $st ) : ?>
                                <label><input type="checkbox" value="<?php echo esc_attr( $st ); ?>"> <?php echo esc_html( ucfirst( $st ) ); ?></label>
                            <?php endforeach; ?>
                        </td></tr>
                        <tr><th>Times</th><td>
                            <label style="font-size:11px;margin-bottom:4px;display:block;"><input type="checkbox" id="fha-detail-all-times" onchange="fhaToggleAllTimes(this.checked)"> <em>All times</em></label>
                            <div class="fha-cb-group" id="fha-detail-times">
                                <?php foreach ( $time_bands as $tb ) : ?>
                                    <label><input type="checkbox" value="<?php echo esc_attr( $tb ); ?>"> <?php echo esc_html( ucfirst( $tb ) ); ?></label>
                                <?php endforeach; ?>
                            </div>
                        </td></tr>
                        <tr><th>Tags</th><td><input type="text" id="fha-detail-tags" placeholder="comma separated"></td></tr>
                        <tr><th>Location</th><td id="fha-detail-location" style="font-size:11px;color:#888;"></td></tr>
                    </table>
                    <div class="fha-detail-meta" id="fha-detail-meta"></div>
                    <div class="fha-detail-actions">
                        <button type="button" class="button button-primary" onclick="fhaDetailSave()"><span class="dashicons dashicons-saved" style="margin-top:3px;"></span> Save</button>
                        <button type="button" class="button" onclick="fhaDetailClose()">Cancel</button>
                        <button type="button" class="button" onclick="fhaUseInLayerDesigner()" title="Use in Layer Designer" style="margin-left:auto"><span class="dashicons dashicons-art" style="margin-top:3px;"></span> Use in Layers</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bulk modal for scene types / time bands -->
        <div class="fha-modal-overlay" id="fha-bulk-modal">
            <div class="fha-modal" style="max-width:400px;">
                <div class="fha-modal-header">
                    <h3 id="fha-bulk-modal-title">Bulk Edit</h3>
                    <button class="fha-modal-close" onclick="document.getElementById('fha-bulk-modal').classList.remove('open')">&times;</button>
                </div>
                <div style="padding:20px;" id="fha-bulk-modal-body">
                    <div id="fha-bulk-scenes" style="display:none;">
                        <p style="font-size:13px;margin-top:0;">Set scene types for selected assets:</p>
                        <div class="fha-cb-group">
                            <?php foreach ( $scene_types as $st ) : ?>
                                <label><input type="checkbox" class="fha-bulk-scene-cb" value="<?php echo esc_attr( $st ); ?>"> <?php echo esc_html( ucfirst( $st ) ); ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div id="fha-bulk-times" style="display:none;">
                        <p style="font-size:13px;margin-top:0;">Set time bands for selected assets:</p>
                        <div class="fha-cb-group">
                            <?php foreach ( $time_bands as $tb ) : ?>
                                <label><input type="checkbox" class="fha-bulk-time-cb" value="<?php echo esc_attr( $tb ); ?>"> <?php echo esc_html( ucfirst( $tb ) ); ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div id="fha-bulk-folder" style="display:none;">
                        <p style="font-size:13px;margin-top:0;">Move selected assets to folder:</p>
                        <select id="fha-bulk-folder-select" class="fha-filter-select" style="width:100%;">
                            <option value="scene-layers">Layer Assets (scene-layers)</option>
                            <option value="scene-backgrounds">Scene Backgrounds (scene)</option>
                            <option value="stamps">Stamps</option>
                        </select>
                    </div>
                    <button type="button" class="button button-primary" style="margin-top:16px;width:100%;" onclick="fhaBulkConfirm()">Apply</button>
                </div>
            </div>
        </div>

        <script>
        (function(){
            var ajaxurl   = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
            var nonce     = '<?php echo esc_js( $nonce ); ?>';
            var allAssets = [];
            var editingId = null;
            var selectedIds = [];
            var pendingFiles = [];
            var folderLabels = <?php echo wp_json_encode( $folder_labels ); ?>;

            /* ── Helpers ── */
            function fmtSize(bytes) {
                if (bytes < 1024) return bytes + ' B';
                if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
                return (bytes / 1048576).toFixed(1) + ' MB';
            }

            /* ── Load assets ── */
            function loadAssets(cb) {
                var fd = new FormData();
                fd.append('action', 'fishotel_get_assets');
                fd.append('nonce', nonce);
                fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(r){
                        if (r.success) {
                            allAssets = r.data.assets;
                            renderGrid();
                            if (cb) cb();
                        }
                    });
            }

            /* ── Render grid ── */
            function renderGrid() {
                var search   = document.getElementById('fha-search').value.toLowerCase();
                var cat      = document.querySelector('.fha-pill.active').dataset.cat || '';
                var scene    = document.getElementById('fha-scene-filter').value;
                var band     = document.getElementById('fha-band-filter').value;
                var filtered = allAssets.filter(function(a){
                    if (cat && a.category !== cat) return false;
                    if (scene && a.scene_types && a.scene_types.length && a.scene_types.indexOf(scene) === -1) return false;
                    if (band && a.time_bands && a.time_bands.length && a.time_bands.indexOf(band) === -1) return false;
                    if (search) {
                        var hay = (a.filename + ' ' + a.label + ' ' + (a.tags||[]).join(' ')).toLowerCase();
                        if (hay.indexOf(search) === -1) return false;
                    }
                    return true;
                });

                var grid = document.getElementById('fha-grid');
                if (!filtered.length) {
                    grid.innerHTML = '<div class="fha-empty">No assets match your filters.</div>';
                    return;
                }

                var html = '';
                filtered.forEach(function(a){
                    var sel = selectedIds.indexOf(a.id) !== -1;
                    var chips = '';
                    (a.scene_types||[]).forEach(function(s){ chips += '<span class="fha-chip">' + s + '</span>'; });
                    (a.time_bands||[]).forEach(function(t){ chips += '<span class="fha-chip time">' + t + '</span>'; });
                    html += '<div class="fha-card' + (sel ? ' selected' : '') + '" data-id="' + a.id + '">'
                        + '<input type="checkbox" class="fha-card-cb" ' + (sel ? 'checked' : '') + ' onchange="fhaToggleSelect(\'' + a.id + '\',this.checked)">'
                        + '<div class="fha-meta-hover">' + a.width + '×' + a.height + ' · ' + fmtSize(a.filesize) + '</div>'
                        + '<div class="fha-thumb"><img src="' + a.url + '" alt="" loading="lazy"></div>'
                        + '<div class="fha-fname" title="' + a.filename + '">' + a.filename + '</div>'
                        + '<div class="fha-label" ondblclick="fhaInlineEdit(this,\'' + a.id + '\')" title="Double-click to edit">' + (a.label || a.filename) + '</div>'
                        + '<div class="fha-chips">' + chips + '</div>'
                        + '<div class="fha-card-actions">'
                        + '<button class="fha-card-btn" onclick="fhaEditAsset(\'' + a.id + '\')" title="Edit"><span class="dashicons dashicons-edit"></span></button>'
                        + '<button class="fha-card-btn delete" onclick="fhaDeleteAsset(\'' + a.id + '\')" title="Delete"><span class="dashicons dashicons-trash"></span></button>'
                        + '</div></div>';
                });
                grid.innerHTML = html;
            }

            /* ── Filter events ── */
            document.getElementById('fha-search').addEventListener('input', renderGrid);
            document.getElementById('fha-scene-filter').addEventListener('change', renderGrid);
            document.getElementById('fha-band-filter').addEventListener('change', renderGrid);
            document.querySelectorAll('.fha-pill').forEach(function(pill){
                pill.addEventListener('click', function(){
                    document.querySelectorAll('.fha-pill').forEach(function(p){ p.classList.remove('active'); });
                    pill.classList.add('active');
                    renderGrid();
                });
            });

            /* ── Inline label edit ── */
            window.fhaInlineEdit = function(el, id) {
                var current = el.textContent;
                el.innerHTML = '<input type="text" class="fha-label-input" value="' + current.replace(/"/g,'&quot;') + '">';
                var inp = el.querySelector('input');
                inp.focus();
                inp.select();
                function save() {
                    var val = inp.value.trim() || current;
                    el.textContent = val;
                    if (val !== current) {
                        var fd = new FormData();
                        fd.append('action', 'fishotel_save_asset_meta');
                        fd.append('nonce', nonce);
                        fd.append('asset_id', id);
                        fd.append('label', val);
                        fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
                            .then(function(r){ return r.json(); })
                            .then(function(r){
                                if (r.success) {
                                    var a = allAssets.find(function(x){ return x.id === id; });
                                    if (a) a.label = val;
                                }
                            });
                    }
                }
                inp.addEventListener('blur', save);
                inp.addEventListener('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); inp.blur(); } });
            };

            /* ── Selection ── */
            window.fhaToggleSelect = function(id, checked) {
                if (checked) {
                    if (selectedIds.indexOf(id) === -1) selectedIds.push(id);
                } else {
                    selectedIds = selectedIds.filter(function(x){ return x !== id; });
                }
                updateBulkBar();
                renderGrid();
            };

            window.fhaSelectAll = function(checked) {
                if (checked) {
                    selectedIds = allAssets.map(function(a){ return a.id; });
                } else {
                    selectedIds = [];
                }
                updateBulkBar();
                renderGrid();
            };

            function updateBulkBar() {
                var bar = document.getElementById('fha-bulk');
                var cnt = document.getElementById('fha-bulk-count');
                if (selectedIds.length > 0) {
                    bar.classList.add('visible');
                    cnt.textContent = selectedIds.length + ' selected';
                } else {
                    bar.classList.remove('visible');
                }
            }

            /* ── Edit detail panel ── */
            window.fhaEditAsset = function(id) {
                var a = allAssets.find(function(x){ return x.id === id; });
                if (!a) return;
                editingId = id;
                document.getElementById('fha-upload-panel').style.display = 'none';
                var dp = document.getElementById('fha-detail-panel');
                dp.classList.add('open');
                document.getElementById('fha-detail-img').src = a.url;
                document.getElementById('fha-detail-filename').textContent = a.filename;
                document.getElementById('fha-detail-label').value = a.label || '';
                document.getElementById('fha-detail-category').value = a.category || 'layer';
                document.getElementById('fha-detail-tags').value = (a.tags || []).join(', ');
                document.getElementById('fha-detail-location').textContent = folderLabels[a.folder] || a.folder;
                document.getElementById('fha-detail-meta').textContent = a.width + '×' + a.height + ' · ' + fmtSize(a.filesize);

                // Scene checkboxes
                document.querySelectorAll('#fha-detail-scenes input').forEach(function(cb){
                    cb.checked = (a.scene_types || []).indexOf(cb.value) !== -1;
                });
                // Time checkboxes
                var tbs = a.time_bands || [];
                document.querySelectorAll('#fha-detail-times input').forEach(function(cb){
                    cb.checked = tbs.indexOf(cb.value) !== -1;
                });
                document.getElementById('fha-detail-all-times').checked = tbs.length === <?php echo count( $time_bands ); ?>;
            };

            window.fhaDetailClose = function() {
                editingId = null;
                document.getElementById('fha-detail-panel').classList.remove('open');
                document.getElementById('fha-upload-panel').style.display = '';
            };

            window.fhaDetailSave = function() {
                if (!editingId) return;
                var fd = new FormData();
                fd.append('action', 'fishotel_save_asset_meta');
                fd.append('nonce', nonce);
                fd.append('asset_id', editingId);
                fd.append('label', document.getElementById('fha-detail-label').value);
                fd.append('category', document.getElementById('fha-detail-category').value);
                fd.append('tags', document.getElementById('fha-detail-tags').value);
                document.querySelectorAll('#fha-detail-scenes input:checked').forEach(function(cb){
                    fd.append('scene_types[]', cb.value);
                });
                document.querySelectorAll('#fha-detail-times input:checked').forEach(function(cb){
                    fd.append('time_bands[]', cb.value);
                });
                fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(r){
                        if (r.success) {
                            loadAssets(function(){ fhaDetailClose(); });
                        } else {
                            alert(r.data && r.data.message || 'Save failed.');
                        }
                    });
            };

            window.fhaToggleAllTimes = function(checked) {
                document.querySelectorAll('#fha-detail-times input').forEach(function(cb){ cb.checked = checked; });
            };

            window.fhaUseInLayerDesigner = function() {
                if (!editingId) return;
                var a = allAssets.find(function(x){ return x.id === editingId; });
                if (a) {
                    window.location.href = '<?php echo esc_url( admin_url( 'admin.php?page=fishotel-hotel-program&tab=layers' ) ); ?>&prefill_asset=' + encodeURIComponent(a.filename);
                }
            };

            /* ── Delete asset ── */
            window.fhaDeleteAsset = function(id) {
                var a = allAssets.find(function(x){ return x.id === id; });
                if (!a) return;
                if (!confirm('Delete "' + a.filename + '"? This cannot be undone.')) return;
                var fd = new FormData();
                fd.append('action', 'fishotel_delete_asset');
                fd.append('nonce', nonce);
                fd.append('asset_id', id);
                fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(r){
                        if (r.success) {
                            loadAssets();
                            if (editingId === id) fhaDetailClose();
                        } else if (r.data && r.data.confirm) {
                            if (confirm('Warning: This asset is used in layer config(s): ' + r.data.references.join(', ') + '. Delete anyway?')) {
                                fd.append('force', '1');
                                fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
                                    .then(function(r2){ return r2.json(); })
                                    .then(function(r2){
                                        if (r2.success) { loadAssets(); if (editingId === id) fhaDetailClose(); }
                                    });
                            }
                        } else {
                            alert(r.data && r.data.message || 'Delete failed.');
                        }
                    });
            };

            /* ── Re-scan ── */
            window.fhaRescan = function() {
                var fd = new FormData();
                fd.append('action', 'fishotel_scan_assets');
                fd.append('nonce', nonce);
                fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(r){
                        if (r.success) {
                            alert('Scan complete. ' + r.data.added + ' new asset(s) found. Total: ' + r.data.total);
                            loadAssets();
                        }
                    });
            };

            /* ── Dropzone / Upload ── */
            var dropzone = document.getElementById('fha-dropzone');
            var fileInput = document.getElementById('fha-file-input');

            dropzone.addEventListener('click', function(){ fileInput.click(); });
            dropzone.addEventListener('dragover', function(e){ e.preventDefault(); dropzone.classList.add('dragover'); });
            dropzone.addEventListener('dragleave', function(){ dropzone.classList.remove('dragover'); });
            dropzone.addEventListener('drop', function(e){
                e.preventDefault();
                dropzone.classList.remove('dragover');
                addPendingFiles(e.dataTransfer.files);
            });
            fileInput.addEventListener('change', function(){
                addPendingFiles(fileInput.files);
                fileInput.value = '';
            });

            function addPendingFiles(files) {
                for (var i = 0; i < files.length; i++) {
                    pendingFiles.push({ file: files[i], label: files[i].name.replace(/\.[^.]+$/, ''), scene_types: [], time_bands: [] });
                }
                renderPending();
            }

            function renderPending() {
                var list = document.getElementById('fha-pending-list');
                var btn  = document.getElementById('fha-upload-all-btn');
                if (!pendingFiles.length) {
                    list.innerHTML = '';
                    btn.style.display = 'none';
                    return;
                }
                btn.style.display = '';
                var html = '';
                pendingFiles.forEach(function(pf, idx) {
                    html += '<div class="fha-pending-item" id="fha-pending-' + idx + '">'
                        + '<div class="fname">' + pf.file.name + ' <span style="color:#999;font-weight:normal;">(' + fmtSize(pf.file.size) + ')</span></div>'
                        + '<input type="text" placeholder="Label" value="' + pf.label.replace(/"/g,'&quot;') + '" onchange="fhaPendingLabel(' + idx + ',this.value)">'
                        + '<div class="fha-progress"><div class="fha-progress-bar" id="fha-prog-' + idx + '"></div></div>'
                        + '</div>';
                });
                list.innerHTML = html;
            }

            window.fhaPendingLabel = function(idx, val) {
                if (pendingFiles[idx]) pendingFiles[idx].label = val;
            };

            window.fhaUploadAll = function() {
                var folder = document.querySelector('input[name=fha_folder]:checked').value;
                var queue = pendingFiles.slice();
                var idx = 0;
                function next() {
                    if (idx >= queue.length) {
                        pendingFiles = [];
                        renderPending();
                        loadAssets();
                        return;
                    }
                    var pf = queue[idx];
                    var el = document.getElementById('fha-pending-' + idx);
                    var bar = document.getElementById('fha-prog-' + idx);
                    var fd = new FormData();
                    fd.append('action', 'fishotel_upload_asset');
                    fd.append('nonce', nonce);
                    fd.append('asset_file', pf.file);
                    fd.append('folder', folder);
                    fd.append('label', pf.label);

                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', ajaxurl);
                    xhr.withCredentials = true;
                    xhr.upload.addEventListener('progress', function(e){
                        if (e.lengthComputable && bar) bar.style.width = Math.round(e.loaded / e.total * 100) + '%';
                    });
                    xhr.addEventListener('load', function(){
                        if (el) el.classList.add('done');
                        if (bar) bar.style.width = '100%';
                        idx++;
                        next();
                    });
                    xhr.addEventListener('error', function(){
                        if (el) el.classList.add('error');
                        idx++;
                        next();
                    });
                    xhr.send(fd);
                }
                next();
            };

            /* ── Bulk actions ── */
            window.fhaBulkApply = function() {
                if (!selectedIds.length) return alert('No assets selected.');
                var action = document.getElementById('fha-bulk-action').value;
                if (!action) return alert('Select a bulk action.');

                if (action === 'delete') {
                    if (!confirm('Delete ' + selectedIds.length + ' asset(s)? This cannot be undone.')) return;
                    var fd = new FormData();
                    fd.append('action', 'fishotel_bulk_update_assets');
                    fd.append('nonce', nonce);
                    fd.append('bulk_action', 'delete');
                    selectedIds.forEach(function(id){ fd.append('asset_ids[]', id); });
                    fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
                        .then(function(r){ return r.json(); })
                        .then(function(r){
                            if (r.success) { selectedIds = []; updateBulkBar(); loadAssets(); }
                        });
                    return;
                }

                // Show bulk modal
                var modal = document.getElementById('fha-bulk-modal');
                document.getElementById('fha-bulk-scenes').style.display = action === 'set_scene_types' ? '' : 'none';
                document.getElementById('fha-bulk-times').style.display = action === 'set_time_bands' ? '' : 'none';
                document.getElementById('fha-bulk-folder').style.display = action === 'move_folder' ? '' : 'none';
                document.getElementById('fha-bulk-modal-title').textContent =
                    action === 'set_scene_types' ? 'Set Scene Types' :
                    action === 'set_time_bands' ? 'Set Time Bands' : 'Move Folder';
                modal.classList.add('open');
            };

            window.fhaBulkConfirm = function() {
                var action = document.getElementById('fha-bulk-action').value;
                var fd = new FormData();
                fd.append('action', 'fishotel_bulk_update_assets');
                fd.append('nonce', nonce);
                fd.append('bulk_action', action);
                selectedIds.forEach(function(id){ fd.append('asset_ids[]', id); });

                if (action === 'set_scene_types') {
                    document.querySelectorAll('.fha-bulk-scene-cb:checked').forEach(function(cb){ fd.append('scene_types[]', cb.value); });
                } else if (action === 'set_time_bands') {
                    document.querySelectorAll('.fha-bulk-time-cb:checked').forEach(function(cb){ fd.append('time_bands[]', cb.value); });
                } else if (action === 'move_folder') {
                    fd.append('folder', document.getElementById('fha-bulk-folder-select').value);
                }

                fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(r){
                        document.getElementById('fha-bulk-modal').classList.remove('open');
                        if (r.success) { selectedIds = []; updateBulkBar(); loadAssets(); }
                        else alert(r.data && r.data.message || 'Bulk action failed.');
                    });
            };

            /* ── Init: auto-scan then load ── */
            (function(){
                var fd = new FormData();
                fd.append('action', 'fishotel_scan_assets');
                fd.append('nonce', nonce);
                fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(){ loadAssets(); });
            })();

        })();
        </script>
        <?php
    }

    /**
     * Auto-scan asset folders (used on first load).
     */
    private function hotel_asset_auto_scan() {
        $base    = plugin_dir_path( FISHOTEL_PLUGIN_FILE ) . 'assists/';
        $folders = [
            'scene-layers'      => $base . 'scene-layers/',
            'scene-backgrounds' => $base . 'scene/',
            'stamps'            => $base . 'stamps/',
        ];
        $cat_map = [
            'scene-layers'      => 'layer',
            'scene-backgrounds' => 'background',
            'stamps'            => 'stamp',
        ];

        $library = [ 'assets' => [] ];

        foreach ( $folders as $folder_key => $dir_path ) {
            if ( ! is_dir( $dir_path ) ) {
                wp_mkdir_p( $dir_path );
                continue;
            }
            $files = glob( $dir_path . '*.{png,jpg,jpeg,gif,webp}', GLOB_BRACE );
            if ( ! $files ) continue;

            foreach ( $files as $file ) {
                $fname = basename( $file );
                $info  = @getimagesize( $file );
                $library['assets'][] = [
                    'id'          => 'asset_' . wp_rand() . '_' . time(),
                    'filename'    => $fname,
                    'folder'      => $folder_key,
                    'label'       => pathinfo( $fname, PATHINFO_FILENAME ),
                    'category'    => $cat_map[ $folder_key ] ?? 'layer',
                    'scene_types' => [],
                    'time_bands'  => [],
                    'tags'        => [],
                    'uploaded'    => filemtime( $file ),
                    'width'       => $info ? $info[0] : 0,
                    'height'      => $info ? $info[1] : 0,
                    'filesize'    => filesize( $file ),
                ];
            }
        }

        update_option( 'fishotel_asset_library', $library );
    }
}
