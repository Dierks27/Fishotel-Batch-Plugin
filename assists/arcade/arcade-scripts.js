/**
 * FisHotel Arcade — Strength Tester Game Logic
 *
 * Reads fishotelArcade from wp_localize_script:
 *   { ajaxUrl, nonce, images: { base, puck } }
 */
(function ($) {
    'use strict';

    /* ─── State ────────────────────────────────────── */
    var isSwinging  = false;
    var isPlaying   = false;   // true while AJAX in flight / animation
    var power       = 0;
    var selectedBet = 5;
    var animTimer   = null;

    /* ─── DOM refs ─────────────────────────────────── */
    var $fill       = $('#fh-st-fill');
    var $puck       = $('#fh-st-puck');
    var $btn        = $('#fh-st-action');
    var $chips      = $('#fh-arcade-chips');
    var $zoneLabel  = $('#fh-st-result-zone');
    var $payoutMsg  = $('#fh-st-result-payout');
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

    /* ─── Main button (SWING → HIT) ───────────────── */
    $btn.on('click', function () {
        if (isPlaying) return;

        if (!isSwinging) {
            startSwing();
        } else {
            hitStop();
        }
    });

    function startSwing() {
        // Check balance client-side first
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

        // Start power meter
        power = 0;
        $fill.css('height', '0%');
        isSwinging = true;
        $btn.text('HIT!').addClass('swinging');

        animTimer = setInterval(function () {
            power += 2;
            if (power > 100) power = 0;
            $fill.css('height', power + '%');
        }, 40);
    }

    function hitStop() {
        // Freeze meter
        clearInterval(animTimer);
        isSwinging = false;
        isPlaying  = true;
        $btn.text('...').removeClass('swinging').prop('disabled', true);

        // Send to server
        $.ajax({
            url:  fishotelArcade.ajaxUrl,
            type: 'POST',
            data: {
                action: 'fishotel_strength_tester_play',
                nonce:  fishotelArcade.nonce,
                bet:    selectedBet,
                power:  power
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

    /* ─── Outcome display ──────────────────────────── */
    function showOutcome(data) {
        // Animate puck to zone position
        var puckBottom = mapPowerToPosition(data.power);
        $puck.addClass('fh-st-puck-animate');

        // Small delay so the transition class takes effect
        setTimeout(function () {
            $puck.css('bottom', puckBottom + '%');
        }, 20);

        // Show result after puck arrives
        setTimeout(function () {
            // Zone label
            $zoneLabel
                .text(data.label)
                .removeClass('zone-bell zone-super zone-strong zone-good zone-miss')
                .addClass('zone-' + data.zone)
                .addClass('show');

            // Payout message
            var net = data.payout - data.bet;
            if (net > 0) {
                $payoutMsg.text('+' + data.payout + ' chips!').removeClass('lose').addClass('win show');
            } else if (net === 0 && data.payout > 0) {
                $payoutMsg.text('Break even — ' + data.payout + ' chips back').removeClass('win lose').addClass('show');
            } else {
                $payoutMsg.text('-' + data.bet + ' chips').removeClass('win').addClass('lose show');
            }

            // Update balance
            $chips.text(data.chips);

            // Bell glow for top zone
            if (data.zone === 'bell') {
                $bellGlow.addClass('active');
            }

            // Re-enable after cooldown
            setTimeout(enablePlay, 2500);
        }, 1600);
    }

    function mapPowerToPosition(pwr) {
        // Map 0-100 power to 2%-88% bottom position on the track area
        // 0 power = 2% (bottom), 100 power = 88% (top near bell)
        return 2 + (pwr / 100) * 86;
    }

    function resetResult() {
        $zoneLabel.removeClass('show zone-bell zone-super zone-strong zone-good zone-miss').text('');
        $payoutMsg.removeClass('show win lose').text('');
    }

    function enablePlay() {
        isPlaying = false;
        $btn.text('SWING!').prop('disabled', false);
        $fill.css('height', '0%');
    }

})(jQuery);
