/**
 * Ship Day → "Send to the printers" (assists/ship-day/print-watcher.js) under
 * node, with a stand-in for the browser's folder handle.
 *
 *   node tests/ship-day-print-watcher-js-test.js
 *
 * The stand-in folder behaves as Chromium's does (checked against a real
 * folder, see the script's file comment): getFileHandle(name, { create })
 * makes an EMPTY file; createWritable() writes into "<name>.crswap" and
 * close() swaps it in; abort() leaves the empty file; move() renames; a
 * deleted folder answers NotFoundError to everything. It records every state
 * the folder was ever in, so "the Print Watcher can never see a half-written
 * file" is read off that record. Exits 1 on any failure, 0 when all pass.
 */
'use strict';

var path = require('path');
var crypto = require('crypto');
var L = require(path.join(__dirname, '..', 'assists', 'vendor', 'pdf-lib', 'pdf-lib.min.js'));
var paper = require(path.join(__dirname, '..', 'assists', 'ship-day', 'paperwork.js'));
var pw = require(path.join(__dirname, '..', 'assists', 'ship-day', 'print-watcher.js'));

var passes = 0;
var failures = 0;
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

var BROTHER = 'BROTHER_shipday_2026-11-19.pdf';
var RX = 'RX106HD_shipday_2026-11-19.pdf';

function err(name, message) {
    var e = new Error(message || name);
    e.name = name;
    return e;
}
function bytesOf(n, fill) {
    var b = new Uint8Array(n);
    for (var i = 0; i < n; i++) {
        b[i] = (fill + i) & 255;
    }
    return b;
}
function same(a, b) {
    return !!a && !!b && a.length === b.length && Buffer.compare(Buffer.from(a), Buffer.from(b)) === 0;
}

/** A folder as Chromium shows it to a page. */
function fakeDir(name) {
    var dir = {
        kind: 'directory',
        name: name || 'FisHotel-PrintQueue',
        files: {},        // what is on disk: name → bytes
        history: [],      // every state the disk was in: { name: size }
        closes: [],       // names a writable was closed on (a finished write)
        perm: 'granted',
        onAsk: 'granted', // what the person answers when the browser asks
        asked: 0,
        gone: false,      // the folder was deleted / renamed
        hasMove: true,
        fail: {},         // { create|write|close|move: Error } — thrown once
        created: [],      // names getFileHandle(…, { create: true }) made
        modified: {},     // name → when it was last written (ms); a name not listed here is fresh
        gate: null        // async hook(op, name): runs when a 'write' or a 'move' starts, before it happens
    };
    function snap() {
        var s = {};
        Object.keys(dir.files).forEach(function (k) { s[k] = dir.files[k].length; });
        dir.history.push(s);
    }
    function guard() {
        if (dir.gone) {
            throw err('NotFoundError', 'A requested file or directory could not be found at the time an operation was processed.');
        }
        if (dir.perm !== 'granted') {
            throw err('NotAllowedError', 'The request is not allowed by the user agent or the platform in the current context.');
        }
    }
    function maybeFail(what) {
        if (dir.fail[what]) {
            var e = dir.fail[what];
            delete dir.fail[what];
            throw e;
        }
    }
    function fileHandle(n) {
        var h = { kind: 'file', name: n };
        h.createWritable = async function () {
            guard();
            if (dir.gate) {
                await dir.gate('write', h.name);
            }
            var swap = h.name + '.crswap';
            dir.files[swap] = new Uint8Array(0);
            snap();
            return {
                write: async function (b) {
                    guard();
                    // The swap file grows in steps, as on disk.
                    dir.files[swap] = b.slice(0, Math.floor(b.length / 2));
                    snap();
                    maybeFail('write');
                    dir.files[swap] = b.slice(0);
                    snap();
                },
                close: async function () {
                    guard();
                    maybeFail('close');
                    dir.files[h.name] = dir.files[swap];
                    delete dir.files[swap];
                    dir.closes.push(h.name);
                    snap();
                },
                abort: async function () {
                    delete dir.files[swap];
                    snap();
                }
            };
        };
        h.getFile = async function () {
            guard();
            if (!(h.name in dir.files)) {
                throw err('NotFoundError');
            }
            return { size: dir.files[h.name].length, lastModified: h.name in dir.modified ? dir.modified[h.name] : Date.now() + 60000 };
        };
        if (dir.hasMove) {
            h.move = async function (to) {
                guard();
                maybeFail('move');
                if (dir.gate) {
                    await dir.gate('move', h.name);
                }
                if (!(h.name in dir.files)) {
                    throw err('NotFoundError');
                }
                dir.files[to] = dir.files[h.name];
                delete dir.files[h.name];
                h.name = to;
                snap();
            };
        }
        return h;
    }
    dir.getFileHandle = async function (n, opts) {
        guard();
        if (!(n in dir.files)) {
            if (!opts || !opts.create) {
                throw err('NotFoundError');
            }
            maybeFail('create');
            dir.files[n] = new Uint8Array(0);
            dir.created.push(n);
            snap();
        }
        return fileHandle(n);
    };
    dir.removeEntry = async function (n) {
        guard();
        if (!(n in dir.files)) {
            throw err('NotFoundError');
        }
        delete dir.files[n];
        snap();
    };
    dir.entries = function () {
        var keys = null;
        var i = 0;
        return {
            next: async function () {
                if (keys === null) {
                    guard();
                    keys = Object.keys(dir.files);
                }
                return i < keys.length ? { done: false, value: [keys[i], fileHandle(keys[i++])] } : { done: true, value: undefined };
            }
        };
    };
    dir.queryPermission = async function () { return dir.perm; };
    dir.requestPermission = async function () {
        dir.asked++;
        dir.perm = dir.onAsk;
        return dir.perm;
    };
    /** What the Print Watcher does: take (delete) a file it prints. */
    dir.take = function (n) {
        delete dir.files[n];
        snap();
    };
    /** Every size a name ever had on disk. */
    dir.sizesOf = function (n) {
        var out = [];
        dir.history.forEach(function (s) {
            if (n in s && out.indexOf(s[n]) === -1) {
                out.push(s[n]);
            }
        });
        return out;
    };
    /** Every name that was ever on disk. */
    dir.namesEver = function () {
        var out = [];
        dir.history.forEach(function (s) {
            Object.keys(s).forEach(function (k) {
                if (out.indexOf(k) === -1) {
                    out.push(k);
                }
            });
        });
        return out;
    };
    return dir;
}

/** A name the Print Watcher would print: a printer at the front, or a PDF by name. */
function printable(n) {
    return /^(BROTHER|RX106HD)_/i.test(n) || /\.pdf$/i.test(n);
}

function fakeStorage(broken) {
    var data = {};
    return {
        data: data,
        getItem: function (k) {
            if (broken) {
                throw err('SecurityError');
            }
            return k in data ? data[k] : null;
        },
        setItem: function (k, v) {
            if (broken) {
                throw err('SecurityError');
            }
            data[k] = String(v);
        }
    };
}

/** A controller with a stand-in clock, store, storage and "browser question". */
function rig(opts) {
    opts = opts || {};
    var r = {
        t: new Date(2026, 10, 19, 9, 14, 0).getTime(), // Nov 19 2026, 9:14 AM on this machine
        dir: opts.dir === undefined ? fakeDir() : opts.dir,
        stored: opts.stored === undefined ? null : opts.stored,
        storage: opts.storage || fakeStorage(),
        questions: [],
        answer: true,
        views: [],
        waits: 0,
        onWait: null,
        picks: 0
    };
    r.memory = pw.makeMemory(r.storage, function () { return r.t; });
    r.ctl = pw.create({
        supported: opts.supported !== false,
        pick: function () {
            r.picks++;
            if (opts.pickError) {
                return Promise.reject(opts.pickError);
            }
            return Promise.resolve(r.dir);
        },
        store: {
            get: function () { return Promise.resolve(r.stored); },
            set: function (h) {
                if (opts.storeFails) {
                    return Promise.resolve(false); // IndexedDB refused (handleStore().set() answers false)
                }
                r.stored = h;
                return Promise.resolve(true);
            },
            clear: function () {
                r.stored = null;
                return Promise.resolve(true);
            }
        },
        memory: r.memory,
        confirm: function (text) {
            r.questions.push(text);
            return r.answer;
        },
        now: function () { return r.t; },
        wait: function (ms) {
            r.t += ms;
            r.waits++;
            if (r.onWait) {
                r.onWait(r);
            }
            return Promise.resolve();
        },
        token: opts.token ? function () { return opts.token; } : undefined,
        paint: function (v) { r.views.push(v); }
    });
    /** tabs: both files also opened in their own print tabs (no folder was chosen when the paperwork was made). */
    r.files = function (tabs) {
        return [{ printer: 'Brother', name: BROTHER, bytes: bytesOf(5000, 1), tab: !!tabs }, { printer: 'RX106HD', name: RX, bytes: bytesOf(7000, 9), tab: !!tabs }];
    };
    r.line = function (name) {
        var l = r.ctl.view().lines.filter(function (x) { return x.name === name; })[0];
        return l ? l.text : '';
    };
    return r;
}

async function throwsName(p) {
    try {
        await p;
        return '';
    } catch (e) {
        return e.name || 'Error';
    }
}

async function main() {
    var d, r, how, out, states;

    section('one file: written whole or not at all (temp name, then renamed)');
    d = fakeDir();
    var big = bytesOf(40000, 3);
    how = await pw.writeWhole(d, BROTHER, big, { tempName: pw.tempNameFor(BROTHER, 0, 2) });
    check('written under another name and renamed', how === 'renamed');
    check('the real name was never empty and never part-written: it only ever had its full size', JSON.stringify(d.sizesOf(BROTHER)) === JSON.stringify([40000]), JSON.stringify(d.sizesOf(BROTHER)));
    check('nothing else that the Print Watcher prints was ever in the folder (the temp name and its .crswap are neither a printer\'s nor a PDF)', d.namesEver().filter(printable).join() === BROTHER, d.namesEver().join());
    check('the part-written bytes were in the folder only under the temp name\'s swap file', d.sizesOf('sending-1-of-2-shipday_2026-11-19.txt.crswap').indexOf(20000) !== -1);
    check('only the file is left, byte for byte', Object.keys(d.files).join() === BROTHER && same(d.files[BROTHER], big));
    check('temp names: one per file of a send, no printer at the front, not .pdf', pw.tempNameFor(BROTHER, 0, 2) === 'sending-1-of-2-shipday_2026-11-19.txt' && pw.tempNameFor(RX, 1, 2) === 'sending-2-of-2-shipday_2026-11-19.txt' && !printable(pw.tempNameFor('odd.pdf', 0, 1)));
    check('... and with a send\'s own letters, so no other send has the same name', pw.tempNameFor(BROTHER, 0, 2, 'k3x9ab') === 'sending-1-of-2-shipday_2026-11-19-k3x9ab.txt' && pw.tempNameFor(RX, 1, 2, '../a b.pdf') === 'sending-2-of-2-shipday_2026-11-19-abpdf.txt' && !printable(pw.tempNameFor(BROTHER, 0, 2, 'pdf')));
    d.files['sending-1-of-2-shipday_2026-11-19.txt'] = bytesOf(10, 0); // a send that was cut off
    d.take(BROTHER);
    await pw.writeWhole(d, BROTHER, big, { tempName: pw.tempNameFor(BROTHER, 0, 2) });
    check('a temp file left by a send that was cut off is cleared, not renamed', Object.keys(d.files).join() === BROTHER && same(d.files[BROTHER], big));

    section('a browser that cannot rename: straight to the real name, still never half-written');
    d = fakeDir();
    d.hasMove = false;
    how = await pw.writeWhole(d, BROTHER, big);
    check('written directly', how === 'direct' && same(d.files[BROTHER], big) && Object.keys(d.files).join() === BROTHER);
    check('the real name was empty, then whole — never part-written', JSON.stringify(d.sizesOf(BROTHER)) === JSON.stringify([0, 40000]), JSON.stringify(d.sizesOf(BROTHER)));
    d = fakeDir();
    d.fail.move = err('NotAllowedError');
    how = await pw.writeWhole(d, BROTHER, big);
    check('a rename the browser refuses: the direct way, the temp file taken away', how === 'direct' && Object.keys(d.files).join() === BROTHER && same(d.files[BROTHER], big));

    section('a write that fails leaves nothing behind');
    for (var what of ['create', 'write', 'close']) {
        d = fakeDir();
        d.fail[what] = err('InvalidStateError', 'disk');
        check('the ' + what + ' fails (renaming browser): the error is passed on, the folder is empty', (await throwsName(pw.writeWhole(d, BROTHER, big))) === 'InvalidStateError' && Object.keys(d.files).length === 0 && d.sizesOf(BROTHER).length === 0, Object.keys(d.files).join());
    }
    for (what of ['write', 'close']) {
        d = fakeDir();
        d.hasMove = false;
        d.fail[what] = err('InvalidStateError', 'disk');
        check('the ' + what + ' fails (direct): the empty file of the real name is taken away again', (await throwsName(pw.writeWhole(d, BROTHER, big))) === 'InvalidStateError' && Object.keys(d.files).length === 0 && JSON.stringify(d.sizesOf(BROTHER)) === JSON.stringify([0]), Object.keys(d.files).join());
    }
    d = fakeDir();
    d.hasMove = false;
    d.files[BROTHER] = bytesOf(300, 5);
    d.fail.close = err('InvalidStateError', 'disk');
    await throwsName(pw.writeWhole(d, BROTHER, big, { replace: true }));
    check('... and a file that was there before a failed replace is still what it was', same(d.files[BROTHER], bytesOf(300, 5)) && Object.keys(d.files).join() === BROTHER);

    section('a file of that name already in the folder is not overwritten without a yes');
    for (var mv of [true, false]) {
        d = fakeDir();
        d.hasMove = mv;
        d.files[BROTHER] = bytesOf(300, 5);
        check((mv ? 'renaming' : 'direct') + ': refused (FileThereError), the earlier file untouched, nothing else left', (await throwsName(pw.writeWhole(d, BROTHER, big))) === 'FileThereError' && same(d.files[BROTHER], bytesOf(300, 5)) && Object.keys(d.files).join() === BROTHER);
        how = await pw.writeWhole(d, BROTHER, big, { replace: true });
        check((mv ? 'renaming' : 'direct') + ': with replace it is replaced, whole' + (mv ? ' (old → new in one step)' : ''), same(d.files[BROTHER], big) && d.sizesOf(BROTHER).filter(function (s) { return s !== 300 && s !== 40000; }).length === 0, JSON.stringify(d.sizesOf(BROTHER)));
    }

    section('after a send: looking in the folder');
    var t = 0;
    var clock = { now: function () { return t; } };
    function watchOpts(d2, onWait, extra) {
        var o = { now: clock.now, wait: function (ms) { t += ms; if (onWait) { onWait(t); } return Promise.resolve(); }, totalMs: 90000, everyMs: 2000 };
        Object.keys(extra || {}).forEach(function (k) { o[k] = extra[k]; });
        return o;
    }
    d = fakeDir();
    d.files[BROTHER] = big;
    d.files[RX] = big;
    t = 0;
    states = await pw.watchFiles(d, [BROTHER, RX], watchOpts(d, function (at) { if (at === 6000) { d.take(BROTHER); } }));
    check('a file that is gone was taken; one still there when the time is up is "still"', states[BROTHER] === 'taken' && states[RX] === 'still', JSON.stringify(states));
    check('it looked for about 90 seconds, every 2', t === 90000);
    d = fakeDir();
    d.files[BROTHER] = big;
    d.files[RX] = big;
    t = 0;
    states = await pw.watchFiles(d, [BROTHER, RX], watchOpts(d, function (at) { if (at === 6000) { d.take(BROTHER); } if (at === 8000) { d.take(RX); } }));
    check('both taken: it stops looking at once', states[BROTHER] === 'taken' && states[RX] === 'taken' && t === 8000, t + ' ' + JSON.stringify(states));
    d = fakeDir();
    d.files[BROTHER] = big;
    d.files[RX] = big;
    t = 0;
    states = await pw.watchFiles(d, [BROTHER, RX], watchOpts(d, function (at) { if (at === 4000) { d.take(BROTHER); } if (at === 6000) { d.perm = 'prompt'; } }));
    check('the permission is lost half-way: what was seen stays, the rest is "no-permission" (not "taken")', states[BROTHER] === 'taken' && states[RX] === 'no-permission', JSON.stringify(states));
    d = fakeDir();
    d.files[BROTHER] = big;
    t = 0;
    states = await pw.watchFiles(d, [BROTHER], watchOpts(d, function (at) { if (at === 4000) { d.gone = true; } }));
    check('the folder itself is deleted: "folder-gone", never "taken"', states[BROTHER] === 'folder-gone', JSON.stringify(states));
    d = fakeDir();
    d.files[BROTHER] = big;
    t = 0;
    var stopped = false;
    states = await pw.watchFiles(d, [BROTHER], watchOpts(d, function (at) { if (at === 4000) { stopped = true; } }, { stop: function () { return stopped; } }));
    check('a watch that is no longer wanted stops', states === null && t === 4000);
    check('folderProblem(): gone / denied / error', pw.folderProblem(err('NotFoundError')) === 'gone' && pw.folderProblem(err('NotAllowedError')) === 'denied' && pw.folderProblem(err('SecurityError')) === 'denied' && pw.folderProblem(err('InvalidStateError')) === 'error');

    section('the permission: asked only when a click allows it');
    d = fakeDir();
    d.perm = 'prompt';
    check('without a click nothing is asked', (await pw.permission(d, false)) === 'prompt' && d.asked === 0);
    check('inside a click the browser asks once', (await pw.permission(d, true)) === 'granted' && d.asked === 1);
    check('already held: not asked again', (await pw.permission(d, true)) === 'granted' && d.asked === 1);
    d.perm = 'prompt';
    d.requestPermission = function () { return Promise.reject(err('SecurityError', 'User activation is required')); };
    check('a browser that refuses to ask (no click behind it): still "prompt", no error', (await pw.permission(d, true)) === 'prompt');

    section('what this browser remembers');
    var st = fakeStorage();
    var now = new Date(2026, 10, 19, 9, 14, 0).getTime();
    var mem = pw.makeMemory(st, function () { return now; });
    check('nothing sent yet; the tick is off by default', mem.sentAt(BROTHER) === 0 && mem.auto() === false);
    mem.markSent(BROTHER);
    mem.setAuto(true);
    check('sent and the tick are kept in this browser\'s storage (another page of it reads them)', pw.makeMemory(st, function () { return now; }).sentAt(BROTHER) === now && pw.makeMemory(st, function () { return now; }).auto() === true && Object.keys(st.data).join() === 'fishotel_print_watcher');
    check('"today at 9:14 AM"', pw.whenWords(now, now + 3600000) === 'today at 9:14 AM');
    check('another day: "on Nov 19 at 9:14 AM"; noon and midnight read right', pw.whenWords(now, now + 30 * 3600000) === 'on Nov 19 at 9:14 AM' && pw.whenWords(new Date(2026, 10, 19, 12, 5).getTime(), now) === 'today at 12:05 PM' && pw.whenWords(new Date(2026, 10, 19, 0, 5).getTime(), now) === 'today at 12:05 AM');
    now += 22 * 24 * 3600000;
    mem.markSent(RX);
    check('a ship day sent three weeks ago is forgotten', mem.sentAt(BROTHER) === 0 && mem.sentAt(RX) === now);
    var mem2 = pw.makeMemory(fakeStorage(true), function () { return now; });
    mem2.markSent(BROTHER);
    mem2.setAuto(true);
    check('storage that throws (private window): remembered for this page, no error', mem2.sentAt(BROTHER) === now && mem2.auto() === true);
    var mem3 = pw.makeMemory(null, function () { return now; });
    mem3.markSent(BROTHER);
    check('no storage at all: the same', mem3.sentAt(BROTHER) === now);
    st.data.fishotel_print_watcher = '{broken';
    check('unreadable storage reads as nothing sent', pw.makeMemory(st, function () { return now; }).sentAt(BROTHER) === 0);

    section('first use: choose the folder once');
    r = rig();
    await r.ctl.init();
    var v = r.ctl.view();
    check('no folder yet: nothing can be sent, the page names the folder to choose', v.supported && v.folder === '' && v.canSend === false && v.expected === 'FisHotel-PrintQueue');
    await r.ctl.setFiles(r.files());
    out = await r.ctl.send();
    check('Send without a folder does nothing', out.sent.length === 0 && Object.keys(r.dir.files).length === 0);
    check('choosing asks the browser\'s folder chooser, keeps the folder in this browser and shows its name', (await r.ctl.choose()) === true && r.picks === 1 && r.stored === r.dir && r.ctl.view().folder === 'FisHotel-PrintQueue' && r.ctl.view().folderNote === '' && r.ctl.hasFolder());
    check('choosing sends nothing by itself', Object.keys(r.dir.files).length === 0 && r.ctl.view().canSend === true);
    var r2 = rig({ stored: r.dir });
    await r2.ctl.init();
    check('a later visit finds the folder again without asking anything', r2.ctl.view().folder === 'FisHotel-PrintQueue' && r2.picks === 0 && r2.dir.asked === 0);
    await r.ctl.forget();
    check('"forget" drops it', r.stored === null && r.ctl.view().folder === '' && !r.ctl.hasFolder());
    r = rig({ dir: fakeDir('Downloads') });
    await r.ctl.choose();
    check('another folder than FisHotel-PrintQueue is allowed, with a plain note', r.ctl.view().folder === 'Downloads' && /normally watches FisHotel-PrintQueue/.test(r.ctl.view().folderNote));
    r = rig({ pickError: err('AbortError') });
    check('the chooser closed without a folder — or the browser\'s own "edit files?" question answered no (it reports both alike): nothing changes, and one line says what to do', (await r.ctl.choose()) === false && r.ctl.view().folder === '' && r.stored === null && r.ctl.view().message === 'No folder was chosen. Press Choose the Print Watcher folder again, pick FisHotel-PrintQueue, and when the browser asks whether this page may edit files there, allow it.', r.ctl.view().message);
    var keep = fakeDir();
    r = rig({ stored: keep, pickError: err('AbortError') });
    await r.ctl.init();
    check('"change" closed without choosing: the folder in use stays, nothing is said', (await r.ctl.choose()) === false && r.ctl.view().folder === 'FisHotel-PrintQueue' && r.ctl.view().message === '' && r.stored === keep);
    r = rig({ storeFails: true });
    check('a browser that cannot keep the folder (a private window): it works for this page and says it must be chosen again next time', (await r.ctl.choose()) === true && r.ctl.view().folder === 'FisHotel-PrintQueue' && /could not keep the folder \(a private window\?\), so it will have to be chosen again/.test(r.ctl.view().message), r.ctl.view().message);
    r = rig({ pickError: err('SecurityError', 'Must be handling a user gesture') });
    check('the chooser refused by the browser: a plain message, no error', (await r.ctl.choose()) === false && /would not open the folder chooser/.test(r.ctl.view().message));

    section('Send to the printers, then what the page says');
    r = rig({ stored: fakeDir() });
    r.dir = r.stored;
    await r.ctl.init();
    var files = r.files();
    await r.ctl.setFiles(files);
    check('files ready, tick off: nothing is sent until the button', Object.keys(r.dir.files).length === 0 && r.ctl.view().canSend && r.ctl.view().lines.length === 0);
    r.onWait = function (x) {
        if (x.t - new Date(2026, 10, 19, 9, 14, 0).getTime() === 6000) {
            x.dir.take(BROTHER);
        }
    };
    out = await r.ctl.send();
    check('both files are in the folder, byte for byte what the links carry', out.sent.join() === BROTHER + ',' + RX && same(r.dir.files[BROTHER] || files[0].bytes, files[0].bytes) && same(r.dir.files[RX], files[1].bytes) && r.dir.closes.length === 2);
    check('no question was asked for a first send', r.questions.length === 0);
    check('neither real name was ever empty or part-written', JSON.stringify(r.dir.sizesOf(BROTHER)) === '[5000]' && JSON.stringify(r.dir.sizesOf(RX)) === '[7000]');
    check('while it looks: "in the folder ... waiting for the Print Watcher to take it"', r.views.some(function (x) { return x.lines.some(function (l) { return l.text === 'Brother: in the folder (sent today at 9:14 AM) — waiting for the Print Watcher to take it…'; }); }));
    await r.ctl.watching;
    check('the file that is gone: "printing — the Print Watcher took the file"', r.line(BROTHER) === 'Brother: printing — the Print Watcher took the file.', r.line(BROTHER));
    check('the file still there after about 90 seconds: the question about the Print Watcher', r.line(RX) === 'RX106HD: still in the folder — is the Print Watcher running? (the fish icon near the clock)' && r.waits === 45, r.line(RX) + ' ' + r.waits);
    check('... and, once for all such files, that it need not be sent again', r.ctl.view().stillNote === 'A file that is still in the folder prints when the Print Watcher takes it, so it does not need sending again.');
    check('"Look again" is offered for the file still there only', r.ctl.view().lines.filter(function (l) { return l.lookAgain; }).map(function (l) { return l.name; }).join() === RX);
    r.onWait = function (x) { x.dir.take(RX); };
    await r.ctl.lookAgain();
    check('Look again, after the Print Watcher was started: taken, and the note is gone', r.line(RX) === 'RX106HD: printing — the Print Watcher took the file.' && r.ctl.view().stillNote === '');
    var said = [];
    r.views.forEach(function (x) { x.lines.forEach(function (l) { said.push(l.text); }); said.push(x.message); });
    check('the page never says a sheet was printed or came out', !said.some(function (s) { return /\bprinted\b|came out|has printed|done printing/i.test(s); }));

    section('never twice by accident');
    r = rig({ stored: fakeDir() });
    r.dir = r.stored;
    await r.ctl.init();
    await r.ctl.setFiles(r.files());
    var p1 = r.ctl.send();
    var p2 = r.ctl.send();
    await Promise.all([p1, p2]);
    check('a double click is one send: each file written once', p1 === p2 && r.dir.closes.length === 2 && r.questions.length === 0, r.dir.closes.join());
    await r.ctl.watching;
    r.dir.take(BROTHER);
    r.dir.take(RX);
    r.t += 10 * 60000;
    await r.ctl.setFiles(r.files()); // the paperwork is made again for the same day
    check('made again for the same day: each line says when it was sent', r.line(BROTHER) === 'Brother: sent today at 9:14 AM — sending it again asks first.', r.line(BROTHER));
    r.answer = false;
    out = await r.ctl.send();
    check('Send asks first, saying when it was sent', r.questions.length === 1 && /Sending again can print the same paperwork twice\./.test(r.questions[0]) && r.questions[0].indexOf('- ' + BROTHER + ': sent today at 9:14 AM') !== -1 && r.questions[0].indexOf('- ' + RX + ': sent today at 9:14 AM') !== -1, r.questions[0]);
    check('Cancel: nothing is written', out.sent.length === 0 && Object.keys(r.dir.files).length === 0 && r.dir.closes.length === 2 && r.line(RX) === 'RX106HD: not sent again (sent today at 9:14 AM).', r.line(RX));
    r.answer = true;
    out = await r.ctl.send();
    check('OK: sent again', out.sent.length === 2 && r.dir.closes.length === 4 && r.questions.length === 2);
    await r.ctl.watching;

    r = rig({ stored: fakeDir() });
    r.dir = r.stored;
    r.dir.files[BROTHER] = bytesOf(300, 5); // put there another way; this browser has no memory of it
    await r.ctl.init();
    files = r.files();
    await r.ctl.setFiles(files);
    r.answer = false;
    out = await r.ctl.send();
    check('a file of that name still in the folder: asked, and with Cancel it is left as it was — the other file is still sent', r.questions.length === 1 && r.questions[0].indexOf('- ' + BROTHER + ': still in the folder (the Print Watcher has not taken it)') !== -1 && /The other file is sent either way\./.test(r.questions[0]) && r.questions[0].indexOf(RX) === -1 && same(r.dir.files[BROTHER], bytesOf(300, 5)) && same(r.dir.files[RX], files[1].bytes) && out.sent.join() === RX, r.questions[0]);
    check('... and its line says so', r.line(BROTHER) === 'Brother: not sent again — the earlier file is still in the folder.', r.line(BROTHER));
    await r.ctl.watching;
    r.dir.take(RX);
    r.answer = true;
    out = await r.ctl.send();
    check('with OK the file in the folder is replaced by the new one, whole', same(r.dir.files[BROTHER], files[0].bytes) && r.dir.sizesOf(BROTHER).filter(function (s) { return s !== 300 && s !== 5000; }).length === 0 && /a file still in the folder is replaced/.test(r.questions[1]));
    await r.ctl.watching;

    section('the files also opened in their print tabs (the folder chosen after "Print the day\'s paperwork")');
    r = rig();
    await r.ctl.init();
    await r.ctl.setFiles(r.files(true)); // no folder yet: the page opened both print tabs
    await r.ctl.choose();
    r.answer = false;
    out = await r.ctl.send();
    check('Send asks first: the files may already be printed from their tabs', r.questions.length === 1 && /^Sending can print the same paperwork twice\./.test(r.questions[0]) && r.questions[0].indexOf('- ' + BROTHER + ': also opened in its own tab — if it was printed there, it prints again') !== -1 && r.questions[0].indexOf('- ' + RX + ': also opened in its own tab') !== -1 && /OK: send them to the printers\./.test(r.questions[0]), r.questions[0]);
    check('Cancel: nothing is written, nothing is remembered as sent, and the line says where the file is', out.sent.length === 0 && r.dir.history.length === 0 && r.memory.sentAt(BROTHER) === 0 && r.line(BROTHER) === 'Brother: not sent — it is open in its own tab to print from there.', r.line(BROTHER));
    r.answer = true;
    out = await r.ctl.send();
    check('OK: both are sent', out.sent.length === 2 && r.questions.length === 2 && r.dir.closes.length === 2);
    await r.ctl.watching;
    r = rig({ stored: fakeDir() });
    r.dir = r.stored;
    r.memory.setAuto(true);
    await r.ctl.init();
    out = await r.ctl.setFiles(r.files(true));
    check('the tick never sends a file that is open in a print tab by itself', out.sent.length === 0 && r.dir.history.length === 0 && r.questions.length === 0 && r.line(RX) === 'RX106HD: not sent by itself — it also opened in its own tab. Send to the printers sends it.', r.line(RX));
    r = rig({ stored: fakeDir() });
    r.dir = r.stored;
    await r.ctl.init();
    await r.ctl.setFiles(r.files(false));
    out = await r.ctl.send();
    check('with a folder chosen beforehand no tabs open, and a first send asks nothing', out.sent.length === 2 && r.questions.length === 0);
    await r.ctl.watching;

    section('two Ship Day tabs sending in the same instant');
    d = fakeDir();
    var tabA = rig({ stored: d });
    var tabB = rig({ stored: d });
    tabA.dir = tabB.dir = d;
    await tabA.ctl.init();
    await tabB.ctl.init();
    await tabA.ctl.setFiles(tabA.files());
    await tabB.ctl.setFiles(tabB.files());
    // The order that left an empty file under a printer's name when both tabs wrote under ONE temp name:
    // tab A has written its file and is about to rename it; tab B clears "the leftover", makes its own
    // empty temp file and is about to write; A renames; only then does B write.
    var sendB = null;
    var held = false;
    var bAboutToWrite, letBWrite;
    var reached = new Promise(function (resolve) { bAboutToWrite = resolve; });
    var release = new Promise(function (resolve) { letBWrite = resolve; });
    d.gate = async function (op) {
        if (op === 'move' && !sendB) {
            sendB = tabB.ctl.send();
            await reached;
            setImmediate(letBWrite); // after A's rename
        } else if (op === 'write' && sendB && !held) {
            held = true;
            bAboutToWrite();
            await release;
        }
    };
    var outA = await tabA.ctl.send();
    var outB = await sendB;
    d.gate = null;
    var temps = d.created.filter(function (n) { return /^sending-/.test(n); });
    check('each send writes under temp names of its own (the second tab made one before it saw the first tab\'s file)', temps.length === 3 && temps.filter(function (n, i) { return temps.indexOf(n) === i; }).length === 3 && temps.every(function (n) { return /^sending-[12]-of-2-shipday_2026-11-19-[a-z0-9]{6}\.txt$/.test(n) && !printable(n); }), temps.join());
    check('neither printer\'s name was ever empty or part-written in the folder', JSON.stringify(d.sizesOf(BROTHER)) === '[5000]' && JSON.stringify(d.sizesOf(RX)) === '[7000]', JSON.stringify(d.sizesOf(BROTHER)) + ' ' + JSON.stringify(d.sizesOf(RX)));
    check('each file is in the folder once and whole; nothing else is left', Object.keys(d.files).sort().join() === BROTHER + ',' + RX && same(d.files[BROTHER], bytesOf(5000, 1)) && same(d.files[RX], bytesOf(7000, 9)), Object.keys(d.files).join());
    check('the first tab sent both; the second says each was already in the folder and remembers nothing as sent', outA.sent.length === 2 && outB.sent.length === 0 && tabB.line(BROTHER) === 'Brother: a file of this name is already in the folder — left as it was, nothing sent.' && tabB.line(RX) === 'RX106HD: a file of this name is already in the folder — left as it was, nothing sent.' && tabB.memory.sentAt(BROTHER) === 0, JSON.stringify([outA, outB]) + ' ' + tabB.line(BROTHER));
    d.take(BROTHER);
    d.take(RX);
    await tabA.ctl.watching;
    await tabB.ctl.watching;

    section('what a cut-off send left in the folder');
    r = rig({ stored: fakeDir(), token: 'abc123' });
    r.dir = r.stored;
    var OLD = ['sending-1-of-2-shipday_2026-11-18.txt', 'sending-1-of-2-shipday_2026-11-18.txt.crswap', 'sending-2-of-2-shipday_2026-11-18-k3x9ab.txt.1.crswap'];
    var YOUNG = 'sending-1-of-2-shipday_2026-11-19-zz9zz9.txt.crswap'; // another tab's write under way
    var NOT_OURS = ['notes.txt', 'BROTHER_other.pdf', 'sending-list.txt', 'report.crswap'];
    OLD.forEach(function (n) {
        r.dir.files[n] = bytesOf(40, 2);
        r.dir.modified[n] = r.t - 11 * 60000;
    });
    r.dir.files[YOUNG] = bytesOf(40, 2);
    r.dir.modified[YOUNG] = r.t - 9 * 60000;
    NOT_OURS.forEach(function (n) {
        r.dir.files[n] = bytesOf(40, 2);
        r.dir.modified[n] = r.t - 24 * 3600000;
    });
    await r.ctl.init();
    await r.ctl.setFiles(r.files());
    out = await r.ctl.send();
    check('a send clears this script\'s own temp files and their swap files once they are ten minutes old (a tab closed or a browser stopped mid-write)', out.sent.length === 2 && OLD.every(function (n) { return !(n in r.dir.files); }), Object.keys(r.dir.files).join());
    check('... but not a younger one (another tab may be writing it), and never a file that is not its own', YOUNG in r.dir.files && NOT_OURS.every(function (n) { return same(r.dir.files[n], bytesOf(40, 2)); }), Object.keys(r.dir.files).join());
    check('... and its own temp names carry the send\'s letters', r.dir.created.join() === 'sending-1-of-2-shipday_2026-11-19-abc123.txt,sending-2-of-2-shipday_2026-11-19-abc123.txt', r.dir.created.join());
    r.dir.take(BROTHER);
    r.dir.take(RX);
    await r.ctl.watching;

    section('the tick: "by itself when the files are ready"');
    r = rig({ stored: fakeDir() });
    r.dir = r.stored;
    await r.ctl.init();
    check('off by default', r.ctl.view().auto === false);
    r.ctl.setAuto(true);
    check('remembered in this browser', pw.makeMemory(r.storage, function () { return r.t; }).auto() === true && r.ctl.view().auto === true && r.ctl.view().autoNote === '');
    files = r.files();
    out = await r.ctl.setFiles(files);
    check('folder chosen and permission held: the files are sent as soon as they are ready, no question', out.sent.length === 2 && same(r.dir.files[RX], files[1].bytes) && r.questions.length === 0 && r.dir.asked === 0);
    await r.ctl.watching;
    r.dir.take(BROTHER);
    r.dir.take(RX);
    out = await r.ctl.setFiles(r.files());
    check('the same day made again: NOT sent by itself, nothing asked, the lines say why', out.sent.length === 0 && Object.keys(r.dir.files).length === 0 && r.questions.length === 0 && r.line(BROTHER) === 'Brother: not sent by itself — it was sent today at 9:14 AM. Send to the printers sends it again.', r.line(BROTHER));
    r = rig({ stored: fakeDir() });
    r.dir = r.stored;
    r.dir.perm = 'prompt'; // a new visit: the browser would have to ask
    r.memory.setAuto(true);
    await r.ctl.init();
    out = await r.ctl.setFiles(r.files());
    check('permission not held: nothing is sent or asked by itself — the button is there', out.sent.length === 0 && r.dir.asked === 0 && Object.keys(r.dir.files).length === 0 && r.ctl.view().canSend === true && r.ctl.view().message === '' && /only asks after a click/.test(r.ctl.view().autoNote));
    out = await r.ctl.send();
    check('the button (a click) lets the browser ask, then sends', r.dir.asked === 1 && out.sent.length === 2);
    await r.ctl.watching;
    r = rig();
    r.memory.setAuto(true);
    await r.ctl.init();
    out = await r.ctl.setFiles(r.files());
    check('tick on but no folder chosen: nothing happens', out === null && r.picks === 0);

    section('the folder says no, or is gone');
    r = rig({ stored: fakeDir() });
    r.dir = r.stored;
    r.dir.perm = 'prompt';
    r.dir.onAsk = 'denied';
    await r.ctl.init();
    await r.ctl.setFiles(r.files());
    out = await r.ctl.send();
    check('the person does not allow it: nothing sent, a plain message pointing at the links', out.sent.length === 0 && r.dir.history.length === 0 && /did not let this page use the folder — nothing was sent\./.test(r.ctl.view().message) && r.ctl.view().canSend === true, r.ctl.view().message);
    r = rig({ stored: fakeDir() });
    r.dir = r.stored;
    r.dir.gone = true;
    await r.ctl.init();
    check('the folder was deleted since: said when the page opens', /cannot be found/.test(r.ctl.view().message));
    await r.ctl.setFiles(r.files());
    out = await r.ctl.send();
    check('... and Send writes nothing and says to choose the folder again', out.sent.length === 0 && /cannot be found \(moved, renamed or deleted\?\)\. Choose the folder again — nothing was sent\./.test(r.ctl.view().message), r.ctl.view().message);
    check('... and nothing is remembered as sent', r.memory.sentAt(BROTHER) === 0);

    r = rig({ stored: fakeDir() });
    r.dir = r.stored;
    await r.ctl.init();
    await r.ctl.setFiles(r.files());
    r.onWait = function (x) { x.dir.gone = true; };
    await r.ctl.send();
    await r.ctl.watching;
    check('the folder deleted while the page looks: it says it cannot tell — not "took the file"', /^Brother: the folder cannot be found any more/.test(r.line(BROTHER)) && !/took the file/.test(r.line(BROTHER) + r.line(RX)), r.line(BROTHER));
    r = rig({ stored: fakeDir() });
    r.dir = r.stored;
    await r.ctl.init();
    await r.ctl.setFiles(r.files());
    r.onWait = function (x) { x.dir.perm = 'prompt'; };
    await r.ctl.send();
    await r.ctl.watching;
    check('the permission taken away while the page looks: it says it cannot tell', /^RX106HD: the file is in the folder, but the browser no longer lets this page look there/.test(r.line(RX)), r.line(RX));

    r = rig({ stored: fakeDir() });
    r.dir = r.stored;
    r.dir.fail.write = err('QuotaExceededError', 'The disk is full');
    await r.ctl.init();
    files = r.files();
    await r.ctl.setFiles(files);
    out = await r.ctl.send();
    check('one file cannot be written: said with the reason, nothing of it left, not remembered as sent; the other is sent', out.sent.join() === RX && r.line(BROTHER) === 'Brother: could not be put in the folder (The disk is full) — nothing was sent. Use its links above.' && Object.keys(r.dir.files).join() === RX && r.memory.sentAt(BROTHER) === 0 && r.memory.sentAt(RX) > 0, r.line(BROTHER) + ' | ' + Object.keys(r.dir.files).join());
    await r.ctl.watching;
    out = await r.ctl.send();
    check('pressing Send again then asks only about the file that was sent', r.questions.length === 1 && r.questions[0].indexOf(BROTHER) === -1 && r.questions[0].indexOf('- ' + RX) !== -1);
    await r.ctl.watching;

    r = rig({ stored: fakeDir() });
    r.dir = r.stored;
    await r.ctl.init();
    await r.ctl.setFiles(r.files());
    var seen = 0;
    r.onWait = function (x) {
        seen++;
        if (seen === 2) {
            x.ctl.clearFiles(); // "Print the day's paperwork" pressed again
        }
    };
    await r.ctl.send();
    await r.ctl.watching;
    check('a new "Print the day\'s paperwork" stops the looking and clears the lines', r.waits === 2 && r.ctl.view().lines.length === 0 && r.ctl.view().ready === false);

    section('a browser without the folder API: no button, no error');
    check('supported(): needs showDirectoryPicker and IndexedDB; anything else is a plain no', pw.supported({ showDirectoryPicker: function () {}, indexedDB: {} }) === true && pw.supported({ indexedDB: {} }) === false && pw.supported({ showDirectoryPicker: function () {} }) === false && pw.supported(undefined) === false && pw.supported(Object.defineProperty({ showDirectoryPicker: function () {} }, 'indexedDB', { get: function () { throw err('SecurityError'); } })) === false);
    var phone = { showDirectoryPicker: function () {}, indexedDB: {}, navigator: { userAgent: 'Mozilla/5.0 (Linux; Android 16; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36' } };
    var tablet = { showDirectoryPicker: function () {}, indexedDB: {}, navigator: { userAgent: 'Mozilla/5.0 (Linux; Android 16; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36' } };
    var hinted = { showDirectoryPicker: function () {}, indexedDB: {}, navigator: { userAgent: 'Mozilla/5.0 (X11; Linux x86_64)', userAgentData: { mobile: true } } };
    var pc = { showDirectoryPicker: function () {}, indexedDB: {}, navigator: { userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36 Edg/153.0.0.0', userAgentData: { mobile: false } } };
    check('supported(): Chrome on an Android phone or tablet has the chooser but not the PC\'s folder — a plain no; Chrome / Edge on Windows yes', pw.supported(phone) === false && pw.supported(tablet) === false && pw.supported(hinted) === false && pw.supported(pc) === true);
    r = rig({ supported: false });
    r.memory.setAuto(true);
    await r.ctl.init();
    out = await r.ctl.setFiles(r.files());
    v = r.ctl.view();
    check('Firefox / Safari / a phone / http: nothing to press, nothing sent, nothing thrown', v.supported === false && v.canSend === false && out === null && (await r.ctl.send()).sent.length === 0 && (await r.ctl.choose()) === false && r.picks === 0);

    section('the two files themselves are unchanged');
    // The same plan through makeFiles() with the clock held still (pdf-lib stamps the time into a file).
    var RealDate = Date;
    var fixed = new RealDate(Date.UTC(2026, 10, 19, 15, 0, 0)).getTime();
    function FrozenDate(a, b, c, d2, e, f, g) {
        if (!(this instanceof FrozenDate)) {
            return new RealDate(fixed).toString();
        }
        return arguments.length === 0 ? new RealDate(fixed) : (arguments.length === 1 ? new RealDate(a) : new RealDate(a, b, c || 1, d2 || 0, e || 0, f || 0, g || 0));
    }
    FrozenDate.now = function () { return fixed; };
    FrozenDate.UTC = RealDate.UTC;
    FrozenDate.parse = RealDate.parse;
    FrozenDate.prototype = RealDate.prototype;
    global.Date = FrozenDate;
    var made;
    try {
        made = await sampleFiles();
    } finally {
        global.Date = RealDate;
    }
    var hashB = crypto.createHash('sha256').update(made.brother).digest('hex');
    var hashR = crypto.createHash('sha256').update(made.rx).digest('hex');
    check('BROTHER file: byte for byte what 10.35 made for this plan (sha256 ' + hashB.slice(0, 12) + '…)', hashB === BASELINE_10_35.brother, hashB);
    check('RX106HD file: byte for byte what 10.35 made for this plan (sha256 ' + hashR.slice(0, 12) + '…)', hashR === BASELINE_10_35.rx, hashR);
    r = rig({ stored: fakeDir() });
    r.dir = r.stored;
    await r.ctl.init();
    await r.ctl.setFiles([{ printer: 'Brother', name: BROTHER, bytes: made.brother }, { printer: 'RX106HD', name: RX, bytes: made.rx }]);
    await r.ctl.send();
    check('what lands in the folder is those bytes, under those names', same(r.dir.files[BROTHER], made.brother) && same(r.dir.files[RX], made.rx) && Object.keys(r.dir.files).sort().join() === BROTHER + ',' + RX && paper.isPdf(r.dir.files[RX]));
    r.dir.take(BROTHER);
    r.dir.take(RX);
    await r.ctl.watching;

    console.log('\n' + passes + ' passed, ' + failures + ' failed.');
    process.exit(failures > 0 ? 1 : 0);
}

/**
 * sha256 of the two files paperwork.js 10.35 (commit fdbe28f) made for
 * sampleFiles()'s plan with the clock held at 2026-11-19 15:00 UTC. "Send to
 * the printers" must not change a byte of either file.
 */
var BASELINE_10_35 = {
    brother: 'd820d8b03546146ab839277ac8dc795ea1d206b88a3f9a3a7db56ddd252d7a0c',
    rx: 'a49ef3914258da061c3b4f94729583efd93aef8cd2ab25b9832ed1149f63f509'
};

async function sampleFiles() {
    async function pdf(size, n) {
        var doc = await L.PDFDocument.create();
        var font = await doc.embedFont(L.StandardFonts.Helvetica);
        for (var i = 0; i < n; i++) {
            doc.addPage(size).drawText('page ' + (i + 1), { x: 20, y: 20, size: 10, font: font });
        }
        return doc.save();
    }
    var store = {
        'inv/101': await pdf([500, 700], 1),
        'inv/102': await pdf([501, 700], 2),
        'media/acc.pdf': await pdf([610, 790], 1),
        'media/labels.pdf': await pdf([792, 612], 1)
    };
    var io = {
        fetchBytes: function (url) { return url in store ? Promise.resolve(store[url]) : Promise.reject(new Error('404')); },
        loadImage: function () { return Promise.resolve(null); }
    };
    var plan = {
        files: { brother: BROTHER, rx: RX },
        label_copies: 4,
        invoice_plugin: true,
        acclimation: { id: 501, url: 'media/acc.pdf', title: 'Acclimation' },
        labels: { id: 502, url: 'media/labels.pdf', title: 'Labels' },
        boxes: [
            { label: 'Order #101', invoices: [{ order_id: 101, number: '101', url: 'inv/101' }], sheets: [] },
            { label: 'Order #102', invoices: [{ order_id: 102, number: '102', url: 'inv/102' }], sheets: [71] }
        ],
        sheets: { 71: { title: 'Invoice - Sam', price: '$125.00', image: '', lines: ['Hectors Goby x1 — $39', 'Yellow Tang x1 — $86'] } },
        pages: {
            pick: {
                title: 'FisHotel Pick List',
                sub: 'Ship Thursday, November 19 • Deliver Friday, November 20 • 2 orders / 3 fish',
                head: ['Pick', 'Qty', 'Fish', 'Order', 'Destination'],
                rows: [
                    { first: true, qty: 2, fish: [{ t: 'Hectors Goby', s: 'n' }, { t: 'Bag: Small bag', s: 'bag' }], order: [{ t: 'Order #101', s: 'b' }, { br: 1 }, { t: 'Pat Reefer', s: 'n' }], dest: [{ t: 'Austin, TX 78701', s: 'n' }, { br: 1 }, { t: '(HEAT PACK)', s: 'flag' }] },
                    { first: true, qty: 1, fish: [{ t: 'Yellow Tang', s: 'n' }, { br: 1 }, { t: 'from Invoice - Sam', s: 'sub' }], order: [{ t: 'Order #102', s: 'b' }], dest: [{ t: 'Boise, ID 83702', s: 'n' }] }
                ],
                foot: '3 fish · 0 dry goods · 2 orders',
                legend: 'Legend: (HEAT PACK) heat pack in the box · (CHECK) verify by hand'
            },
            weather: {
                title: 'Weather Overview — Delivery Friday, November 20',
                sub: 'by delivery-day high: <30 hold · 30–40 UPS Store · 40–70 heat pack',
                head: ['Order', 'Destination', 'High', 'Low', 'Conditions', 'Action'],
                rows: [{ cells: ['Order #101', 'Austin, TX 78701', '62°F', '41°F', 'Sunny', 'Heat pack'], tone: 'blue', fill: '#dbeeff' }],
                notes: ['Data: National Weather Service (api.weather.gov)']
            }
        },
        warnings: []
    };
    return paper.makeFiles(plan, io, L);
}

main().catch(function (e) {
    console.log('  FAIL - ' + (e && e.stack ? e.stack : e));
    process.exit(1);
});
