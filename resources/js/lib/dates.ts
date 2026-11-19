/**
 * Dates and times in the admin, one way everywhere: on the site's clock and
 * in the admin's language, never the browser's (issue 530). "13 Nov 2026"
 * for a day, "13 Nov 2026, 11:20" for a moment. app.tsx sets the zone and
 * the language from every page, as it sets the words.
 */

/** A moment as the server sends it (ISO 8601), or as milliseconds. */
export type Moment = string | number | Date;

let zone = 'UTC';
let language = 'en-GB';

/**
 * The admin's language as Intl names it. English is written the British
 * way, day before month, as the admin's own words are.
 */
export function intlLocale(locale: string): string {
    const tag = locale.replace('_', '-');

    return tag === 'en' ? 'en-GB' : tag;
}

/** Puts the site's zone and the admin's language in use; either left out keeps the one before. */
export function setDateContext(
    siteZone?: string | null,
    locale?: string | null,
): void {
    if (typeof siteZone === 'string' && siteZone !== '') {
        zone = siteZone;
    }

    if (typeof locale === 'string' && locale !== '') {
        language = intlLocale(locale);
    }
}

/** The site's zone, as an IANA name. */
export function siteZone(): string {
    return zone;
}

/** The admin's language, as Intl names it. */
export function adminLocale(): string {
    return language;
}

/** A day on the site's clock: "13 Nov 2026". */
export function formatDate(at: Moment, inZone: string = zone): string {
    return new Intl.DateTimeFormat(language, {
        timeZone: inZone,
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(new Date(at));
}

/** A moment on the site's clock: "13 Nov 2026, 11:20". */
export function formatDateTime(at: Moment, inZone: string = zone): string {
    return new Intl.DateTimeFormat(language, {
        timeZone: inZone,
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(at));
}

/** The day a moment falls on, on the site's clock, as "2026-11-13". */
function dayOf(at: Moment, inZone: string): string {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: inZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date(at));
}

/**
 * A recent moment as people say it, on the site's clock: "Today, 08:52"
 * and "Yesterday, 17:20", and an earlier one as its day, "12 Nov 2026".
 * `words` puts the time into the admin's own sentence for each of the two
 * days, and `now` is the moment to count from.
 */
export function formatRecent(
    at: Moment,
    words: {
        today: (time: string) => string;
        yesterday: (time: string) => string;
    },
    now: Moment = Date.now(),
    inZone: string = zone,
): string {
    const day = dayOf(at, inZone);
    const time = new Intl.DateTimeFormat(language, {
        timeZone: inZone,
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(at));

    if (day === dayOf(now, inZone)) {
        return words.today(time);
    }

    if (day === dayOf(new Date(now).getTime() - 86_400_000, inZone)) {
        return words.yesterday(time);
    }

    return formatDate(at, inZone);
}
