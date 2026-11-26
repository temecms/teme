/**
 * Today's date, in the admin's language: "Wednesday 25 November" for English
 * (day before month, British order). The caller adds the full stop.
 */
export function todayLine(locale: string, date = new Date()): string {
    const tag =
        locale === '' || locale.startsWith('en')
            ? 'en-GB'
            : locale.replace('_', '-');

    return new Intl.DateTimeFormat(tag, {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    }).format(date);
}
