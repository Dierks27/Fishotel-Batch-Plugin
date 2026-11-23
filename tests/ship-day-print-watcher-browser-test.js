/**
 * Ship Day → "Send to the printers" against the REAL File System Access API,
 * in Chromium driven by Playwright.
 *
 *   node tests/ship-day-print-watcher-browser-test.js
 *
 * Needs `require('playwright')` with its Chromium, and php for the page's
 * markup (the real FisHotel_Ship_Day_Paperwork::panel_html()). Where either
 * is missing — as in CI — it prints SKIPPED and exits 0; the stand-in folder
 * test (ship-day-print-watcher-js-test.js) always runs.
 *
 * What it drives:
 *   1. the folder functions on a real folder handle of the browser's private
 *      file system (navigator.storage.getDirectory()), and the handle kept in
 *      IndexedDB across a reload;
 *   2. the same functions on a REAL FOLDER ON DISK, read from outside every
 *      millisecond while a large file is written — the proof that the name
 *      the Print Watcher prints is never there empty or part-written. The
 *      handle comes from a synthetic drag-and-drop (DevTools protocol), and
 *      the headless browser is started with
 *      --enable-experimental-web-platform-features, which makes it grant
 *      writing without its question (nobody can press it here);
 *   3. the Ship Day page itself (paperwork.js + print-watcher.js + pdf-lib,
 *      a stand-in for admin-ajax) with window.showDirectoryPicker stood in
 *      for: first use (also the chooser ending without a folder), the
 *      question when the files also opened in their print tabs, Send, a
 *      stand-in Print Watcher (this test deleting a file from the disk
 *      folder), "still in the folder", a second send asking, the tick,
 *      Chrome under an Android phone's name and browsers without the API.
 *
 * Each part runs in a browser with an ordinary profile of its own (a
 * throw-away folder): in Playwright's default private-window contexts this
 * Chromium build (153) stops dead when a folder handle is read back from
 * IndexedDB, in an ordinary profile it does not.
 *
 * What it CANNOT drive: the browser's own folder chooser and its permission
 * question (no automation can press them), Chrome's Safe Browsing check on a
 * written file, Windows, the Print Watcher itself and the printers.
 */
'use strict';

var path = require('path');
var fs = require('fs');
var os = require('os');
var http = require('http');
var childProcess = require('child_process');

var playwright = null;
try {
    playwright = require('playwright');
} catch (e) {
    console.log('SKIPPED: Playwright is not installed here (require(\'playwright\') failed). The stand-in folder test covers the logic.');
    process.exit(0);
}

var ROOT = path.join(__dirname, '..');
var L = require(path.join(ROOT, 'assists', 'vendor', 'pdf-lib', 'pdf-lib.min.js'));

var passes = 0;
var failures = 0;
var notDone = [];
function check(label, cond, detail) {
    if (cond) {
        passes++;
        console.log('  ok   - ' + label);
    } else {
        failures++;
        console.log('  FAIL - ' + label + (detail ? ' [' + detail + ']' : ''));
    }
}
function section(t) {
    console.log('\n' + t);
}
function sleep(ms) {
    return new Promise(function (resolve) { setTimeout(resolve, ms); });
}

/** The page's markup, from the real class (a few WordPress functions stood in for). */
function panelMarkup() {
    var php = [
        'define("ABSPATH", "/");',
        'function esc_attr($s){return htmlspecialchars((string)$s, ENT_QUOTES);}',
        'function esc_html($s){return htmlspecialchars((string)$s, ENT_QUOTES);}',
        'function esc_url($s){return (string)$s;}',
        'function admin_url($p=""){return "/wp-admin/".$p;}',
        'function wp_create_nonce($a){return "nonce-".$a;}',
        'function wp_nonce_field($a,$n,$r,$e){return "";}',
        'require ' + JSON.stringify(path.join(ROOT, 'includes', 'class-ship-day-paperwork.php')) + ';',
        'echo FisHotel_Ship_Day_Paperwork::button_html("/ajax");',
        'echo FisHotel_Ship_Day_Paperwork::panel_html(FisHotel_Ship_Day_Paperwork::normalize_settings([]), []);'
    ].join('\n');
    var out = childProcess.spawnSync('php', ['-r', php], { encoding: 'utf8' });
    if (out.status !== 0 || out.stdout.indexOf('fh-sd-paperwork-send') === -1) {
        return null;
    }
    return out.stdout;
}

async function pdf(size, n) {
    var doc = await L.PDFDocument.create();
    var font = await doc.embedFont(L.StandardFonts.Helvetica);
    for (var i = 0; i < n; i++) {
        doc.addPage(size).drawText('page ' + (i + 1), { x: 20, y: 20, size: 10, font: font });
    }
    return Buffer.from(await doc.save());
}

function shipDay(delivery) {
    var d = new Date(delivery + 'T12:00:00Z');
    d.setUTCDate(d.getUTCDate() - 1);
    return d.toISOString().slice(0, 10);
}

async function startServer(markup) {
    var store = {
        '/inv/101': await pdf([500, 700], 1),
        '/inv/102': await pdf([501, 700], 2),
        '/media/acc.pdf': await pdf([610, 790], 1),
        '/media/labels.pdf': await pdf([792, 612], 1)
    };
    var scripts = {
        '/pdf-lib.min.js': path.join(ROOT, 'assists', 'vendor', 'pdf-lib', 'pdf-lib.min.js'),
        '/print-watcher.js': path.join(ROOT, 'assists', 'ship-day', 'print-watcher.js'),
        '/paperwork.js': path.join(ROOT, 'assists', 'ship-day', 'paperwork.js')
    };
    var state = { planRequests: 0 };
    var server = http.createServer(function (req, res) {
        var url = req.url.split('?')[0];
        if (url === '/') {
            res.setHeader('content-type', 'text/html; charset=utf-8');
            res.end('<!doctype html><meta charset="utf-8"><title>Ship Day</title><body style="background:#1e1e1e;color:#ddd;font:13px sans-serif">' +
                '<input type="date" id="fh-sd-date" value="2026-11-20">' + markup +
                '<script src="/pdf-lib.min.js"></script><script src="/print-watcher.js"></script><script src="/paperwork.js"></script>');
            return;
        }
        if (url === '/bare') {
            res.setHeader('content-type', 'text/html; charset=utf-8');
            res.end('<!doctype html><meta charset="utf-8"><title>bare</title><script src="/print-watcher.js"></script>');
            return;
        }
        if (scripts[url]) {
            res.setHeader('content-type', 'application/javascript; charset=utf-8');
            res.end(fs.readFileSync(scripts[url]));
            return;
        }
        if (store[url]) {
            res.setHeader('content-type', 'application/pdf');
            res.end(store[url]);
            return;
        }
        if (url === '/ajax' && req.method === 'POST') {
            var body = '';
            req.on('data', function (c) { body += c; });
            req.on('end', function () {
                state.planRequests++;
                var m = /name="delivery_date"\r\n\r\n([0-9-]+)/.exec(body);
                var ship = shipDay(m ? m[1] : '2026-11-20');
                res.setHeader('content-type', 'application/json');
                res.end(JSON.stringify({ success: true, data: {
                    files: { brother: 'BROTHER_shipday_' + ship + '.pdf', rx: 'RX106HD_shipday_' + ship + '.pdf' },
                    label_copies: 4,
                    invoice_plugin: true,
                    acclimation: { id: 501, url: '/media/acc.pdf', title: 'Acclimation' },
                    labels: { id: 502, url: '/media/labels.pdf', title: 'Labels' },
                    boxes: [
                        { label: 'Order #101', invoices: [{ order_id: 101, number: '101', url: '/inv/101' }], sheets: [] },
                        { label: 'Order #102', invoices: [{ order_id: 102, number: '102', url: '/inv/102' }], sheets: [] }
                    ],
                    sheets: {},
                    pages: null,
                    warnings: []
                } }));
            });
            return;
        }
        res.statusCode = 404;
        res.end('not found');
    });
    await new Promise(function (resolve) { server.listen(0, '127.0.0.1', resolve); });
    state.server = server;
    state.port = server.address().port;
    return state;
}

/** Reads a folder on disk as fast as it can and keeps every (name → size) it ever saw. */
function diskRecorder(dir) {
    var rec = { sizes: {}, on: true, reads: 0 };
    (function loop() {
        if (!rec.on) {
            return;
        }
        try {
            fs.readdirSync(dir).forEach(function (n) {
                var size;
                try {
                    size = fs.statSync(path.join(dir, n)).size;
                } catch (e) {
                    return; // renamed or taken between the two calls
                }
                (rec.sizes[n] = rec.sizes[n] || {})[size] = true;
            });
        } catch (e) { /* the folder is gone */ }
        rec.reads++;
        setImmediate(loop);
    }());
    rec.of = function (n) {
        return Object.keys(rec.sizes[n] || {}).map(Number).sort(function (a, b) { return a - b; });
    };
    rec.names = function () { return Object.keys(rec.sizes).sort(); };
    rec.stop = function () { rec.on = false; };
    return rec;
}

/** Give the page a REAL folder: a synthetic drag-and-drop of it onto the page. */
async function dropFolder(page, dir) {
    await page.evaluate(function () {
        window.__dropped = null;
        document.addEventListener('dragover', function (e) { e.preventDefault(); });
        document.addEventListener('drop', function (e) {
            e.preventDefault();
            e.dataTransfer.items[0].getAsFileSystemHandle().then(function (h) { window.__dropped = h; });
        });
    });
    var cdp = await page.context().newCDPSession(page);
    var data = { items: [], files: [dir], dragOperationsMask: 1 };
    for (var type of ['dragEnter', 'dragOver', 'drop']) {
        await cdp.send('Input.dispatchDragEvent', { type: type, x: 20, y: 20, data: data });
    }
    try {
        await page.waitForFunction(function () { return !!window.__dropped; }, null, { timeout: 5000 });
    } catch (e) {
        return 'no handle';
    }
    return page.evaluate(function () { return window.__dropped.queryPermission({ mode: 'readwrite' }); });
}

async function main() {
    var markup = panelMarkup();
    if (!markup) {
        console.log('SKIPPED: php is needed for the page\'s markup and did not run here.');
        process.exit(0);
    }
    var tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'fh-print-watcher-'));
    var FLAGS = ['--enable-experimental-web-platform-features', '--host-resolver-rules=MAP shop.test 127.0.0.1'];
    var profiles = 0;
    var open = [];
    /** A browser with an ordinary profile of its own; its one context. */
    async function openBrowser(opts) {
        var c = await playwright.chromium.launchPersistentContext(path.join(tmp, 'profile-' + (++profiles)), opts || {});
        open.push(c);
        return c;
    }
    var ctx;
    try {
        ctx = await openBrowser(); // no flags at all
    } catch (e) {
        console.log('SKIPPED: Playwright\'s Chromium did not start here (' + String(e.message).split('\n')[0] + ').');
        fs.rmSync(tmp, { recursive: true, force: true });
        process.exit(0);
    }
    var srv = await startServer(markup);
    var base = 'http://localhost:' + srv.port;
    var errors = [];

    try {
        /* ── 1. the browser's private file system ─────────────────── */
        section('the folder functions on a real folder handle (the browser\'s private file system)');
        var page = ctx.pages()[0] || await ctx.newPage();
        page.on('pageerror', function (e) { errors.push('bare: ' + e.message); });
        await page.goto(base + '/bare');
        console.log('  (Chromium ' + (await page.evaluate(function () { return (/Chrome\/([0-9.]+)/.exec(navigator.userAgent) || [])[1]; })) + ')');
        var r = await page.evaluate(async function () {
            var pw = window.FisHotelPrintWatcher;
            var root = await navigator.storage.getDirectory();
            var dir = await root.getDirectoryHandle('FisHotel-PrintQueue', { create: true });
            var bytes = new Uint8Array(3 * 1024 * 1024);
            for (var i = 0; i < bytes.length; i++) { bytes[i] = (i * 7) & 255; }
            var out = { secure: window.isSecureContext, supported: pw.supported(window) };
            out.perm = await pw.permission(dir, false);
            out.how = await pw.writeWhole(dir, 'BROTHER_shipday_2026-11-19.pdf', bytes, { tempName: pw.tempNameFor('BROTHER_shipday_2026-11-19.pdf', 0, 2) });
            out.names = (await pw.listNames(dir)).sort();
            var back = new Uint8Array(await (await (await dir.getFileHandle('BROTHER_shipday_2026-11-19.pdf')).getFile()).arrayBuffer());
            out.same = back.length === bytes.length && back.every(function (b, i2) { return b === bytes[i2]; });
            try {
                await pw.writeWhole(dir, 'BROTHER_shipday_2026-11-19.pdf', new Uint8Array(10));
                out.second = 'written';
            } catch (e) {
                out.second = e.name;
            }
            out.sizeAfterRefusal = (await (await dir.getFileHandle('BROTHER_shipday_2026-11-19.pdf')).getFile()).size;
            out.namesAfterRefusal = (await pw.listNames(dir)).sort();
            await pw.writeWhole(dir, 'BROTHER_shipday_2026-11-19.pdf', new Uint8Array(10), { replace: true });
            out.sizeAfterReplace = (await (await dir.getFileHandle('BROTHER_shipday_2026-11-19.pdf')).getFile()).size;
            // The looking: one file taken after a moment, one never.
            await pw.writeWhole(dir, 'RX106HD_shipday_2026-11-19.pdf', new Uint8Array(20));
            setTimeout(function () { dir.removeEntry('BROTHER_shipday_2026-11-19.pdf'); }, 150);
            out.watch = await pw.watchFiles(dir, ['BROTHER_shipday_2026-11-19.pdf', 'RX106HD_shipday_2026-11-19.pdf'], { wait: function (ms) { return new Promise(function (res) { setTimeout(res, ms); }); }, totalMs: 900, everyMs: 100 });
            // The folder itself deleted while looking.
            var sub = await root.getDirectoryHandle('gone-soon', { create: true });
            await pw.writeWhole(sub, 'BROTHER_x.pdf', new Uint8Array(5));
            setTimeout(function () { root.removeEntry('gone-soon', { recursive: true }); }, 150);
            out.watchGone = await pw.watchFiles(sub, ['BROTHER_x.pdf'], { wait: function (ms) { return new Promise(function (res) { setTimeout(res, ms); }); }, totalMs: 900, everyMs: 100 });
            try {
                await pw.writeWhole(sub, 'BROTHER_y.pdf', new Uint8Array(5));
                out.writeGone = 'written';
            } catch (e) {
                out.writeGone = pw.folderProblem(e);
            }
            // Kept in IndexedDB.
            out.stored = await pw.handleStore(window.indexedDB).set(dir);
            return out;
        });
        check('localhost is a secure context and has the API', r.secure === true && r.supported === true);
        check('permission(): held', r.perm === 'granted');
        check('writeWhole(): written under the temp name and renamed with the real move(); only the file is left, byte for byte', r.how === 'renamed' && r.names.join() === 'BROTHER_shipday_2026-11-19.pdf' && r.same === true, JSON.stringify(r.names) + ' ' + r.how);
        check('a file of that name is not overwritten without replace: FileThereError, file and folder as they were', r.second === 'FileThereError' && r.sizeAfterRefusal === 3 * 1024 * 1024 && r.namesAfterRefusal.join() === 'BROTHER_shipday_2026-11-19.pdf', JSON.stringify(r));
        check('with replace it is replaced', r.sizeAfterReplace === 10);
        check('watchFiles(): the file that went is "taken", the one that stayed is "still"', r.watch['BROTHER_shipday_2026-11-19.pdf'] === 'taken' && r.watch['RX106HD_shipday_2026-11-19.pdf'] === 'still', JSON.stringify(r.watch));
        check('the folder deleted while looking: "folder-gone" (not "taken"); a write there fails as "gone"', r.watchGone['BROTHER_x.pdf'] === 'folder-gone' && r.writeGone === 'gone', JSON.stringify(r.watchGone) + ' ' + r.writeGone);
        await page.reload();
        r = await page.evaluate(async function () {
            var pw = window.FisHotelPrintWatcher;
            var store = pw.handleStore(window.indexedDB);
            var h = await store.get();
            var root = await navigator.storage.getDirectory();
            var out = { name: h && h.name, same: !!h && await h.isSameEntry(await root.getDirectoryHandle('FisHotel-PrintQueue')), perm: h && await pw.permission(h, false) };
            await store.clear();
            out.afterClear = await store.get();
            return out;
        });
        check('the folder handle comes back from IndexedDB after a reload, still usable; "forget" removes it', r.name === 'FisHotel-PrintQueue' && r.same === true && r.perm === 'granted' && r.afterClear === null, JSON.stringify(r));
        await ctx.close();

        /* ── 2. a real folder on disk ─────────────────────────────── */
        section('a REAL folder on disk, read from outside every millisecond while Chromium writes');
        var queue = path.join(tmp, 'FisHotel-PrintQueue');
        fs.mkdirSync(queue);
        ctx = await openBrowser({ args: FLAGS });
        page = ctx.pages()[0] || await ctx.newPage();
        page.on('pageerror', function (e) { errors.push('disk: ' + e.message); });
        await page.goto(base + '/bare');
        var granted = await dropFolder(page, queue);
        var realDisk = granted === 'granted';
        if (!realDisk) {
            notDone.push('a real folder on disk (this Chromium did not give a writable handle to a dropped folder: ' + granted + ')');
            console.log('  (not exercised here: ' + notDone[notDone.length - 1] + ')');
        } else {
            var SIZE = 24 * 1024 * 1024;
            var rec = diskRecorder(queue);
            r = await page.evaluate(async function (size) {
                var pw = window.FisHotelPrintWatcher;
                var dir = window.__dropped;
                var bytes = new Uint8Array(size);
                for (var i = 0; i < size; i += 4096) { bytes[i] = (i / 4096) & 255; }
                var out = {};
                out.how = await pw.writeWhole(dir, 'BROTHER_shipday_2026-11-19.pdf', bytes, { tempName: pw.tempNameFor('BROTHER_shipday_2026-11-19.pdf', 0, 2) });
                // A browser without move(): the same folder through a handle that has none.
                var noMove = {
                    name: dir.name,
                    getFileHandle: async function (n, o) {
                        var h = await dir.getFileHandle(n, o);
                        return { name: h.name, createWritable: function () { return h.createWritable(); }, getFile: function () { return h.getFile(); } };
                    },
                    removeEntry: function (n) { return dir.removeEntry(n); },
                    entries: function () { return dir.entries(); }
                };
                out.howDirect = await pw.writeWhole(noMove, 'RX106HD_shipday_2026-11-19.pdf', bytes, { tempName: pw.tempNameFor('RX106HD_shipday_2026-11-19.pdf', 1, 2) });
                return out;
            }, SIZE);
            await sleep(50);
            rec.stop();
            var onDisk = fs.readdirSync(queue).sort();
            check('renamed: the folder holds the file, whole (' + SIZE + ' bytes; ' + rec.reads + ' reads of the folder from outside)', r.how === 'renamed' && fs.statSync(path.join(queue, 'BROTHER_shipday_2026-11-19.pdf')).size === SIZE && rec.reads > 200, r.how + ' ' + rec.reads);
            check('... and from outside its name was only ever seen whole — never empty, never part-written', JSON.stringify(rec.of('BROTHER_shipday_2026-11-19.pdf')) === JSON.stringify([SIZE]), JSON.stringify(rec.of('BROTHER_shipday_2026-11-19.pdf')));
            var partSeen = rec.of('sending-1-of-2-shipday_2026-11-19.txt.crswap');
            check('... the part-written bytes were seen, under the temp name\'s swap file only (' + partSeen.length + ' different sizes)', partSeen.length > 2 && partSeen.some(function (s) { return s > 0 && s < SIZE; }), JSON.stringify(partSeen.slice(0, 5)));
            check('... no name the Print Watcher prints carried them: every other name seen is neither a printer\'s nor a PDF', rec.names().filter(function (n) { return n !== 'BROTHER_shipday_2026-11-19.pdf' && n.indexOf('RX106HD_shipday_2026-11-19.pdf') !== 0; }).every(function (n) { return !/^(BROTHER|RX106HD)_/i.test(n) && !/\.pdf$/i.test(n); }), rec.names().join());
            check('a browser without move(): written directly — the real name was seen empty, then whole, never part-written (what createWritable()\'s swap file gives)', r.howDirect === 'direct' && JSON.stringify(rec.of('RX106HD_shipday_2026-11-19.pdf').filter(function (s) { return s !== 0; })) === JSON.stringify([SIZE]) && rec.of('RX106HD_shipday_2026-11-19.pdf.crswap').some(function (s) { return s > 0 && s < SIZE; }), JSON.stringify(rec.of('RX106HD_shipday_2026-11-19.pdf')));
            check('nothing but the two files is left in the folder', onDisk.join() === 'BROTHER_shipday_2026-11-19.pdf,RX106HD_shipday_2026-11-19.pdf', onDisk.join());

            // What a browser stopped mid-write leaves: the empty temp file and its swap file (made here by hand, an hour old).
            var hourAgo = new Date(Date.now() - 3600 * 1000);
            var stale = ['sending-1-of-2-shipday_2026-11-18-k3x9ab.txt', 'sending-1-of-2-shipday_2026-11-18-k3x9ab.txt.crswap', 'sending-2-of-2-shipday_2026-11-18.txt.1.crswap'];
            var kept = ['sending-1-of-2-shipday_2026-11-19-zz9zz9.txt.crswap', 'notes.txt'];
            stale.concat(kept).forEach(function (n, k) {
                fs.writeFileSync(path.join(queue, n), k === 0 ? '' : 'part of a file');
            });
            stale.concat(['notes.txt']).forEach(function (n) {
                fs.utimesSync(path.join(queue, n), hourAgo, hourAgo);
            });
            r = await page.evaluate(async function () {
                var pw = window.FisHotelPrintWatcher;
                return (await pw.sweep(window.__dropped, await pw.listNames(window.__dropped), Date.now())).sort();
            });
            onDisk = fs.readdirSync(queue).sort();
            check('leftovers of a cut-off send (the temp file, its .crswap) are cleared once they are old; a young one (another tab writing) and files that are not this script\'s stay', r.join() === stale.slice().sort().join() && onDisk.join() === ['BROTHER_shipday_2026-11-19.pdf', 'RX106HD_shipday_2026-11-19.pdf'].concat(kept).sort().join(), r.join() + ' | ' + onDisk.join());
            kept.forEach(function (n) { fs.unlinkSync(path.join(queue, n)); });

            // A stand-in Print Watcher outside the browser takes one file; then the folder is deleted.
            setTimeout(function () { fs.unlinkSync(path.join(queue, 'BROTHER_shipday_2026-11-19.pdf')); }, 200);
            r = await page.evaluate(function () {
                var pw = window.FisHotelPrintWatcher;
                return pw.watchFiles(window.__dropped, ['BROTHER_shipday_2026-11-19.pdf', 'RX106HD_shipday_2026-11-19.pdf'], { wait: function (ms) { return new Promise(function (res) { setTimeout(res, ms); }); }, totalMs: 1000, everyMs: 100 });
            });
            check('a file deleted from outside (what the Print Watcher does) is "taken"; the other "still"', r['BROTHER_shipday_2026-11-19.pdf'] === 'taken' && r['RX106HD_shipday_2026-11-19.pdf'] === 'still', JSON.stringify(r));
            // Moved away in one step (a delete empties the folder first, and a look in between would rightly say "taken").
            setTimeout(function () { fs.renameSync(queue, queue + '-moved'); }, 200);
            r = await page.evaluate(async function () {
                var pw = window.FisHotelPrintWatcher;
                var out = { watch: await pw.watchFiles(window.__dropped, ['RX106HD_shipday_2026-11-19.pdf'], { wait: function (ms) { return new Promise(function (res) { setTimeout(res, ms); }); }, totalMs: 1000, everyMs: 100 }) };
                out.permission = await pw.permission(window.__dropped, false);
                try {
                    await pw.writeWhole(window.__dropped, 'BROTHER_z.pdf', new Uint8Array(5));
                    out.write = 'written';
                } catch (e) {
                    out.write = pw.folderProblem(e);
                }
                return out;
            });
            check('the folder moved away from outside, file and all: "folder-gone" — not "taken" — though the browser still reports the permission; a write fails as "gone"', r.watch['RX106HD_shipday_2026-11-19.pdf'] === 'folder-gone' && r.write === 'gone' && r.permission === 'granted', JSON.stringify(r));
        }
        await ctx.close();

        /* ── 3. the Ship Day page ─────────────────────────────────── */
        section('the Ship Day page: first use');
        fs.rmSync(queue + '-moved', { recursive: true, force: true });
        fs.rmSync(queue, { recursive: true, force: true });
        fs.mkdirSync(queue);
        ctx = await openBrowser({ args: FLAGS, timezoneId: 'America/Chicago' });
        await ctx.clock.install({ time: new Date('2026-11-19T15:14:00Z') }); // 9:14 AM in the shop's zone
        // The browser's folder chooser cannot be pressed by a test: it is stood in for, and hands over
        // the real disk folder (dropped on the page below) or, failing that, a private-file-system folder.
        await ctx.addInitScript(function () {
            if (window.__noPicker) {
                return;
            }
            window.__picks = [];
            Object.defineProperty(window, 'showDirectoryPicker', { configurable: true, writable: true, value: async function (opts) {
                window.__picks.push(opts);
                if (window.__pickAbort) {
                    // What the browser reports when the chooser is closed, and when its own
                    // "let this page edit files?" question after the chooser is answered no.
                    window.__pickAbort = false;
                    throw new DOMException('The user aborted a request.', 'AbortError');
                }
                if (window.__dropped) {
                    return window.__dropped;
                }
                var root = await navigator.storage.getDirectory();
                return root.getDirectoryHandle('FisHotel-PrintQueue', { create: true });
            } });
        });
        page = ctx.pages()[0] || await ctx.newPage();
        page.on('pageerror', function (e) { errors.push('page: ' + e.message); });
        var dialogs = [];
        var answer = true;
        page.on('dialog', function (d) {
            dialogs.push(d.message());
            if (answer) {
                d.accept();
            } else {
                d.dismiss();
            }
        });
        await page.goto(base + '/');
        if (realDisk) {
            realDisk = (await dropFolder(page, queue)) === 'granted';
        }
        var rec2 = diskRecorder(queue);
        var whole = {}; // every size a finished file had: name → { size: true }
        /** The folder now: name → size. */
        async function folderNow() {
            if (realDisk) {
                var o = {};
                fs.readdirSync(queue).forEach(function (n) {
                    o[n] = fs.statSync(path.join(queue, n)).size;
                    (whole[n] = whole[n] || {})[o[n]] = true;
                });
                return o;
            }
            return page.evaluate(async function () {
                var root = await navigator.storage.getDirectory();
                var dir = await root.getDirectoryHandle('FisHotel-PrintQueue', { create: true });
                var o = {};
                for await (var e of dir.entries()) { o[e[0]] = (await e[1].getFile()).size; }
                return o;
            });
        }
        /** The stand-in Print Watcher: take (delete) a file. */
        async function take(name) {
            if (realDisk) {
                fs.unlinkSync(path.join(queue, name));
                return;
            }
            await page.evaluate(async function (n) {
                var root = await navigator.storage.getDirectory();
                await (await root.getDirectoryHandle('FisHotel-PrintQueue')).removeEntry(n);
            }, name);
        }
        function boxText() {
            return page.locator('#fh-sd-paperwork-send').innerText();
        }
        function line(name) {
            return page.locator('#fh-sd-paperwork-send p[data-file="' + name + '"]');
        }
        async function printPaperwork() {
            var before = srv.planRequests;
            await page.click('#fh-sd-paperwork');
            await page.waitForFunction(function () { return /^Done: /.test(document.getElementById('fh-sd-paperwork-status').textContent); }, null, { timeout: 20000 });
            return srv.planRequests - before;
        }
        var B = 'BROTHER_shipday_2026-11-19.pdf';
        var R = 'RX106HD_shipday_2026-11-19.pdf';

        await page.waitForSelector('#fh-sd-pw-choose');
        var text = await boxText();
        check('before anything: the box explains itself, names the folder and says the browser will ask to edit files there; no Send button, nothing chosen', /Send to the printers\./.test(text) && /Documents → FisHotel-PrintQueue\); the browser then asks whether this site may edit files there — press Allow\./.test(text) && (await page.locator('#fh-sd-pw-send').count()) === 0, text);
        check('... and under it one short line, with no "change" or "forget" to press yet', /The Print Watcher must be running on this PC \(the fish icon near the clock\)\.$/.test(text.trim()) && !/change|forget/.test(text), text);
        check('one plan request, then both files with their links as before', (await printPaperwork()) === 1 && (await page.locator('#fh-sd-paperwork-files a[download]').count()) === 2 && (await page.locator('#fh-sd-paperwork-files a', { hasText: 'Open to print' }).count()) === 2);
        check('no folder chosen yet: the two print tabs open as in 10.35', ctx.pages().length === 3, String(ctx.pages().length));
        check('... and nothing was written anywhere', Object.keys(await folderNow()).length === 0 && (await page.locator('#fh-sd-pw-send').count()) === 0);
        await page.evaluate(function () { window.__pickAbort = true; });
        await page.click('#fh-sd-pw-choose');
        await page.waitForFunction(function () { return /No folder was chosen\./.test(document.getElementById('fh-sd-paperwork-send').textContent); }, null, { timeout: 10000 });
        text = await boxText();
        check('the chooser ended without a folder (closed, or "Don\'t allow" on the browser\'s own question): one line says so and what to do; still nothing chosen', /No folder was chosen\. Press Choose the Print Watcher folder again, pick FisHotel-PrintQueue, and when the browser asks whether this site may edit files there, press Allow\./.test(text) && (await page.locator('#fh-sd-pw-choose').count()) === 1 && (await page.locator('#fh-sd-pw-send').count()) === 0, text);
        await page.click('#fh-sd-pw-choose');
        await page.waitForSelector('#fh-sd-pw-send');
        text = await boxText();
        var picks = await page.evaluate(function () { return window.__picks; });
        check('"Choose the Print Watcher folder" asks the browser for a folder to read and write, starting in Documents', picks.length === 2 && picks[1].mode === 'readwrite' && picks[1].startIn === 'documents' && !/No folder was chosen/.test(text), JSON.stringify(picks));
        check('the choice is shown by name with "change" and "forget"; choosing sent nothing', /Print Watcher folder: FisHotel-PrintQueue · change · forget/.test(text) && Object.keys(await folderNow()).length === 0, text);
        check('the tick is there and off', (await page.locator('#fh-sd-pw-auto').isChecked()) === false && /Send to the printers by itself when the files are ready/.test(text));

        section('the Ship Day page: Send to the printers');
        var linkBytes = await page.evaluate(async function () {
            var out = {};
            for (var a of document.querySelectorAll('#fh-sd-paperwork-files a[download]')) {
                var buf = new Uint8Array(await (await fetch(a.href)).arrayBuffer());
                var digest = new Uint8Array(await crypto.subtle.digest('SHA-256', buf));
                out[a.download] = { size: buf.length, sha: Array.from(digest).map(function (x) { return ('0' + x.toString(16)).slice(-2); }).join('') };
            }
            return out;
        });
        // Both files are open in their print tabs (no folder was chosen when they were made): Send asks first.
        answer = false;
        await page.click('#fh-sd-pw-send');
        await page.waitForFunction(function (n) { var p = document.querySelector('#fh-sd-paperwork-send p[data-file="' + n + '"]'); return p && p.getAttribute('data-state') === 'left'; }, B, { timeout: 10000 });
        check('the files also opened in their print tabs: Send asks before anything is written ("Sending can print the same paperwork twice." … also opened in its own tab)', dialogs.length === 1 && /^Sending can print the same paperwork twice\./.test(dialogs[0]) && dialogs[0].indexOf('- ' + B + ': also opened in its own tab — if it was printed there, it prints again') !== -1 && dialogs[0].indexOf('- ' + R + ': also opened in its own tab') !== -1, dialogs.join(' / '));
        check('Cancel: nothing is written; the line says the file is open in its tab', Object.keys(await folderNow()).length === 0 && (await line(B).innerText()) === 'Brother: not sent — it is open in its own tab to print from there.', await line(B).innerText());
        answer = true;
        dialogs.length = 0;
        await page.dblclick('#fh-sd-pw-send'); // a double click, answered OK
        await page.waitForFunction(function (n) { var p = document.querySelector('#fh-sd-paperwork-send p[data-file="' + n + '"]'); return p && p.getAttribute('data-state') === 'waiting'; }, B, { timeout: 10000 });
        await page.waitForFunction(function () { return document.querySelectorAll('#fh-sd-paperwork-send p[data-state="waiting"]').length === 2; }, null, { timeout: 10000 });
        var now = await folderNow();
        check('both files are in the folder under their names, nothing else', Object.keys(now).sort().join() === B + ',' + R, JSON.stringify(now));
        async function shaOnDisk(name) {
            if (realDisk) {
                return require('crypto').createHash('sha256').update(fs.readFileSync(path.join(queue, name))).digest('hex');
            }
            return page.evaluate(async function (n) {
                var root = await navigator.storage.getDirectory();
                var f = await (await (await root.getDirectoryHandle('FisHotel-PrintQueue')).getFileHandle(n)).getFile();
                var digest = new Uint8Array(await crypto.subtle.digest('SHA-256', await f.arrayBuffer()));
                return Array.from(digest).map(function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
            }, name);
        }
        check('byte for byte the files the Download links carry (sha256)', (await shaOnDisk(B)) === linkBytes[B].sha && (await shaOnDisk(R)) === linkBytes[R].sha && now[B] === linkBytes[B].size);
        check('the double click sent once: the question was asked once', dialogs.length === 1 && /also opened in its own tab/.test(dialogs[0]), dialogs.join(' / '));
        dialogs.length = 0;
        check('each line: "in the folder (sent today at 9:14 AM) — waiting for the Print Watcher to take it…"', (await line(B).innerText()) === 'Brother: in the folder (sent today at 9:14 AM) — waiting for the Print Watcher to take it…', await line(B).innerText());
        await take(B); // the stand-in Print Watcher takes the Brother file
        await ctx.clock.runFor(2100);
        await page.waitForFunction(function (n) { var p = document.querySelector('#fh-sd-paperwork-send p[data-file="' + n + '"]'); return p && p.getAttribute('data-state') === 'taken'; }, B, { timeout: 10000 });
        check('the Brother file was taken: "Brother: printing — the Print Watcher took the file."', (await line(B).innerText()) === 'Brother: printing — the Print Watcher took the file.', await line(B).innerText());
        check('the RX106HD file is still waiting', (await line(R).getAttribute('data-state')) === 'waiting');
        for (var i = 0; i < 60 && (await line(R).getAttribute('data-state')) === 'waiting'; i++) {
            await ctx.clock.runFor(2000);
            await sleep(20);
        }
        text = await line(R).innerText();
        check('after about 90 seconds in the folder: "still in the folder — is the Print Watcher running? (the fish icon near the clock)" with Look again', text === 'RX106HD: still in the folder — is the Print Watcher running? (the fish icon near the clock) Look again' && i >= 40 && i <= 46, i + ' ' + text);
        text = await boxText();
        check('... then, once, that the file need not be sent again; the fish icon is named once, not again in the line under it', /A file that is still in the folder prints when the Print Watcher takes it, so it does not need sending again\.\s+The Print Watcher must be running on this PC\.$/.test(text.trim()) && text.split('the fish icon near the clock').length === 2, text);
        await take(R);
        await line(R).locator('a', { hasText: 'Look again' }).click();
        await page.waitForFunction(function (n) { var p = document.querySelector('#fh-sd-paperwork-send p[data-file="' + n + '"]'); return p && p.getAttribute('data-state') === 'taken'; }, R, { timeout: 10000 });
        check('Look again, once the stand-in Print Watcher took it: "printing — the Print Watcher took the file"', (await line(R).innerText()) === 'RX106HD: printing — the Print Watcher took the file.');
        check('the page never says a sheet was printed', !/\bprinted\b|came out/i.test(await boxText()));

        section('the Ship Day page: a later visit, and never twice by accident');
        await ctx.clock.fastForward(10 * 60000);
        await page.reload();
        await page.waitForFunction(function () { return /Print Watcher folder: FisHotel-PrintQueue/.test(document.getElementById('fh-sd-paperwork-send').textContent); }, null, { timeout: 10000 });
        check('after a reload the folder is remembered without choosing again', (await page.evaluate(function () { return window.__picks.length; })) === 0);
        var tabsBefore = ctx.pages().length;
        await printPaperwork();
        check('with a folder chosen the print tabs are not opened; the links are still there', ctx.pages().length === tabsBefore && (await page.locator('#fh-sd-paperwork-files a[download]').count()) === 2 && /The files are ready below\./.test(await page.locator('#fh-sd-paperwork-status').innerText()));
        check('the same day made again says when it was sent', (await line(B).innerText()) === 'Brother: sent today at 9:14 AM — sending it again asks first.', await line(B).innerText());
        check('... and nothing was sent by making it', Object.keys(await folderNow()).length === 0);
        answer = false;
        await page.click('#fh-sd-pw-send');
        await page.waitForFunction(function (n) { var p = document.querySelector('#fh-sd-paperwork-send p[data-file="' + n + '"]'); return p && p.getAttribute('data-state') === 'left'; }, B, { timeout: 10000 });
        check('Send asks first ("Sending again can print the same paperwork twice." … sent today at 9:14 AM)', dialogs.length === 1 && /^Sending again can print the same paperwork twice\./.test(dialogs[0]) && dialogs[0].indexOf('- ' + B + ': sent today at 9:14 AM') !== -1, dialogs.join(' / '));
        check('Cancel: nothing is written', Object.keys(await folderNow()).length === 0 && (await line(R).innerText()) === 'RX106HD: not sent again (sent today at 9:14 AM).');
        answer = true;
        await page.click('#fh-sd-pw-send');
        await page.waitForFunction(function () { return document.querySelectorAll('#fh-sd-paperwork-send p[data-state="waiting"]').length === 2; }, null, { timeout: 10000 });
        check('OK: sent again', Object.keys(await folderNow()).sort().join() === B + ',' + R && dialogs.length === 2);
        // The files are still in the folder (no Print Watcher took them): the same day once more.
        await printPaperwork();
        answer = false;
        await page.click('#fh-sd-pw-send');
        await page.waitForFunction(function (n) { var p = document.querySelector('#fh-sd-paperwork-send p[data-file="' + n + '"]'); return p && p.getAttribute('data-state') === 'left'; }, B, { timeout: 10000 });
        check('a file still in the folder is named in the question and not replaced on Cancel', dialogs.length === 3 && dialogs[2].indexOf('still in the folder (the Print Watcher has not taken it)') !== -1 && (await shaOnDisk(B)) !== '' && /the earlier file is still in the folder/.test(await line(B).innerText()), dialogs[2]);
        await take(B);
        await take(R);

        section('the Ship Day page: the tick');
        await page.check('#fh-sd-pw-auto');
        await page.reload();
        await page.waitForSelector('#fh-sd-pw-auto');
        check('the tick is remembered in this browser', (await page.locator('#fh-sd-pw-auto').isChecked()) === true);
        await printPaperwork();
        await page.waitForFunction(function (n) { var p = document.querySelector('#fh-sd-paperwork-send p[data-file="' + n + '"]'); return p && p.getAttribute('data-state') === 'held'; }, B, { timeout: 10000 });
        check('a day already sent is NOT sent again by the tick, and nothing is asked', Object.keys(await folderNow()).length === 0 && dialogs.length === 3 && /^Brother: not sent by itself — it was sent today at/.test(await line(B).innerText()), await line(B).innerText());
        await page.fill('#fh-sd-date', '2026-11-25'); // another day: ships the 24th
        await printPaperwork();
        await page.waitForFunction(function () { return document.querySelectorAll('#fh-sd-paperwork-send p[data-state="waiting"]').length === 2; }, null, { timeout: 10000 });
        check('a day not sent yet goes to the folder by itself as soon as the files are ready — no Send, no question, no print tabs', Object.keys(await folderNow()).sort().join() === 'BROTHER_shipday_2026-11-24.pdf,RX106HD_shipday_2026-11-24.pdf' && dialogs.length === 3 && ctx.pages().length === tabsBefore, JSON.stringify(await folderNow()));
        await sleep(50);
        rec2.stop();
        if (realDisk) {
            var bad = rec2.names().filter(function (n) {
                var sizes = rec2.of(n);
                var real = /^(BROTHER|RX106HD)_shipday_[0-9-]+\.pdf$/.test(n);
                return real ? sizes.some(function (s) { return s === 0 || !(whole[n] && whole[n][s]); }) : (/^(BROTHER|RX106HD)_/i.test(n) || /\.pdf$/i.test(n));
            });
            check('through all of that, read from outside (' + rec2.reads + ' reads): each file\'s name was only ever seen at the size of a finished file — never empty — and nothing else printable was ever in the folder', bad.length === 0 && rec2.names().length >= 4, rec2.names().map(function (n) { return n + ' ' + JSON.stringify(rec2.of(n)); }).join('; '));
        } else {
            notDone.push('the page flow on a real disk folder (it ran on a private-file-system folder instead)');
        }
        await page.locator('#fh-sd-paperwork-send a', { hasText: 'forget' }).click();
        await page.waitForSelector('#fh-sd-pw-choose');
        await page.reload();
        await page.waitForSelector('#fh-sd-pw-choose');
        check('"forget": the folder is no longer used, also after a reload', (await page.locator('#fh-sd-pw-send').count()) === 0 && !/Print Watcher folder:/.test(await boxText()));
        await ctx.close();

        section('browsers without the folder API: the links only, one quiet line, no error');
        // Chrome on Android has had the folder chooser since about version 132, and so does this
        // Chromium under a phone's name: it must get the quiet line, not a folder to choose.
        ctx = await openBrowser({ args: FLAGS, userAgent: 'Mozilla/5.0 (Linux; Android 16; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', viewport: { width: 412, height: 915 }, isMobile: true, hasTouch: true });
        page = ctx.pages()[0] || await ctx.newPage();
        var phoneErrs = [];
        page.on('pageerror', function (e) { phoneErrs.push(e.message); });
        await page.goto(base + '/');
        var phoneHas = await page.evaluate(function () { return typeof window.showDirectoryPicker; });
        await page.click('#fh-sd-paperwork');
        await page.waitForFunction(function () { return /^Done: /.test(document.getElementById('fh-sd-paperwork-status').textContent); }, null, { timeout: 20000 });
        text = await page.locator('#fh-sd-paperwork-send').innerText();
        check('Chrome on an Android phone (the chooser is there: ' + phoneHas + '): no folder to choose, no button — the quiet line and the links; nothing threw', phoneHas === 'function' && (await page.locator('#fh-sd-paperwork-send button, #fh-sd-paperwork-send input').count()) === 0 && /^Sending the files straight to the Print Watcher needs Chrome or Edge on the PC\. Here, use the links above\.$/.test(text.trim()) && (await page.locator('#fh-sd-paperwork-files a[download]').count()) === 2 && phoneErrs.length === 0, text + ' ' + phoneErrs.join(' / '));
        await ctx.close();
        for (var kind of ['no API (Firefox, Safari, an iPhone)', 'plain http (not a secure page)']) {
            ctx = await openBrowser({ args: FLAGS });
            var http_ = kind.indexOf('http') !== -1;
            if (!http_) {
                await ctx.addInitScript(function () {
                    window.__noPicker = true;
                    delete window.showDirectoryPicker;
                    Object.defineProperty(window, 'showDirectoryPicker', { configurable: true, value: undefined });
                });
            }
            page = ctx.pages()[0] || await ctx.newPage();
            var errs = [];
            page.on('pageerror', function (e) { errs.push(e.message); });
            await page.goto((http_ ? 'http://shop.test:' + srv.port : base) + '/');
            var has = await page.evaluate(function () { return [typeof window.showDirectoryPicker, window.isSecureContext]; });
            var hidden = await page.locator('#fh-sd-paperwork-send').isHidden();
            await page.click('#fh-sd-paperwork');
            await page.waitForFunction(function () { return /^Done: /.test(document.getElementById('fh-sd-paperwork-status').textContent); }, null, { timeout: 20000 });
            text = await page.locator('#fh-sd-paperwork-send').innerText();
            check(kind + ': the API is not there (' + has.join(', ') + '); the box is hidden until the files are ready', has[0] === 'undefined' && has[1] === !http_ && hidden === true);
            check(kind + ': both files and their links, the two print tabs, and one quiet line — no button, no tick', (await page.locator('#fh-sd-paperwork-files a[download]').count()) === 2 && ctx.pages().length === 3 && (await page.locator('#fh-sd-paperwork-send button, #fh-sd-paperwork-send input').count()) === 0 && (await page.locator('#fh-sd-paperwork-send p').count()) === 1 && (http_ ? /needs this page opened over https, in Chrome or Edge on the PC\. Here, use the links above\.$/ : /^Sending the files straight to the Print Watcher needs Chrome or Edge on the PC\. Here, use the links above\.$/).test(text.trim()), text);
            check(kind + ': nothing threw', errs.length === 0, errs.join(' / '));
            await ctx.close();
        }

        check('no script error on any page of this run', errors.length === 0, errors.join(' / '));
    } finally {
        for (var c of open) {
            try {
                await c.close();
            } catch (e) { /* closed already */ }
        }
        srv.server.close();
        fs.rmSync(tmp, { recursive: true, force: true });
    }

    console.log('\nNot exercised by this test: the browser\'s own folder chooser and permission question, Chrome\'s Safe Browsing check, Windows, the Print Watcher and the printers' + (notDone.length ? '; ' + notDone.join('; ') : '') + '.');
    console.log('\n' + passes + ' passed, ' + failures + ' failed.');
    process.exit(failures > 0 ? 1 : 0);
}

main().catch(function (e) {
    console.log('  FAIL - ' + (e && e.stack ? e.stack : e));
    process.exit(1);
});
