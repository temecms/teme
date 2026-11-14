/**
 * Times in the site's zone, not the browser's. The admin always names the
 * zone it means, because "18:00" in the wrong zone is how an embargo gets
 * broken.
 */

type WallClock = {
    year: number;
    month: number;
    day: number;
    hour: number;
    minute: number;
};

/** What a clock in `zone` reads at the instant `utcMs`. */
function wallClockAt(utcMs: number, zone: string): WallClock {
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat('en-GB', {
            timeZone: zone,
            hourCycle: 'h23',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
        })
            .formatToParts(new Date(utcMs))
            .map((part) => [part.type, part.value]),
    );

    return {
        year: Number(parts.year),
        month: Number(parts.month),
        day: Number(parts.day),
        hour: Number(parts.hour),
        minute: Number(parts.minute),
    };
}

/** The zone's offset from UTC at an instant, in minutes. */
function offsetAt(utcMs: number, zone: string): number {
    const clock = wallClockAt(utcMs, zone);
    const asUtc = Date.UTC(
        clock.year,
        clock.month - 1,
        clock.day,
        clock.hour,
        clock.minute,
    );

    return Math.round((asUtc - Math.floor(utcMs / 60000) * 60000) / 60000);
}

const pad = (value: number) => String(Math.abs(value)).padStart(2, '0');

/**
 * A date (YYYY-MM-DD) and a time (HH:MM) read on a clock in `zone`, as
 * ISO 8601 with that zone's offset at that moment, daylight saving included.
 */
export function zonedToIso(date: string, time: string, zone: string): string {
    const [year, month, day] = date.split('-').map(Number);
    const [hour, minute] = time.split(':').map(Number);
    const guess = Date.UTC(year, month - 1, day, hour, minute);

    // Twice, so a guess on the far side of a clock change settles.
    let offset = offsetAt(guess, zone);
    offset = offsetAt(guess - offset * 60000, zone);

    const sign = offset < 0 ? '-' : '+';

    return `${date}T${time}:00${sign}${pad(Math.trunc(offset / 60))}:${pad(offset % 60)}`;
}

/** Today's date and a time, now, on a clock in `zone`. */
export function nowIn(zone: string): { date: string; time: string } {
    const clock = wallClockAt(Date.now(), zone);

    return {
        date: `${clock.year}-${pad(clock.month)}-${pad(clock.day)}`,
        time: `${pad(clock.hour)}:${pad(clock.minute)}`,
    };
}

/** The next whole hour on a clock in `zone`: 14:20 gives 15:00, 23:40 tomorrow's 00:00. */
export function nextWholeHour(zone: string): { date: string; time: string } {
    const now = nowIn(zone);
    const hour = Number(now.time.slice(0, 2)) + 1;

    return hour === 24
        ? { date: addDays(now.date, 1), time: '00:00' }
        : { date: now.date, time: `${pad(hour)}:00` };
}

/** A date moved by whole days, as YYYY-MM-DD. */
export function addDays(date: string, days: number): string {
    const [year, month, day] = date.split('-').map(Number);
    const moved = new Date(Date.UTC(year, month - 1, day + days));

    return moved.toISOString().slice(0, 10);
}

/** The weekday of a date, 0 for Sunday. */
export function weekday(date: string): number {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, day)).getUTCDay();
}

/** An instant as the site's clock shows it: "Fri 18 Sep, 18:00". */
export function formatInZone(iso: string, zone: string): string {
    return new Intl.DateTimeFormat([], {
        timeZone: zone,
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(iso));
}
