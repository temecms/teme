import { Head, Link, router, useHttp } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useEffect, useState } from 'react';
import RevisionController from '@/actions/App/Http/Admin/Contents/RevisionController';
import { DiffView } from '@/components/editor/diff-view';
import { MetaChanges } from '@/components/editor/meta-changes';
import { RestoreButton } from '@/components/editor/restore-button';
import Heading from '@/components/heading';
import { EditorPage } from '@/components/patterns/editor-page';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { SelectField } from '@/components/ui/select-field';
import { __ } from '@/lib/i18n';
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
 * The comparison screen, on the editor's page without its rail: two
 * revisions of one article, block by block, with a control to pick which
 * two, and Restore for either one that is not the working version.
 * Restoring adds a revision; nothing else here writes.
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
            <Head title={__('Compare: :title', { title })} />
            <EditorPage
                header={
                    <header className="border-line bg-chrome flex h-(--top-h) shrink-0 items-center gap-3 border-b px-4">
                        <Link
                            href={edit(contentId).url}
                            className="text-ink-2 hover:text-ink text-small inline-flex items-center gap-2"
                        >
                            <ArrowLeft className="size-4" />
                            {__('Back to the editor')}
                        </Link>
                    </header>
                }
            >
                <Heading
                    title={__('Compare versions of “:title”', { title })}
                    description={__(
                        'What changed between two revisions. Nothing here changes the article.',
                    )}
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
                    <div className="grid gap-2">
                        <Label htmlFor="compare-from">{__('Before')}</Label>
                        <SelectField
                            id="compare-from"
                            value={older}
                            onValueChange={setOlder}
                            options={options}
                            className="w-72 max-w-full"
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="compare-to">{__('After')}</Label>
                        <SelectField
                            id="compare-to"
                            value={newer}
                            onValueChange={setNewer}
                            options={options}
                            className="w-72 max-w-full"
                        />
                    </div>
                    <Button type="submit" variant="outline">
                        {__('Compare')}
                    </Button>
                </form>
                <div className="text-ink-2 text-small grid gap-3 lg:grid-cols-2 lg:gap-6">
                    {[
                        {
                            side: __('Before'),
                            restore: __('Restore the before version'),
                            revision: from,
                        },
                        {
                            side: __('After'),
                            restore: __('Restore the after version'),
                            revision: to,
                        },
                    ].map(({ side, restore, revision }) => (
                        <div
                            key={side}
                            className="flex flex-wrap items-center gap-3"
                        >
                            <p>
                                {__(':side: :name', {
                                    side,
                                    name: nameOf(revision),
                                })}
                            </p>
                            {revision.id !== workingRevisionId && (
                                <RestoreButton
                                    contentId={contentId}
                                    revisionId={revision.id}
                                    createdAt={revision.createdAt}
                                    label={restore}
                                />
                            )}
                        </div>
                    ))}
                </div>
                <MetaChanges fields={fields} terms={terms} />
                <DiffView blocks={blocks} />
            </EditorPage>
        </>
    );
}
