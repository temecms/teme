/** Today's date in the admin's locale: weekday, day and month. */
export function todayLine(locale: string, date = new Date()): string {
    return new Intl.DateTimeFormat(locale, {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    }).format(date);
}
