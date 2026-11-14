import { useHttp } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import StructuredDataController from '@/actions/App/Http/Admin/Contents/StructuredDataController';
import type { WithheldNode } from './rail/checks';

/**
 * What the gate leaves out of the item's page as its working revision makes
 * it (I-703): asked when the editor opens and again for each new revision,
 * never for a plain autosave, which writes none. Null until it answers;
 * 'unknown' when it couldn't.
 */
export function useStructuredData(
    contentId: string,
    revisionId: string | null,
): {
    withheld: WithheldNode[] | 'unknown' | null;
    ask: (revisionId: string) => Promise<WithheldNode[] | 'unknown'>;
} {
    const { submit } = useHttp();
    const [withheld, setWithheld] = useState<WithheldNode[] | 'unknown' | null>(
        null,
    );
    // Only the latest question's answer is kept, so a slow earlier one never overwrites it.
    const latest = useRef(0);
    const askedFor = useRef<string | null>(null);

    const ask = useCallback(
        async (revision: string): Promise<WithheldNode[] | 'unknown'> => {
            const mine = ++latest.current;
            let answer: WithheldNode[] | 'unknown';

            try {
                const found = (await submit(
                    StructuredDataController(contentId, {
                        query: { revision },
                    }),
                )) as { withheld: WithheldNode[] };
                answer = found.withheld;
            } catch {
                answer = 'unknown';
            }

            if (mine === latest.current) {
                setWithheld(answer);
            }

            return answer;
        },
        [contentId, submit],
    );

    useEffect(() => {
        if (revisionId === null || revisionId === askedFor.current) {
            return;
        }

        askedFor.current = revisionId;
        void ask(revisionId);
    }, [revisionId, ask]);

    return { withheld, ask };
}
