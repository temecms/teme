import { useHttp } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import LinkedContentController from '@/actions/App/Http/Admin/Contents/LinkedContentController';
import type { LinkedContent } from '@/types';
import { linkedContentIds } from './rail/checks';

// Later (I-427): a linked article trashed while this editor is open still
// shows its old state until the editor is reopened.

/**
 * Whether each article the document links to is published, for the rail's
 * checks. It starts from what the editor opened with, and asks
 * content.linked about links added since, once the document has been still
 * for 400 ms: a document changes on every keystroke. Each link's state is
 * read once per session.
 */
export function useLinkedContent(
    initial: Record<string, LinkedContent>,
    document: unknown,
): Record<string, LinkedContent> {
    const { submit } = useHttp();
    // An empty map arrives from PHP as a list; spreading makes it an object.
    const [linked, setLinked] = useState<Record<string, LinkedContent>>(() => ({
        ...initial,
    }));
    const asked = useRef(new Set(Object.keys(initial)));

    useEffect(() => {
        const wanted = linkedContentIds(document).filter(
            (id) => !asked.current.has(id),
        );

        if (wanted.length === 0) {
            return;
        }

        const timer = window.setTimeout(() => {
            wanted.forEach((id) => asked.current.add(id));

            void (
                submit(
                    LinkedContentController({ query: { ids: wanted } }),
                ) as Promise<Record<string, LinkedContent>>
            )
                .then((found) =>
                    setLinked((current) => ({ ...current, ...found })),
                )
                .catch(() => {
                    // Asked again the next time the document changes.
                    wanted.forEach((id) => asked.current.delete(id));
                });
        }, 400);

        return () => window.clearTimeout(timer);
    }, [document, submit]);

    return linked;
}
