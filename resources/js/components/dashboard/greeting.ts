/**
 * The Home greeting from the browser's clock: morning 5–11, afternoon 12–17,
 * evening otherwise. `:name` is the person's first name, or the whole name.
 */
export function greeting(name: string, hour = new Date().getHours()): string {
    const first = name.trim().split(/\s+/u).filter(Boolean)[0] ?? name;

    if (hour >= 5 && hour <= 11) {
        return `Good morning, ${first}`;
    }

    if (hour >= 12 && hour <= 17) {
        return `Good afternoon, ${first}`;
    }

    return `Good evening, ${first}`;
}

/** Today's date in the admin's locale: weekday, day and month. */
export function todayLine(locale: string, date = new Date()): string {
    return new Intl.DateTimeFormat(locale, {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    }).format(date);
}
