import type { PendingVisit } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { guardHistory } from '@/lib/history-guard';

/**
 * Asks before a page with unsaved changes is left (docs/architecture/design-system.md,
 * 4.12): a visit to another page waits for the answer, the browser's Back and
 * Forward are held the same way, and a reload or a closed tab gets the
 * browser's own question. A form's own submission always goes.
 */
export function useLeaveGuard(dirty: boolean): {
    asking: boolean;
    stay: () => void;
    leave: () => void;
} {
    const [pending, setPending] = useState<(() => void) | null>(null);
    const dirtyNow = useRef(dirty);
    const passing = useRef(false);

    useEffect(() => {
        dirtyNow.current = dirty;
    }, [dirty]);

    useEffect(() => {
        const here = {
            url: window.location.href,
            state: window.history.state as unknown,
        };

        const go = (visit: () => void) => () => {
            passing.current = true;
            visit();
            passing.current = false;
        };

        const unload = (event: BeforeUnloadEvent) => {
            if (dirtyNow.current) {
                event.preventDefault();
                // Older Safari asks only when this is set.
                event.returnValue = '';
            }
        };

        window.addEventListener('beforeunload', unload);

        const stopGuarding = router.on('before', (event) => {
            const visit: PendingVisit = event.detail.visit;

            if (
                passing.current ||
                !dirtyNow.current ||
                visit.method !== 'get' ||
                visit.only.length > 0 ||
                visit.prefetch ||
                visit.async
            ) {
                return true;
            }

            setPending(() =>
                go(() =>
                    router.visit(visit.url, {
                        method: visit.method,
                        data: visit.data,
                        replace: visit.replace,
                        preserveScroll: visit.preserveScroll,
                        preserveState: visit.preserveState,
                        headers: visit.headers,
                    }),
                ),
            );

            return false;
        });

        const stopHoldingHistory = guardHistory(() => {
            if (!dirtyNow.current || window.location.href === here.url) {
                return false;
            }

            const target = window.location.href;
            window.history.pushState(here.state, '', here.url);
            setPending(() => go(() => router.visit(target)));

            return true;
        });

        return () => {
            window.removeEventListener('beforeunload', unload);
            stopGuarding();
            stopHoldingHistory();
        };
    }, []);

    const stay = useCallback(() => setPending(null), []);

    const leave = useCallback(() => {
        const visit = pending;

        setPending(null);
        dirtyNow.current = false;
        visit?.();
    }, [pending]);

    return { asking: pending !== null, stay, leave };
}
