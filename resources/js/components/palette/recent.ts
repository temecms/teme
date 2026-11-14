/** How many screens Recent shows (I-220). */
export const RECENT_SHOWN = 5;

/** Where this browser keeps a person's Recent: by their ID, so people sharing it never see each other's. */
function storageKey(userId: number): string {
    return `teme.palette.recent.${userId}`;
}

/**
 * The addresses this person visited last in this browser, newest first:
 * one more than Recent shows, so the page they're on can be left out.
 * Storage that can't be read gives none.
 */
export function recentHrefs(userId: number): string[] {
    try {
        const stored: unknown = JSON.parse(
            window.localStorage.getItem(storageKey(userId)) ?? '[]',
        );

        return Array.isArray(stored)
            ? stored
                  .filter((href): href is string => typeof href === 'string')
                  .slice(0, RECENT_SHOWN + 1)
            : [];
    } catch {
        return [];
    }
}

/** Puts an address first in this person's Recent. Storage that can't be written keeps nothing. */
export function rememberVisit(userId: number, href: string): void {
    try {
        const next = [
            href,
            ...recentHrefs(userId).filter((kept) => kept !== href),
        ].slice(0, RECENT_SHOWN + 1);

        window.localStorage.setItem(storageKey(userId), JSON.stringify(next));
    } catch {
        // A private window or blocked storage: Recent stays empty.
    }
}
