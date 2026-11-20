/**
 * Ship Day → "Print the day's paperwork" (FisHotel Batch Manager 10.35).
 *
 * Asks the server for the day's plan (FisHotel_Ship_Day_Paperwork::plan()),
 * then makes two PDF files in the browser with the vendored pdf-lib:
 *
 *   BROTHER_shipday_<ship date>.pdf  per box: each live order's invoice (the
 *       invoice plugin's own PDF, fetched from its own document link — the
 *       request its Create PDF → Invoice button makes), one acclimation sheet;
 *       then the pick list and the weather overview.
 *   RX106HD_shipday_<ship date>.pdf  per box: the shipping labels N times, then
 *       a product sheet for each "Invoice" product in the box.
 *
 * Invoices are fetched one at a time, in box order, and copied in as they
 * arrive, so no request is large and the page shows progress. A file that is
 * missing or cannot be read is skipped with a plain warning, never a failure.
 *
 * makeFiles() takes its I/O as a parameter so tests can run it under node:
 *   node tests/ship-day-paperwork-js-test.js
 */
/* global PDFLib */
(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.FisHotelShipDayPaperwork = api;
        if (root.document) {
            api.bindPage(root);
        }
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var PT_PER_MM = 72 / 25.4;
    var LETTER = [612, 792];
    var MARGIN = 36; // 0.5in, as the page's @page rule

    /* ── small helpers ─────────────────────────────────────────── */

    function hex(L, h) {
        var n = parseInt(String(h).replace('#', ''), 16);
        return L.rgb(((n >> 16) & 255) / 255, ((n >> 8) & 255) / 255, (n & 255) / 255);
    }

    function repeatIndices(indices, times) {
        var out = [];
        for (var t = 0; t < times; t++) {
            for (var i = 0; i < indices.length; i++) {
                out.push(indices[i]);
            }
        }
        return out;
    }

    /** True when the bytes are a PDF (the header may sit after a little junk, as the spec allows). */
    function isPdf(bytes) {
        var n = Math.min(bytes.length, 1024);
        for (var i = 0; i + 4 < n; i++) {
            if (bytes[i] === 0x25 && bytes[i + 1] === 0x50 && bytes[i + 2] === 0x44 && bytes[i + 3] === 0x46 && bytes[i + 4] === 0x2d) {
                return true;
            }
        }
        return false;
    }

    /** A short plain-text excerpt of a non-PDF answer (an error page) for the warning. */
    function excerpt(bytes) {
        var text = '';
        try {
            text = new TextDecoder('utf-8').decode(bytes.subarray(0, 4000));
        } catch (e) {
            return '';
        }
        text = text.replace(/<script[\s\S]*?<\/script>/gi, ' ').replace(/<style[\s\S]*?<\/style>/gi, ' ').replace(/<[^>]+>/g, ' ').replace(/&[a-z#0-9]+;/gi, ' ').replace(/\s+/g, ' ').trim();
        return text.length > 140 ? text.slice(0, 137) + '...' : text;
    }

    /**
     * Text the standard (WinAnsi) fonts can print: accents are dropped when the
     * letter has no WinAnsi form, anything else unprintable becomes '?'.
     */
    function makeSafe(font) {
        var cache = {};
        function ok(ch) {
            if (!(ch in cache)) {
                try {
                    font.encodeText(ch);
                    cache[ch] = true;
                } catch (e) {
                    cache[ch] = false;
                }
            }
            return cache[ch];
        }
        return function (s) {
            s = String(s == null ? '' : s).replace(/[\r\n\t]+/g, ' ').replace(/[  ]/g, ' ').replace(/→/g, '->').replace(/−/g, '-');
            var out = '';
            for (var i = 0; i < s.length; i++) {
                var ch = s.charAt(i);
                var code = s.charCodeAt(i);
                if (code >= 0xd800 && code <= 0xdbff && i + 1 < s.length) {
                    ch = s.substr(i, 2);
                    i++;
                }
                if (ok(ch)) {
                    out += ch;
                    continue;
                }
                var base = ch.normalize ? ch.normalize('NFD').replace(/[̀-ͯ]/g, '') : '';
                var good = base !== '' && base !== ch;
                for (var j = 0; good && j < base.length; j++) {
                    good = ok(base.charAt(j));
                }
                out += good ? base : '?';
            }
            return out;
        };
    }

    /** Word-wrap one string to a width (a word wider than the line is cut). */
    function wrapText(font, size, text, width) {
        var words = String(text).split(' ');
        var lines = [];
        var line = '';
        for (var i = 0; i < words.length; i++) {
            var w = words[i];
            var tryLine = line === '' ? w : line + ' ' + w;
            if (font.widthOfTextAtSize(tryLine, size) <= width) {
                line = tryLine;
                continue;
            }
            if (line !== '') {
                lines.push(line);
            }
            while (font.widthOfTextAtSize(w, size) > width && w.length > 1) {
                var cut = w.length - 1;
                while (cut > 1 && font.widthOfTextAtSize(w.slice(0, cut), size) > width) {
                    cut--;
                }
                lines.push(w.slice(0, cut));
                w = w.slice(cut);
            }
            line = w;
        }
        if (line !== '' || lines.length === 0) {
            lines.push(line);
        }
        return lines;
    }

    /* ── fonts and styles of the two print pages ───────────────── */

    function makeKit(L, doc) {
        return Promise.all([
            doc.embedFont(L.StandardFonts.Helvetica),
            doc.embedFont(L.StandardFonts.HelveticaBold),
            doc.embedFont(L.StandardFonts.HelveticaOblique)
        ]).then(function (f) {
            var kit = { L: L, doc: doc, regular: f[0], bold: f[1], italic: f[2] };
            kit.safe = makeSafe(f[0]);
            // The page's print CSS, px → pt (× 0.75).
            kit.styles = {
                n: { font: f[0], size: 9, color: '#000000' },
                b: { font: f[1], size: 9, color: '#000000' },
                tag: { font: f[0], size: 8.25, color: '#555555' },
                tagb: { font: f[1], size: 8.25, color: '#000000' },
                warn: { font: f[1], size: 8.25, color: '#a40000' },
                bag: { font: f[1], size: 8.25, color: '#000000', boxed: true },
                sub: { font: f[2], size: 7.5, color: '#666666' },
                flag: { font: f[1], size: 9, color: '#a40000' },
                th: { font: f[1], size: 8.25, color: '#ffffff' },
                h1: { font: f[1], size: 15, color: '#000000' },
                lede: { font: f[0], size: 9.75, color: '#333333' }
            };
            return kit;
        });
    }

    /** Lay out a cell's runs into lines of positioned tokens. */
    function layoutRuns(kit, runs, width) {
        var lines = [[]];
        var x = 0;
        function newLine() {
            lines.push([]);
            x = 0;
        }
        (runs || []).forEach(function (run) {
            if (run.br) {
                newLine();
                return;
            }
            var st = kit.styles[run.s] || kit.styles.n;
            var text = kit.safe(run.t);
            var words = st.boxed ? [text] : text.split(' ');
            var space = st.font.widthOfTextAtSize(' ', st.size);
            words.forEach(function (word) {
                if (word === '') {
                    return;
                }
                var parts = st.boxed ? [word] : wrapText(st.font, st.size, word, width);
                parts.forEach(function (part, pi) {
                    var w = st.font.widthOfTextAtSize(part, st.size) + (st.boxed ? 8 : 0);
                    var gap = x > 0 ? space : 0;
                    if ((pi > 0 || x + gap + w > width) && x > 0) {
                        newLine();
                        gap = 0;
                    }
                    lines[lines.length - 1].push({ text: part, st: st, x: x + gap, w: w });
                    x += gap + w;
                });
            });
        });
        return lines.map(function (toks) {
            var size = 0;
            toks.forEach(function (t) { size = Math.max(size, t.st.size); });
            return { toks: toks, height: (size || 9) * 1.25, size: size || 9 };
        });
    }

    function linesHeight(lines) {
        return lines.reduce(function (h, l) { return h + l.height; }, 0);
    }

    function drawLines(kit, page, lines, x, top, width, align) {
        var y = top;
        lines.forEach(function (line) {
            var lineW = line.toks.length ? line.toks[line.toks.length - 1].x + line.toks[line.toks.length - 1].w : 0;
            var dx = align === 'center' ? (width - lineW) / 2 : (align === 'right' ? width - lineW : 0);
            var base = y - line.size; // baseline
            line.toks.forEach(function (t) {
                var tx = x + dx + t.x + (t.st.boxed ? 4 : 0);
                page.drawText(t.text, { x: tx, y: base, size: t.st.size, font: t.st.font, color: hex(kit.L, t.st.color) });
                if (t.st.boxed) {
                    page.drawRectangle({ x: x + dx + t.x, y: base - 2, width: t.w, height: t.st.size + 3, borderColor: hex(kit.L, '#777777'), borderWidth: 0.75 });
                }
            });
            y -= line.height;
        });
    }

    /**
     * One table across as many letter pages as it needs; the header row
     * repeats on each page (the print CSS's table-header-group). Returns
     * { page, y } where the table ended.
     */
    function drawTablePages(kit, opts) {
        var L = kit.L;
        var doc = kit.doc;
        var width = LETTER[0] - 2 * MARGIN;
        var padX = 6;
        var padY = 4.5;
        var cols = opts.widths;
        var page = null;
        var y = 0;

        function headerRow() {
            var cells = opts.head.map(function (h, i) {
                return layoutRuns(kit, [{ t: h, s: 'th' }], cols[i] - 2 * padX);
            });
            var hgt = Math.max.apply(null, cells.map(linesHeight)) + 2 * padY;
            page.drawRectangle({ x: MARGIN, y: y - hgt, width: width, height: hgt, color: hex(L, '#333333') });
            var x = MARGIN;
            cells.forEach(function (lines, i) {
                drawLines(kit, page, lines, x + padX, y - padY, cols[i] - 2 * padX, (opts.aligns && opts.aligns[i] === 'right') ? 'right' : (opts.aligns && opts.aligns[i] === 'center' ? 'center' : 'left'));
                x += cols[i];
            });
            y -= hgt;
        }

        function newPage(first) {
            page = doc.addPage(LETTER);
            y = LETTER[1] - MARGIN;
            if (first) {
                var title = layoutRuns(kit, [{ t: opts.title, s: 'h1' }], width);
                drawLines(kit, page, title, MARGIN, y, width);
                y -= linesHeight(title) + 3;
                var sub = layoutRuns(kit, [{ t: opts.sub, s: 'lede' }], width);
                drawLines(kit, page, sub, MARGIN, y, width);
                y -= linesHeight(sub) + 9;
            }
            headerRow();
        }

        newPage(true);
        opts.rows.forEach(function (row) {
            var cells = row.cells.map(function (runs, i) {
                return layoutRuns(kit, runs, cols[i] - 2 * padX);
            });
            var hgt = Math.max.apply(null, cells.map(linesHeight).concat([row.minHeight || 0])) + 2 * padY;
            if (y - hgt < MARGIN) {
                newPage(false);
            }
            if (row.fill) {
                page.drawRectangle({ x: MARGIN, y: y - hgt, width: width, height: hgt, color: hex(L, row.fill) });
            }
            if (row.topRule) {
                page.drawLine({ start: { x: MARGIN, y: y }, end: { x: MARGIN + width, y: y }, thickness: 1.5, color: hex(L, '#999999') });
            }
            var x = MARGIN;
            cells.forEach(function (lines, i) {
                if (row.checkbox && i === 0) {
                    page.drawRectangle({ x: x + (cols[0] - 10.5) / 2, y: y - padY - 10.5, width: 10.5, height: 10.5, borderColor: hex(L, '#000000'), borderWidth: 0.9, color: hex(L, '#ffffff') });
                } else {
                    var align = opts.aligns ? opts.aligns[i] : 'left';
                    drawLines(kit, page, lines, x + padX, y - padY, cols[i] - 2 * padX, align);
                }
                x += cols[i];
            });
            y -= hgt;
            page.drawLine({ start: { x: MARGIN, y: y }, end: { x: MARGIN + width, y: y }, thickness: 0.75, color: hex(L, '#dddddd') });
        });
        return { page: page, y: y };
    }

    /** Paragraph lines under a table, onto new pages when needed. */
    function drawParagraphs(kit, at, paras) {
        var width = LETTER[0] - 2 * MARGIN;
        var page = at.page;
        var y = at.y;
        paras.forEach(function (p) {
            y -= p.before || 0;
            var lines = wrapText(p.style.font, p.style.size, kit.safe(p.text), width - (p.indent || 0)).map(function (txt) {
                return { toks: [{ text: txt, st: p.style, x: 0, w: p.style.font.widthOfTextAtSize(txt, p.style.size) }], size: p.style.size, height: p.style.size * 1.3 };
            });
            lines.forEach(function (line, i) {
                if (y - line.height < MARGIN) {
                    page = kit.doc.addPage(LETTER);
                    y = LETTER[1] - MARGIN;
                }
                if (i === 0 && p.bullet) {
                    page.drawText('•', { x: MARGIN + (p.indent || 0) - 9, y: y - line.size, size: line.size, font: p.style.font, color: hex(kit.L, p.style.color) });
                }
                drawLines(kit, page, [line], MARGIN + (p.indent || 0), y, width);
                y -= line.height;
            });
        });
        return { page: page, y: y };
    }

    /** The pick list page(s), as render_pick_list() prints them. */
    function drawPickList(kit, pick) {
        // Pick, Qty 40px, Order 170px, Destination 190px of a 720px line (7.5in), as the print CSS; Pick a little
        // wider than its 34px so its header fits (the browser widens it the same way); Fish takes the rest.
        var widths = [30, 30, 210, 127.5, 142.5];
        var at = drawTablePages(kit, {
            title: pick.title,
            sub: pick.sub,
            head: pick.head,
            widths: widths,
            aligns: ['center', 'center', 'left', 'left', 'left'],
            rows: (pick.rows || []).map(function (r) {
                return {
                    cells: [[], [{ t: String(r.qty), s: 'n' }], r.fish, r.order, r.dest],
                    checkbox: true,
                    minHeight: 10.5,
                    topRule: !!r.first
                };
            })
        });
        return drawParagraphs(kit, at, [
            { text: pick.foot, style: { font: kit.bold, size: 9, color: '#000000' }, before: 7.5 },
            { text: pick.legend, style: { font: kit.regular, size: 7.5, color: '#444444' }, before: 3 }
        ]);
    }

    /** The weather overview page(s), as render_weather_page() prints them. */
    function drawWeather(kit, weather) {
        var at = drawTablePages(kit, {
            title: weather.title,
            sub: weather.sub,
            head: weather.head,
            widths: [132, 120, 39, 39, 120, 90],
            aligns: ['left', 'left', 'right', 'right', 'left', 'left'],
            rows: (weather.rows || []).map(function (r) {
                return {
                    cells: r.cells.map(function (c) { return [{ t: String(c), s: 'n' }]; }),
                    fill: r.fill
                };
            })
        });
        return drawParagraphs(kit, at, (weather.notes || []).map(function (n, i) {
            return { text: n, style: { font: kit.regular, size: 8.25, color: '#000000' }, before: i === 0 ? 9 : 2, indent: 13.5, bullet: true };
        }));
    }

    /**
     * One product sheet, laid out as Step 7c of the old routine: letter
     * portrait, 20mm margin, title 26pt bold, picture up to 80mm, price 26pt
     * bold, "Description" 22pt bold, each line 20pt with a bullet.
     */
    function drawProductSheet(kit, sheet, image) {
        var L = kit.L;
        var page = kit.doc.addPage(LETTER);
        var H = 279.4;
        var margin = 20;
        var usable = 215.9 - 2 * margin;
        var y = margin;
        function Y(mm) { return (H - mm) * PT_PER_MM; }
        var black = L.rgb(0, 0, 0);

        var title = wrapText(kit.bold, 26, kit.safe(sheet.title), usable * PT_PER_MM);
        title.forEach(function (line, i) {
            page.drawText(line, { x: margin * PT_PER_MM, y: Y(y + 9) - i * 26 * 1.15, size: 26, font: kit.bold, color: black });
        });
        y += title.length * 11 + 14;

        if (image) {
            var ratio = image.height / image.width;
            var w = 80;
            var h = w * ratio;
            if (h > 80) {
                h = 80;
                w = h / ratio;
            }
            page.drawImage(image, { x: margin * PT_PER_MM, y: Y(y + h), width: w * PT_PER_MM, height: h * PT_PER_MM });
            y += h + 12;
        }

        if (sheet.price) {
            page.drawText(kit.safe(sheet.price), { x: margin * PT_PER_MM, y: Y(y + 9), size: 26, font: kit.bold, color: black });
        }
        y += 20;

        page.drawText('Description', { x: margin * PT_PER_MM, y: Y(y + 8), size: 22, font: kit.bold, color: black });
        y += 16;

        (sheet.lines || []).forEach(function (item) {
            var lines = wrapText(kit.regular, 20, kit.safe('•  ' + item), (usable - 5) * PT_PER_MM);
            if (Y(y + 7 + (lines.length - 1) * 8.1) < margin * PT_PER_MM) {
                page = kit.doc.addPage(LETTER);
                y = margin;
            }
            lines.forEach(function (line, i) {
                page.drawText(line, { x: (margin + 5) * PT_PER_MM, y: Y(y + 7) - i * 20 * 1.15, size: 20, font: kit.regular, color: black });
            });
            y += lines.length * 9 + 4;
        });
    }

    /* ── the two files ─────────────────────────────────────────── */

    /**
     * Make both files from a plan.
     *
     * @param {object}   plan     FisHotel_Ship_Day_Paperwork::plan()
     * @param {object}   io       { fetchBytes(url) → Promise<Uint8Array>, loadImage(url) → Promise<{bytes,type}|null> }
     * @param {object}   L        pdf-lib (window.PDFLib)
     * @param {function} progress progress(text)
     * @return {Promise<{brother:Uint8Array|null, rx:Uint8Array|null, brotherPages:number, rxPages:number, warnings:string[]}>}
     */
    async function makeFiles(plan, io, L, progress) {
        progress = progress || function () {};
        var warnings = (plan.warnings || []).slice();
        var boxes = plan.boxes || [];
        var copies = Math.max(1, Math.min(20, parseInt(plan.label_copies, 10) || 4));

        var brother = await L.PDFDocument.create();
        var rx = await L.PDFDocument.create();

        async function loadStatic(file, what) {
            if (!file || !file.url) {
                return null;
            }
            try {
                progress('Reading the ' + what + ' PDF...');
                var bytes = await io.fetchBytes(file.url);
                if (!isPdf(bytes)) {
                    warnings.push('The ' + what + ' file (' + (file.title || file.url) + ') is not a PDF — skipped.');
                    return null;
                }
                var doc = await L.PDFDocument.load(bytes, { ignoreEncryption: true });
                if (doc.getPageCount() < 1) {
                    warnings.push('The ' + what + ' PDF has no pages — skipped.');
                    return null;
                }
                return doc;
            } catch (e) {
                warnings.push('The ' + what + ' PDF (' + (file.title || file.url) + ') could not be read — skipped.');
                return null;
            }
        }

        var acc = await loadStatic(plan.acclimation, 'acclimation sheet');
        var lab = await loadStatic(plan.labels, 'shipping labels');
        // One copyPages() call per static file: every copy shares the file's
        // fonts and images, so 60 boxes do not make the file 60 times bigger.
        var accPer = acc ? acc.getPageCount() : 0;
        var labPer = lab ? lab.getPageCount() : 0;
        var accPages = acc ? await brother.copyPages(acc, repeatIndices(acc.getPageIndices(), boxes.length)) : [];
        var labPages = lab ? await rx.copyPages(lab, repeatIndices(lab.getPageIndices(), boxes.length * copies)) : [];

        var rxKit = await makeKit(L, rx);
        var images = {};
        var sheets = plan.sheets || {};

        var invoiceTotal = 0;
        boxes.forEach(function (b) { invoiceTotal += (b.invoices || []).length; });
        var invoiceDone = 0;

        for (var bi = 0; bi < boxes.length; bi++) {
            var box = boxes[bi];
            var invs = box.invoices || [];
            for (var ii = 0; ii < invs.length; ii++) {
                var inv = invs[ii];
                invoiceDone++;
                progress('Invoice ' + invoiceDone + ' of ' + invoiceTotal + ' (order #' + inv.number + ', box ' + (bi + 1) + ' of ' + boxes.length + ')...');
                try {
                    var bytes = await io.fetchBytes(inv.url);
                    if (!isPdf(bytes)) {
                        var why = excerpt(bytes);
                        warnings.push('Order #' + inv.number + ': the invoice plugin did not return a PDF' + (why ? ' ("' + why + '")' : '') + ' — not in the file.');
                        continue;
                    }
                    var invDoc = await L.PDFDocument.load(bytes, { ignoreEncryption: true });
                    var copied = await brother.copyPages(invDoc, invDoc.getPageIndices());
                    copied.forEach(function (p) { brother.addPage(p); });
                } catch (e) {
                    warnings.push('Order #' + inv.number + ': its invoice could not be fetched or read — not in the file.');
                }
            }
            for (var a = 0; a < accPer; a++) {
                brother.addPage(accPages[bi * accPer + a]);
            }

            var labStart = bi * copies * labPer;
            for (var l = 0; l < copies * labPer; l++) {
                rx.addPage(labPages[labStart + l]);
            }
            var pids = box.sheets || [];
            for (var si = 0; si < pids.length; si++) {
                var sheet = sheets[String(pids[si])];
                if (!sheet) {
                    continue;
                }
                var key = String(pids[si]);
                if (!(key in images)) {
                    images[key] = null;
                    if (sheet.image) {
                        progress('Product sheet: ' + sheet.title + '...');
                        try {
                            var img = await io.loadImage(sheet.image);
                            if (img && img.type === 'png') {
                                images[key] = await rx.embedPng(img.bytes);
                            } else if (img && img.type === 'jpg') {
                                images[key] = await rx.embedJpg(img.bytes);
                            }
                        } catch (e) {
                            images[key] = null;
                        }
                        if (!images[key]) {
                            warnings.push('Product sheet "' + sheet.title + '": its picture could not be loaded — printed without it.');
                        }
                    }
                }
                drawProductSheet(rxKit, sheet, images[key]);
            }
        }

        if (plan.pages) {
            progress('Drawing the pick list and the weather overview...');
            var bKit = await makeKit(L, brother);
            drawPickList(bKit, plan.pages.pick);
            drawWeather(bKit, plan.pages.weather);
        }

        progress('Saving the files...');
        var out = { brother: null, rx: null, brotherPages: brother.getPageCount(), rxPages: rx.getPageCount(), warnings: warnings };
        if (out.brotherPages > 0) {
            out.brother = await brother.save();
        } else {
            warnings.push('Nothing to print on the Brother.');
        }
        if (out.rxPages > 0) {
            out.rx = await rx.save();
        } else {
            warnings.push('Nothing to print on the RX106HD (no shipping-labels PDF and no "Invoice" products).');
        }
        return out;
    }

    /* ── the page ──────────────────────────────────────────────── */

    function browserIo(win) {
        function fetchBytes(url) {
            return win.fetch(url, { credentials: 'same-origin' }).then(function (r) {
                if (!r.ok) {
                    throw new Error('HTTP ' + r.status);
                }
                return r.arrayBuffer();
            }).then(function (buf) { return new Uint8Array(buf); });
        }
        function loadImage(url) {
            return fetchBytes(url).then(function (bytes) {
                if (bytes[0] === 0xff && bytes[1] === 0xd8) {
                    return { bytes: bytes, type: 'jpg' };
                }
                if (bytes[0] === 0x89 && bytes[1] === 0x50 && bytes[2] === 0x4e && bytes[3] === 0x47) {
                    return { bytes: bytes, type: 'png' };
                }
                // WebP, GIF, ...: let the browser decode it and re-encode as JPEG on white.
                if (!win.createImageBitmap) {
                    return null;
                }
                return win.createImageBitmap(new win.Blob([bytes])).then(function (bmp) {
                    var c = win.document.createElement('canvas');
                    c.width = bmp.width;
                    c.height = bmp.height;
                    var ctx = c.getContext('2d');
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, c.width, c.height);
                    ctx.drawImage(bmp, 0, 0);
                    return new Promise(function (resolve) {
                        c.toBlob(function (blob) {
                            if (!blob) {
                                resolve(null);
                                return;
                            }
                            blob.arrayBuffer().then(function (buf) { resolve({ bytes: new Uint8Array(buf), type: 'jpg' }); });
                        }, 'image/jpeg', 0.85);
                    });
                });
            }).catch(function () { return null; });
        }
        return { fetchBytes: fetchBytes, loadImage: loadImage };
    }

    function bindPage(win) {
        var doc = win.document;
        function start() {
            var btn = doc.getElementById('fh-sd-paperwork');
            if (!btn) {
                return;
            }
            var statusEl = doc.getElementById('fh-sd-paperwork-status');
            var warnEl = doc.getElementById('fh-sd-paperwork-warnings');
            var filesEl = doc.getElementById('fh-sd-paperwork-files');
            var busy = false;
            var urls = [];

            function status(text) {
                statusEl.style.display = text ? 'block' : 'none';
                statusEl.textContent = text || '';
            }
            function showWarnings(list) {
                warnEl.innerHTML = '';
                (list || []).forEach(function (w) {
                    var li = doc.createElement('li');
                    li.textContent = w;
                    warnEl.appendChild(li);
                });
                warnEl.style.display = list && list.length ? 'block' : 'none';
            }
            function waitingTab(tab, name) {
                if (!tab) {
                    return;
                }
                try {
                    tab.document.title = name;
                    tab.document.body.innerHTML = '<p style="font:16px sans-serif;padding:24px;">Preparing ' + name + '&hellip; keep the Ship Day tab open.</p>';
                } catch (e) { /* the tab is still usable */ }
            }
            function closeTab(tab) {
                try {
                    if (tab) {
                        tab.close();
                    }
                } catch (e) { /* ignore */ }
            }
            function fileRow(name, bytes, pages, tab, printer) {
                var url = win.URL.createObjectURL(new win.Blob([bytes], { type: 'application/pdf' }));
                urls.push(url);
                var row = doc.createElement('p');
                row.style.margin = '4px 0';
                var strong = doc.createElement('strong');
                strong.textContent = name;
                row.appendChild(strong);
                row.appendChild(doc.createTextNode(' — ' + pages + ' page' + (pages === 1 ? '' : 's') + ', print on the ' + printer + ': '));
                var open = doc.createElement('a');
                open.href = url;
                open.target = '_blank';
                open.rel = 'noopener';
                open.textContent = 'Open to print';
                open.style.color = '#2986cc';
                row.appendChild(open);
                row.appendChild(doc.createTextNode(' · '));
                var dl = doc.createElement('a');
                dl.href = url;
                dl.download = name;
                dl.textContent = 'Download';
                dl.style.color = '#2986cc';
                row.appendChild(dl);
                filesEl.appendChild(row);
                if (tab) {
                    try {
                        tab.location.href = url;
                    } catch (e) { /* the links above still work */ }
                }
            }

            btn.addEventListener('click', function () {
                if (busy) {
                    return;
                }
                var dateEl = doc.getElementById('fh-sd-date');
                var date = dateEl ? dateEl.value : '';
                if (!date) {
                    win.alert('Please select a delivery date.');
                    return;
                }
                if (!win.PDFLib) {
                    status('The PDF library did not load — reload the page and try again.');
                    return;
                }
                busy = true;
                btn.disabled = true;
                urls.forEach(function (u) { win.URL.revokeObjectURL(u); });
                urls = [];
                filesEl.innerHTML = '';
                filesEl.style.display = 'none';
                showWarnings([]);
                // Opened now, while the click still counts, so the browser lets them open.
                var tabB = win.open('', '_blank');
                var tabR = win.open('', '_blank');
                waitingTab(tabB, 'the Brother file');
                waitingTab(tabR, 'the RX106HD file');
                status('Planning the day...');

                var fd = new win.FormData();
                fd.append('action', btn.getAttribute('data-action'));
                fd.append('nonce', btn.getAttribute('data-nonce'));
                fd.append('delivery_date', date);
                win.fetch(btn.getAttribute('data-ajax'), { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (resp) {
                        if (!resp || !resp.success) {
                            throw new Error(resp && resp.data && resp.data.message ? resp.data.message : 'The plan could not be made.');
                        }
                        var plan = resp.data;
                        if (!plan.boxes || !plan.boxes.length) {
                            closeTab(tabB);
                            closeTab(tabR);
                            showWarnings(plan.warnings);
                            status('No boxes go out for this delivery date — nothing to print.');
                            return null;
                        }
                        return makeFiles(plan, browserIo(win), win.PDFLib, status).then(function (out) {
                            filesEl.style.display = 'block';
                            if (out.brother) {
                                fileRow(plan.files.brother, out.brother, out.brotherPages, tabB, 'Brother');
                            } else {
                                closeTab(tabB);
                            }
                            if (out.rx) {
                                fileRow(plan.files.rx, out.rx, out.rxPages, tabR, 'RX106HD');
                            } else {
                                closeTab(tabR);
                            }
                            showWarnings(out.warnings);
                            status('Done: ' + plan.boxes.length + ' box' + (plan.boxes.length === 1 ? '' : 'es') + '. Each file opened in its own tab — press Print there once (or use the links below).');
                        });
                    })
                    .catch(function (e) {
                        closeTab(tabB);
                        closeTab(tabR);
                        status('Could not make the paperwork: ' + (e && e.message ? e.message : 'request failed') + '.');
                    })
                    .then(function () {
                        busy = false;
                        btn.disabled = false;
                    });
            });
        }
        if (doc.readyState === 'loading') {
            doc.addEventListener('DOMContentLoaded', start);
        } else {
            start();
        }
    }

    return {
        makeFiles: makeFiles,
        isPdf: isPdf,
        wrapText: wrapText,
        repeatIndices: repeatIndices,
        bindPage: bindPage
    };
}));
