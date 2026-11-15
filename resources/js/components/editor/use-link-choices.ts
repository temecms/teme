import { useHttp } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import LinkChoicesController from '@/actions/App/Http/Admin/Contents/LinkChoicesController';

/** Something a link can lead to by ID: the address to store, its title or name, and a word about it. */
export type LinkChoice = { href: string; title: string; note: string | null };

/** One kind of thing the link box offers, under its heading. */
export type LinkChoiceGroup = {
    key: string;
    label: string;
    choices: LinkChoice[];
};

/**
 * What a link can lead to by ID, matching the query, fetched a moment after
 * typing stops while `enabled` is true (LinkChoices): the articles, then,
 * once something is typed, each taxonomy's terms, the authors and the files.
 * `loading` is true until the answer for the latest query has arrived.
 */
export function useLinkChoices(
    query: string,
    enabled: boolean,
): { groups: LinkChoiceGroup[]; loading: boolean } {
    const [groups, setGroups] = useState<LinkChoiceGroup[]>([]);
    const [answered, setAnswered] = useState<string | null>(null);
    const { submit } = useHttp();

    useEffect(() => {
        if (!enabled) {
            return;
        }

        const timer = window.setTimeout(() => {
            void submit(LinkChoicesController({ query: { q: query } })).then(
                (found) => {
                    setGroups(found as LinkChoiceGroup[]);
                    setAnswered(query);
                },
            );
        }, 200);

        return () => window.clearTimeout(timer);
    }, [enabled, query, submit]);

    return { groups, loading: enabled && answered !== query };
}
