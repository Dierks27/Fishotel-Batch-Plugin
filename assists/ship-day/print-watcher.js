/**
 * Ship Day → "Send to the printers" (FisHotel Batch Manager 10.36).
 *
 * The FisHotel Print Watcher is the owner's own program on the shop PC. It
 * watches ONE folder (Documents → FisHotel-PrintQueue), prints every PDF that
 * appears there on the printer its name starts with (BROTHER_… / RX106HD_…)
 * once the file has been still for about 5 seconds, then deletes the file.
 * The two files "Print the day's paperwork" makes (paperwork.js) are already
 * named that way, so this script puts them in that folder: Chrome / Edge on
 * the PC are given the folder ONCE (the File System Access API,
 * showDirectoryPicker), the browser keeps the folder (IndexedDB), and from
 * then on a click — or the tick "by itself" — writes both files there with no
 * dialog. The Download / Print links stay for every browser.
 *
 * Nothing goes to the server and nothing is stored there: the folder, the
 * tick and "already sent" live in this browser only.
 *
 * NEVER A HALF-WRITTEN FILE under a name the Print Watcher prints. Checked in
 * Chromium 153 against a real folder on disk (a poller reading the folder every
 * millisecond while 8 and 40 MB files were written) and in its source
 * (content/browser/file_system_access: file_system_access_file_handle_impl.cc,
 * file_system_access_safe_move_helper.cc, file_system_access_handle_base.cc):
 *
 *   - createWritable() does not write into the file. It writes into a sibling
 *     "<name>.crswap", and close() moves that over the file in one step, so a
 *     file is either what it was before or whole. But getFileHandle(name,
 *     { create: true }) first makes an EMPTY file of that name, which stays
 *     empty for as long as the writing takes, and abort() leaves it there.
 *   - handle.move(newName) renames a finished file inside the folder; the new
 *     name appears once, already whole. It needs no click, only the write
 *     permission the folder already has, and replaces a file of that name.
 *
 * So writeWhole() writes each file under a name the Print Watcher does not
 * print ("sending-1-of-2-shipday_<date>.part": no printer at the front, not a
 * PDF by name — and so neither is its ".crswap"), checks its size, and renames
 * it to the real name: the real name is never empty and never part-written.
 * Only when the browser has no move() or refuses the rename does it write
 * straight to the real name; that still can never be read half-written (the
 * swap file above), but the name exists empty while the file is written, and
 * the Print Watcher's own 5-second wait is what covers that moment. A write
 * that fails takes its own leftovers away.
 *
 * NEVER TWICE BY ACCIDENT. A file that was sent from this browser before
 * ("Sent today at 9:14 AM"), or whose name is still in the folder, is only sent
 * again after a plain question; the tick never sends such a file by itself; a
 * double click is one send.
 *
 * WHAT THE PAGE CAN KNOW. It sees the folder, not the printers. After a send
 * it looks in the folder for about 90 seconds: the file gone means the Print
 * Watcher took it; the file still there means the Print Watcher is probably
 * not running. It never says a sheet was printed.
 *
 * The folder functions take the folder (a FileSystemDirectoryHandle) as a
 * parameter, so tests drive them with a stand-in under node:
 *   node tests/ship-day-print-watcher-js-test.js
 * and against the real API in Chromium when Playwright is installed:
 *   node tests/ship-day-print-watcher-browser-test.js
 */
(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.FisHotelPrintWatcher = api;
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    /** The folder the Print Watcher watches (Documents → FisHotel-PrintQueue). */
    var FOLDER_NAME = 'FisHotel-PrintQueue';
    /** How long the page looks in the folder after a send, and how often. */
    var WATCH_MS = 90000;
    var WATCH_EVERY_MS = 2000;
    /** localStorage: { auto: bool, sent: { file name: time } }. */
    var MEMORY_KEY = 'fishotel_print_watcher';
    /** "Already sent" is forgotten after three weeks (the file name carries the ship date). */
    var KEEP_SENT_MS = 21 * 24 * 3600 * 1000;
    var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    /* ── small helpers ─────────────────────────────────────────── */

    function errName(e) {
        return e && e.name ? String(e.name) : '';
    }

    /** True when this browser can be given a folder (Chrome / Edge on a computer, over https). */
    function supported(win) {
        try {
            return !!win && typeof win.showDirectoryPicker === 'function' && !!win.indexedDB;
        } catch (e) {
            return false;
        }
    }

    /** Why a folder operation failed: 'gone' (the folder is not there), 'denied' (no permission) or 'error'. */
    function folderProblem(e) {
        var n = errName(e);
        if (n === 'NotFoundError') {
            return 'gone';
        }
        if (n === 'NotAllowedError' || n === 'SecurityError') {
            return 'denied';
        }
        return 'error';
    }

    /**
     * The name a file is written under before it is renamed: nothing the Print
     * Watcher prints (no printer at the front, not ".pdf"), and different for
     * each file of one send.
     */
    function tempNameFor(name, index, count) {
        var rest = String(name).replace(/\.pdf$/i, '');
        var cut = rest.indexOf('_');
        rest = cut === -1 ? 'file' : rest.slice(cut + 1);
        return 'sending-' + ((index || 0) + 1) + '-of-' + (count || 1) + '-' + rest + '.part';
    }

    /** "today at 9:14 AM" / "on Nov 19 at 9:14 AM", in the PC's own time. */
    function whenWords(at, now) {
        var d = new Date(at);
        var n = new Date(now);
        var h = d.getHours();
        var m = d.getMinutes();
        var time = (h % 12 === 0 ? 12 : h % 12) + ':' + (m < 10 ? '0' : '') + m + (h < 12 ? ' AM' : ' PM');
        var sameDay = d.getFullYear() === n.getFullYear() && d.getMonth() === n.getMonth() && d.getDate() === n.getDate();
        return (sameDay ? 'today' : 'on ' + MONTHS[d.getMonth()] + ' ' + d.getDate()) + ' at ' + time;
    }

    /* ── the folder (every function takes the folder handle) ───── */

    /**
     * 'granted', 'prompt' or 'denied' for reading and writing in the folder.
     * With ask, the browser's own question is shown when it is needed — a
     * browser only asks inside a click, so ask is for clicks only.
     */
    async function permission(dir, ask) {
        var opts = { mode: 'readwrite' };
        var state;
        try {
            state = await dir.queryPermission(opts);
        } catch (e) {
            return 'denied';
        }
        if (state === 'granted' || !ask) {
            return state;
        }
        try {
            return await dir.requestPermission(opts);
        } catch (e) {
            return state; // no click behind it: nothing was asked
        }
    }

    /**
     * The names in the folder. Throws when the folder is gone (NotFoundError)
     * or may not be read (NotAllowedError) — which is how "the folder is gone"
     * is told from "the file is gone": getFileHandle() answers NotFoundError
     * for both.
     */
    async function listNames(dir) {
        var names = [];
        var it = dir.entries();
        for (;;) {
            var step = await it.next();
            if (step.done) {
                break;
            }
            names.push(step.value[0]);
        }
        return names;
    }

    async function exists(dir, name) {
        try {
            await dir.getFileHandle(name);
            return true;
        } catch (e) {
            if (errName(e) === 'NotFoundError') {
                return false;
            }
            if (errName(e) === 'TypeMismatchError') {
                return true; // a folder of that name
            }
            throw e;
        }
    }

    async function removeQuietly(dir, name) {
        try {
            await dir.removeEntry(name);
        } catch (e) { /* not there */ }
    }

    /** Write all the bytes through the handle's swap file; on any failure nothing of it is kept. */
    async function fill(handle, bytes) {
        var writable = await handle.createWritable();
        try {
            await writable.write(bytes);
            await writable.close();
        } catch (e) {
            try {
                await writable.abort();
            } catch (e2) { /* already closed */ }
            throw e;
        }
    }

    function namedError(name, message) {
        var e = new Error(message);
        e.name = name;
        return e;
    }

    /**
     * Put one whole file in the folder under `name` — whole or not at all (see
     * the file comment for what is relied on).
     *
     * @param {object}     dir   FileSystemDirectoryHandle (write permission held)
     * @param {string}     name  the name the Print Watcher prints
     * @param {Uint8Array} bytes
     * @param {object}     opts  { tempName, replace } — without replace a file
     *                           of that name already in the folder is left as it
     *                           is and FileThereError is thrown.
     * @return {Promise<string>} 'renamed' (temp name, then move) or 'direct'
     */
    async function writeWhole(dir, name, bytes, opts) {
        opts = opts || {};
        var temp = opts.tempName || tempNameFor(name, 0, 1);
        if (!opts.replace && await exists(dir, name)) {
            throw namedError('FileThereError', 'A file of this name is already in the folder.');
        }

        await removeQuietly(dir, temp); // left by a send that was cut off
        var handle = await dir.getFileHandle(temp, { create: true });
        if (typeof handle.move === 'function') {
            try {
                await fill(handle, bytes);
                if (typeof handle.getFile === 'function') {
                    var size = (await handle.getFile()).size;
                    if (size !== bytes.length) {
                        throw namedError('ShortWriteError', 'Only ' + size + ' of ' + bytes.length + ' bytes were written.');
                    }
                }
            } catch (e) {
                await removeQuietly(dir, temp);
                throw e;
            }
            if (!opts.replace && await exists(dir, name)) {
                await removeQuietly(dir, temp);
                throw namedError('FileThereError', 'A file of this name is already in the folder.');
            }
            try {
                await handle.move(name);
                return 'renamed';
            } catch (e) {
                // This browser does not rename here: write to the real name below.
            }
        }
        await removeQuietly(dir, temp);

        // The real name directly. getFileHandle() makes it EMPTY first; the
        // bytes arrive whole when close() swaps the ".crswap" in. A failure
        // takes the empty file away again (abort() does not), unless a file of
        // that name was there before — that one is still what it was.
        var was = await exists(dir, name);
        var real = await dir.getFileHandle(name, { create: true });
        try {
            await fill(real, bytes);
        } catch (e) {
            if (!was) {
                await removeQuietly(dir, name);
            }
            throw e;
        }
        return 'direct';
    }

    /**
     * Look in the folder until every name is gone or the time is up.
     *
     * @param {object}   dir   FileSystemDirectoryHandle
     * @param {string[]} names the files just put there
     * @param {object}   opts  { now(), wait(ms), totalMs, everyMs, onChange(states), stop() }
     * @return {Promise<object>} name → 'taken' (gone from the folder), 'still'
     *     (there when the time was up), 'folder-gone', 'no-permission' or
     *     'unknown' (the folder could not be read)
     */
    async function watchFiles(dir, names, opts) {
        opts = opts || {};
        var now = opts.now || Date.now;
        var total = opts.totalMs == null ? WATCH_MS : opts.totalMs;
        var every = opts.everyMs || WATCH_EVERY_MS;
        var states = {};
        names.forEach(function (n) { states[n] = 'waiting'; });
        function setWaiting(to) {
            names.forEach(function (n) {
                if (states[n] === 'waiting') {
                    states[n] = to;
                }
            });
        }
        var start = now();
        for (;;) {
            var listed;
            try {
                listed = await listNames(dir);
            } catch (e) {
                var why = folderProblem(e);
                setWaiting(why === 'gone' ? 'folder-gone' : (why === 'denied' ? 'no-permission' : 'unknown'));
                break;
            }
            var left = 0;
            names.forEach(function (n) {
                if (states[n] !== 'waiting') {
                    return;
                }
                if (listed.indexOf(n) === -1) {
                    states[n] = 'taken';
                } else {
                    left++;
                }
            });
            if (left === 0) {
                break;
            }
            if (now() - start >= total) {
                setWaiting('still');
                break;
            }
            if (opts.onChange) {
                opts.onChange(states);
            }
            await opts.wait(every);
            if (opts.stop && opts.stop()) {
                return null;
            }
        }
        if (opts.onChange) {
            opts.onChange(states);
        }
        return states;
    }

    /* ── what this browser remembers ───────────────────────────── */

    /** The chosen folder, kept in IndexedDB (a folder handle cannot go in localStorage). */
    function handleStore(idb) {
        var DB = 'fishotel-ship-day';
        var STORE = 'kv';
        var KEY = 'print-watcher-folder';
        function run(mode, fn) {
            return new Promise(function (resolve, reject) {
                var open;
                try {
                    open = idb.open(DB, 1);
                } catch (e) {
                    reject(e);
                    return;
                }
                open.onupgradeneeded = function () {
                    open.result.createObjectStore(STORE);
                };
                open.onerror = function () { reject(open.error); };
                open.onsuccess = function () {
                    var db = open.result;
                    try {
                        var tx = db.transaction(STORE, mode);
                        var req = fn(tx.objectStore(STORE));
                        tx.oncomplete = function () {
                            db.close();
                            resolve(req.result);
                        };
                        tx.onerror = tx.onabort = function () {
                            db.close();
                            reject(tx.error);
                        };
                    } catch (e) {
                        db.close();
                        reject(e);
                    }
                };
            });
        }
        return {
            get: function () {
                return run('readonly', function (s) { return s.get(KEY); }).then(function (v) { return v || null; }).catch(function () { return null; });
            },
            set: function (handle) {
                return run('readwrite', function (s) { return s.put(handle, KEY); }).then(function () { return true; }).catch(function () { return false; });
            },
            clear: function () {
                return run('readwrite', function (s) { return s.delete(KEY); }).then(function () { return true; }).catch(function () { return false; });
            }
        };
    }

    /**
     * The tick and "already sent", in localStorage. When the browser has no
     * storage (private window) it is remembered for this page only.
     */
    function makeMemory(storage, now) {
        var mem = {};
        function load() {
            try {
                var d = JSON.parse(storage.getItem(MEMORY_KEY) || 'null');
                if (d && typeof d === 'object') {
                    mem = d;
                }
            } catch (e) { /* keep what this page knows */ }
            if (!mem.sent || typeof mem.sent !== 'object') {
                mem.sent = {};
            }
            return mem;
        }
        function save() {
            try {
                storage.setItem(MEMORY_KEY, JSON.stringify(mem));
            } catch (e) { /* this page still knows */ }
        }
        return {
            auto: function () { return load().auto === true; },
            setAuto: function (on) {
                load().auto = !!on;
                save();
            },
            sentAt: function (name) {
                var at = load().sent[name];
                return typeof at === 'number' && at > 0 ? at : 0;
            },
            markSent: function (name) {
                var sent = load().sent;
                var t = now();
                Object.keys(sent).forEach(function (k) {
                    if (typeof sent[k] !== 'number' || t - sent[k] > KEEP_SENT_MS) {
                        delete sent[k];
                    }
                });
                sent[name] = t;
                save();
            }
        };
    }

    /* ── the words ─────────────────────────────────────────────── */

    /** One file's line. Never says a sheet was printed: the page sees the folder only. */
    function lineText(f, now) {
        var p = f.printer + ': ';
        var sent = f.at ? 'sent ' + whenWords(f.at, now) : '';
        switch (f.state) {
            case 'writing':
                return p + 'putting the file in the folder…';
            case 'waiting':
                return p + 'in the folder (' + sent + ') — waiting for the Print Watcher to take it…';
            case 'taken':
                return p + 'printing — the Print Watcher took the file.';
            case 'still':
                return p + 'still in the folder — is the Print Watcher running? (the fish icon near the clock) The file prints when the Print Watcher takes it, so it does not need sending again.';
            case 'held':
                return p + 'not sent by itself — ' + (f.at ? 'it was ' + sent : '') + (f.at && f.there ? ' and ' : '') + (f.there ? 'a file of this name is still in the folder' : '') + '. Send to the printers sends it again.';
            case 'left':
                return p + 'not sent again' + (f.at ? ' (' + sent + ')' : '') + (f.there ? ' — the earlier file is still in the folder.' : '.');
            case 'there':
                return p + 'a file of this name is already in the folder — left as it was, nothing sent.';
            case 'failed':
                return p + 'could not be put in the folder' + (f.detail ? ' (' + f.detail + ')' : '') + ' — nothing was sent. Use its links above.';
            case 'no-permission':
                return p + 'the file is in the folder, but the browser no longer lets this page look there, so the page cannot tell whether the Print Watcher took it.';
            case 'folder-gone':
                return p + 'the folder cannot be found any more (moved, renamed or deleted?), so the page cannot tell what happened to the file. Choose the folder again.';
            case 'unknown':
                return p + 'the file was put in the folder, but the folder could not be read afterwards, so the page cannot tell whether the Print Watcher took it.';
            default:
                return f.at ? p + 'sent ' + whenWords(f.at, now) + ' — sending it again asks first.' : '';
        }
    }

    /** The question before a file is sent a second time. */
    function question(ask, others, now) {
        var one = ask.length === 1;
        var lines = ['Sending again can print the same paperwork twice.', ''];
        var anyThere = false;
        ask.forEach(function (f) {
            var why = [];
            if (f.at) {
                why.push('sent ' + whenWords(f.at, now));
            }
            if (f.there) {
                anyThere = true;
                why.push('still in the folder (the Print Watcher has not taken it)');
            }
            lines.push('- ' + f.name + ': ' + why.join('; '));
        });
        lines.push('');
        lines.push('OK: send ' + (one ? 'it' : 'them') + ' again' + (anyThere ? ' (a file still in the folder is replaced)' : '') + '.');
        lines.push('Cancel: leave ' + (one ? 'it' : 'them') + ' alone.' + (others ? ' The other file is sent either way.' : ''));
        return lines.join('\n');
    }

    /* ── the controller (no DOM: paint(view) shows it) ─────────── */

    /**
     * @param {object} deps {
     *     supported  bool
     *     pick()     → Promise<FileSystemDirectoryHandle> (the browser's folder chooser; a click only)
     *     store      { get(), set(handle), clear() } → Promises
     *     memory     makeMemory()
     *     confirm(text) → bool | Promise<bool>
     *     now(), wait(ms)
     *     paint(view)
     *     watchMs, watchEveryMs   (tests)
     * }
     */
    function create(deps) {
        var now = deps.now || Date.now;
        var memory = deps.memory;
        var folder = null;
        var perm = '';
        var files = [];
        var busy = false;
        var message = '';
        var sending = null;  // the send under way: a second click joins it
        var round = 0;       // a newer set of files, or a newer send, stops an older watch
        var ctl = { watching: null };

        function view() {
            var t = now();
            return {
                supported: !!deps.supported,
                folder: folder ? String(folder.name) : '',
                expected: FOLDER_NAME,
                folderNote: folder && String(folder.name) !== FOLDER_NAME ? 'This folder is called "' + folder.name + '". The Print Watcher normally watches ' + FOLDER_NAME + ' — if nothing prints, choose that one.' : '',
                permission: perm,
                auto: memory.auto(),
                autoNote: memory.auto() && folder && perm !== 'granted' ? 'The browser will ask about the folder first, and it only asks after a click — so Send to the printers has to be pressed. Choosing "Allow on every visit" when it asks stops the question.' : '',
                ready: files.length > 0,
                canSend: !!folder && files.length > 0 && !busy,
                busy: busy,
                message: message,
                lines: files.map(function (f) {
                    return { printer: f.printer, name: f.name, state: f.state, text: lineText(f, t), lookAgain: f.state === 'still' || f.state === 'unknown' };
                }).filter(function (l) { return l.text !== ''; })
            };
        }
        function paint() {
            if (deps.paint) {
                deps.paint(view());
            }
        }

        function folderMessage(why, detail) {
            if (why === 'gone') {
                return 'The Print Watcher folder cannot be found (moved, renamed or deleted?). Choose the folder again — nothing was sent.';
            }
            if (why === 'denied') {
                return 'The browser did not let this page use the folder — nothing was sent. Press Send to the printers again and allow it, or use the links above.';
            }
            return 'The folder could not be read' + (detail ? ' (' + detail + ')' : '') + ' — nothing was sent. Use the links above.';
        }

        /** Look for the stored folder; ask nothing. */
        ctl.init = async function () {
            if (!deps.supported) {
                paint();
                return;
            }
            folder = await deps.store.get();
            perm = folder ? await permission(folder, false) : '';
            if (folder && perm === 'granted') {
                try {
                    await listNames(folder);
                } catch (e) {
                    if (folderProblem(e) === 'gone') {
                        message = 'The Print Watcher folder cannot be found (moved, renamed or deleted?). Choose the folder again.';
                    }
                }
            }
            paint();
        };

        /** A click on "Choose the Print Watcher folder" / "change". Never sends anything. */
        ctl.choose = async function () {
            if (!deps.supported) {
                return false;
            }
            var picked;
            try {
                picked = await deps.pick();
            } catch (e) {
                if (errName(e) !== 'AbortError') {
                    message = 'The browser would not open the folder chooser' + (e && e.message ? ' (' + e.message + ')' : '') + '.';
                    paint();
                }
                return false; // closed without choosing: nothing changes
            }
            if (!picked) {
                return false;
            }
            folder = picked;
            perm = await permission(folder, false);
            message = '';
            await deps.store.set(folder);
            paint();
            return true;
        };

        ctl.forget = async function () {
            folder = null;
            perm = '';
            message = '';
            await deps.store.clear();
            paint();
        };

        ctl.setAuto = function (on) {
            memory.setAuto(on);
            paint();
        };

        /** True once a folder is chosen in this browser (known without waiting). */
        ctl.hasFolder = function () {
            return !!folder;
        };

        /** A new "Print the day's paperwork" started: the old files are gone. */
        ctl.clearFiles = function () {
            round++;
            files = [];
            message = '';
            paint();
        };

        /**
         * The files are ready: [{ printer, name, bytes }]. With the tick on, a
         * folder chosen and the permission held they are sent now; otherwise
         * the button waits. Resolves when that is settled (not when the
         * watching ends).
         */
        ctl.setFiles = function (list) {
            round++;
            message = '';
            files = (list || []).filter(function (f) { return f && f.name && f.bytes; }).map(function (f) {
                return { printer: f.printer, name: f.name, bytes: f.bytes, state: 'ready', at: memory.sentAt(f.name), there: false, detail: '' };
            });
            paint();
            if (deps.supported && folder && files.length && memory.auto()) {
                return ctl.send({ auto: true });
            }
            return Promise.resolve(null);
        };

        /** "Send to the printers" (a click), or the tick ({ auto: true }). A double click is one send. */
        ctl.send = function (opts) {
            if (sending) {
                return sending;
            }
            var p = doSend(!!(opts && opts.auto)).then(function (r) {
                sending = null;
                busy = false;
                paint();
                return r;
            }, function (e) {
                sending = null;
                busy = false;
                message = 'Sending failed' + (e && e.message ? ' (' + e.message + ')' : '') + '. Use the links above.';
                paint();
                return { sent: [] };
            });
            sending = p;
            return p;
        };

        async function doSend(auto) {
            var result = { sent: [] };
            if (!deps.supported || !folder || !files.length) {
                return result;
            }
            var mine = files;
            busy = true;
            message = '';
            paint();

            perm = await permission(folder, !auto);
            if (perm !== 'granted') {
                message = auto ? '' : folderMessage('denied');
                return result;
            }
            var listed;
            try {
                listed = await listNames(folder);
            } catch (e) {
                message = folderMessage(folderProblem(e), e && e.message);
                return result;
            }

            // Which files would be a second send?
            var go = [];
            var ask = [];
            mine.forEach(function (f) {
                f.at = memory.sentAt(f.name);
                f.there = listed.indexOf(f.name) !== -1;
                f.replace = false;
                (f.at || f.there ? ask : go).push(f);
            });
            if (ask.length) {
                var again = auto ? false : !!(await deps.confirm(question(ask, go.length, now())));
                ask.forEach(function (f) {
                    if (again) {
                        f.replace = f.there;
                        go.push(f);
                    } else {
                        f.state = auto ? 'held' : 'left';
                    }
                });
            }
            if (mine !== files) {
                return result; // a new set of files arrived while the question was open
            }

            for (var i = 0; i < mine.length; i++) {
                var f = mine[i];
                if (go.indexOf(f) === -1) {
                    continue;
                }
                f.state = 'writing';
                paint();
                try {
                    await writeWhole(folder, f.name, f.bytes, { tempName: tempNameFor(f.name, i, mine.length), replace: f.replace });
                    memory.markSent(f.name);
                    f.at = memory.sentAt(f.name) || now();
                    f.state = 'waiting';
                    result.sent.push(f.name);
                } catch (e) {
                    if (errName(e) === 'FileThereError') {
                        f.state = 'there';
                    } else {
                        var why = folderProblem(e);
                        f.state = 'failed';
                        f.detail = why === 'gone' ? 'the folder cannot be found' : (why === 'denied' ? 'the browser did not allow it' : (e && e.message ? String(e.message) : ''));
                    }
                }
            }
            if (result.sent.length) {
                ctl.watching = watch(mine);
            }
            return result;
        }

        /** Look in the folder for the files that are waiting ('waiting', 'still' or 'unknown'). */
        function watch(mine) {
            var my = ++round;
            var names = [];
            mine.forEach(function (f) {
                if (f.state === 'waiting' || f.state === 'still' || f.state === 'unknown') {
                    f.state = 'waiting';
                    names.push(f.name);
                }
            });
            paint();
            if (!names.length) {
                return Promise.resolve(null);
            }
            function apply(states) {
                mine.forEach(function (f) {
                    if (states[f.name]) {
                        f.state = states[f.name];
                    }
                });
                paint();
            }
            return watchFiles(folder, names, {
                now: now,
                wait: deps.wait,
                totalMs: deps.watchMs,
                everyMs: deps.watchEveryMs,
                stop: function () { return my !== round || mine !== files; },
                onChange: function (states) {
                    if (my === round && mine === files) {
                        apply(states);
                    }
                }
            }).catch(function () { return null; });
        }

        /** "Look again" beside a file that was still in the folder. */
        ctl.lookAgain = function () {
            if (!folder || !files.length) {
                return Promise.resolve(null);
            }
            ctl.watching = watch(files);
            return ctl.watching;
        };

        ctl.view = view;
        return ctl;
    }

    /* ── the page ──────────────────────────────────────────────── */

    /**
     * Show the box in `box` (an empty element the page provides) and return
     * the controller. Without the folder API there is no button, only one
     * quiet line beside the files; nothing here throws on any browser.
     */
    function mount(win, box) {
        var doc = win.document;
        var ok = supported(win);
        var storage = null;
        try {
            storage = win.localStorage;
        } catch (e) { /* remembered for this page only */ }

        function el(tag, text, style) {
            var n = doc.createElement(tag);
            if (text) {
                n.textContent = text;
            }
            if (style) {
                n.style.cssText = style;
            }
            return n;
        }
        function link(text, fn) {
            var a = el('a', text, 'color:#2986cc;cursor:pointer;text-decoration:underline;');
            a.href = '#';
            a.addEventListener('click', function (ev) {
                ev.preventDefault();
                fn();
            });
            return a;
        }
        function button(text, id, fn, primary) {
            var b = el('button', text, 'background:' + (primary ? '#2e7d32' : '#1e6fa5') + ';color:#fff;font-weight:700;border:none;border-radius:6px;padding:8px 20px;font-size:13px;cursor:pointer;');
            b.type = 'button';
            b.id = id;
            b.addEventListener('click', fn);
            return b;
        }
        var MUTED = 'color:#aaa;font-size:12px;margin:6px 0;';
        var LINE_COLOR = { taken: '#7bd88f', still: '#f0c060', failed: '#f0c060', there: '#f0c060', 'folder-gone': '#f0c060', 'no-permission': '#f0c060', unknown: '#f0c060' };

        function paint(v) {
            box.innerHTML = '';
            if (!v.supported) {
                // One quiet line, only once there is something to print.
                box.style.display = v.ready ? 'block' : 'none';
                if (v.ready) {
                    box.appendChild(el('p', win.isSecureContext === false
                        ? 'Sending the files straight to the Print Watcher needs this page opened over https, in Chrome or Edge on the PC. Here, use the links above.'
                        : 'Sending the files straight to the Print Watcher needs Chrome or Edge on the PC. Here, use the links above.', MUTED));
                }
                return;
            }
            box.style.display = 'block';
            if (!v.folder) {
                var p = el('p', '', 'margin:4px 0;');
                p.appendChild(el('strong', 'Send to the printers. '));
                p.appendChild(doc.createTextNode('This page can put the two files straight into the Print Watcher’s folder, and the Print Watcher prints each one on its own printer — no Print to press. Choose the folder once (Documents → ' + v.expected + '); this browser remembers it.'));
                box.appendChild(p);
                var pc = el('p', '', 'margin:8px 0;');
                pc.appendChild(button('Choose the Print Watcher folder', 'fh-sd-pw-choose', function () { ctl.choose(); }, v.ready));
                box.appendChild(pc);
            } else {
                var pf = el('p', 'Print Watcher folder: ', 'margin:4px 0;');
                pf.appendChild(el('strong', v.folder));
                pf.appendChild(doc.createTextNode(' · '));
                pf.appendChild(link('change', function () { ctl.choose(); }));
                pf.appendChild(doc.createTextNode(' · '));
                pf.appendChild(link('forget', function () { ctl.forget(); }));
                box.appendChild(pf);
                if (v.folderNote) {
                    box.appendChild(el('p', v.folderNote, 'color:#f0c060;font-size:12px;margin:4px 0;'));
                }
                var lab = el('label', '', 'display:block;margin:6px 0;cursor:pointer;');
                var tick = doc.createElement('input');
                tick.type = 'checkbox';
                tick.id = 'fh-sd-pw-auto';
                tick.checked = v.auto;
                tick.style.marginRight = '6px';
                tick.addEventListener('change', function () { ctl.setAuto(tick.checked); });
                lab.appendChild(tick);
                lab.appendChild(doc.createTextNode('Send to the printers by itself when the files are ready'));
                box.appendChild(lab);
                if (v.autoNote) {
                    box.appendChild(el('p', v.autoNote, MUTED));
                }
                if (v.ready) {
                    var ps = el('p', '', 'margin:8px 0;');
                    var send = button(v.busy ? 'Sending…' : 'Send to the printers', 'fh-sd-pw-send', function () { ctl.send(); }, true);
                    send.disabled = !v.canSend;
                    ps.appendChild(send);
                    box.appendChild(ps);
                } else {
                    box.appendChild(el('p', 'Send to the printers appears here when the two files are ready.', MUTED));
                }
            }
            if (v.message) {
                box.appendChild(el('p', v.message, 'color:#f0c060;margin:6px 0;'));
            }
            v.lines.forEach(function (l) {
                var row = el('p', l.text, 'margin:4px 0;color:' + (LINE_COLOR[l.state] || '#ddd') + ';');
                row.setAttribute('data-file', l.name);
                row.setAttribute('data-state', l.state);
                if (l.lookAgain) {
                    row.appendChild(doc.createTextNode(' '));
                    row.appendChild(link('Look again', function () { ctl.lookAgain(); }));
                }
                box.appendChild(row);
            });
            box.appendChild(el('p', 'The Print Watcher must be running on this PC (the fish icon near the clock). This page sees the folder, not the printers: “printing” means the Print Watcher took the file. To use another folder, press “change”; “forget” makes this browser stop using it.', MUTED));
        }

        var ctl = create({
            supported: ok,
            pick: function () {
                return win.showDirectoryPicker({ id: 'fishotel-print-queue', mode: 'readwrite', startIn: 'documents' });
            },
            store: ok ? handleStore(win.indexedDB) : null,
            memory: makeMemory(storage, function () { return Date.now(); }),
            confirm: function (text) { return win.confirm(text); },
            wait: function (ms) {
                return new Promise(function (resolve) { win.setTimeout(resolve, ms); });
            },
            paint: paint
        });
        ctl.ready = ctl.init().catch(function () { /* the links still work */ });
        return ctl;
    }

    return {
        FOLDER_NAME: FOLDER_NAME,
        supported: supported,
        folderProblem: folderProblem,
        tempNameFor: tempNameFor,
        whenWords: whenWords,
        permission: permission,
        listNames: listNames,
        writeWhole: writeWhole,
        watchFiles: watchFiles,
        handleStore: handleStore,
        makeMemory: makeMemory,
        lineText: lineText,
        question: question,
        create: create,
        mount: mount
    };
}));
