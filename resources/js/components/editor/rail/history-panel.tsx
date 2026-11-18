import { Link, useHttp } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Fragment, useCallback, useEffect, useState } from 'react';
import RevisionController from '@/actions/App/Http/Admin/Contents/RevisionController';
import { Button } from '@/components/ui/button';
import { __ } from '@/lib/i18n';
import { compare } from '@/routes/content';
import { index as aiActivity } from '@/routes/tools/ai-activity';
import { RestoreButton } from '../restore-button';
import type { RevisionAssistant, RevisionPage, RevisionRow } from '@/types';
import { formatDateTime } from '@/lib/dates';

/** What each kind of revision is called in the history. */
const KINDS: Record<string, string> = {
    save: 'Saved',
    checkpoint: 'Autosaved',
    publish: 'Published',
    restore: 'Restored',
    import: 'Imported',
    ai: 'Assistant',
};

/** "12 words added", "3 words removed", or nothing for the first revision. */
function change(delta: number | null): string | null {
    if (delta === null) {
        return null;
    }

    if (delta === 0) {
        return 'No change in length';
    }

    const words = Math.abs(delta) === 1 ? 'word' : 'words';

    return delta > 0 ? `${delta} ${words} added` : `${-delta} ${words} removed`;
}

/** "Assistant" or "Assistant · Shorter"; for someone who may open AI activity, a link to the request (I-1307). */
function Assistant({ assistant }: { assistant: RevisionAssistant }) {
    const words =
        assistant.label === null
            ? 'Assistant'
            : `Assistant · ${assistant.label}`;

    if (assistant.row === null) {
        return <>{words}</>;
    }

    return (
        <Link
            href={aiActivity({ query: { row: assistant.row } }).url}
            aria-label={__(':words, in AI activity', { words })}
            className="text-accent-ink underline-offset-2 hover:underline"
        >
            {words}
        </Link>
    );
}

/** The line under a revision: what made it, who, and how much it changed. */
function Meta({ row }: { row: RevisionRow }) {
    const parts: { key: string; node: ReactNode }[] = [
        {
            key: 'kind',
            node:
                row.assistant === null ? (
                    (KINDS[row.kind] ?? row.kind)
                ) : (
                    <Assistant assistant={row.assistant} />
                ),
        },
        {
            key: 'author',
            node:
                row.via === null
                    ? (row.author ?? 'Someone')
                    : `${row.author ?? 'Someone'}, ${row.via}`,
        },
        { key: 'change', node: change(row.wordDelta) },
    ];

    return (
        <span className="text-ink-2">
            {parts
                .filter((part) => part.node !== null)
                .map((part, position) => (
                    <Fragment key={part.key}>
                        {position > 0 && ' · '}
                        {part.node}
                    </Fragment>
                ))}
        </span>
    );
}

/**
 * The History pane: an article's revisions, newest first, each with when,
 * who, what kind, and how much it changed, with Load older while there are
 * more.
 */
export function HistoryPanel({ contentId }: { contentId: string }) {
    const { submit } = useHttp();
    const [rows, setRows] = useState<RevisionRow[]>([]);
    const [older, setOlder] = useState<string | null>(null);
    const [loading, setLoading] = useState(true);
    const [problem, setProblem] = useState<string | null>(null);

    const load = useCallback(
        async (after: string | null) => {
            setLoading(true);
            setProblem(null);

            try {
                const page = (await submit(
                    RevisionController.index(contentId, {
                        query: after === null ? {} : { after },
                    }),
                )) as RevisionPage;

                setRows((current) =>
                    after === null ? page.rows : [...current, ...page.rows],
                );
                setOlder(page.older);
            } catch {
                setProblem('The history could not be loaded. Try again.');
            } finally {
                setLoading(false);
            }
        },
        [contentId, submit],
    );

    useEffect(() => {
        void load(null);
    }, [load]);

    return (
        <div className="grid gap-3 p-4">
            {problem && (
                <p role="alert" className="text-danger-ink text-small">
                    {problem}
                </p>
            )}
            <ol className="grid gap-1">
                {rows.map((row, index) => (
                    <li
                        key={row.id}
                        className="border-line text-small grid gap-0.5 border-b py-2 last:border-b-0"
                    >
                        {/* Each row opens the comparison with the revision
                            before it; the first has nothing before it. */}
                        <span className="flex flex-wrap items-center gap-x-2">
                            <time
                                dateTime={row.createdAt}
                                className="font-medium"
                            >
                                {formatDateTime(row.createdAt)}
                            </time>
                            {row.isPublished && (
                                <span className="text-ok-ink text-caption font-medium">
                                    {__('Live')}
                                </span>
                            )}
                            {row.isWorking && (
                                <span className="text-ink-2 text-caption font-medium">
                                    {__('Current')}
                                </span>
                            )}
                        </span>
                        {rows[index + 1] !== undefined && (
                            <Link
                                href={
                                    compare(contentId, {
                                        query: {
                                            from: rows[index + 1].id,
                                            to: row.id,
                                        },
                                    }).url
                                }
                                className="text-accent-ink text-caption justify-self-start underline-offset-2 hover:underline"
                            >
                                {__('Compare with the version before')}
                            </Link>
                        )}
                        {!row.isWorking && (
                            <RestoreButton
                                contentId={contentId}
                                revisionId={row.id}
                                createdAt={row.createdAt}
                            />
                        )}
                        <Meta row={row} />
                    </li>
                ))}
            </ol>
            {loading && (
                <p aria-busy="true" className="text-ink-2 text-caption">
                    {__('Loading…')}
                </p>
            )}
            {older !== null && !loading && (
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="justify-self-start"
                    onClick={() => void load(older)}
                >
                    {__('Load older')}
                </Button>
            )}
        </div>
    );
}
