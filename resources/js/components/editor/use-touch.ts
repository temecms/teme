import { useSyncExternalStore } from 'react';

const COARSE = '(pointer: coarse)';

/** Whether the person is pointing with a finger: a coarse pointer, as the tokens' 44-pixel targets read it (app.css). */
export function isTouch(): boolean {
    return typeof window !== 'undefined' && window.matchMedia(COARSE).matches;
}

function subscribe(changed: () => void): () => void {
    const query = window.matchMedia(COARSE);
    query.addEventListener('change', changed);

    return () => query.removeEventListener('change', changed);
}

/** isTouch(), kept current as the pointer changes. */
export function useTouch(): boolean {
    return useSyncExternalStore(subscribe, isTouch, () => false);
}
