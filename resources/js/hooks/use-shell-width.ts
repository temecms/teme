import { useSyncExternalStore } from 'react';

/** The shell's three widths (docs/architecture/design-system.md, section 2.12). */
export type ShellWidth = 'small' | 'compact' | 'wide';

const small =
    typeof window === 'undefined'
        ? undefined
        : window.matchMedia('(max-width: 47.99rem)');
const compact =
    typeof window === 'undefined'
        ? undefined
        : window.matchMedia('(max-width: 63.99rem)');

function subscribe(callback: () => void): () => void {
    small?.addEventListener('change', callback);
    compact?.addEventListener('change', callback);

    return () => {
        small?.removeEventListener('change', callback);
        compact?.removeEventListener('change', callback);
    };
}

function widthNow(): ShellWidth {
    if (small?.matches) {
        return 'small';
    }

    return compact?.matches ? 'compact' : 'wide';
}

function serverWidth(): ShellWidth {
    return 'wide';
}

/**
 * How wide the shell is: under 768px the menu is a drawer, from 768 to
 * 1023 it is the icon rail with the full menu over the page on demand, and
 * from 1024 it is the full menu, folded to the rail by choice.
 */
export function useShellWidth(): ShellWidth {
    return useSyncExternalStore(subscribe, widthNow, serverWidth);
}
