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

/** A time of day on the site's clock: "11:20". */
export function formatTime(at: Moment, inZone: string = zone): string {
    return new Intl.DateTimeFormat(language, {
        timeZone: inZone,
        hour: '2-digit',
        minute: '2-digit',
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
