import { useSyncExternalStore } from 'react';

/** Where the Media screen's detail panel sits beside the library rather than in a sheet. */
const WIDE = '(min-width: 1024px)';

function subscribe(callback: () => void): () => void {
    if (typeof window === 'undefined') {
        return () => {};
    }

    const query = window.matchMedia(WIDE);
    query.addEventListener('change', callback);

    return () => query.removeEventListener('change', callback);
}

function snapshot(): boolean {
    return typeof window === 'undefined' || window.matchMedia(WIDE).matches;
}

/** Whether the window is 1024px or wider; true where there is no window. */
export function useWide(): boolean {
    return useSyncExternalStore(subscribe, snapshot, () => true);
}
