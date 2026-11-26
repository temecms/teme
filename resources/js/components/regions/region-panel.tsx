import { Link, router } from '@inertiajs/react';
import type { JSONContent } from '@tiptap/core';
import { ChevronDown, ChevronUp, GripVertical, Plus } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import ContentEditorController from '@/actions/App/Http/Admin/Contents/ContentEditorController';
import { CardEnvironmentContext } from '@/components/editor/cards/card-environment';
import type { CardEnvironment } from '@/components/editor/cards/card-environment';
import { reasonOf, send } from '@/components/editor/editor-http';
import { LeaveDialog } from '@/components/editor/leave-dialog';
import { SaveState } from '@/components/editor/save-state';
import { StaleDialog } from '@/components/editor/stale-dialog';
import { useAutosave } from '@/components/editor/use-autosave';
import { useCardMedia } from '@/components/editor/use-card-media';
import { useElevated } from '@/components/editor/use-elevated';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { IconTile } from '@/components/ui/icon-tile';
import { __ } from '@/lib/i18n';
import { lucideIcon } from '@/lib/lucide-icons';
import { cn } from '@/lib/utils';
import { edit as editContent } from '@/routes/content';
import { discard as discardRoute } from '@/routes/regions';
import type {
    CardManifest,
    ChosenRegion,
    EditorSession,
    RegionsWorkspacePage,
} from '@/types';
import { AddCardDialog, regionChoices } from './add-card-dialog';
import { EditCardDialog } from './edit-card-dialog';
import { changesRegion, forgetRegion } from './region-visits';
import { appended, moved, replaced, rowsOf } from './region-rows';
import type { RegionRowItem } from './region-rows';

/** A region files under no terms; one list, so the autosave sees no change. */
const NO_TERMS: string[] = [];

/** One block's row: what it is, where it comes from, and its tools. */
function CardRow({
    row,
    last,
    dragging,
    onEdit,
    onMove,
    onRemove,
    onDragStart,
    onDrop,
}: {
    row: RegionRowItem;
    last: boolean;
    dragging: boolean;
    onEdit: () => void;
    onMove: (to: number) => void;
    onRemove: () => void;
    onDragStart: () => void;
    onDrop: () => void;
}) {
    const Icon = lucideIcon(row.icon);

    return (
        <li
            data-test="region-card-row"
            data-block={row.type}
            draggable
            onDragStart={(event) => {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', row.key);
                onDragStart();
            }}
            onDragOver={(event) => {
                event.preventDefault();
                event.dataTransfer.dropEffect = 'move';
            }}
            onDrop={(event) => {
                event.preventDefault();
                onDrop();
            }}
            className={cn(
                'bg-surface flex min-w-0 flex-wrap items-center gap-x-3 gap-y-2 rounded-md px-2 py-2 @max-lg:flex-col @max-lg:items-stretch',
                dragging && 'opacity-50',
                row.off && 'bg-surface-2',
            )}
        >
            <div className="flex min-w-0 flex-1 items-center gap-3">
                <GripVertical
                    aria-hidden
                    className="text-ink-3 size-4 shrink-0 cursor-grab"
                />
                {Icon !== undefined && <IconTile icon={Icon} size="sm" />}
                <div className="grid min-w-0 gap-0.5">
                    <span className="text-ink truncate font-semibold">
                        {row.title !== '' ? row.title : row.label}
                    </span>
                    <span className="text-small text-ink-2 truncate">
                        {row.off
                            ? __(
                                  ':label · From :source, so it is kept but not shown on the site',
                                  { label: row.label, source: row.source },
                              )
                            : `${row.label} · ${row.source}`}
                    </span>
                </div>
            </div>
            <div className="flex shrink-0 items-center gap-0.5 @max-lg:justify-end">
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-xs"
                    aria-label={__('Move :card up', { card: row.label })}
                    title={__('Move up')}
                    disabled={row.index === 0}
                    onClick={() => onMove(row.index - 1)}
                >
                    <ChevronUp />
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-xs"
                    aria-label={__('Move :card down', { card: row.label })}
                    title={__('Move down')}
                    disabled={last}
                    onClick={() => onMove(row.index + 1)}
                >
                    <ChevronDown />
                </Button>
                {!row.off && (
                    <Button
                        type="button"
                        variant="neutral"
                        size="xs"
                        onClick={onEdit}
                    >
                        {__('Edit')}
                    </Button>
                )}
                <Button
                    type="button"
                    variant="ghost"
                    size="xs"
                    aria-label={__('Remove :card', { card: row.label })}
                    onClick={onRemove}
                >
                    {__('Remove')}
                </Button>
            </div>
        </li>
    );
}

/**
 * The chosen region, once it has content: its blocks as rows to add, edit,
 * move and remove, autosaved as the editor saves; Save draft makes a
 * revision, Publish saves and publishes it, and Discard changes goes back to
 * what is published.
 */
export function RegionPanel({
    region,
    session,
    page,
    onCount,
}: {
    region: ChosenRegion;
    session: EditorSession;
    page: RegionsWorkspacePage;
    /** Told how many cards the panel holds now, as they are added and removed. */
    onCount?: (count: number) => void;
}) {
    const manifest: CardManifest = page.manifest;
    const [document, putDocument] = useState<JSONContent>(
        session.document as JSONContent,
    );
    const edited = useRef(false);
    const setDocument = (next: JSONContent) => {
        edited.current = true;
        putDocument(next);
    };
    const [unpublished, setUnpublished] = useState(region.dirty);
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<{
        index: number;
        block: JSONContent;
        label: string;
        added: boolean;
    } | null>(null);
    const [dragged, setDragged] = useState<number | null>(null);
    const [discarding, setDiscarding] = useState(false);
    const [busy, setBusy] = useState(false);
    const elevated = useElevated();
    const { media, loading, addMedia } = useCardMedia(session.media);

    // An edited region's autosave lands as the panel goes, behind any visit kept of it: leaving drops that one, so coming back asks the server. A region only looked at keeps its visit.
    useEffect(
        () => () => {
            if (edited.current) {
                forgetRegion(region.key);
            }
        },
        [region.key],
    );

    const saving = useAutosave({
        contentId: session.id,
        askPassword: elevated.ask,
        title: session.title,
        document,
        fields: session.fields,
        terms: NO_TERMS,
        basedOn: session.workingRevisionId,
        idleMs: page.editor.autosaveIdleMs,
        maxMs: page.editor.autosaveMaxMs,
        checkpointAfterMinutes: page.editor.checkpointAfterMinutes,
        initialSavedAt: session.savedAt,
        initialOthers: session.otherEditors,
        hasAutosave: session.fromAutosave,
    });

    const environment = useMemo<CardEnvironment>(
        () => ({
            media,
            loading,
            manifest,
            addMedia,
            uploadedFor: { id: session.id, noun: 'region' },
        }),
        [media, loading, manifest, addMedia, session.id],
    );

    const rows = useMemo(
        () => rowsOf(document, manifest),
        [document, manifest],
    );
    const full = rows.length >= region.max;

    useEffect(() => {
        onCount?.(rows.length);
    }, [rows.length, onCount]);
    const choices = regionChoices(
        manifest,
        region.cards,
        page.canManageSiteCode && !page.siteCodeLocked,
        page.theme?.name ?? null,
    );

    const change = (next: JSONContent) => {
        setDocument(next);
        setUnpublished(true);
    };

    const remove = (row: RegionRowItem) => {
        const before = document;
        change(replaced(document, row.index, []));
        toast(__(':card removed.', { card: row.label }), {
            action: { label: __('Undo'), onClick: () => setDocument(before) },
        });
    };

    const saveDraft = async () => {
        setBusy(true);

        try {
            if ((await saving.checkpoint()) !== null) {
                forgetRegion(region.key);
                toast.success(__('Draft saved.'));
            }
        } finally {
            setBusy(false);
        }
    };

    const publish = async () => {
        setBusy(true);

        try {
            const revisionId = await saving.checkpoint();

            if (revisionId === null) {
                return;
            }

            const route = ContentEditorController.publish(session.id);
            let answer = await send(route, { revision_id: revisionId });

            if (answer.status === 423 && (await elevated.ask())) {
                answer = await send(route, { revision_id: revisionId });
            }

            if (answer.status >= 400) {
                toast.error(
                    __('Not published: :reason', {
                        reason: reasonOf(answer.body),
                    }),
                );

                return;
            }

            setUnpublished(answer.body.unpublishedChanges === true);
            forgetRegion(region.key);
            toast.success(__('Published. Readers see it on their next page.'));
            router.reload({ only: ['groups', 'regions'] });
        } finally {
            setBusy(false);
        }
    };

    const discard = () => {
        setDiscarding(false);
        // A visit, so the autosave on its way lands first, then is thrown away with the rest.
        router.post(
            discardRoute(session.id).url,
            { working_revision_id: saving.workingRevisionId() },
            { preserveScroll: true, ...changesRegion(region.key) },
        );
    };

    return (
        <CardEnvironmentContext value={environment}>
            {elevated.password}
            <div className="@container grid gap-4" data-test="region-panel">
                <div className="flex flex-wrap items-center justify-end gap-2">
                    <Button
                        type="button"
                        size="sm"
                        data-test="region-add-card"
                        disabled={full}
                        onClick={() => setAdding(true)}
                    >
                        <Plus />
                        {__('Add card')}
                    </Button>
                </div>
                {full && (
                    <p className="text-small text-ink-2">
                        {__(
                            'This region is full. Remove a block to add another.',
                        )}
                    </p>
                )}

                {rows.length === 0 ? (
                    <p className="border-line text-ink-2 rounded-md border border-dashed px-4 py-6 text-center">
                        {__(
                            'Empty. The theme draws nothing here until the region has something in it.',
                        )}
                    </p>
                ) : (
                    <ol
                        className="grid gap-2"
                        aria-label={__('Blocks in this region')}
                    >
                        {rows.map((row) => (
                            <CardRow
                                key={row.key}
                                row={row}
                                last={row.index === rows.length - 1}
                                dragging={dragged === row.index}
                                onEdit={() =>
                                    setEditing({
                                        index: row.index,
                                        block: (document.content ?? [])[
                                            row.index
                                        ],
                                        label: row.label,
                                        added: false,
                                    })
                                }
                                onMove={(to) =>
                                    change(moved(document, row.index, to))
                                }
                                onRemove={() => remove(row)}
                                onDragStart={() => setDragged(row.index)}
                                onDrop={() => {
                                    if (dragged !== null) {
                                        change(
                                            moved(document, dragged, row.index),
                                        );
                                    }

                                    setDragged(null);
                                }}
                            />
                        ))}
                    </ol>
                )}

                {region.takes !== null && (
                    <p className="text-small text-ink-3">{region.takes}</p>
                )}

                <div className="flex flex-wrap items-center gap-3 pt-3">
                    {unpublished ? (
                        <span
                            className="text-small text-warn-ink"
                            data-test="region-unpublished"
                        >
                            {__('Changes not published yet')}
                        </span>
                    ) : (
                        <span
                            className="text-small text-ink-3"
                            data-test="region-published"
                        >
                            {__('No unpublished changes')}
                        </span>
                    )}
                    <SaveState
                        state={saving.state}
                        pending={saving.pending}
                        savedAt={saving.savedAt}
                        failure={saving.failure}
                    />
                    <div className="ml-auto flex flex-wrap items-center gap-2">
                        <Link
                            href={editContent(session.id).url}
                            className="text-small text-accent-ink underline"
                        >
                            {__('Open in the full editor')}
                        </Link>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            disabled={busy || !unpublished}
                            onClick={() => setDiscarding(true)}
                        >
                            {__('Discard changes')}
                        </Button>
                        <Button
                            type="button"
                            variant="neutral"
                            size="sm"
                            disabled={busy}
                            data-test="region-save-draft"
                            onClick={() => void saveDraft()}
                        >
                            {__('Save draft')}
                        </Button>
                        <Button
                            type="button"
                            variant="primary"
                            size="sm"
                            disabled={busy || !unpublished}
                            data-test="region-publish"
                            onClick={() => void publish()}
                        >
                            {__('Publish region')}
                        </Button>
                    </div>
                </div>
            </div>

            <AddCardDialog
                open={adding}
                groups={choices}
                onClose={() => setAdding(false)}
                onChoose={(block, label) => {
                    setAdding(false);
                    const next = appended(document, block);
                    change(next);
                    // A new block opens on its form, to be filled in.
                    setEditing({
                        index: (next.content ?? []).length - 1,
                        block,
                        label,
                        added: true,
                    });
                }}
            />
            <EditCardDialog
                editing={editing}
                manifest={manifest}
                siteUrl={page.siteUrl}
                onCancel={() => {
                    // A block just added and not filled in isn't kept.
                    if (editing?.added === true) {
                        setDocument(replaced(document, editing.index, []));
                    }

                    setEditing(null);
                }}
                onDone={(blocks) => {
                    if (editing !== null) {
                        change(replaced(document, editing.index, blocks));
                    }

                    setEditing(null);
                }}
            />
            <Dialog open={discarding} onOpenChange={setDiscarding}>
                <DialogContent size="sm">
                    <DialogHeader>
                        <DialogTitle>{__('Discard changes?')}</DialogTitle>
                        <DialogDescription>
                            {region.state === 'draft'
                                ? __(
                                      'This region has never been published, so it goes back to empty. Its earlier drafts stay in its history.',
                                  )
                                : __(
                                      'The region goes back to what readers see now. Your drafts stay in its history.',
                                  )}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="neutral"
                            onClick={() => setDiscarding(false)}
                        >
                            {__('Keep editing')}
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            data-test="region-discard-confirm"
                            onClick={discard}
                        >
                            {__('Discard changes')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <StaleDialog
                stale={saving.stale}
                title={region.label}
                noun="region"
                document={document}
                onLeave={saving.release}
            />
            <LeaveDialog
                open={saving.asking}
                onStay={saving.stay}
                onLeave={saving.leave}
            />
        </CardEnvironmentContext>
    );
}
