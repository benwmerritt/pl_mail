/**
 * A time typed into the snooze or send-later menu (BMAIL-12): "tue 3pm",
 * "2 days", "next month", "morgen 9", "di 15 uhr".
 *
 * Read on the wall clock, the way both menus already count: the snooze menu on
 * the browser's clock (snooze_options.js), the send menu in the configured zone
 * (schedule_options.js). Each hands in its own clock, an instant to
 * "YYYY-MM-DDTHH:MM", and gets the same spelling back, so the zone stays each
 * menu's business. Days, weeks and months are counted on that wall clock;
 * minutes and hours are elapsed time, so "in 2 hours" across a clock change is
 * still two hours.
 *
 * English and German words are both read whatever the interface language,
 * since none of them means something else in the other: someone with a German
 * interface who types "tomorrow" means tomorrow.
 *
 * What it reads, and where a time lands when none is given:
 *
 *   in 20 min, 3 hours, 2 h            that long from now, to the minute
 *   2 days, 1 week, 3 months           that many out, at 08:00
 *   today, tomorrow, übermorgen        that day, at 08:00
 *   tue, friday, next mon, am Freitag  the next one after today, at 08:00
 *   weekend, next week, next month     Saturday, Monday, the 1st, at 08:00
 *   3pm, 15:30, 9.45, 15 uhr, 15h30    today if still to come, else tomorrow
 *
 * and any of the day words with one of the times around it ("tomorrow 9:30",
 * "fri noon", "2 days at 3pm", "Montag morgen", "15:30 am Freitag"). A time
 * from 1 to 7:59 is the afternoon one: "3" means 15:00, as it does when said
 * aloud, and nobody snoozes to 3am by typing one digit. "am", "pm", "uhr" or a
 * leading zero ("07:30") say exactly which. "2h" is two hours; "15h" is 15:00,
 * as French and German write it, since nobody means fifteen hours that way.
 */

/** Where a day lands when no time is given: the hour every preset uses. */
const MORNING_HOUR = 8;

const PART_OF_DAY = {
    morning: MORNING_HOUR, morgens: MORNING_HOUR, fruh: MORNING_HOUR, vormittag: 10, vormittags: 10,
    noon: 12, midday: 12, mittag: 12, mittags: 12,
    afternoon: 14, nachmittag: 14, nachmittags: 14,
    evening: 18, abend: 18, abends: 18, tonight: 20,
};

const WEEKDAYS = {
    sun: 0, sunday: 0, so: 0, sonntag: 0,
    mon: 1, monday: 1, mo: 1, montag: 1,
    tue: 2, tues: 2, tuesday: 2, di: 2, dienstag: 2,
    wed: 3, weds: 3, wednesday: 3, mi: 3, mittwoch: 3,
    thu: 4, thur: 4, thurs: 4, thursday: 4, do: 4, donnerstag: 4,
    fri: 5, friday: 5, fr: 5, freitag: 5,
    sat: 6, saturday: 6, sa: 6, samstag: 6, sonnabend: 6,
};

const UNITS = {
    m: "minute", min: "minute", mins: "minute", minute: "minute", minutes: "minute", minuten: "minute",
    h: "hour", hr: "hour", hrs: "hour", hour: "hour", hours: "hour", std: "hour", stunde: "hour", stunden: "hour",
    d: "day", day: "day", days: "day", tag: "day", tage: "day", tagen: "day",
    w: "week", wk: "week", wks: "week", week: "week", weeks: "week", woche: "week", wochen: "week",
    month: "month", months: "month", monat: "month", monate: "month", monaten: "month",
};

const ONE = new Set(["a", "an", "one", "ein", "eine", "einem", "einen", "einer"]);

/** Words that say nothing about when: "in 2 days", "at 3pm", "am Freitag um 15 Uhr". */
const FILLER = new Set(["in", "at", "on", "um", "am", "this", "diesen", "diese", "dieses", "coming", "kommenden", "kommende"]);

const NEXT = new Set(["next", "nachste", "nachsten", "nachstes", "naechste", "naechsten"]);

/** Days a German "morgen" after them is the morning of: "Montag morgen", "heute morgen". */
const MORNING_OF = (word) => Object.hasOwn(WEEKDAYS, word) || "heute" === word || "ubermorgen" === word;

/**
 * @param {string} text what was typed
 * @param {(instant: Date) => string} wallClock the menu's clock: an instant as "YYYY-MM-DDTHH:MM"
 * @param {Date} [now]
 * @returns {string|null} the moment meant, on that clock, or null when not read
 */
export function readTypedTime(text, wallClock, now = new Date()) {
    const clock = parse(wallClock(now));
    const words = wordsOf(text);

    if (null === clock || 0 === words.length) {
        return null;
    }

    const span = readSpan(words);

    if (null !== span) {
        return undefined === span.minutes ? span.on(clock) : wallClock(new Date(now.getTime() + span.minutes * 60_000));
    }

    const time = takeTime(words);
    const day  = readDay(words, clock);

    if (null === time || null === day) {
        return null;
    }

    if (undefined === day.offset && undefined === time.hour) {
        return null;
    }

    if (undefined !== day.offset) {
        const date = undefined === day.monthStart ? shift(clock, day.offset) : day.monthStart;

        return spell(date, time.hour ?? MORNING_HOUR, time.minute ?? 0);
    }

    // A time alone: today while it is still to come, else tomorrow.
    const today = spell(clock, time.hour, time.minute);

    return today > spell(clock, clock.hour, clock.minute) ? today : spell(shift(clock, 1), time.hour, time.minute);
}

// ── Reading ────────────────────────────────────────────────────────────────

/**
 * Lower case, accents gone (ü to u, ß to ss), "a.m." as "am", "15h30" and "15h"
 * as clock times, and "15uhr" and "3pm" split from their number.
 */
function wordsOf(text) {
    return String(text)
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "")
        .toLowerCase()
        .replace(/ß/g, "ss")
        .replace(/(^|[\s\d])([ap])\.m\.?(?=\s|$)/g, "$1$2m")
        .replace(/\b(\d{1,2})h(\d{2})\b/g, "$1:$2")
        .replace(/\b(1[3-9]|2[0-3])h\b/g, "$1:00")
        .replace(/(\d)([a-z])/g, "$1 $2")
        .replace(/[,@]/g, " ")
        .split(/\s+/)
        .filter((word) => "" !== word);
}

/**
 * "2 days", "in 3 hours", "a week": the whole text is one span, maybe with a
 * time after a day-sized one. Minutes for an elapsed span; otherwise `on`,
 * which counts from today's wall clock.
 */
function readSpan(words) {
    const rest = words[0] === "in" ? words.slice(1) : words;

    if (rest.length < 2) {
        return null;
    }

    const count = ONE.has(rest[0]) ? 1 : /^\d{1,3}$/.test(rest[0]) ? Number(rest[0]) : null;
    const unit  = Object.hasOwn(UNITS, rest[1]) ? UNITS[rest[1]] : undefined;

    if (null === count || undefined === unit) {
        return null;
    }

    const tail = rest.slice(2);

    if ("minute" === unit || "hour" === unit) {
        return 0 === tail.length ? { minutes: count * ("hour" === unit ? 60 : 1) } : null;
    }

    const time = takeTime(tail);

    if (null === time || tail.some((word) => false === FILLER.has(word))) {
        return null;
    }

    return {
        on: (clock) => {
            const date = "month" === unit ? addMonths(clock, count) : shift(clock, count * ("week" === unit ? 7 : 1));

            return spell(date, time.hour ?? MORNING_HOUR, time.minute ?? 0);
        },
    };
}

/**
 * Takes the time of day out of `words`, leaving the day words behind.
 * Returns {} when there is none, and null when something time-shaped cannot be one ("25:00").
 */
function takeTime(words) {
    for (let at = 0; at < words.length; at++) {
        const word = words[at];

        if (Object.hasOwn(PART_OF_DAY, word) || ("morgen" === word && at > 0 && MORNING_OF(words[at - 1]))) {
            words.splice(at, 1);

            return { hour: PART_OF_DAY[word] ?? MORNING_HOUR, minute: 0 };
        }

        const match = /^(\d{1,2})(?:[:.](\d{2}))?$/.exec(word);

        if (null === match) {
            continue;
        }

        // After an hour past noon, "am" is German for "on": "15:30 am Freitag".
        let hour     = Number(match[1]);
        const after  = words[at + 1];
        const marker = ["am", "pm", "a", "p", "uhr"].includes(after) && false === ("am" === after && hour > 12) ? after : null;
        const minute = undefined === match[2] ? 0 : Number(match[2]);

        words.splice(at, null === marker ? 1 : 2);

        if (minute > 59) {
            return null;
        }

        if ("am" === marker || "a" === marker || "pm" === marker || "p" === marker) {
            if (hour < 1 || hour > 12) {
                return null;
            }

            hour = hour % 12 + ("pm" === marker || "p" === marker ? 12 : 0);
        } else if (null === marker && hour >= 1 && hour <= 7 && false === match[1].startsWith("0")) {
            hour += 12;
        }

        return hour > 23 ? null : { hour, minute };
    }

    return {};
}

/**
 * What is left once the time is out: a day, as days from today (and, for a
 * month, the date itself). {} when nothing is left; null when what is left is
 * not a day.
 */
function readDay(words, clock) {
    const rest = words.filter((word) => false === FILLER.has(word));
    const next = rest.length > 0 && NEXT.has(rest[0]);
    const key  = (next ? rest.slice(1) : rest).join(" ");

    switch (key) {
        case "":
            return next ? null : {};
        case "today": case "heute":
            return next ? null : { offset: 0 };
        case "tomorrow": case "tmrw": case "tmr": case "morgen":
            return next ? null : { offset: 1 };
        case "day after tomorrow": case "ubermorgen":
            return next ? null : { offset: 2 };
        case "weekend": case "wochenende":
            return { offset: daysUntil(clock, 6) };
        case "week": case "woche":
            return next ? { offset: daysUntil(clock, 1) } : null;
        case "month": case "monat":
            return next ? { offset: 0, monthStart: addMonths({ ...clock, day: 1 }, 1) } : null;
    }

    return Object.hasOwn(WEEKDAYS, key) ? { offset: daysUntil(clock, WEEKDAYS[key]) } : null;
}

// ── Calendar arithmetic, on the wall clock ─────────────────────────────────

function parse(at) {
    const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(String(at ?? ""));

    if (null === match) {
        return null;
    }

    const [, year, month, day, hour, minute] = match.map(Number);

    return { year, month, day, hour, minute };
}

function spell({ year, month, day }, hour, minute) {
    const pad = (value) => String(value).padStart(2, "0");

    return `${year}-${pad(month)}-${pad(day)}T${pad(hour)}:${pad(minute)}`;
}

/** As UTC, where every day is 24 hours long: the day count is what is being moved, not an instant. */
function fromUtc(ms) {
    const date = new Date(ms);

    return { year: date.getUTCFullYear(), month: date.getUTCMonth() + 1, day: date.getUTCDate(), hour: date.getUTCHours(), minute: date.getUTCMinutes() };
}

function shift(clock, days) {
    return fromUtc(Date.UTC(clock.year, clock.month - 1, clock.day + days, clock.hour, clock.minute));
}

/** The same date that many months on, or the month's last day where it is shorter (31 Jan + 1 month is 28 Feb). */
function addMonths(clock, months) {
    const first = new Date(Date.UTC(clock.year, clock.month - 1 + months, 1));
    const last  = new Date(Date.UTC(first.getUTCFullYear(), first.getUTCMonth() + 1, 0)).getUTCDate();

    return { year: first.getUTCFullYear(), month: first.getUTCMonth() + 1, day: Math.min(clock.day, last), hour: clock.hour, minute: clock.minute };
}

/** Days from today to the next `weekday` (0 Sunday … 6 Saturday), never today itself. */
function daysUntil(clock, weekday) {
    const today = new Date(Date.UTC(clock.year, clock.month - 1, clock.day)).getUTCDay();
    const delta = (weekday - today + 7) % 7;

    return 0 === delta ? 7 : delta;
}
