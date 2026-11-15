import { useSyncExternalStore } from 'react';

/** Under 640px a list draws cards instead of its table (I-3422). */
const PHONE = '(max-width: 39.99rem)';

function subscribe(onChange: () => void): () => void {
    const query = window.matchMedia(PHONE);

    query.addEventListener('change', onChange);

    return () => query.removeEventListener('change', onChange);
}

/**
 * Whether the screen is narrow enough for a list's cards (I-3422): under
 * 640px. The server draws the table.
 */
export function useCards(): boolean {
    return useSyncExternalStore(
        subscribe,
        () => window.matchMedia(PHONE).matches,
        () => false,
    );
}
