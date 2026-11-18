import { useHttp } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import PluginChecksController from '@/actions/App/Http/Admin/Contents/PluginChecksController';
import type { PluginCheckGroup } from '@/types/editor';

/**
 * The plugins' checks on the item as its working revision makes it
 * (docs/guides/developers/plugins/seo.md): asked when the editor opens and
 * again for each new revision, as the structured-data check is, never while
 * the person types. Empty until it answers, and when it couldn't.
 */
export function usePluginChecks(
    contentId: string,
    revisionId: string | null,
    enabled: boolean,
): PluginCheckGroup[] {
    const { submit } = useHttp();
    const [groups, setGroups] = useState<PluginCheckGroup[]>([]);
    // Only the latest question's answer is kept, so a slow earlier one never overwrites it.
    const latest = useRef(0);
    const askedFor = useRef<string | null>(null);

    const ask = useCallback(
        async (revision: string) => {
            const mine = ++latest.current;
            let answer: PluginCheckGroup[] = [];

            try {
                const found = (await submit(
                    PluginChecksController(contentId, {
                        query: { revision },
                    }),
                )) as { plugins: PluginCheckGroup[] };
                answer = found.plugins;
            } catch {
                answer = [];
            }

            if (mine === latest.current) {
                setGroups(answer);
            }
        },
        [contentId, submit],
    );

    useEffect(() => {
        if (
            !enabled ||
            revisionId === null ||
            revisionId === askedFor.current
        ) {
            return;
        }

        askedFor.current = revisionId;
        void ask(revisionId);
    }, [revisionId, ask, enabled]);

    return groups;
}
