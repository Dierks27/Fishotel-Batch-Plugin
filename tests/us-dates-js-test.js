/**
 * assists/us-dates.js — the browser's twin of FisHotel_US_Dates, under node.
 *
 *   node tests/us-dates-js-test.js
 *
 * The same cases as tests/us-dates-test.php, so the two helpers cannot drift
 * apart: a stored YYYY-MM-DD reads MM-DD-YY, typed text is read month first,
 * an impossible date is refused, and no time zone can move a day (the whole
 * file is run again with the computer's clock set to other zones).
 * Exits 1 on any failure, 0 when everything passes.
 */
'use strict';

var path = require('path');
var fs = require('fs');
var FILE = path.join(__dirname, '..', 'assists', 'us-dates.js');
var us = require(FILE);

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
function same(label, want, got) {
    check(label, want === got, 'want ' + JSON.stringify(want) + ', got ' + JSON.stringify(got));
}
function section(t) {
    console.log('\n' + t);
}

section('show(): a stored YYYY-MM-DD reads MM-DD-YY');
same('2026-11-27 → 11-27-26', '11-27-26', us.show('2026-11-27'));
same('single-digit month and day keep their zero', '01-05-27', us.show('2027-01-05'));
same('the year 2000', '02-29-00', us.show('2000-02-29'));
same('the last day a two-digit year can name', '12-31-99', us.show('2099-12-31'));
same('white space around it is ignored', '11-27-26', us.show(' 2026-11-27\n'));

section('show(): empty and odd values');
same('empty → empty', '', us.show(''));
same('null → empty', '', us.show(null));
same('undefined → empty', '', us.show(undefined));
same('false → empty', '', us.show(false));
same('not a date: shown as it is stored, never guessed', 'soon', us.show('soon'));
same('an impossible stored date is shown as it is stored', '2026-02-30', us.show('2026-02-30'));
same('month 13 is shown as it is stored', '2026-13-01', us.show('2026-13-01'));
same('a date already in US form is left alone', '11-27-26', us.show('11-27-26'));

section('show(): a year outside 2000–2099 keeps all four digits');
same('1999 is not written as 99', '12-31-1999', us.show('1999-12-31'));
same('2100 is not written as 00', '01-01-2100', us.show('2100-01-01'));

section('showDateTime(): the date part turns US, the time part stays');
same('seconds kept', '11-27-26 14:03:22', us.showDateTime('2026-11-27 14:03:22'));
same('minutes only', '11-27-26 14:03', us.showDateTime('2026-11-27 14:03'));
same('a zone after the time stays', '10-17-26 14:03 EDT', us.showDateTime('2026-10-17 14:03 EDT'));
same('the T of a form value becomes a space', '11-27-26 09:30', us.showDateTime('2026-11-27T09:30'));
same('a date alone works too', '11-27-26', us.showDateTime('2026-11-27'));
same('empty → empty', '', us.showDateTime(''));
same('null → empty', '', us.showDateTime(null));
same('not a date-time: left as it is', 'never', us.showDateTime('never'));
same('an impossible date part: left as it is', '2026-02-30 10:00:00', us.showDateTime('2026-02-30 10:00:00'));

section('showDateTime(): near midnight the day never moves');
same('one second before midnight', '11-27-26 23:59:59', us.showDateTime('2026-11-27 23:59:59'));
same('midnight itself', '11-28-26 00:00:00', us.showDateTime('2026-11-28 00:00:00'));
same("New Year's Eve", '12-31-26 23:59:59', us.showDateTime('2026-12-31 23:59:59'));
same('New Year', '01-01-27 00:00:00', us.showDateTime('2027-01-01 00:00:00'));

section('parse(): the forms a person may type');
same('MM-DD-YY', '2026-11-27', us.parse('11-27-26'));
same('MM/DD/YY', '2026-11-27', us.parse('11/27/26'));
same('MM-DD-YYYY', '2026-11-27', us.parse('11-27-2026'));
same('MM/DD/YYYY', '2026-11-27', us.parse('11/27/2026'));
same('M/D/YY', '2026-03-05', us.parse('3/5/26'));
same('M-D-YY', '2026-03-05', us.parse('3-5-26'));
same('M/D/YYYY', '2026-03-05', us.parse('3/5/2026'));
same('YYYY-MM-DD still works', '2026-11-27', us.parse('2026-11-27'));
same('white space around it is ignored', '2026-11-27', us.parse('  11-27-26\t'));

section('parse(): a two-digit year means 20YY');
same('00 → 2000', '2000-01-01', us.parse('01-01-00'));
same('26 → 2026', '2026-01-01', us.parse('01-01-26'));
same('69 → 2069 (not 1969)', '2069-07-04', us.parse('07-04-69'));
same('70 → 2070 (not 1970)', '2070-07-04', us.parse('07-04-70'));
same('99 → 2099', '2099-12-31', us.parse('12-31-99'));

section('parse(): month comes first, never day');
same('02-03-26 is February 3', '2026-02-03', us.parse('02-03-26'));
same('27-11-26 (day first) is refused, not swapped', null, us.parse('27-11-26'));

section('parse(): empty');
same('empty → empty (no date)', '', us.parse(''));
same('spaces only → empty', '', us.parse('   '));
same('null → empty', '', us.parse(null));
same('undefined → empty', '', us.parse(undefined));

section('parse(): impossible dates are refused, never changed');
same('02-30-26', null, us.parse('02-30-26'));
same('month 13', null, us.parse('13-01-26'));
same('month 0', null, us.parse('00-10-26'));
same('day 0', null, us.parse('10-00-26'));
same('day 32', null, us.parse('01-32-26'));
same('April 31', null, us.parse('04/31/2026'));
same('February 29 in a year that has none', null, us.parse('02-29-26'));
same('February 29 in a leap year is fine', '2028-02-29', us.parse('02-29-28'));
same('2100 is not a leap year', null, us.parse('02-29-2100'));
same('2000 was a leap year', '2000-02-29', us.parse('02-29-2000'));
same('year-first impossible date', null, us.parse('2026-02-30'));
same('year-first month 13', null, us.parse('2026-13-01'));
same('the year 0000', null, us.parse('01-01-0000'));

section('parse(): text that is not a date is refused');
['soon', '11-27', '11-27-126', '11-27-26 14:00', '11.27.26', 'Nov 27', '2026-11-27T00:00', '1-2-3', '112726', '11--27-26'].forEach(function (junk) {
    same("'" + junk + "'", null, us.parse(junk));
});

section('refusal(): one plain sentence');
same('an accepted date has no refusal', '', us.refusal('11-27-26'));
same('empty has no refusal', '', us.refusal(''));
same('the sentence names what was typed and shows the way', '"02-30-26" is not a real date — type it as month-day-year, like 11-27-26.', us.refusal('02-30-26'));
same('the same sentence for month 13', '"13-01-26" is not a real date — type it as month-day-year, like 11-27-26.', us.refusal('13-01-26'));

section('isYmd(): the stored shape');
check('a real date', us.isYmd('2026-11-27'));
check('not without zeros', !us.isYmd('2026-1-5'));
check('not US form', !us.isYmd('11-27-26'));
check('not an impossible date', !us.isYmd('2026-02-30'));
check('not empty', !us.isYmd(''));
check('not an array', !us.isYmd(['2026-11-27']));

section('A date is a calendar day: no time zone can move it (clock set to ' + (process.env.TZ || 'the default') + ')');
(function () {
    var monthDays = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    var bad = [];
    var count = 0;
    for (var m = 1; m <= 12; m++) {
        for (var d = 1; d <= monthDays[m - 1]; d++) {
            var mm = (m < 10 ? '0' : '') + m;
            var dd = (d < 10 ? '0' : '') + d;
            var ymd = '2026-' + mm + '-' + dd;
            var shown = us.show(ymd);
            count++;
            if (shown !== mm + '-' + dd + '-26' || us.parse(shown) !== ymd) {
                bad.push(ymd + '→' + shown);
            }
        }
    }
    check('all ' + count + ' days of 2026 show and read back the same day', 365 === count && 0 === bad.length, bad.slice(0, 5).join(', '));
    same('the day the clocks go forward', '03-08-26', us.show('2026-03-08'));
    same('the hour that does not exist that night is still that day', '03-08-26 02:30:00', us.showDateTime('2026-03-08 02:30:00'));
    same('the day the clocks go back', '11-01-26', us.show('2026-11-01'));
    same('the hour that happens twice that night is still that day', '11-01-26 01:30:00', us.showDateTime('2026-11-01 01:30:00'));
    same('11:59 pm stays on its day', '03-07-26 23:59', us.showDateTime('2026-03-07 23:59'));
    same('typed 3/8/26 is March 8', '2026-03-08', us.parse('3/8/26'));
    same('typed 11/1/26 is November 1', '2026-11-01', us.parse('11/1/26'));
})();

section('Round trip: what show() writes, parse() reads back');
['2000-01-01', '2024-02-29', '2026-11-27', '2099-12-31', '1999-12-31', '2100-01-01', '1987-06-15'].forEach(function (ymd) {
    same(ymd, ymd, us.parse(us.show(ymd)));
});

section('The helper is pure: it never asks the clock');
(function () {
    // Comments out, so only code is scanned.
    var code = fs.readFileSync(FILE, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
    check('no Date object', !/\bDate\b/.test(code));
    check('no toISOString / toLocale…', !/toISOString|toLocale|Intl\./.test(code));
})();

section('In a page it is window.FisHotelUSDates');
(function () {
    var vm = require('vm');
    var sandbox = { window: {} };
    vm.runInNewContext(fs.readFileSync(FILE, 'utf8'), sandbox);
    var w = sandbox.window.FisHotelUSDates;
    check('the page gets the same four functions', !!w && 'function' === typeof w.show && 'function' === typeof w.showDateTime && 'function' === typeof w.parse && 'function' === typeof w.refusal);
    same('and they work there', '11-27-26', w && w.show('2026-11-27'));
})();

// Once more with the computer's clock in other zones: the answers must not change.
if (!process.env.FH_US_DATES_CHILD) {
    section('The same file with the clock in other time zones');
    var spawnSync = require('child_process').spawnSync;
    ['UTC', 'America/Chicago', 'America/Los_Angeles', 'Pacific/Honolulu', 'Pacific/Kiritimati', 'Asia/Kolkata', 'Australia/Lord_Howe'].forEach(function (zone) {
        var env = {};
        Object.keys(process.env).forEach(function (k) { env[k] = process.env[k]; });
        env.TZ = zone;
        env.FH_US_DATES_CHILD = '1';
        var run = spawnSync(process.execPath, [__filename], { env: env, encoding: 'utf8' });
        var tail = String(run.stdout || '').trim().split('\n').pop();
        check(zone + ': ' + tail, 0 === run.status, String(run.stdout || '').split('\n').filter(function (l) { return l.indexOf('FAIL') !== -1; }).join(' | '));
    });
}

console.log('\n' + passes + ' passed, ' + failures + ' failed.');
process.exit(failures > 0 ? 1 : 0);
