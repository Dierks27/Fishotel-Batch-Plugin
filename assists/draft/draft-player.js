(function($) {
    'use strict';

    var currentPick = 0;
    var isPlaying   = false;
    var playbackSpeed = 1;
    var timeouts    = [];

    var script = window.fhDraftScript;
    if (!script || !script.picks) return;

    var stage = document.getElementById('fhDraftStage');
    if (!stage) return;

    function clearTimeouts() {
        timeouts.forEach(function(t) { clearTimeout(t); });
        timeouts = [];
    }

    function delay(ms) {
        return new Promise(function(resolve) {
            var t = setTimeout(resolve, ms / playbackSpeed);
            timeouts.push(t);
        });
    }

    function setStage(html) {
        stage.innerHTML = html;
    }

    function appendStage(html) {
        stage.insertAdjacentHTML('beforeend', html);
    }

    /* Find a graphic URL by key (partial match) */
    function graphic(key) {
        var g = script.assets.graphics;
        if (g[key]) return g[key];
        for (var k in g) {
            if (k.indexOf(key) !== -1) return g[k];
        }
        return '';
    }

    /* Play an inline video overlay */
    function playClip(url, start, duration) {
        if (!url) return;
        var video = document.createElement('video');
        video.className = 'fh-crowd-clip';
        video.muted     = true;
        video.autoplay  = true;
        video.src       = url + '#t=' + start;
        video.addEventListener('loadedmetadata', function() {
            video.currentTime = start;
            setTimeout(function() {
                video.style.opacity = '0';
                setTimeout(function() {
                    if (video.parentNode) video.parentNode.removeChild(video);
                }, 500);
            }, duration * 1000);
        });
        stage.appendChild(video);
    }

    /* ─── Opening sequence ─── */
    async function showOpening() {
        var logos = Object.keys(script.assets.graphics).filter(function(k) {
            return k.indexOf('draft-night-logo') !== -1 && k === 'draft-night-logo';
        });
        if (!logos.length) {
            logos = Object.keys(script.assets.graphics).filter(function(k) {
                return k.indexOf('draft-night-logo') !== -1;
            });
        }
        var logoUrl = logos.length ? script.assets.graphics[logos[0]] : '';

        setStage(
            '<div class="fh-opening">' +
            (logoUrl ? '<img src="' + logoUrl + '" alt="Draft Night" class="fh-logo-intro">' : '') +
            '<h1>LIVE FROM THE FISHOTEL</h1>' +
            '<h2>DRAFT NIGHT ' + new Date().getFullYear() + '</h2>' +
            '</div>'
        );
        await delay(3000);

        var bg = graphic('draft-board-bg');
        if (bg) stage.style.backgroundImage = 'url(' + bg + ')';
    }

    /* ─── Single pick sequence ─── */
    async function showPick(pick) {
        var userName = pick.display_name || ('User #' + pick.user_id);
        var podiumBg = graphic('podium-bg');
        var clockImg = graphic('on-the-clock');

        /* 1. Build-up */
        setStage(
            '<div class="fh-on-clock">' +
            (clockImg ? '<img src="' + clockImg + '" alt="On The Clock">' : '<div class="fh-otc-text">ON THE CLOCK</div>') +
            '</div>'
        );
        playClip(pick.crowd_clip, pick.clip_start, pick.clip_duration);
        await delay(4000);

        /* 2. Announcement */
        setStage(
            '<div class="fh-announcement">' +
            '<div class="fh-podium" style="' + (podiumBg ? 'background-image:url(' + podiumBg + ');' : '') + '">' +
            '<h1>WITH THE #' + pick.pick_num + ' PICK...</h1>' +
            '<h2>' + escHtml(userName.toUpperCase()) + ' SELECTS...</h2>' +
            '</div></div>'
        );
        await delay(2500);

        /* 3. Draft card reveal */
        setStage(
            '<div class="fh-draft-card slide-in">' +
            '<div class="fh-card-header"><span class="fh-pick-num">PICK #' + pick.pick_num + '</span></div>' +
            '<div class="fh-card-body">' +
            '<h2>' + escHtml(pick.fish_name.toUpperCase()) + '</h2>' +
            '<p class="fh-selected-by">Selected by: ' + escHtml(userName) + '</p>' +
            '<div class="fh-scouting">' +
            '<h3>SCOUTING REPORT</h3>' +
            '<div class="fh-stat"><span>Position:</span> ' + escHtml(pick.scouting.position) + '</div>' +
            '<div class="fh-stat"><span>College:</span> ' + escHtml(pick.scouting.college) + '</div>' +
            '<div class="fh-stat"><span>Nickname:</span> &ldquo;' + escHtml(pick.scouting.nickname) + '&rdquo;</div>' +
            '<div class="fh-stat"><span>40-Yard Dash:</span> ' + pick.scouting.dash_time + ' sec</div>' +
            '</div></div></div>'
        );
        await delay(7000);

        /* 4. Crowd reaction */
        playClip(pick.crowd_clip, pick.clip_start, pick.clip_duration);
        await delay(2000);

        /* 5. Analyst hot take + grade overlay */
        appendStage(
            '<div class="fh-hot-take fade-in">' + escHtml(pick.hot_take) + '</div>' +
            '<div class="fh-grade fade-in">GRADE: ' + escHtml(pick.grade) + '</div>'
        );
        await delay(3000);

        /* 6. Optional b-roll transition */
        if (pick.broll_clip) {
            playClip(pick.broll_clip, 0, 3);
            await delay(3000);
        }

        stage.innerHTML = '';
        stage.style.backgroundImage = '';
    }

    /* ─── Final results screen ─── */
    function showResults() {
        var byUser = {};
        script.picks.forEach(function(pick) {
            var uid = pick.user_id;
            if (!byUser[uid]) byUser[uid] = { name: pick.display_name || ('User #' + uid), picks: [] };
            byUser[uid].picks.push(pick);
        });

        var gradeOrder = ['A+','A','A-','B+','B','B-','C+','C'];

        var rows = '';
        for (var uid in byUser) {
            var entry = byUser[uid];
            var sum = entry.picks.reduce(function(s, p) {
                var idx = gradeOrder.indexOf(p.grade);
                return s + (idx >= 0 ? idx : gradeOrder.length - 1);
            }, 0);
            var avgIdx = Math.min(Math.round(sum / entry.picks.length), gradeOrder.length - 1);
            var avgGrade = gradeOrder[avgIdx];
            rows += '<div class="fh-user-grade">' +
                '<span class="fh-user-name">' + escHtml(entry.name) + '</span>' +
                '<span class="fh-grade-badge">' + avgGrade + '</span>' +
                '<span class="fh-pick-count">' + entry.picks.length + ' ' + (entry.picks.length === 1 ? 'pick' : 'picks') + '</span>' +
                '</div>';
        }

        setStage(
            '<div class="fh-results">' +
            '<h1>DRAFT COMPLETE</h1>' +
            '<h2>FINAL GRADES</h2>' +
            '<div class="fh-grades-table">' + rows + '</div>' +
            '</div>'
        );
    }

    /* ─── Main playback loop ─── */
    async function playBroadcast() {
        if (isPlaying) return;
        isPlaying = true;
        document.getElementById('fhPlayPause').textContent = '\u23F8 PAUSE';

        if (currentPick === 0) {
            await showOpening();
        }

        while (currentPick < script.picks.length && isPlaying) {
            await showPick(script.picks[currentPick]);
            currentPick++;
        }

        if (currentPick >= script.picks.length) {
            showResults();
        }

        isPlaying = false;
        document.getElementById('fhPlayPause').textContent = '\u25B6 REPLAY';
    }

    /* ─── Controls ─── */
    document.getElementById('fhPlayPause').addEventListener('click', function() {
        if (isPlaying) {
            isPlaying = false;
            clearTimeouts();
            this.textContent = '\u25B6 RESUME';
        } else {
            if (currentPick >= script.picks.length) {
                currentPick = 0;
                stage.innerHTML = '';
                stage.style.backgroundImage = '';
            }
            playBroadcast();
        }
    });

    document.getElementById('fhSkipResults').addEventListener('click', function() {
        isPlaying = false;
        clearTimeouts();
        currentPick = script.picks.length;
        showResults();
    });

    document.querySelectorAll('.fh-speed-controls button').forEach(function(btn) {
        btn.addEventListener('click', function() {
            playbackSpeed = parseFloat(this.dataset.speed);
            document.querySelectorAll('.fh-speed-controls button').forEach(function(b) {
                b.classList.remove('active');
            });
            this.classList.add('active');
        });
    });

    /* ─── Helper ─── */
    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

})(jQuery);
