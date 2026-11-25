import { Head, Link, router, setLayoutProps, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import { DiffView } from '@/components/editor/diff-view';
import { MetaChanges } from '@/components/editor/meta-changes';
import { RestoreButton } from '@/components/editor/restore-button';
import { DetailPage } from '@/components/patterns/detail-page';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';
import { SelectField } from '@/components/ui/select-field';
import { adminLocale, formatDateTime, siteZone } from '@/lib/dates';
import { __, __n } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { compare, edit, index as contentIndex } from '@/routes/content';
import type {
    CompareVersion,
    ComparePageProps,
    RevisionSummary,
} from '@/types';

/** What each kind of revision is called in the list of versions, as in the History pane. */
const KINDS: Record<string, string> = {
    save: 'Saved',
    checkpoint: 'Autosaved',
    publish: 'Published',
    restore: 'Restored',
    import: 'Imported',
    ai: 'Assistant',
};

/** "14 Aug 2026, 10:42 · Ada", for a revision's name in the picker and above the columns. */
function nameOf(revision: RevisionSummary | CompareVersion): string {
    const when = formatDateTime(revision.createdAt);

    return revision.author ? `${when} · ${revision.author}` : when;
}

/** "14 Aug, 10:42": a version's time in the list, on the site's clock. */
function shortTime(at: string): string {
    return new Intl.DateTimeFormat(adminLocale(), {
        timeZone: siteZone(),
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(at));
}

/** What a version is: the working one, the first, or the kind of save that made it. */
function noteOf(version: CompareVersion, first: boolean): string {
    if (version.isWorking) {
        return __('Working version');
    }

    if (first) {
        return __('Created');
    }

    return __(KINDS[version.kind] ?? version.kind);
}

/**
 * The comparison screen: two revisions of one article, block by block,
 * beside the list of saved versions, each with Before and After to set
 * either side, and Restore for either side that is not the working
 * version. Restoring adds a revision; nothing else here writes.
 */
export default function Compare({
    contentId,
    title,
    type,
    versions,
    workingRevisionId,
    from,
    to,
    blocks,
    fields,
    terms,
}: ComparePageProps) {
    const { nav } = usePage().props;
    const [older, setOlder] = useState(from.id);
    const [newer, setNewer] = useState(to.id);
    const list =
        type.segment === null ? edit(contentId) : contentIndex(type.segment);
    // The section's first page, so its crumb opens the section's switcher.
    const section = nav.find(
        (entry) => entry.section === 'content' && !entry.hidden,
    );

    setLayoutProps({
        breadcrumbs: [
            { title: __('Content'), href: section?.href ?? list },
            { title: type.plural, href: list },
            {
                title: __('Compare versions'),
                href: compare(contentId, {
                    query: { from: from.id, to: to.id },
                }),
            },
        ],
    });

    const go = (before: string, after: string) =>
        router.get(
            compare(contentId, { query: { from: before, to: after } }).url,
        );

    const rows = versions.rows;
    const options = [
        ...rows,
        ...[from, to].filter(
            (revision) => !rows.some((row) => row.id === revision.id),
        ),
    ].map((revision) => ({ value: revision.id, label: nameOf(revision) }));

    return (
        <>
            <Head title={__('Compare: :title', { title })} />
            <DetailPage
                title={__('Compare versions of “:title”', { title })}
                showTitle
                description={__(
                    'What changed between two revisions. Nothing here changes the article.',
                )}
                back={
                    <Link
                        href={edit(contentId).url}
                        className="text-ink-2 hover:text-ink text-small inline-flex min-h-6 items-center gap-1 pointer-coarse:min-h-11"
                    >
                        <ArrowLeft aria-hidden="true" className="size-4" />
                        {__('Back to the editor')}
                    </Link>
                }
                panelLabel={__('Versions')}
                panel={
                    <Panel>
                        <PanelHeader>
                            {__('Versions')}
                            <span className="text-ink-3 text-small flex-1 font-normal">
                                {versions.older === null
                                    ? __n(':count saved', rows.length)
                                    : __n(':count most recent', rows.length)}
                            </span>
                        </PanelHeader>
                        <PanelBody>
                            <ol className="grid gap-1">
                                {rows.map((version, position) => {
                                    const chosen =
                                        version.id === from.id ||
                                        version.id === to.id;
                                    const name = nameOf(version);
                                    const first =
                                        position === rows.length - 1 &&
                                        versions.older === null;

                                    return (
                                        <li
                                            key={version.id}
                                            data-chosen={chosen || undefined}
                                            className={cn(
                                                'grid gap-1 rounded-md py-2 pr-2 pl-3',
                                                chosen &&
                                                    'bg-accent-soft text-on-accent-soft',
                                            )}
                                        >
                                            <span className="grid min-w-0">
                                                <time
                                                    dateTime={version.createdAt}
                                                    className="text-body font-semibold"
                                                >
                                                    {shortTime(
                                                        version.createdAt,
                                                    )}
                                                </time>
                                                <span
                                                    className={cn(
                                                        'text-small',
                                                        chosen
                                                            ? 'text-on-accent-soft'
                                                            : 'text-ink-3',
                                                    )}
                                                >
                                                    {`${version.author ?? __('Someone')} · ${noteOf(version, first)}`}
                                                </span>
                                            </span>
                                            <span className="flex flex-wrap items-center gap-1">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="xs"
                                                    aria-pressed={
                                                        version.id === from.id
                                                    }
                                                    onClick={() =>
                                                        go(version.id, to.id)
                                                    }
                                                >
                                                    {__('Before')}
                                                    <span className="sr-only">
                                                        {`: ${name}`}
                                                    </span>
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="xs"
                                                    aria-pressed={
                                                        version.id === to.id
                                                    }
                                                    onClick={() =>
                                                        go(from.id, version.id)
                                                    }
                                                >
                                                    {__('After')}
                                                    <span className="sr-only">
                                                        {`: ${name}`}
                                                    </span>
                                                </Button>
                                            </span>
                                        </li>
                                    );
                                })}
                            </ol>
                        </PanelBody>
                    </Panel>
                }
            >
                <div className="grid gap-4">
                    <form
                        className="flex flex-wrap items-end gap-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            go(older, newer);
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
                    <div className="text-ink-2 text-small flex flex-wrap items-center gap-x-3 gap-y-2">
                        <p>
                            {__('Before: :name', { name: nameOf(from) })}
                        </p>
                        {from.id !== workingRevisionId && (
                            <RestoreButton
                                contentId={contentId}
                                revisionId={from.id}
                                createdAt={from.createdAt}
                                label={__('Restore the before version')}
                            />
                        )}
                        <p>{__('After: :name', { name: nameOf(to) })}</p>
                    </div>
                    <DiffView
                        blocks={blocks}
                        meta={
                            fields.length > 0 || terms.length > 0 ? (
                                <MetaChanges fields={fields} terms={terms} />
                            ) : null
                        }
                    />
                </div>
            </DetailPage>
        </>
    );
}
