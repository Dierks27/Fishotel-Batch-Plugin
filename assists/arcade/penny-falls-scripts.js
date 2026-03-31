/**
 * FisHotel Arcade — Penny Falls Coin Pusher
 *
 * Physics v2: velocity-based with friction and momentum transfer.
 * Coins sit still until pushed. Pusher is the only force.
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
        top:        2,      // % top of field
        pusherHome: 10,     // % pusher retracted position (top)
        pusherMax:  48,     // % pusher fully extended
        bottom:     93,     // % bottom edge — coins past here = win
        dropY:      4       // % where new coins appear
    };

    var COIN_SIZE      = 8;     // % of field width (collision diameter)
    var PUSHER_SPEED   = 0.018; // radians per tick (oscillation speed)
    var TICK_MS        = 30;    // ms per game tick
    var DROP_COST      = 1;     // nickels per coin drop
    var TICKET_PER     = 1;     // tickets per coin that falls off the front
    var MAX_COINS      = 55;    // max coins on field
    var START_COINS    = 22;    // pre-loaded coins
    var PUSHER_DEPTH   = 4;     // % height of the pusher bar
    var FRICTION       = 0.82;  // velocity damping per tick (lower = more friction)
    var MIN_VEL        = 0.02;  // below this, coin stops completely
    var PUSH_TRANSFER  = 0.55;  // fraction of velocity transferred in collision
    var FALL_GRAVITY   = 0.8;   // gravity for coins falling from the drop zone
    var JITTER         = 0.12;  // random lateral nudge when pushed

    /* ─── State ─────────────────────────────────────── */
    var coins          = [];
    var nextId         = 0;
    var pusherPhase    = 0;
    var pusherY        = FIELD.pusherHome;
    var lastPusherY    = FIELD.pusherHome;
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
       RENDER — called when game modal opens
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
            var pctY = ((e.clientY - rect.top) / rect.height) * 100;
            if (pctY < 15) doDrop();
        });
    };

    /* ═══════════════════════════════════════════════════
       PRE-LOAD — dense pack of coins below the pusher
       ═══════════════════════════════════════════════════ */
    function preload() {
        /* Pack coins in a grid-ish pattern so they form a solid mass */
        var cols = 6;
        var rows = Math.ceil(START_COINS / cols);
        var xStep = (FIELD.right - FIELD.left - COIN_SIZE) / (cols - 1);
        var yStart = FIELD.pusherMax + PUSHER_DEPTH + COIN_SIZE * 0.6;
        var yStep = COIN_SIZE * 0.85;
        var count = 0;

        for (var r = 0; r < rows && count < START_COINS; r++) {
            var offset = (r % 2 === 0) ? 0 : xStep * 0.5; // stagger rows
            for (var c = 0; c < cols && count < START_COINS; c++) {
                var x = FIELD.left + COIN_SIZE / 2 + c * xStep + offset;
                var y = yStart + r * yStep;
                /* Add slight randomness so it doesn't look robotic */
                x += (Math.random() - 0.5) * 2;
                y += (Math.random() - 0.5) * 1.5;
                /* Clamp x */
                x = Math.max(FIELD.left + COIN_SIZE / 2, Math.min(FIELD.right - COIN_SIZE / 2, x));
                if (y < FIELD.bottom - 2) {
                    spawn(x, y, true);
                    count++;
                }
            }
        }
        /* Settle overlaps without velocity */
        for (var p = 0; p < 20; p++) settleOverlaps();
        updateDOM();
    }

    /* ═══════════════════════════════════════════════════
       SPAWN a coin
       ═══════════════════════════════════════════════════ */
    function spawn(x, y, silent) {
        var id  = nextId++;
        var rot = Math.floor(Math.random() * 360);
        var sc  = 0.88 + Math.random() * 0.2;
        var el  = $('<div class="fh-pf-coin' + (silent ? '' : ' fh-pf-coin-new') + '">' +
                    '<img src="' + coinImg + '" draggable="false"></div>');
        el.css({
            left: x + '%',
            top:  y + '%',
            transform: 'translate(-50%,-50%) rotate(' + rot + 'deg) scale(' + sc + ')'
        });
        $field.append(el);
        coins.push({ id: id, x: x, y: y, vx: 0, vy: 0, el: el, rot: rot, sc: sc });
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
                /* Spawn coin at drop zone — it will fall via gravity in tick() */
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
       GAME TICK — the core physics loop
       ═══════════════════════════════════════════════════ */
    function tick() {
        if (!document.getElementById('fh-penny-falls')) { cleanup(); return; }

        /* ── 1. Pusher oscillation ── */
        pusherPhase += PUSHER_SPEED;
        if (pusherPhase > Math.PI * 2) pusherPhase -= Math.PI * 2;
        var t = (Math.sin(pusherPhase) + 1) / 2;
        var newPusherY = FIELD.pusherHome + t * (FIELD.pusherMax - FIELD.pusherHome);
        var pusherVel = newPusherY - lastPusherY;   // +ve = moving forward (down)
        lastPusherY = pusherY;
        pusherY = newPusherY;
        $pusher.css('top', pusherY + '%');

        var pusherFront = pusherY + PUSHER_DEPTH;

        /* ── 2. Pusher pushes coins ── */
        /* ONLY push when the pusher is actually advancing forward */
        if (pusherVel > 0.01) {
            coins.forEach(function (c) {
                /* Coin is at or just in front of the pusher face */
                if (c.y > pusherY - COIN_SIZE * 0.3 && c.y < pusherFront + COIN_SIZE * 0.5) {
                    /* Give the coin forward velocity proportional to pusher speed */
                    c.vy = Math.max(c.vy, pusherVel * 1.5);
                    /* Slight random lateral nudge */
                    c.vx += (Math.random() - 0.5) * JITTER;
                }
            });
        }

        /* ── 3. Coins behind the pusher get swept forward ── */
        coins.forEach(function (c) {
            if (c.y < pusherFront && c.y > pusherY) {
                c.y = pusherFront + 0.2;
                c.vy = Math.max(c.vy, Math.abs(pusherVel) * 0.8);
            }
        });

        /* ── 4. Gravity for coins in the drop zone (above the tray) ── */
        coins.forEach(function (c) {
            if (c.y < pusherFront - COIN_SIZE * 0.5) {
                c.vy += FALL_GRAVITY;   // coin is falling from the top
            }
        });

        /* ── 5. Apply velocity + friction ── */
        coins.forEach(function (c) {
            if (c.vx === 0 && c.vy === 0) return; // at rest, skip
            c.x += c.vx;
            c.y += c.vy;
            c.vx *= FRICTION;
            c.vy *= FRICTION;
            /* Stop if barely moving */
            if (Math.abs(c.vx) < MIN_VEL) c.vx = 0;
            if (Math.abs(c.vy) < MIN_VEL) c.vy = 0;
        });

        /* ── 6. Collision resolution with momentum transfer ── */
        resolveCollisions();

        /* ── 7. Check fallen coins ── */
        checkFallen();

        /* ── 8. DOM update ── */
        updateDOM();
    }

    /* ═══════════════════════════════════════════════════
       COLLISION — separate + transfer momentum
       ═══════════════════════════════════════════════════ */
    function resolveCollisions() {
        var minD = COIN_SIZE * 0.8;
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

                        /* Separate the pair */
                        a.x -= nx * overlap;
                        a.y -= ny * overlap;
                        b.x += nx * overlap;
                        b.y += ny * overlap;

                        /* Transfer velocity from the faster coin to the slower one */
                        var aSpd = Math.abs(a.vx) + Math.abs(a.vy);
                        var bSpd = Math.abs(b.vx) + Math.abs(b.vy);

                        if (aSpd > bSpd && aSpd > MIN_VEL) {
                            b.vx += a.vx * PUSH_TRANSFER;
                            b.vy += a.vy * PUSH_TRANSFER;
                            a.vx *= (1 - PUSH_TRANSFER * 0.5);
                            a.vy *= (1 - PUSH_TRANSFER * 0.5);
                        } else if (bSpd > aSpd && bSpd > MIN_VEL) {
                            a.vx += b.vx * PUSH_TRANSFER;
                            a.vy += b.vy * PUSH_TRANSFER;
                            b.vx *= (1 - PUSH_TRANSFER * 0.5);
                            b.vy *= (1 - PUSH_TRANSFER * 0.5);
                        }
                    }
                }
            }
        }

        /* Clamp to field walls — kill velocity on impact */
        coins.forEach(function (c) {
            if (c.x < FIELD.left + COIN_SIZE / 2) {
                c.x = FIELD.left + COIN_SIZE / 2;
                c.vx = 0;
            }
            if (c.x > FIELD.right - COIN_SIZE / 2) {
                c.x = FIELD.right - COIN_SIZE / 2;
                c.vx = 0;
            }
            /* Don't let coins drift above the field top */
            if (c.y < FIELD.top) {
                c.y = FIELD.top;
                c.vy = 0;
            }
        });
    }

    /* ═══════════════════════════════════════════════════
       SETTLE — position-only overlap fix (no velocity)
       Used during preload to pack coins without launching them
       ═══════════════════════════════════════════════════ */
    function settleOverlaps() {
        var minD = COIN_SIZE * 0.8;
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
                    a.x -= nx * overlap;
                    a.y -= ny * overlap;
                    b.x += nx * overlap;
                    b.y += ny * overlap;
                }
            }
        }
        coins.forEach(function (c) {
            if (c.x < FIELD.left + COIN_SIZE / 2) c.x = FIELD.left + COIN_SIZE / 2;
            if (c.x > FIELD.right - COIN_SIZE / 2) c.x = FIELD.right - COIN_SIZE / 2;
        });
    }

    /* ═══════════════════════════════════════════════════
       FALLEN COINS — front edge = tickets
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
        pusherY = FIELD.pusherHome;
        lastPusherY = FIELD.pusherHome;
        pendingTix = 0;
        isDropping = false;
    }
    window.cleanupPennyFalls = cleanup;

})(jQuery);
