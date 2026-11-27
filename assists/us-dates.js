/**
 * FisHotel Batch Manager — how a date is shown to a person in the browser, and
 * how one a person typed is read (10.38). The twin of
 * includes/class-us-dates.php: same functions, same answers
 * (tests/us-dates-js-test.js runs the same cases as tests/us-dates-test.php).
 *
 *   FisHotelUSDates.show('2026-11-27')                   '11-27-26'
 *   FisHotelUSDates.showDateTime('2026-11-27 14:03:22')  '11-27-26 14:03:22'
 *   FisHotelUSDates.parse('11/27/26')                    '2026-11-27'
 *   FisHotelUSDates.parse('02-30-26')                    null  (refused)
 *   FisHotelUSDates.refusal('02-30-26')                  the sentence to show
 *
 * Only what a person reads is month-day-year. A calendar field's value, what a
 * form posts and what an AJAX call sends stay YYYY-MM-DD.
 *
 * A date is a calendar day: the digits are moved around as text and the clock
 * is never asked, so a day cannot slip to the one before or after in any time
 * zone. write() is the one place the written form lives.
 */
(function (root) {
    'use strict';

    var EXAMPLE = '11-27-26';

    function text(value) {
        return ('string' === typeof value || 'number' === typeof value) ? String(value).replace(/^\s+|\s+$/g, '') : '';
    }

    function pad(n, width) {
        var s = String(n);
        while (s.length < width) {
            s = '0' + s;
        }
        return s;
    }

    /** A day that exists on the calendar (leap years by the usual rule). */
    function isReal(year, month, day) {
        if (year < 1 || month < 1 || month > 12 || day < 1) {
            return false;
        }
        var leap = (0 === year % 4 && 0 !== year % 100) || 0 === year % 400;
        var days = [31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        return day <= days[month - 1];
    }

    /** [year, month, day] of a strict, real YYYY-MM-DD, or null. */
    function ymdParts(s) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s);
        if (!m) {
            return null;
        }
        var year = parseInt(m[1], 10);
        var month = parseInt(m[2], 10);
        var day = parseInt(m[3], 10);
        return isReal(year, month, day) ? [year, month, day] : null;
    }

    /**
     * THE written form. Change it here (and in includes/class-us-dates.php) and
     * every screen follows. A year outside 2000–2099 keeps its four digits, so
     * "99" always means 2099 and parse() reads back exactly what this wrote.
     */
    function write(year, month, day) {
        var yy = (year >= 2000 && year <= 2099) ? pad(year - 2000, 2) : pad(year, 4);
        return pad(month, 2) + '-' + pad(day, 2) + '-' + yy;
    }

    /** A stored YYYY-MM-DD as a person reads it; anything else as it is. */
    function show(ymd) {
        var s = text(ymd);
        var parts = ymdParts(s);
        return parts ? write(parts[0], parts[1], parts[2]) : s;
    }

    /** A stored date-time: the date part as show() writes it, the time part as stored. */
    function showDateTime(stored) {
        var s = text(stored);
        var m = /^(\d{4}-\d{2}-\d{2})(?:[ T](\S.*))?$/.exec(s);
        if (!m) {
            return s;
        }
        var parts = ymdParts(m[1]);
        if (!parts) {
            return s;
        }
        var date = write(parts[0], parts[1], parts[2]);
        return m[2] ? date + ' ' + m[2] : date;
    }

    /**
     * Typed text → the stored YYYY-MM-DD. '' when nothing was typed; null when
     * it is not a real date (never changed into a nearby one). The month is
     * first unless the year is; a two-digit year means 20YY.
     */
    function parse(typed) {
        var s = text(typed);
        if ('' === s) {
            return '';
        }
        var year;
        var month;
        var day;
        var m = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(s);
        if (m) {
            year = parseInt(m[1], 10);
            month = parseInt(m[2], 10);
            day = parseInt(m[3], 10);
        } else {
            m = /^(\d{1,2})([-\/])(\d{1,2})\2(\d{2}|\d{4})$/.exec(s);
            if (!m) {
                return null;
            }
            month = parseInt(m[1], 10);
            day = parseInt(m[3], 10);
            year = 2 === m[4].length ? 2000 + parseInt(m[4], 10) : parseInt(m[4], 10);
        }
        if (!isReal(year, month, day)) {
            return null;
        }
        return pad(year, 4) + '-' + pad(month, 2) + '-' + pad(day, 2);
    }

    /** The one sentence to show when parse() refuses ('' when it reads fine). Plain text. */
    function refusal(typed) {
        if (null !== parse(typed)) {
            return '';
        }
        return '"' + text(typed) + '" is not a real date — type it as month-day-year, like ' + EXAMPLE + '.';
    }

    function isYmd(value) {
        return 'string' === typeof value && null !== ymdParts(value);
    }

    var api = { show: show, showDateTime: showDateTime, parse: parse, refusal: refusal, isYmd: isYmd };

    if ('object' === typeof module && module.exports) {
        module.exports = api;
    }
    if (root) {
        root.FisHotelUSDates = api;
    }
})('undefined' !== typeof window ? window : null);
