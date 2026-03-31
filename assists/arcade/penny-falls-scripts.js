/**
 * FisHotel Arcade — Penny Falls Coin Pusher
 *
 * Physics v3: Pusher is a solid wall. Coins cannot pass through it.
 * The pusher sweeps forward and drags/pushes every coin in its path.
 *
 * Reads fishotelArcade from wp_localize_script:
 *   { ajaxUrl, nonce, pennyFalls: { playfield, coin, pusher } }
 */
(function ($) {
    'use strict';

    /* ─── Tunables ──────────────────────────────────── */
    var FIELD = {
        left:       8,      // % left bound of playable area
        right:      92,     // % right bound
        top:        2,      // % top of field
        pusherHome: 8,      // % pusher retracted position (top)
        pusherMax:  45,     // % pusher fully extended
        bottom:     94,     // % bottom edge — coins past here = win
        dropY:      3       // % where new coins appear
    };

    var COIN_SIZE      = 8;     // % of field width (collision diameter)
    var PUSHER_SPEED   = 0.02;  // radians per tick (oscillation speed)
    var PUSHER_IMG_H   = 6;     // approx % height the pusher image takes up
    var TICK_MS        = 30;    // ms per game tick
    var DROP_COST      = 1;     // nickels per coin drop
    var TICKET_PER     = 1;     // tickets per coin that falls off the front
    var MAX_COINS      = 55;    // max coins on field
    var START_COINS    = 22;    // pre-loaded coins
    var FRICTION       = 0.78;  // velocity damping per tick
    var MIN_VEL        = 0.03;  // below this, coin stops
    var PUSH_TRANSFER  = 0.5;   // velocity transferred in collision
    var FALL_SPEED     = 1.2;   // speed of coins falling from drop zone
    var JITTER         = 0.15;  // random lateral nudge when pushed

    /* ─── State ─────────────────────────────────────── */
    var coins          = [];
    var nextId         = 0;
    var pusherPhase    = 0;
    var pusherY        = FIELD.pusherHome;
    var prevPusherY    = FIELD.pusherHome;
    var gameTimer      = null;
    var dropSliderX    = 50;
    var dropSliderDir  = 1;
    var dropSliderSpd  = 0.8;
    var dropTimer      = null;
    var isDropping     = false;
    var pendingTix     = 0;
    var awardTimer     = null;
    var coinImg        = '';

    /* ─── DOM cache ─────────────────────────────────── */
    var $field, $pusher, $indicator, $chips, $tickets, $msg, $err, $dropBtn;

    /* ═══════════════════════════════════════════════════
       RENDER
       ═══════════════════════════════════════════════════ */
    window.renderPennyFalls = function (body) {
        cleanup();

        var pf       = fishotelArcade.pennyFalls || {};
        var fieldImg = pf.playfield || '';
        coinImg      = pf.coin || '';
        var pushImg  = pf.pusher || '';

        body.innerHTML =
            '<div class="fh-pf-game" id="fh-penny-falls">' +
                '<div class="fh-pf-machine">' +
                    '<img src="' + fieldImg + '" class="fh-pf-bg" alt="Penny Falls" draggable="false">' +
                    '<div class="fh-pf-field" id="fh-pf-field">' +
                        '<div class="fh-pf-pusher" id="fh-pf-pusher">' +
                            '<img src="' + pushImg + '" alt="Pusher" draggable="false">' +
                        '</div>' +
                        '<div class="fh-pf-indicator" id="fh-pf-indicator">' +
                            '<img src="' + coinImg + '" alt="" draggable="false">' +
                        '</div>' +
                    '</div>' +
                '</div>' +
                '<div class="fh-pf-controls">' +
                    '<button type="button" id="fh-pf-drop" class="fh-pf-drop-btn">DROP!</button>' +
                    ' <button type="button" id="fh-pf-debug-toggle" style="font-size:11px;padding:4px 8px;background:#333;color:#aaa;border:1px solid #555;border-radius:4px;cursor:pointer;vertical-align:middle;">Debug</button>' +
                '</div>' +
                '<div class="fh-pf-messages">' +
                    '<div class="fh-pf-msg" id="fh-pf-msg"></div>' +
                    '<div class="fh-pf-err" id="fh-pf-err"></div>' +
                '</div>' +
            '</div>';

        $field     = $('#fh-pf-field');
        $pusher    = $('#fh-pf-pusher');
        $indicator = $('#fh-pf-indicator');
        $chips     = $('#fh-arcade-chips-pf').length ? $('#fh-arcade-chips-pf') : $('#fh-arcade-chips');
        $tickets   = $('#fh-arcade-tickets-pf').length ? $('#fh-arcade-tickets-pf') : $('#fh-arcade-tickets');
        $msg       = $('#fh-pf-msg');
        $err       = $('#fh-pf-err');
        $dropBtn   = $('#fh-pf-drop');

        preload();
        gameTimer = setInterval(tick, TICK_MS);
        dropTimer = setInterval(moveIndicator, TICK_MS);
        $dropBtn.on('click', doDrop);
        $field.on('click', function (e) {
            var rect = $field[0].getBoundingClientRect();
            if (((e.clientY - rect.top) / rect.height) * 100 < 15) doDrop();
        });

        /* Debug toggle */
        debugOn = false;
        $('#fh-pf-debug-toggle').on('click', function () {
            debugOn = !debugOn;
            $(this).css('color', debugOn ? '#0f0' : '#aaa');
            if (debugOn) {
                buildDebugOverlay();
            } else {
                $('#fh-pf-debug-overlay').remove();
                $('#fh-pf-debug-wall').remove();
                $('#fh-pf-debug-info').remove();
            }
        });
    };

    /* ═══════════════════════════════════════════════════
       DEBUG OVERLAY — shows all zones as colored boxes
       ═══════════════════════════════════════════════════ */
    var debugOn = false;

    function buildDebugOverlay() {
        /* Remove old debug elements */
        $('#fh-pf-debug-overlay, #fh-pf-debug-wall, #fh-pf-debug-info').remove();

        var css = 'position:absolute;pointer-events:none;z-index:50;font-family:monospace;font-size:9px;color:#fff;text-shadow:0 0 2px #000;';

        /* Field bounds — green border */
        $field.append(
            '<div id="fh-pf-debug-overlay" style="' + css + 'left:' + FIELD.left + '%;right:' + (100 - FIELD.right) + '%;top:' + FIELD.top + '%;bottom:' + (100 - FIELD.bottom) + '%;border:2px solid lime;background:rgba(0,255,0,0.05);">' +
                '<span style="position:absolute;top:2px;left:2px;color:lime;">FIELD BOUNDS</span>' +
            '</div>'
        );

        /* Bottom edge — red line (win zone) */
        $field.append(
            '<div style="' + css + 'left:' + FIELD.left + '%;right:' + (100 - FIELD.right) + '%;top:' + FIELD.bottom + '%;height:2px;background:red;">' +
                '<span style="position:absolute;top:-14px;right:2px;color:red;">WIN EDGE (' + FIELD.bottom + '%)</span>' +
            '</div>'
        );

        /* Drop zone — yellow line */
        $field.append(
            '<div style="' + css + 'left:' + FIELD.left + '%;right:' + (100 - FIELD.right) + '%;top:' + FIELD.dropY + '%;height:2px;background:yellow;">' +
                '<span style="position:absolute;top:2px;left:2px;color:yellow;">DROP Y (' + FIELD.dropY + '%)</span>' +
            '</div>'
        );

        /* Pusher wall line — cyan, updates in tick */
        $field.append(
            '<div id="fh-pf-debug-wall" style="' + css + 'left:' + FIELD.left + '%;right:' + (100 - FIELD.right) + '%;top:0;height:2px;background:cyan;">' +
                '<span style="position:absolute;top:2px;left:2px;color:cyan;">WALL (pusherY + IMG_H)</span>' +
            '</div>'
        );

        /* Pusher home / max range — dashed lines */
        $field.append(
            '<div style="' + css + 'left:' + FIELD.left + '%;right:' + (100 - FIELD.right) + '%;top:' + FIELD.pusherHome + '%;height:1px;border-top:1px dashed #ff0;">' +
                '<span style="position:absolute;top:2px;left:2px;color:#ff0;">PUSHER HOME (' + FIELD.pusherHome + '%)</span>' +
            '</div>'
        );
        $field.append(
            '<div style="' + css + 'left:' + FIELD.left + '%;right:' + (100 - FIELD.right) + '%;top:' + FIELD.pusherMax + '%;height:1px;border-top:1px dashed #f80;">' +
                '<span style="position:absolute;top:2px;left:2px;color:#f80;">PUSHER MAX (' + FIELD.pusherMax + '%)</span>' +
            '</div>'
        );
        $field.append(
            '<div style="' + css + 'left:' + FIELD.left + '%;right:' + (100 - FIELD.right) + '%;top:' + (FIELD.pusherMax + PUSHER_IMG_H) + '%;height:1px;border-top:1px dashed #f00;">' +
                '<span style="position:absolute;top:2px;left:2px;color:#f00;">WALL AT MAX (' + (FIELD.pusherMax + PUSHER_IMG_H) + '%)</span>' +
            '</div>'
        );

        /* Info panel */
        $field.append(
            '<div id="fh-pf-debug-info" style="' + css + 'bottom:4px;left:4px;background:rgba(0,0,0,0.7);padding:4px 6px;border-radius:3px;line-height:1.4;z-index:60;">' +
                'Loading...' +
            '</div>'
        );
    }

    function updateDebug() {
        if (!debugOn) return;
        var wallY = pusherY + PUSHER_IMG_H;
        var $wall = $('#fh-pf-debug-wall');
        if ($wall.length) $wall.css('top', wallY + '%');

        var $info = $('#fh-pf-debug-info');
        if ($info.length) {
            $info.html(
                'pusherY: ' + pusherY.toFixed(1) + '%<br>' +
                'wallY: ' + wallY.toFixed(1) + '%<br>' +
                'coins: ' + coins.length + '<br>' +
                'moving: ' + coins.filter(function(c){return Math.abs(c.vx)+Math.abs(c.vy) > MIN_VEL;}).length
            );
        }
    }

    /* ═══════════════════════════════════════════════════
       PRE-LOAD — packed grid below the pusher's max reach
       ═══════════════════════════════════════════════════ */
    function preload() {
        var cols   = 6;
        var rows   = Math.ceil(START_COINS / cols);
        var xRange = FIELD.right - FIELD.left - COIN_SIZE;
        var xStep  = xRange / (cols - 1);
        var yStart = FIELD.pusherMax + PUSHER_IMG_H + COIN_SIZE * 0.6;
        var yStep  = COIN_SIZE * 0.85;
        var count  = 0;

        for (var r = 0; r < rows && count < START_COINS; r++) {
            var off = (r % 2 === 0) ? 0 : xStep * 0.5;
            for (var c = 0; c < cols && count < START_COINS; c++) {
                var x = FIELD.left + COIN_SIZE / 2 + c * xStep + off;
                var y = yStart + r * yStep;
                x += (Math.random() - 0.5) * 1.5;
                y += (Math.random() - 0.5) * 1;
                x = clampX(x);
                if (y < FIELD.bottom - 4) {
                    spawn(x, y, true);
                    count++;
                }
            }
        }
        for (var p = 0; p < 20; p++) settleOverlaps();
        updateDOM();
    }

    /* ═══════════════════════════════════════════════════
       SPAWN
       ═══════════════════════════════════════════════════ */
    function spawn(x, y, silent) {
        var id  = nextId++;
        var rot = Math.floor(Math.random() * 360);
        var sc  = 0.88 + Math.random() * 0.2;
        var el  = $('<div class="fh-pf-coin' + (silent ? '' : ' fh-pf-coin-new') + '">' +
                    '<img src="' + coinImg + '" draggable="false"></div>');
        el.css({
            left: x + '%', top: y + '%',
            transform: 'translate(-50%,-50%) rotate(' + rot + 'deg) scale(' + sc + ')'
        });
        $field.append(el);
        coins.push({ id: id, x: x, y: y, vx: 0, vy: 0, el: el, rot: rot, sc: sc });
    }

    /* ═══════════════════════════════════════════════════
       DROP INDICATOR
       ═══════════════════════════════════════════════════ */
    function moveIndicator() {
        if (!$indicator || !$indicator.length) return;
        dropSliderX += dropSliderDir * dropSliderSpd;
        if (dropSliderX > FIELD.right - COIN_SIZE / 2) { dropSliderDir = -1; }
        if (dropSliderX < FIELD.left  + COIN_SIZE / 2) { dropSliderDir =  1; }
        dropSliderX = Math.max(FIELD.left + COIN_SIZE/2, Math.min(FIELD.right - COIN_SIZE/2, dropSliderX));
        $indicator.css('left', dropSliderX + '%');
    }

    /* ═══════════════════════════════════════════════════
       DROP — player action
       ═══════════════════════════════════════════════════ */
    function doDrop() {
        if (isDropping) return;
        if (coins.length >= MAX_COINS) { showErr('Table full!'); return; }
        var have = parseInt($chips.text(), 10) || 0;
        if (have < DROP_COST) { showErr('Not enough nickels!'); return; }

        isDropping = true;
        $err.text('');
        $dropBtn.prop('disabled', true);

        $.post(fishotelArcade.ajaxUrl, {
            action: 'fishotel_penny_falls_drop',
            nonce:  fishotelArcade.nonce,
            cost:   DROP_COST
        }, function (r) {
            isDropping = false;
            $dropBtn.prop('disabled', false);
            if (r.success) {
                $chips.text(r.data.chips);
                spawn(dropSliderX, FIELD.dropY, false);
            } else { showErr(r.data.message || 'Error'); }
        }).fail(function () {
            isDropping = false;
            $dropBtn.prop('disabled', false);
            showErr('Connection error');
        });
    }

    /* ═══════════════════════════════════════════════════
       GAME TICK
       ═══════════════════════════════════════════════════ */
    function tick() {
        if (!document.getElementById('fh-penny-falls')) { cleanup(); return; }

        /* ── 1. Pusher oscillation ── */
        pusherPhase += PUSHER_SPEED;
        if (pusherPhase > Math.PI * 2) pusherPhase -= Math.PI * 2;
        var t = (Math.sin(pusherPhase) + 1) / 2;
        pusherY = FIELD.pusherHome + t * (FIELD.pusherMax - FIELD.pusherHome);
        var pusherDelta = pusherY - prevPusherY;          // +ve = advancing
        prevPusherY = pusherY;
        $pusher.css('top', pusherY + '%');

        /* The pusher's BOTTOM EDGE — the solid wall nothing can pass */
        var wallY = pusherY + PUSHER_IMG_H;

        /* ── 2. Pusher wall enforcement ──
         * ANY coin whose top is above the wall gets shoved to just below it.
         * This is the core mechanic: the pusher is an impenetrable barrier. */
        coins.forEach(function (c) {
            var coinTop = c.y - COIN_SIZE * 0.4; // top edge of coin
            if (coinTop < wallY && c.y > pusherY - COIN_SIZE) {
                /* Coin is overlapping the pusher — push it below the wall */
                var newY = wallY + COIN_SIZE * 0.4;
                if (newY > c.y) {
                    /* Transfer pusher movement as velocity */
                    c.vy = Math.max(c.vy, (newY - c.y) * 1.5);
                    c.vx += (Math.random() - 0.5) * JITTER;
                    c.y = newY;
                }
            }
        });

        /* ── 3. Gravity for coins falling from drop zone ──
         * Coins above the pusher top are in free-fall until they
         * land on the pusher wall or on coins below the pusher */
        coins.forEach(function (c) {
            if (c.y < pusherY - COIN_SIZE * 0.5) {
                c.vy += FALL_SPEED;
            }
        });

        /* ── 4. Apply velocity + friction ── */
        coins.forEach(function (c) {
            if (c.vx === 0 && c.vy === 0) return;
            c.x += c.vx;
            c.y += c.vy;
            c.vx *= FRICTION;
            c.vy *= FRICTION;
            if (Math.abs(c.vx) < MIN_VEL) c.vx = 0;
            if (Math.abs(c.vy) < MIN_VEL) c.vy = 0;
        });

        /* ── 5. Collisions with momentum transfer ── */
        resolveCollisions();

        /* ── 6. Re-enforce pusher wall after collisions ──
         * (collisions can push coins back into the pusher) */
        coins.forEach(function (c) {
            var coinTop = c.y - COIN_SIZE * 0.4;
            if (coinTop < wallY && c.y > pusherY - COIN_SIZE) {
                c.y = wallY + COIN_SIZE * 0.4;
                if (c.vy < 0) c.vy = 0; // kill upward velocity
            }
        });

        /* ── 7. Fallen coins ── */
        checkFallen();

        /* ── 8. DOM ── */
        updateDOM();

        /* ── 9. Debug overlay ── */
        updateDebug();
    }

    /* ═══════════════════════════════════════════════════
       COLLISION — separate + transfer momentum
       ═══════════════════════════════════════════════════ */
    function resolveCollisions() {
        var minD = COIN_SIZE * 0.78;
        for (var pass = 0; pass < 4; pass++) {
            for (var i = 0; i < coins.length; i++) {
                for (var j = i + 1; j < coins.length; j++) {
                    var a = coins[i], b = coins[j];
                    var dx = b.x - a.x;
                    var dy = b.y - a.y;
                    var dist = Math.sqrt(dx * dx + dy * dy);
                    if (dist >= minD || dist < 0.01) continue;

                    var overlap = (minD - dist) / 2;
                    var nx = dx / dist;
                    var ny = dy / dist;

                    /* Separate */
                    a.x -= nx * overlap;
                    a.y -= ny * overlap;
                    b.x += nx * overlap;
                    b.y += ny * overlap;

                    /* Transfer velocity from faster to slower */
                    var aSpd = Math.abs(a.vx) + Math.abs(a.vy);
                    var bSpd = Math.abs(b.vx) + Math.abs(b.vy);

                    if (aSpd > bSpd && aSpd > MIN_VEL) {
                        b.vx += a.vx * PUSH_TRANSFER;
                        b.vy += a.vy * PUSH_TRANSFER;
                        a.vx *= (1 - PUSH_TRANSFER * 0.6);
                        a.vy *= (1 - PUSH_TRANSFER * 0.6);
                    } else if (bSpd > aSpd && bSpd > MIN_VEL) {
                        a.vx += b.vx * PUSH_TRANSFER;
                        a.vy += b.vy * PUSH_TRANSFER;
                        b.vx *= (1 - PUSH_TRANSFER * 0.6);
                        b.vy *= (1 - PUSH_TRANSFER * 0.6);
                    }
                }
            }
        }

        /* Clamp walls */
        coins.forEach(function (c) {
            c.x = clampX(c.x);
            if (c.x <= FIELD.left + COIN_SIZE / 2 || c.x >= FIELD.right - COIN_SIZE / 2) c.vx = 0;
            if (c.y < FIELD.top) { c.y = FIELD.top; c.vy = 0; }
        });
    }

    /* ═══════════════════════════════════════════════════
       SETTLE — position-only (preload)
       ═══════════════════════════════════════════════════ */
    function settleOverlaps() {
        var minD = COIN_SIZE * 0.78;
        for (var i = 0; i < coins.length; i++) {
            for (var j = i + 1; j < coins.length; j++) {
                var a = coins[i], b = coins[j];
                var dx = b.x - a.x, dy = b.y - a.y;
                var dist = Math.sqrt(dx * dx + dy * dy);
                if (dist < minD && dist > 0.01) {
                    var ov = (minD - dist) / 2;
                    var nx = dx / dist, ny = dy / dist;
                    a.x -= nx * ov; a.y -= ny * ov;
                    b.x += nx * ov; b.y += ny * ov;
                }
            }
        }
        coins.forEach(function (c) { c.x = clampX(c.x); });
    }

    /* ═══════════════════════════════════════════════════
       FALLEN COINS
       ═══════════════════════════════════════════════════ */
    function checkFallen() {
        var won = 0;
        coins = coins.filter(function (c) {
            if (c.y >= FIELD.bottom) {
                c.el.addClass('fh-pf-coin-fall');
                (function (el) { setTimeout(function () { el.remove(); }, 500); })(c.el);
                won++;
                return false;
            }
            return true;
        });
        if (won > 0) {
            pendingTix += won * TICKET_PER;
            showWin(won);
            clearTimeout(awardTimer);
            awardTimer = setTimeout(flushAward, 800);
        }
    }

    /* ═══════════════════════════════════════════════════
       AWARD TICKETS
       ═══════════════════════════════════════════════════ */
    function flushAward() {
        if (pendingTix <= 0) return;
        var amt = pendingTix;
        pendingTix = 0;
        $.post(fishotelArcade.ajaxUrl, {
            action: 'fishotel_penny_falls_award', nonce: fishotelArcade.nonce, tickets: amt
        }, function (r) { if (r.success) $tickets.text(r.data.tickets); });
    }

    /* ═══════════════════════════════════════════════════
       DOM helpers
       ═══════════════════════════════════════════════════ */
    function clampX(x) {
        return Math.max(FIELD.left + COIN_SIZE / 2, Math.min(FIELD.right - COIN_SIZE / 2, x));
    }

    function updateDOM() {
        coins.forEach(function (c) {
            c.el.css({ left: c.x + '%', top: c.y + '%' });
        });
    }

    function showWin(n) {
        $msg.text('+' + n + ' ticket' + (n > 1 ? 's' : '') + '!')
            .removeClass('show').addClass('win');
        requestAnimationFrame(function () { $msg.addClass('show'); });
        setTimeout(function () { $msg.removeClass('show'); }, 1800);
    }

    function showErr(s) {
        $err.text(s);
        setTimeout(function () { $err.text(''); }, 2500);
    }

    /* ═══════════════════════════════════════════════════
       CLEANUP
       ═══════════════════════════════════════════════════ */
    function cleanup() {
        clearInterval(gameTimer);  gameTimer = null;
        clearInterval(dropTimer);  dropTimer = null;
        clearTimeout(awardTimer);
        if (pendingTix > 0) flushAward();
        coins = []; nextId = 0; pusherPhase = 0;
        pusherY = FIELD.pusherHome; prevPusherY = FIELD.pusherHome;
        pendingTix = 0; isDropping = false;
    }
    window.cleanupPennyFalls = cleanup;

})(jQuery);
