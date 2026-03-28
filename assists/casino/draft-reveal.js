/**
 * FisHotel Draft Card Reveal — v9.10
 * Animated card-dealing + card-flipping reveal for Last Call draft results.
 * Desktop (>768px): card grid with 3D flip animation.
 * Mobile  (<=768px): synced table with row pulse animation.
 */
(function () {
    'use strict';

    /* ── Globals from wp_localize_script ── */
    var D       = window.fhlcDraftData || {};
    var ajaxUrl = D.ajaxUrl;
    var nonce   = D.nonce;
    var batch   = D.batchName;
    var myUid   = parseInt(D.myUid, 10) || 0;
    var autoPlay = !!D.startLive;
    var cardBack  = D.cardBack  || '';   // face-down: seeded cardback image
    var cardFace  = D.cardFace  || '';   // face-up: FisHotel-Face-Card.png
    var soundsUrl = D.soundsUrl || '';

    /* ── State ── */
    var picks       = [];
    var rounds      = 0;
    var flipDelay   = 2;      // seconds between flips (Normal)
    var state       = 'idle'; // idle | dealing | revealing | complete
    var skipped     = false;
    var filterMine  = false;
    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ── Sound engine ── */
    var shuffleSounds = ['card-shuffle.mp3', 'card-shuffle-2.mp3', 'card-shuffle-3.mp3'];
    var flipSounds    = ['card-flip-1.mp3', 'card-flip-2.mp3', 'card-flip-3.mp3', 'card-flip-4.mp3'];
    var lastShuffle   = -1;
    var lastFlip      = -1;

    function pickRandom(arr, lastIdx) {
        if (arr.length <= 1) return 0;
        var idx;
        do { idx = Math.floor(Math.random() * arr.length); } while (idx === lastIdx);
        return idx;
    }

    function playSound(type) {
        if (reducedMotion) return;
        try {
            var arr, last;
            if (type === 'shuffle') { arr = shuffleSounds; last = lastShuffle; }
            else { arr = flipSounds; last = lastFlip; }
            var idx = pickRandom(arr, last);
            if (type === 'shuffle') lastShuffle = idx; else lastFlip = idx;
            var audio = new Audio(soundsUrl + arr[idx]);
            audio.volume = 0.6;
            audio.play().catch(function () {});
        } catch (e) {}
    }

    /* ── DOM refs ── */
    var stageEl     = document.getElementById('fhlc-card-stage');
    var mobileBody  = document.getElementById('fhlc-mobile-tbody');
    var controlsEl  = document.getElementById('fhlc-reveal-controls');
    var postCtrl    = document.getElementById('fhlc-post-controls');
    var skipBtn     = document.getElementById('fhlc-skip');
    var filterBtn   = document.getElementById('fhlc-filter-mine');
    var viewAllBtn  = document.getElementById('fhlc-view-all');
    var replayBtn   = document.getElementById('fhlc-replay-btn');
    var fullResults = document.getElementById('fhlc-full-results');

    /* ── Helpers ── */
    function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    function groupByRound(picks) {
        var map = {};
        picks.forEach(function (p) {
            var r = p.round || 1;
            if (!map[r]) map[r] = [];
            map[r].push(p);
        });
        return map;
    }

    /* ── Speed controls ── */
    document.querySelectorAll('.fhlc-speed-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.fhlc-speed-btn').forEach(function (b) { b.classList.remove('fhlc-speed-active'); });
            btn.classList.add('fhlc-speed-active');
            flipDelay = parseFloat(btn.dataset.speed);
        });
    });

    /* ── Skip button ── */
    if (skipBtn) skipBtn.addEventListener('click', function () {
        skipped = true;
        showFinalState();
        markSeen();
    });

    /* ── Replay button ── */
    if (replayBtn) replayBtn.addEventListener('click', function () {
        skipped = false;
        filterMine = false;
        if (filterBtn) filterBtn.classList.remove('fhlc-filter-active');
        postCtrl.style.display = 'none';
        fullResults.style.display = 'none';
        controlsEl.style.display = 'flex';
        runReveal();
    });

    /* ── Filter: Your Fish ── */
    if (filterBtn) filterBtn.addEventListener('click', function () {
        filterMine = !filterMine;
        filterBtn.classList.toggle('fhlc-filter-active', filterMine);
        applyFilter();
    });

    function applyFilter() {
        var cards = stageEl.querySelectorAll('.fhlc-deal-card');
        cards.forEach(function (card) {
            if (filterMine && !card.classList.contains('fhlc-mine')) {
                card.classList.add('fhlc-dimmed');
            } else {
                card.classList.remove('fhlc-dimmed');
            }
        });
    }

    /* ── View Full Results ── */
    if (viewAllBtn) viewAllBtn.addEventListener('click', function () {
        var showing = fullResults.style.display !== 'none';
        fullResults.style.display = showing ? 'none' : 'block';
    });

    /* ── Fetch draft results ── */
    var fd = new FormData();
    fd.append('action', 'fishotel_get_lastcall_results');
    fd.append('nonce', nonce);
    fd.append('batch_name', batch);
    fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.success || !d.data.picks) return;
            picks  = d.data.picks;
            rounds = d.data.rounds || 1;
            buildFullResultsTable();
            if (reducedMotion || !autoPlay) {
                showFinalState();
                if (autoPlay) markSeen();
            } else {
                runReveal();
            }
        });

    /* ── Mark reveal as seen ── */
    function markSeen() {
        if (!myUid) return;
        var fd2 = new FormData();
        fd2.append('action', 'fishotel_mark_lastcall_seen');
        fd2.append('nonce', nonce);
        fd2.append('batch_name', batch);
        fetch(ajaxUrl, { method: 'POST', body: fd2, credentials: 'same-origin' });
    }

    /* ── Build full results table (for dropdown) ── */
    function buildFullResultsTable() {
        if (!picks.length) {
            fullResults.innerHTML = '<p style="color:#888;font-family:Special Elite,cursive;">No picks were made in this draft.</p>';
            return;
        }
        var html = '<table><thead><tr><th>Rd</th><th>Customer</th><th>Fish</th><th>Qty</th></tr></thead><tbody>';
        var lastR = 0;
        picks.forEach(function (p) {
            if (p.round !== lastR) {
                if (lastR > 0) html += '<tr class="fhlc-round-hdr"><td colspan="4">Round ' + p.round + '</td></tr>';
                lastR = p.round;
            }
            var mine = myUid && parseInt(p.user_id, 10) === myUid;
            html += '<tr class="' + (mine ? 'fhlc-row-mine' : '') + '">';
            html += '<td style="text-align:center;">' + p.round + '</td>';
            html += '<td>' + esc(p.hf_username || p.display_name || 'User #' + p.user_id) + '</td>';
            html += '<td>' + esc(p.fish_name) + '</td>';
            html += '<td style="text-align:center;">' + p.qty + '</td>';
            html += '</tr>';
        });
        html += '</tbody></table>';
        fullResults.innerHTML = html;
    }

    /* ── Create a card DOM element (face-down) ── */
    function createCard(pick, idx) {
        var isMine = myUid && parseInt(pick.user_id, 10) === myUid;
        var suit   = (idx % 2 === 0) ? '\u2660' : '\u2665';
        var suitColor = (idx % 2 === 0) ? '#111' : '#b00';
        var name   = pick.hf_username || pick.display_name || 'User #' + pick.user_id;

        var card = document.createElement('div');
        card.className = 'fhlc-deal-card';
        if (isMine) card.dataset.mine = '1';

        var inner = document.createElement('div');
        inner.className = 'fhlc-card-inner';

        // Back (face-down) — shows seeded cardback image
        var back = document.createElement('div');
        back.className = 'fhlc-card-back';
        back.style.backgroundImage = 'url(' + cardBack + ')';

        // Front (face-up) — Art Deco face card + fish details overlaid
        var front = document.createElement('div');
        front.className = 'fhlc-card-front';
        if (cardFace) front.style.backgroundImage = 'url(' + cardFace + ')';
        front.innerHTML =
            '<span class="fhlc-cf-suit" style="color:' + suitColor + ';">' + suit + '</span>' +
            '<span class="fhlc-cf-suit-br" style="color:' + suitColor + ';">' + suit + '</span>' +
            '<span class="fhlc-cf-round">Round ' + pick.round + '</span>' +
            '<span class="fhlc-cf-fish">' + esc(pick.fish_name) + '</span>' +
            '<span class="fhlc-cf-customer">' + esc(name) + '</span>' +
            '<span class="fhlc-cf-qty">&times;' + pick.qty + '</span>';

        inner.appendChild(back);
        inner.appendChild(front);
        card.appendChild(inner);

        return card;
    }

    /* ── Add a mobile table row ── */
    function addMobileRow(pick) {
        var mine = myUid && parseInt(pick.user_id, 10) === myUid;
        var name = pick.hf_username || pick.display_name || 'User #' + pick.user_id;
        var tr = document.createElement('tr');
        tr.className = (mine ? 'fhlc-row-mine ' : '') + 'fhlc-row-entering';
        if (mine) tr.dataset.mine = '1';
        tr.innerHTML =
            '<td style="text-align:center;">' + pick.round + '</td>' +
            '<td>' + esc(name) + '</td>' +
            '<td>' + esc(pick.fish_name) + '</td>' +
            '<td style="text-align:center;">' + pick.qty + '</td>';
        mobileBody.appendChild(tr);
    }

    /* ══════════════════════════════════════════
     *  Main reveal sequence
     * ══════════════════════════════════════════ */
    function runReveal() {
        state = 'dealing';
        stageEl.innerHTML = '';
        mobileBody.innerHTML = '';

        var grouped = groupByRound(picks);
        var roundNums = Object.keys(grouped).map(Number).sort(function (a, b) { return a - b; });
        var roundIdx = 0;

        function revealRound() {
            if (skipped || roundIdx >= roundNums.length) {
                if (!skipped) {
                    markSeen();
                    setTimeout(function () { if (!skipped) showFinalState(); }, 1500);
                }
                return;
            }

            var rNum     = roundNums[roundIdx];
            var rPicks   = grouped[rNum];
            roundIdx++;

            // Sweep previous round off, then shuffle + pause before dealing
            if (roundIdx > 1) {
                var prev = stageEl.querySelectorAll('.fhlc-round-section');
                prev.forEach(function (section) {
                    section.style.transition = 'opacity 0.5s, transform 0.5s';
                    section.style.opacity = '0';
                    section.style.transform = 'translateX(-60px)';
                });
                setTimeout(function () {
                    prev.forEach(function (s) { s.remove(); });
                    // Table is now empty — shuffle, wait 1.5s, then deal
                    playSound('shuffle');
                    setTimeout(function () {
                        if (!skipped) dealRound(rNum, rPicks);
                    }, reducedMotion ? 0 : 1500);
                }, reducedMotion ? 0 : 550);
            } else {
                // First round — table already empty, shuffle then deal
                playSound('shuffle');
                setTimeout(function () {
                    if (!skipped) dealRound(rNum, rPicks);
                }, reducedMotion ? 0 : 1500);
            }
        }

        function dealRound(rNum, rPicks) {
            if (skipped) return;

            // Round label
            var section = document.createElement('div');
            section.className = 'fhlc-round-section';
            var label = document.createElement('div');
            label.className = 'fhlc-round-label';
            label.textContent = 'Round ' + rNum;
            section.appendChild(label);

            // Card grid
            var grid = document.createElement('div');
            grid.className = 'fhlc-card-grid';
            section.appendChild(grid);
            stageEl.appendChild(section);

            // Deal cards face-down with stagger
            var dealDelay = reducedMotion ? 0 : 200; // ms between each deal
            var cardEls = [];

            rPicks.forEach(function (pick, i) {
                var card = createCard(pick, i);
                card.style.opacity = '0';
                grid.appendChild(card);
                cardEls.push(card);

                setTimeout(function () {
                    if (skipped) return;
                    card.style.opacity = '';
                    card.classList.add('fhlc-entering');
                }, i * dealDelay);
            });

            // After all dealt, start flipping
            var totalDealTime = reducedMotion ? 0 : (rPicks.length * dealDelay + 400);

            setTimeout(function () {
                if (skipped) return;
                state = 'revealing';
                flipCards(cardEls, rPicks, 0, function () {
                    revealRound();
                });
            }, totalDealTime);
        }

        function flipCards(cardEls, rPicks, idx, done) {
            if (skipped || idx >= cardEls.length) {
                if (done) done();
                return;
            }

            var card = cardEls[idx];
            var pick = rPicks[idx];
            var isMine = myUid && parseInt(pick.user_id, 10) === myUid;

            // Play flip sound slightly before visual midpoint
            setTimeout(function () { playSound('flip'); }, reducedMotion ? 0 : 50);

            if (reducedMotion) {
                card.classList.add('fhlc-flipping');
                if (isMine) card.classList.add('fhlc-mine');
                addMobileRow(pick);
                flipCards(cardEls, rPicks, idx + 1, done);
                return;
            }

            card.classList.add('fhlc-flipping');

            // After flip completes (0.6s), highlight if mine then proceed
            setTimeout(function () {
                if (isMine) card.classList.add('fhlc-mine');
                addMobileRow(pick);

                setTimeout(function () {
                    flipCards(cardEls, rPicks, idx + 1, done);
                }, flipDelay * 1000);
            }, 650);
        }

        revealRound();
    }

    /* ── Show final state (skip or after animation) ── */
    function showFinalState() {
        state = 'complete';
        controlsEl.style.display = 'none';
        postCtrl.style.display = 'flex';

        // Rebuild all cards face-up in stage
        stageEl.innerHTML = '';
        mobileBody.innerHTML = '';

        var grouped = groupByRound(picks);
        var roundNums = Object.keys(grouped).map(Number).sort(function (a, b) { return a - b; });

        roundNums.forEach(function (rNum) {
            var rPicks = grouped[rNum];

            var section = document.createElement('div');
            section.className = 'fhlc-round-section';

            var label = document.createElement('div');
            label.className = 'fhlc-round-label';
            label.textContent = 'Round ' + rNum;
            section.appendChild(label);

            var grid = document.createElement('div');
            grid.className = 'fhlc-card-grid';

            rPicks.forEach(function (pick, i) {
                var card = createCard(pick, i);
                card.classList.add('fhlc-flipping'); // show face-up
                var isMine = myUid && parseInt(pick.user_id, 10) === myUid;
                if (isMine) card.classList.add('fhlc-mine');
                grid.appendChild(card);

                // Mobile row
                addMobileRow(pick);
            });

            section.appendChild(grid);
            stageEl.appendChild(section);
        });

        applyFilter();
    }

})();
