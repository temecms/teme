const UNITS = ['KB', 'MB', 'GB', 'TB'];

/**
 * A size in decimal units, one decimal place and no trailing ".0": "950
 * bytes", "1 GB", "1.2 GB". Made in the browser because the intl extension
 * Laravel's own formatter needs isn't one Teme requires.
 */
export function sizeWords(bytes: number): string {
    if (bytes < 1000) {
        return `${bytes} ${bytes === 1 ? 'byte' : 'bytes'}`;
    }

    let value = bytes / 1000;
    let unit = 0;

    while (value >= 1000 && unit < UNITS.length - 1) {
        value /= 1000;
        unit += 1;
    }

    return `${(Math.round(value * 10) / 10).toFixed(1).replace(/\.0$/, '')} ${UNITS[unit]}`;
}

/** Names in a sentence: "A", "A and B", "A, B and C". */
export function names(list: string[]): string {
    if (list.length <= 1) {
        return list[0] ?? '';
    }

    return `${list.slice(0, -1).join(', ')} and ${list[list.length - 1]}`;
}
