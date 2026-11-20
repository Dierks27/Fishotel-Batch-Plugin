/**
 * Ship Day → "Print the day's paperwork": the browser half
 * (assists/ship-day/paperwork.js) run under node with the vendored pdf-lib.
 *
 *   node tests/ship-day-paperwork-js-test.js
 *
 * Builds small stand-in PDFs (each kind its own page size, so the order of the
 * pages in the output can be read back), feeds makeFiles() a plan like the one
 * FisHotel_Ship_Day_Paperwork::plan() returns, and checks both files page by
 * page. Exits 1 on any failure, 0 when everything passes.
 */
'use strict';

var path = require('path');
var L = require(path.join(__dirname, '..', 'assists', 'vendor', 'pdf-lib', 'pdf-lib.min.js'));
var paper = require(path.join(__dirname, '..', 'assists', 'ship-day', 'paperwork.js'));

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

// Page sizes that name what a page is.
var SIZE = {
    inv101: [500, 700], inv102: [501, 700], inv201: [502, 700], inv203: [503, 700],
    acc: [610, 790], label: [792, 612], letter: [612, 792]
};
function nameOf(page) {
    var s = page.getSize();
    var w = Math.round(s.width);
    var h = Math.round(s.height);
    for (var k in SIZE) {
        if (SIZE[k][0] === w && SIZE[k][1] === h) {
            return k === 'letter' ? 'drawn' : k;
        }
    }
    return w + 'x' + h;
}

async function pdf(size, pages, heavy) {
    var d = await L.PDFDocument.create();
    var font = await d.embedFont(L.StandardFonts.Helvetica);
    var img = null;
    if (heavy) {
        // A big picture, as a scanned sheet would carry: ~250 KB that must not be copied per box.
        var png = await makePng(400, 400);
        img = await d.embedPng(png);
    }
    for (var i = 0; i < pages; i++) {
        var p = d.addPage(size);
        p.drawText('page ' + (i + 1), { x: 20, y: 20, size: 10, font: font });
        if (img) {
            p.drawImage(img, { x: 0, y: 0, width: 200, height: 200 });
        }
    }
    return d.save();
}

/** A noisy RGB PNG (does not compress), built by hand: signature, IHDR, IDAT (stored deflate), IEND. */
async function makePng(w, h) {
    var zlib = require('zlib');
    var raw = Buffer.alloc((w * 3 + 1) * h);
    var seed = 7;
    for (var i = 0; i < raw.length; i++) {
        seed = (Math.imul(seed, 1103515245) + 12345) & 0x7fffffff;
        raw[i] = (i % (w * 3 + 1)) === 0 ? 0 : (seed >> 16) & 255;
    }
    function crc32(buf) {
        var c, crc = 0xffffffff;
        for (var n = 0; n < buf.length; n++) {
            c = (crc ^ buf[n]) & 0xff;
            for (var k = 0; k < 8; k++) {
                c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
            }
            crc = (crc >>> 8) ^ c;
        }
        return (crc ^ 0xffffffff) >>> 0;
    }
    function chunk(type, data) {
        var len = Buffer.alloc(4);
        len.writeUInt32BE(data.length);
        var td = Buffer.concat([Buffer.from(type), data]);
        var crc = Buffer.alloc(4);
        crc.writeUInt32BE(crc32(td));
        return Buffer.concat([len, td, crc]);
    }
    var ihdr = Buffer.alloc(13);
    ihdr.writeUInt32BE(w, 0);
    ihdr.writeUInt32BE(h, 4);
    ihdr[8] = 8;
    ihdr[9] = 2;
    return new Uint8Array(Buffer.concat([
        Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
        chunk('IHDR', ihdr),
        chunk('IDAT', zlib.deflateSync(raw)),
        chunk('IEND', Buffer.alloc(0))
    ]));
}

function pages(fish) {
    return {
        pick: {
            title: 'FisHotel Pick List',
            sub: 'Ship Thursday, November 19 • Deliver Friday, November 20 • 3 orders / 5 fish',
            head: ['Pick', 'Qty', 'Fish', 'Order', 'Destination'],
            rows: fish,
            foot: '5 fish · 0 dry goods · 3 orders',
            legend: 'Legend: (HEAT PACK) heat pack in the box · (CHECK) verify by hand'
        },
        weather: {
            title: 'Weather Overview — Delivery Friday, November 20',
            sub: 'by delivery-day high: <30 hold · 30–40 UPS Store · 40–70 heat pack',
            head: ['Order', 'Destination', 'High', 'Low', 'Conditions', 'Action'],
            rows: [
                { cells: ['Order #101', 'Austin, TX 78701', '62°F', '41°F', 'Sunny', 'Heat pack'], tone: 'blue', fill: '#dbeeff' },
                { cells: ['Order #104 (combined: #201, #203)', 'Zoë’s place, 李 city', '—', '—', '', 'Not checked'], tone: 'grey', fill: '#e6e6e6' }
            ],
            notes: ['Data: National Weather Service (api.weather.gov) via the FisHotel Tools Weather section nightly scan']
        }
    };
}

function pickRow(first, qty, name, label) {
    return {
        first: first,
        qty: qty,
        fish: [{ t: name, s: 'n' }, { t: '(CHECK)', s: 'warn' }, { t: 'Bag: Small bag', s: 'bag' }, { br: 1 }, { t: 'from Invoice - Sam', s: 'sub' }],
        order: first ? [{ t: label, s: 'b' }, { br: 1 }, { t: 'Pat Reefer → Ünïcode 李', s: 'n' }] : [],
        dest: first ? [{ t: 'Austin, TX 78701', s: 'n' }, { br: 1 }, { t: '(HEAT PACK)', s: 'flag' }] : []
    };
}

var TINY_PNG = new Uint8Array(Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64'));

async function main() {
    var files = {
        'inv/101': await pdf(SIZE.inv101, 1),
        'inv/102': await pdf(SIZE.inv102, 2),
        'inv/201': await pdf(SIZE.inv201, 1),
        'inv/203': await pdf(SIZE.inv203, 1),
        'inv/html': new Uint8Array(Buffer.from('<html><body><p>You do not have sufficient permissions to access this page.</p></body></html>')),
        'media/acc.pdf': await pdf(SIZE.acc, 1, true),
        'media/labels.pdf': await pdf(SIZE.label, 1, true),
        'img/ok.png': TINY_PNG
    };
    var fetched = [];
    var io = {
        fetchBytes: function (url) {
            fetched.push(url);
            if (!(url in files)) {
                return Promise.reject(new Error('404'));
            }
            return Promise.resolve(files[url]);
        },
        loadImage: function (url) {
            if (url === 'img/ok.png') {
                return Promise.resolve({ bytes: files[url], type: 'png' });
            }
            return Promise.resolve(null);
        }
    };
    function basePlan() {
        return {
            files: { brother: 'BROTHER_shipday_2026-11-19.pdf', rx: 'RX106HD_shipday_2026-11-19.pdf' },
            label_copies: 3,
            invoice_plugin: true,
            acclimation: { id: 501, url: 'media/acc.pdf', title: 'Acclimation' },
            labels: { id: 502, url: 'media/labels.pdf', title: 'Labels' },
            boxes: [
                { label: 'Order #101', invoices: [{ order_id: 101, number: '101', url: 'inv/101' }], sheets: [] },
                { label: 'Order #102', invoices: [{ order_id: 102, number: '102', url: 'inv/102' }], sheets: [71] },
                { label: 'Order #104 (combined: #201, #203)', invoices: [{ order_id: 201, number: '201', url: 'inv/201' }, { order_id: 203, number: '203', url: 'inv/203' }], sheets: [71, 72] }
            ],
            sheets: {
                71: { title: 'Invoice - Sam', price: '$125.00', image: 'img/ok.png', lines: ['Hectors Goby x1 — $39', 'Yellow Tang x1 — $86', 'A very long description line that has to wrap across more than one printed line on the sheet'] },
                72: { title: 'Invoice - Zoë 李', price: '', image: 'img/missing.png', lines: [] }
            },
            pages: pages([pickRow(true, 2, 'Hectors Goby', 'Order #101'), pickRow(true, 1, 'Yellow Tang', 'Order #102'), pickRow(false, 1, 'Mandarin', ''), pickRow(true, 1, 'Goby', 'Order #104 (combined: #201, #203)')]),
            warnings: ['from the server']
        };
    }

    section('the two files, page by page, in the pick list\'s box order');
    var progress = [];
    var out = await paper.makeFiles(basePlan(), io, L, function (t) { progress.push(t); });
    var b = await L.PDFDocument.load(out.brother);
    var r = await L.PDFDocument.load(out.rx);
    var bNames = b.getPages().map(nameOf);
    var rNames = r.getPages().map(nameOf);
    var head = bNames.slice(0, 8);
    check('Brother: per box its invoices, then one acclimation sheet', JSON.stringify(head) === JSON.stringify(['inv101', 'acc', 'inv102', 'inv102', 'acc', 'inv201', 'inv203', 'acc']), JSON.stringify(bNames));
    check('Brother: then the pick list and weather overview (drawn, letter)', bNames.length >= 10 && bNames.slice(8).every(function (n) { return n === 'drawn'; }), JSON.stringify(bNames));
    check('RX106HD: per box the labels 3 times, then each "Invoice" product sheet', JSON.stringify(rNames) === JSON.stringify(['label', 'label', 'label', 'label', 'label', 'label', 'drawn', 'label', 'label', 'label', 'drawn', 'drawn']), JSON.stringify(rNames));
    check('page counts reported', out.brotherPages === b.getPageCount() && out.rxPages === r.getPageCount());
    check('invoices fetched one at a time, in box order', JSON.stringify(fetched.filter(function (u) { return u.indexOf('inv/') === 0; })) === JSON.stringify(['inv/101', 'inv/102', 'inv/201', 'inv/203']));
    check('progress counts the invoices', progress.indexOf('Invoice 4 of 4 (order #203, box 3 of 3)...') !== -1, JSON.stringify(progress));
    check('server warnings kept; a picture that cannot load is a warning, the sheet still prints', out.warnings[0] === 'from the server' && out.warnings.some(function (w) { return /Invoice - Zoë 李": its picture could not be loaded/.test(w); }), JSON.stringify(out.warnings));
    check('names with letters the print font lacks do not break the file', r.getPageCount() === 12);

    section('a bad invoice answer is skipped with a warning');
    var p2 = basePlan();
    p2.boxes[0].invoices[0].url = 'inv/html';
    p2.boxes[1].invoices[0].url = 'inv/gone';
    out = await paper.makeFiles(p2, io, L);
    b = await L.PDFDocument.load(out.brother);
    check('the other pages stay', JSON.stringify(b.getPages().map(nameOf).slice(0, 5)) === JSON.stringify(['acc', 'acc', 'inv201', 'inv203', 'acc']), JSON.stringify(b.getPages().map(nameOf)));
    check('an error page instead of a PDF: a warning quoting it', out.warnings.some(function (w) { return /^Order #101: the invoice plugin did not return a PDF \("You do not have sufficient permissions/.test(w); }), JSON.stringify(out.warnings));
    check('a request that fails: a warning', out.warnings.some(function (w) { return /^Order #102: its invoice could not be fetched or read/.test(w); }));

    section('missing settings files and the invoice plugin absent');
    var p3 = basePlan();
    p3.acclimation = null;
    p3.labels = { url: 'media/none.pdf', title: 'Gone' };
    p3.boxes.forEach(function (bx) { bx.invoices = []; bx.sheets = []; });
    p3.invoice_plugin = false;
    out = await paper.makeFiles(p3, io, L);
    b = await L.PDFDocument.load(out.brother);
    check('Brother: only the pick list and weather overview are left', b.getPages().every(function (pg) { return nameOf(pg) === 'drawn'; }) && b.getPageCount() >= 2);
    check('RX106HD: nothing to print → no file, a plain warning', out.rx === null && out.warnings.some(function (w) { return /^Nothing to print on the RX106HD/.test(w); }));
    check('the unreadable labels file: a warning', out.warnings.some(function (w) { return /^The shipping labels PDF \(Gone\) could not be read/.test(w); }));
    var p4 = basePlan();
    p4.boxes.forEach(function (bx) { bx.invoices = []; });
    out = await paper.makeFiles(p4, io, L);
    b = await L.PDFDocument.load(out.brother);
    check('plugin absent: acclimation sheets (one per box) + pick list + weather', JSON.stringify(b.getPages().map(nameOf).slice(0, 4)) === JSON.stringify(['acc', 'acc', 'acc', 'drawn']));
    var p5 = basePlan();
    p5.labels = { url: 'inv/html', title: 'Not a PDF' };
    out = await paper.makeFiles(p5, io, L);
    check('a settings file that is not a PDF: skipped with a warning', out.warnings.some(function (w) { return /^The shipping labels file \(Not a PDF\) is not a PDF/.test(w); }));

    section('the label count');
    for (var n of [1, 4, 7]) {
        var pn = basePlan();
        pn.label_copies = n;
        pn.boxes.forEach(function (bx) { bx.sheets = []; });
        out = await paper.makeFiles(pn, io, L);
        check(n + ' per box → ' + (3 * n) + ' label pages', out.rxPages === 3 * n);
    }

    section('a busy day stays small: the static PDFs are shared, not copied per box');
    var big = basePlan();
    big.label_copies = 4;
    big.boxes = [];
    for (var i = 0; i < 60; i++) {
        big.boxes.push({ label: 'Order #' + (1000 + i), invoices: [{ order_id: 1000 + i, number: String(1000 + i), url: 'inv/101' }], sheets: [] });
    }
    var t0 = Date.now();
    out = await paper.makeFiles(big, io, L);
    var single = files['media/labels.pdf'].length;
    check('60 boxes: 240 label pages, 60 invoices + 60 acclimation sheets', out.rxPages === 240 && out.brotherPages >= 120);
    check('RX106HD file is not 240 copies of the labels file (' + Math.round(out.rx.length / 1024) + ' KB vs ' + Math.round(single / 1024) + ' KB each)', out.rx.length < single * 3, out.rx.length + ' / ' + single);
    check('Brother file likewise (' + Math.round(out.brother.length / 1024) + ' KB)', out.brother.length < files['media/acc.pdf'].length * 3);
    check('and it is quick (' + (Date.now() - t0) + ' ms)', Date.now() - t0 < 30000);

    section('helpers');
    check('isPdf(): a PDF header, also after a little junk; not HTML', paper.isPdf(new Uint8Array(Buffer.from('%PDF-1.7'))) && paper.isPdf(new Uint8Array(Buffer.from('\n\n%PDF-1.4'))) && !paper.isPdf(new Uint8Array(Buffer.from('<html>'))));
    check('repeatIndices()', JSON.stringify(paper.repeatIndices([0, 1], 3)) === JSON.stringify([0, 1, 0, 1, 0, 1]));

    if (process.argv[2]) {
        // Write sample files for a look (node tests/ship-day-paperwork-js-test.js <dir>).
        var fs = require('fs');
        var sample = await paper.makeFiles(basePlan(), io, L);
        fs.writeFileSync(path.join(process.argv[2], 'BROTHER_sample.pdf'), sample.brother);
        fs.writeFileSync(path.join(process.argv[2], 'RX106HD_sample.pdf'), sample.rx);
    }

    console.log('\n' + passes + ' passed, ' + failures + ' failed.');
    process.exit(failures > 0 ? 1 : 0);
}

main().catch(function (e) {
    console.log('  FAIL - ' + (e && e.stack ? e.stack : e));
    process.exit(1);
});
