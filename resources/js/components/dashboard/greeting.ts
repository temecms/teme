/**
 * Today's date: "Wednesday 25 November" (day before month, British order),
 * always in English regardless of the browser's locale. The caller adds the
 * full stop.
 */
export function todayLine(_locale: string, date = new Date()): string {
    return new Intl.DateTimeFormat('en-GB', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    }).format(date);
}
