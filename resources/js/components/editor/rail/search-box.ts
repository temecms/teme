import { useCallback, useState } from 'react';
import { flushSync } from 'react-dom';
import type { CoreCheck } from '@/components/editor/rail/checks';
import { __n } from '@/lib/i18n';

/** How many of the search checks warn: what the box's header and the rail's link count. */
export function searchToCheck(checks: CoreCheck[]): number {
    return checks.filter((check) => check.search && check.state === 'warn')
        .length;
}

/** "3 things to check", or "All checks pass" when none warns. */
export function searchCheckWords(toCheck: number): string {
    return __n(
        '{0} All checks pass|{1} :count thing to check|[2,*] :count things to check',
        toCheck,
    );
}

/** Search and social's state as the editor keeps it: whether it is unfolded, its tab, and the way to it. */
export type SearchBox = {
    open: boolean;
    setOpen: (open: boolean) => void;
    tab: string;
    setTab: (tab: string) => void;
    /** Unfolds the box, brings its heading to the top of the writing column and focuses it. */
    show: () => void;
};

/**
 * Search and social's fold and tab (I-3124), with the jump the rail's
 * "Search and social: 3 things to check" makes: folded on every visit, the
 * Search tab first.
 */
export function useSearchBox(): SearchBox {
    const [open, setOpen] = useState(false);
    const [tab, setTab] = useState('search');

    const show = useCallback(() => {
        flushSync(() => setOpen(true));

        const heading = document.getElementById('editor-search-label');

        heading?.scrollIntoView({ block: 'start' });
        heading?.focus({ preventScroll: true });
    }, []);

    return { open, setOpen, tab, setTab, show };
}
