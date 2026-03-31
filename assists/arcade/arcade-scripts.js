/**
 * FisHotel Arcade — Strength Tester Game Logic
 *
 * Reads fishotelArcade from wp_localize_script:
 *   { ajaxUrl, nonce, images: { base, puck } }
 */
(function ($) {
    'use strict';

    /* ─── State ────────────────────────────────────── */
    var isSwinging   = false;
    var isPlaying    = false;   // true while AJAX in flight / animation
    var power        = 0;
    var direction    = 1;       // 1 = rising, -1 = falling (bounce mode)
    var speed        = 3;       // current step size (varies randomly)
    var selectedBet  = 5;
    var animTimer    = null;
    var captures     = [];
    var captureCount = 0;

    /* ─── DOM refs ─────────────────────────────────── */
    var $fill       = $('#fh-st-fill');
    var $puck       = $('#fh-st-puck');
    var $btn        = $('#fh-st-action');
    var $chips      = $('#fh-arcade-chips');
    var $tickets    = $('#fh-arcade-tickets');
    var $zoneLabel  = $('#fh-st-result-zone');
    var $payoutMsg  = $('#fh-st-result-payout');
    var $captures   = $('#fh-st-captures');
    var $bellGlow   = $('#fh-st-bell-glow');
    var $error      = $('#fh-st-error');

    /* ─── Bet selector ─────────────────────────────── */
    $('.fh-st-bet').on('click', function () {
        if (isPlaying) return;
        $('.fh-st-bet').removeClass('active');
        $(this).addClass('active');
        selectedBet = parseInt($(this).data('bet'), 10);
        $error.text('');
    });

    /* ─── Main button (SWING → HIT x3) ────────────── */
    $btn.on('click', function () {
        if (isPlaying) return;

        if (!isSwinging) {
            startSwing();
        } else {
            captureHit();
        }
    });

    function startSwing() {
        var chips = parseInt($chips.text(), 10) || 0;
        if (chips < selectedBet) {
            $error.text('Not enough nickels!');
            return;
        }
        $error.text('');

        // Reset visual state
        resetResult();
        $puck.removeClass('fh-st-puck-animate').css('bottom', '2%');
        $bellGlow.removeClass('active');
        captures     = [];
        captureCount = 0;
        $captures.text('');

        // Start power meter — bouncing with random speed shifts
        power     = 0;
        direction = 1;
        speed     = 3;
        $fill.css('height', '0%');
        isSwinging = true;
        $btn.text('HIT! (1 of 3)').addClass('swinging');

        animTimer = setInterval(function () {
            // Random speed jitter every tick (2–5 step size)
            if (Math.random() < 0.15) {
                speed = 2 + Math.floor(Math.random() * 4);
            }
            power += speed * direction;

            // Bounce off top and bottom
            if (power >= 100) { power = 100; direction = -1; }
            if (power <= 0)   { power = 0;   direction = 1;  }

            $fill.css('height', power + '%');
        }, 15);
    }

    function captureHit() {
        captureCount++;
        captures.push(power);

        // Show captured values
        var vals = captures.map(function (v) { return v + '%'; });
        $captures.text(vals.join(' | '));

        if (captureCount < 3) {
            $btn.text('HIT! (' + (captureCount + 1) + ' of 3)');
        } else {
            // All 3 captured — stop meter, calculate average
            clearInterval(animTimer);
            isSwinging = false;
            isPlaying  = true;
            $btn.text('...').removeClass('swinging').prop('disabled', true);

            var avgPower = Math.round((captures[0] + captures[1] + captures[2]) / 3);
            $captures.text(vals.join(' | ') + ' = Avg ' + avgPower + '%');
            $fill.css('height', avgPower + '%');

            // Send averaged power to server
            $.ajax({
                url:  fishotelArcade.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fishotel_strength_tester_play',
                    nonce:  fishotelArcade.nonce,
                    bet:    selectedBet,
                    power:  avgPower
                },
                success: function (resp) {
                    if (resp.success) {
                        showOutcome(resp.data);
                    } else {
                        $error.text(resp.data.message || 'Something went wrong.');
                        enablePlay();
                    }
                },
                error: function () {
                    $error.text('Network error. Please try again.');
                    enablePlay();
                }
            });
        }
    }

    /* ─── Outcome display ──────────────────────────── */
    function showOutcome(data) {
        var puckBottom = mapPowerToPosition(data.power);
        $puck.addClass('fh-st-puck-animate');

        setTimeout(function () {
            $puck.css('bottom', puckBottom + '%');
        }, 20);

        // Show result after puck arrives
        setTimeout(function () {
            $zoneLabel
                .text(data.label)
                .removeClass('zone-bell zone-super zone-strong zone-good zone-miss')
                .addClass('zone-' + data.zone)
                .addClass('show');

            var net = data.payout - data.bet;
            if (net > 0) {
                $payoutMsg.text('+' + data.payout + ' tickets!').removeClass('lose').addClass('win show');
            } else if (net === 0 && data.payout > 0) {
                $payoutMsg.text('Break even — ' + data.payout + ' tickets back').removeClass('win lose').addClass('show');
            } else {
                $payoutMsg.text('-' + data.bet + ' nickels').removeClass('win').addClass('lose show');
            }

            // Update balances
            $chips.text(data.chips);
            if (data.tickets !== undefined) {
                $tickets.text(data.tickets);
            }

            if (data.zone === 'bell') {
                $bellGlow.addClass('active');
            }

            setTimeout(enablePlay, 2500);
        }, 1600);
    }

    /**
     * Map power to puck position so it lands in the correct visual zone
     * on the machine artwork, regardless of where the admin sets thresholds.
     *
     * Visual zone bands on strength-tester-base.png (% from bottom):
     *   Miss:         12% – 24%
     *   Good Try:     24% – 37%
     *   Strong:       37% – 50%
     *   Super Strong: 50% – 62%
     *   Ring the Bell:62% – 70%
     */
    var vizBands = [
        { floor: 0,    top: 0,    posLo: 12, posHi: 24 }, // miss
        { floor: 0,    top: 0,    posLo: 24, posHi: 37 }, // good
        { floor: 0,    top: 0,    posLo: 37, posHi: 50 }, // strong
        { floor: 0,    top: 0,    posLo: 50, posHi: 62 }, // super
        { floor: 0,    top: 0,    posLo: 62, posHi: 70 }  // bell
    ];

    function rebuildBands() {
        var z = fishotelArcade.zones || {};
        var good   = parseInt(z.good, 10)   || 20;
        var strong = parseInt(z.strong, 10) || 40;
        var sup    = parseInt(z['super'], 10) || 65;
        var bell   = parseInt(z.bell, 10)   || 85;

        vizBands[0].floor = 0;      vizBands[0].top = good - 1;
        vizBands[1].floor = good;    vizBands[1].top = strong - 1;
        vizBands[2].floor = strong;  vizBands[2].top = sup - 1;
        vizBands[3].floor = sup;     vizBands[3].top = bell - 1;
        vizBands[4].floor = bell;    vizBands[4].top = 100;
    }
    rebuildBands();

    function mapPowerToPosition(pwr) {
        for (var i = vizBands.length - 1; i >= 0; i--) {
            var b = vizBands[i];
            if (pwr >= b.floor) {
                var range = b.top - b.floor;
                var frac  = range > 0 ? (pwr - b.floor) / range : 0.5;
                return b.posLo + frac * (b.posHi - b.posLo);
            }
        }
        return 12;
    }

    function resetResult() {
        $zoneLabel.removeClass('show zone-bell zone-super zone-strong zone-good zone-miss').text('');
        $payoutMsg.removeClass('show win lose').text('');
    }

    function enablePlay() {
        isPlaying    = false;
        captures     = [];
        captureCount = 0;
        $captures.text('');
        $btn.text('SWING!').prop('disabled', false);
        $fill.css('height', '0%');
    }

})(jQuery);
