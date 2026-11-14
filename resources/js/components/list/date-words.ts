/**
 * The Date filter's words (I-3129): a month as "September 2026", a range of
 * the site's days as "1–30 Sep 2026", and the button's value. The month
 * names are fixed, so the words never vary with the browser's language. It
 * imports nothing, so Node runs it as it is (tests/Fixtures/date-words.mjs).
 */
const MONTHS = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

type Day = { year: number; month: number; date: number };

const dayOf = (day: string | undefined): Day | null => {
    const match =
        day === undefined ? null : /^(\d{4})-(\d{2})-(\d{2})$/.exec(day);

    return match === null
        ? null
        : {
              year: Number(match[1]),
              month: Number(match[2]),
              date: Number(match[3]),
          };
};

const short = (month: number): string => (MONTHS[month - 1] ?? '').slice(0, 3);

const whole = (day: Day): string =>
    `${day.date} ${short(day.month)} ${day.year}`;

/** "2026-09" as "September 2026"; anything else as it is. */
export function monthLabel(month: string): string {
    const match = /^(\d{4})-(\d{2})$/.exec(month);
    const name = match === null ? undefined : MONTHS[Number(match[2]) - 1];

    return match === null || name === undefined ? month : `${name} ${match[1]}`;
}

/** A range of days in words, or null when it names neither end. */
export function rangeLabel(
    from: string | undefined,
    to: string | undefined,
): string | null {
    const start = dayOf(from);
    const end = dayOf(to);

    if (start === null) {
        return end === null ? null : `Until ${whole(end)}`;
    }

    if (end === null) {
        return `Since ${whole(start)}`;
    }

    if (start.year !== end.year) {
        return `${whole(start)} – ${whole(end)}`;
    }

    if (start.month !== end.month) {
        return `${start.date} ${short(start.month)} – ${whole(end)}`;
    }

    if (start.date !== end.date) {
        return `${start.date}–${end.date} ${short(end.month)} ${end.year}`;
    }

    return whole(start);
}

/** The Date button's value: the month, else the range, else "All dates". */
export function dateValue(filters: {
    month?: string;
    from?: string;
    to?: string;
}): string {
    if (filters.month !== undefined) {
        return monthLabel(filters.month);
    }

    return rangeLabel(filters.from, filters.to) ?? 'All dates';
}
