/** Today's date: "Wednesday 25 November" (day before month, British order). */
export function todayLine(locale: string, date = new Date()): string {
    return new Intl.DateTimeFormat(locale, {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    }).format(date);
}
