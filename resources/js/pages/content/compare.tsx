import { Head, Link, router, useHttp } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useEffect, useState } from 'react';
import RevisionController from '@/actions/App/Http/Admin/Contents/RevisionController';
import { DiffView } from '@/components/editor/diff-view';
import { MetaChanges } from '@/components/editor/meta-changes';
import { RestoreButton } from '@/components/editor/restore-button';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { SelectField } from '@/components/ui/select-field';
import { compare, edit } from '@/routes/content';
import type {
    ComparePageProps,
    RevisionPage,
    RevisionRow,
    RevisionSummary,
} from '@/types';

/** "14 Aug 2026, 10:42 · Ada", for a revision's name in the picker and above the columns. */
function nameOf(revision: RevisionSummary | RevisionRow): string {
    const when = new Date(revision.createdAt).toLocaleString([], {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

    return revision.author ? `${when} · ${revision.author}` : when;
}

/**
 * The comparison screen: two revisions of one article, block by block, with
 * a control to pick which two, and Restore for either one that is not the
 * working version. Restoring adds a revision; nothing else here writes.
 */
export default function Compare({
    contentId,
    title,
    workingRevisionId,
    from,
    to,
    blocks,
    fields,
    terms,
}: ComparePageProps) {
    const { submit } = useHttp();
    const [revisions, setRevisions] = useState<RevisionRow[]>([]);
    const [older, setOlder] = useState(from.id);
    const [newer, setNewer] = useState(to.id);

    // The first page of the history is enough to choose from; the History
    // pane pages further.
    useEffect(() => {
        void submit(RevisionController.index(contentId)).then((page) =>
            setRevisions((page as RevisionPage).rows),
        );
    }, [contentId, submit]);

    const options = [
        ...revisions,
        ...[from, to].filter(
            (revision) => !revisions.some((row) => row.id === revision.id),
        ),
    ].map((revision) => ({ value: revision.id, label: nameOf(revision) }));

    return (
        <>
            <Head title={`Compare: ${title}`} />
            <div className="grid grid-cols-[minmax(0,1fr)] gap-6 px-4 py-6 sm:px-6">
                <Link
                    href={edit(contentId).url}
                    className="text-ink-2 hover:text-ink inline-flex items-center gap-1.5 justify-self-start text-[13px]"
                >
                    <ArrowLeft className="size-4" />
                    Back to the editor
                </Link>
                <Heading
                    title={`Compare versions of “${title}”`}
                    description="What changed between two revisions. Nothing here changes the article."
                    level={1}
                />
                <form
                    className="flex flex-wrap items-end gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get(
                            compare(contentId, {
                                query: { from: older, to: newer },
                            }).url,
                        );
                    }}
                >
                    <div className="grid gap-1.5">
                        <Label htmlFor="compare-from">Before</Label>
                        <SelectField
                            id="compare-from"
                            value={older}
                            onValueChange={setOlder}
                            options={options}
                            className="h-[34px] w-72 max-w-full"
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="compare-to">After</Label>
                        <SelectField
                            id="compare-to"
                            value={newer}
                            onValueChange={setNewer}
                            options={options}
                            className="h-[34px] w-72 max-w-full"
                        />
                    </div>
                    <Button type="submit" variant="outline">
                        Compare
                    </Button>
                </form>
                <div className="text-ink-2 grid gap-3 text-[13px] lg:grid-cols-2 lg:gap-6">
                    {[
                        { side: 'Before', revision: from },
                        { side: 'After', revision: to },
                    ].map(({ side, revision }) => (
                        <div
                            key={side}
                            className="flex flex-wrap items-center gap-3"
                        >
                            <p>
                                {side}: {nameOf(revision)}
                            </p>
                            {revision.id !== workingRevisionId && (
                                <RestoreButton
                                    contentId={contentId}
                                    revisionId={revision.id}
                                    createdAt={revision.createdAt}
                                    label={`Restore the ${side.toLowerCase()} version`}
                                />
                            )}
                        </div>
                    ))}
                </div>
                <MetaChanges fields={fields} terms={terms} />
                <DiffView blocks={blocks} />
            </div>
        </>
    );
}
