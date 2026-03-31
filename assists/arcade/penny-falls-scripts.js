/**
 * FisHotel Arcade — Penny Falls Coin Pusher
 *
 * Reads fishotelArcade from wp_localize_script:
 *   { ajaxUrl, nonce, pennyFalls: { playfield, coin, pusher } }
 */
(function ($) {
    'use strict';

    /* ─── Tunables ──────────────────────────────────── */
    var FIELD = {
        left:       10,     // % left bound of playable area
        right:      90,     // % right bound
        top:        4,      // % top of coin area
        pusherHome: 10,     // % pusher retracted (top)
        pusherMax:  50,     // % pusher fully extended
        bottom:     92,     // % bottom edge — coins past here = win
        dropY:      4       // % where new coins appear
    };

    var COIN_SIZE     = 9;      // % of field width (collision diameter)
    var PUSHER_SPEED  = 0.02;   // radians per tick (oscillation speed)
    var TICK_MS       = 30;     // ms per game tick
    var DROP_COST     = 1;      // nickels per coin drop
    var TICKET_PER    = 1;      // tickets per coin that falls off
    var MAX_COINS     = 55;     // max coins on field before blocking drops
    var START_COINS   = 20;     // pre-loaded coins on the tray
    var GRAVITY       = 0.03;   // gentle forward slide per tick
    var PUSHER_DEPTH  = 5;      // % height of the pusher's push zone

    /* ─── State ─────────────────────────────────────── */
    var coins         = [];
    var nextId        = 0;
    var pusherPhase   = 0;
    var pusherY       = FIELD.pusherHome;
    var gameTimer     = null;
    var dropSliderX   = 50;
    var dropSliderDir = 1;
    var dropSliderSpd = 0.8;
    var dropTimer     = null;
    var isDropping    = false;
    var pendingTix    = 0;
    var awardTimer    = null;
    var coinImg       = '';

    /* ─── DOM cache ─────────────────────────────────── */
    var $field, $pusher, $indicator, $chips, $tickets, $msg, $err, $dropBtn;

    /* ═══════════════════════════════════════════════════
       RENDER — called from loadRoomContent()
       ═══════════════════════════════════════════════════ */
    window.renderPennyFalls = function (body) {
        // Clean up any previous instance
        cleanup();

        var pf        = fishotelArcade.pennyFalls || {};
        var fieldImg  = pf.playfield || '';
        coinImg       = pf.coin || '';
        var pushImg   = pf.pusher || '';

        body.innerHTML =
            '<div class="fh-pf-game" id="fh-penny-falls">' +

                /* Machine backdrop + interactive field overlay */
                '<div class="fh-pf-machine">' +
                    '<img src="' + fieldImg + '" class="fh-pf-bg" alt="Penny Falls" draggable="false">' +
                    '<div class="fh-pf-field" id="fh-pf-field">' +
                        /* Pusher shelf */
                        '<div class="fh-pf-pusher" id="fh-pf-pusher">' +
                            '<img src="' + pushImg + '" alt="Pusher" draggable="false">' +
                        '</div>' +
                        /* Drop indicator coin at top */
                        '<div class="fh-pf-indicator" id="fh-pf-indicator">' +
                            '<img src="' + coinImg + '" alt="" draggable="false">' +
                        '</div>' +
                    '</div>' +
                '</div>' +

                /* Controls */
                '<div class="fh-pf-controls">' +
                    '<button type="button" id="fh-pf-drop" class="fh-pf-drop-btn">DROP!</button>' +
                '</div>' +

                /* Result / error messages */
                '<div class="fh-pf-messages">' +
                    '<div class="fh-pf-msg" id="fh-pf-msg"></div>' +
                    '<div class="fh-pf-err" id="fh-pf-err"></div>' +
                '</div>' +

            '</div>';

        /* Cache DOM */
        $field     = $('#fh-pf-field');
        $pusher    = $('#fh-pf-pusher');
        $indicator = $('#fh-pf-indicator');
        /* Use Penny Falls modal balance spans, fall back to main arcade ones */
        $chips     = $('#fh-arcade-chips-pf').length ? $('#fh-arcade-chips-pf') : $('#fh-arcade-chips');
        $tickets   = $('#fh-arcade-tickets-pf').length ? $('#fh-arcade-tickets-pf') : $('#fh-arcade-tickets');
        $msg       = $('#fh-pf-msg');
        $err       = $('#fh-pf-err');
        $dropBtn   = $('#fh-pf-drop');

        /* Scatter starting coins */
        preload();

        /* Start loops */
        gameTimer = setInterval(tick, TICK_MS);
        dropTimer = setInterval(moveIndicator, TICK_MS);

        /* Drop button */
        $dropBtn.on('click', doDrop);

        /* Click field top area to drop */
        $field.on('click', function (e) {
            var rect = $field[0].getBoundingClientRect();
            var pctY = ((e.clientY - rect.top) / rect.height) * 100;
            if (pctY < 12) doDrop();
        });
    };

    /* ═══════════════════════════════════════════════════
       PRE-LOAD starting coins
       ═══════════════════════════════════════════════════ */
    function preload() {
        for (var i = 0; i < START_COINS; i++) {
            var x = FIELD.left + COIN_SIZE / 2 +
                    Math.random() * (FIELD.right - FIELD.left - COIN_SIZE);
            var y = FIELD.pusherMax + PUSHER_DEPTH + 2 +
                    Math.random() * (FIELD.bottom - FIELD.pusherMax - PUSHER_DEPTH - 12);
            spawn(x, y, true);
        }
        /* Resolve initial overlaps */
        for (var p = 0; p < 15; p++) resolveCollisions();
        updateDOM();
    }

    /* ═══════════════════════════════════════════════════
       SPAWN a coin
       ═══════════════════════════════════════════════════ */
    function spawn(x, y, silent) {
        var id  = nextId++;
        var rot = Math.floor(Math.random() * 360);
        var sc  = 0.85 + Math.random() * 0.25;
        var el  = $('<div class="fh-pf-coin' + (silent ? '' : ' fh-pf-coin-new') + '">' +
                    '<img src="' + coinImg + '" draggable="false"></div>');
        el.css({
            left: x + '%',
            top:  y + '%',
            transform: 'translate(-50%,-50%) rotate(' + rot + 'deg) scale(' + sc + ')'
        });
        $field.append(el);
        coins.push({ id: id, x: x, y: y, el: el, rot: rot, sc: sc });
    }

    /* ═══════════════════════════════════════════════════
       DROP INDICATOR — slides left ↔ right
       ═══════════════════════════════════════════════════ */
    function moveIndicator() {
        if (!$indicator || !$indicator.length) return;
        dropSliderX += dropSliderDir * dropSliderSpd;
        if (dropSliderX > FIELD.right - COIN_SIZE / 2) {
            dropSliderX = FIELD.right - COIN_SIZE / 2;
            dropSliderDir = -1;
        }
        if (dropSliderX < FIELD.left + COIN_SIZE / 2) {
            dropSliderX = FIELD.left + COIN_SIZE / 2;
            dropSliderDir = 1;
        }
        $indicator.css('left', dropSliderX + '%');
    }

    /* ═══════════════════════════════════════════════════
       DROP — player action
       ═══════════════════════════════════════════════════ */
    function doDrop() {
        if (isDropping) return;
        if (coins.length >= MAX_COINS) { showErr('Table full — wait for coins to fall!'); return; }

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
            } else {
                showErr(r.data.message || 'Error');
            }
        }).fail(function () {
            isDropping = false;
            $dropBtn.prop('disabled', false);
            showErr('Connection error');
        });
    }

    /* ═══════════════════════════════════════════════════
       GAME TICK — pusher + physics
       ═══════════════════════════════════════════════════ */
    function tick() {
        /* Self-cleanup if popup was closed */
        if (!document.getElementById('fh-penny-falls')) { cleanup(); return; }

        /* 1. Pusher oscillation */
        pusherPhase += PUSHER_SPEED;
        if (pusherPhase > Math.PI * 2) pusherPhase -= Math.PI * 2;
        var t = (Math.sin(pusherPhase) + 1) / 2;          // 0→1
        pusherY = FIELD.pusherHome + t * (FIELD.pusherMax - FIELD.pusherHome);
        $pusher.css('top', pusherY + '%');

        /* 2. Pusher pushes coins */
        var front = pusherY + PUSHER_DEPTH;
        coins.forEach(function (c) {
            if (c.y < front + COIN_SIZE * 0.4) {
                c.y = front + COIN_SIZE * 0.4 + Math.random() * 0.3;
                c.x += (Math.random() - 0.5) * 0.6;       // slight lateral nudge
            }
        });

        /* 3. Gravity — gentle forward slide */
        coins.forEach(function (c) {
            if (c.y > front + COIN_SIZE) {
                c.y += GRAVITY;
            }
        });

        /* 4. Collisions */
        resolveCollisions();

        /* 5. Fallen coins */
        checkFallen();

        /* 6. DOM update */
        updateDOM();
    }

    /* ═══════════════════════════════════════════════════
       COLLISION RESOLUTION
       ═══════════════════════════════════════════════════ */
    function resolveCollisions() {
        var minD = COIN_SIZE * 0.82;
        for (var pass = 0; pass < 3; pass++) {
            for (var i = 0; i < coins.length; i++) {
                for (var j = i + 1; j < coins.length; j++) {
                    var a = coins[i], b = coins[j];
                    var dx = b.x - a.x;
                    var dy = b.y - a.y;
                    var dist = Math.sqrt(dx * dx + dy * dy);
                    if (dist < minD && dist > 0.01) {
                        var overlap = (minD - dist) / 2;
                        var nx = dx / dist;
                        var ny = dy / dist;

                        /* The coin further forward (higher y) gets pushed MORE forward */
                        if (a.y > b.y) {
                            a.x -= nx * overlap * 0.7;
                            a.y -= ny * overlap * 0.7;
                            b.x += nx * overlap * 0.3;
                            b.y += ny * overlap * 0.3;
                        } else {
                            b.x += nx * overlap * 0.7;
                            b.y += ny * overlap * 0.7;
                            a.x -= nx * overlap * 0.3;
                            a.y -= ny * overlap * 0.3;
                        }
                    }
                }
            }
        }
        /* Clamp to field bounds */
        coins.forEach(function (c) {
            if (c.x < FIELD.left + COIN_SIZE / 2)  c.x = FIELD.left + COIN_SIZE / 2;
            if (c.x > FIELD.right - COIN_SIZE / 2) c.x = FIELD.right - COIN_SIZE / 2;
        });
    }

    /* ═══════════════════════════════════════════════════
       FALLEN COINS — score tickets
       ═══════════════════════════════════════════════════ */
    function checkFallen() {
        var won = 0;
        coins = coins.filter(function (c) {
            if (c.y >= FIELD.bottom) {
                c.el.addClass('fh-pf-coin-fall');
                (function (el) {
                    setTimeout(function () { el.remove(); }, 500);
                })(c.el);
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
       AWARD TICKETS via AJAX (batched / debounced)
       ═══════════════════════════════════════════════════ */
    function flushAward() {
        if (pendingTix <= 0) return;
        var amt = pendingTix;
        pendingTix = 0;
        $.post(fishotelArcade.ajaxUrl, {
            action:  'fishotel_penny_falls_award',
            nonce:   fishotelArcade.nonce,
            tickets: amt
        }, function (r) {
            if (r.success) $tickets.text(r.data.tickets);
        });
    }

    /* ═══════════════════════════════════════════════════
       DOM helpers
       ═══════════════════════════════════════════════════ */
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
        coins = [];
        nextId = 0;
        pusherPhase = 0;
        pendingTix = 0;
        isDropping = false;
    }
    window.cleanupPennyFalls = cleanup;

})(jQuery);
