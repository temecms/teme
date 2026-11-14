import { useHttp } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import LinkTargetsController from '@/actions/App/Http/Admin/Contents/LinkTargetsController';

/** An article a link or a button may lead to. */
export type LinkTarget = {
    id: string;
    title: string;
    type: string;
    status: string;
};

/**
 * The articles whose titles match the query, fetched a moment after typing
 * stops, while `enabled` is true. The link popover and the button card
 * search the same way.
 */
export function useLinkTargets(query: string, enabled: boolean): LinkTarget[] {
    const [targets, setTargets] = useState<LinkTarget[]>([]);
    const { submit } = useHttp();

    useEffect(() => {
        if (!enabled) {
            return;
        }

        const timer = window.setTimeout(() => {
            void submit(LinkTargetsController({ query: { q: query } })).then(
                (found) => setTargets(found as LinkTarget[]),
            );
        }, 200);

        return () => window.clearTimeout(timer);
    }, [enabled, query, submit]);

    return targets;
}
