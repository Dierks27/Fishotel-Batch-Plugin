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
            $error.text('Not enough chips!');
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

        // Start power meter (fast loop ~1.25s per cycle)
        power = 0;
        $fill.css('height', '0%');
        isSwinging = true;
        $btn.text('HIT! (1 of 3)').addClass('swinging');

        animTimer = setInterval(function () {
            power += 2;
            if (power > 100) power = 0;
            $fill.css('height', power + '%');
        }, 25);
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
                $payoutMsg.text('-' + data.bet + ' chips').removeClass('win').addClass('lose show');
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

    function mapPowerToPosition(pwr) {
        return 2 + (pwr / 100) * 86;
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
