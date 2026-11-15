import { Form, Link } from '@inertiajs/react';
import type { Editor } from '@tiptap/core';
import { NodeSelection } from '@tiptap/pm/state';
import { ArrowLeft, Eye, X } from 'lucide-react';
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { createPortal } from 'react-dom';
import type { ReactNode } from 'react';
import ContentActionController from '@/actions/App/Http/Admin/Contents/ContentActionController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { PluginMark } from '@/components/ui/plugin-mark';
import { ScrollArea } from '@/components/ui/scroll-area';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { __ } from '@/lib/i18n';
import { nextWholeHour } from '@/lib/zoned-time';
import { compare } from '@/routes/content';
import type {
    CardManifestEntry,
    EditorAuthorChoice,
    EditorPerson,
    EditorReview,
    EditorSchedule,
    EditorSession,
    RailPane,
} from '@/types';
import { CardInspector } from './rail/card-inspector';
import type { CheckId, CoreCheck } from './rail/checks';
import { ChecksPanel } from './rail/checks-panel';
import { PublishPanel } from './rail/publish-panel';
import type { Visibility, VisibilityRefusal } from './rail/publish-panel';
import { TaxonomyPanel } from './rail/taxonomy-panel';
import type { ChosenTerm } from './rail/taxonomy-panel';
import type { Discussion } from '@/lib/discussion';
import type { WhenChoice } from './rail/publish-when';
import {
    defaultAt,
    futureIso,
    SCHEDULE_WORDS,
    ScheduleControl,
} from './schedule-control';

/** The publishing decisions the action bar can make, each posted by the page. */
export type PublishingActions = {
    publish: () => void;
    schedule: (runAt: string, action: 'publish' | 'update') => void;
    cancelSchedule: () => void;
    submit: () => void;
    /** Publishes the submitted version now, or at `runAt` (I-437). */
    approve: (runAt?: string) => void;
    sendBack: (note: string) => void;
};

export type RailContext = {
    pane: RailPane | null;
    openPane: (pane: RailPane) => void;
    closePane: (id?: string) => void;
    /** Closes the pane from its head, and puts focus back on what opened it. */
    leavePane: () => void;
};

const Rail = createContext<RailContext | null>(null);

/**
 * The rail's one pane (I-234), as state the page holds. A pane takes the
 * rail's whole height for a while: a card's settings, the assistant,
 * History or Share, and later a plugin's own (I-234). Opening one replaces
 * any other; closing it brings the sections back. There is no special case
 * per kind of pane.
 */
export function useRailPanes(): RailContext {
    const [pane, setPane] = useState<RailPane | null>(null);
    // What had focus as the pane opened, and its id, should the sections draw it again.
    const opener = useRef<{ element: HTMLElement; id: string } | null>(null);

    const openPane = useCallback((next: RailPane) => {
        const active = document.activeElement;

        if (active instanceof HTMLElement && active !== document.body) {
            opener.current = { element: active, id: active.id };
        }

        setPane(next);
    }, []);
    const closePane = useCallback(
        (id?: string) =>
            setPane((current) =>
                id === undefined || current?.id === id ? null : current,
            ),
        [],
    );
    const leavePane = useCallback(() => {
        const back = opener.current;

        setPane(null);
        opener.current = null;

        // Once the sections are drawn again.
        requestAnimationFrame(() => {
            const target =
                back === null
                    ? null
                    : back.element.isConnected
                      ? back.element
                      : back.id !== ''
                        ? document.getElementById(back.id)
                        : null;

            target?.focus();
        });
    }, []);

    return useMemo(
        () => ({ pane, openPane, closePane, leavePane }),
        [pane, openPane, closePane, leavePane],
    );
}

/** Gives the page's rail state, from useRailPanes(), to everything under it. */
export function RailScope({
    value,
    children,
}: {
    value: RailContext;
    children: ReactNode;
}) {
    return <Rail.Provider value={value}>{children}</Rail.Provider>;
}

/** The rail's pane, and the way to open or close it. */
export function useRail(): RailContext {
    const rail = useContext(Rail);

    if (rail === null) {
        throw new Error('useRail() needs a RailProvider around it.');
    }

    return rail;
}

/**
 * Selecting a card opens the card pane, CardInspector, and deselecting it
 * closes that pane. The pane is an ordinary occupant: another pane opened
 * over it replaces it.
 */
function useCardPane(editor: Editor | null, cards: CardManifestEntry[]) {
    const { openPane, closePane } = useRail();

    useEffect(() => {
        if (editor === null) {
            return;
        }

        const follow = () => {
            const { selection } = editor.state;
            const card =
                selection instanceof NodeSelection
                    ? cards.find(
                          (entry) => entry.name === selection.node.type.name,
                      )
                    : undefined;

            if (card === undefined) {
                closePane('card');

                return;
            }

            openPane({
                id: 'card',
                owner: 'core',
                title: card.label,
                render: () => <CardInspector editor={editor} cards={cards} />,
            });
        };

        editor.on('selectionUpdate', follow);

        return () => {
            editor.off('selectionUpdate', follow);
        };
    }, [editor, cards, openPane, closePane]);
}

/**
 * The rail's pinned action bar: the one place publishing decisions are made.
 * What it offers follows the article's state and what this person may do —
 * the policy's publish ability on this item, never a role name. A pending
 * review offers no Publish: publishing around it would skip the review the
 * writer asked for.
 */
function ActionBar({
    session,
    status,
    schedule,
    review,
    siteTimezone,
    saveDisabled,
    onSave,
    onPreview,
    actions,
    busy,
    notice,
    when,
    onWhenChange,
}: {
    session: EditorSession;
    status: string;
    schedule: EditorSchedule | null;
    review: EditorReview | null;
    siteTimezone: string;
    saveDisabled: boolean;
    onSave: () => void;
    /** Saves, then opens the latest saved version in its own tab; null for a theme region. */
    onPreview: (() => void) | null;
    actions: PublishingActions;
    busy: boolean;
    notice: string | null;
    /** The Publish row's choice: now, or a set time. */
    when: WhenChoice;
    onWhenChange: (next: WhenChoice) => void;
}) {
    const [returning, setReturning] = useState(false);
    const [approvingLater, setApprovingLater] = useState(false);
    const [approveAt, setApproveAt] = useState('');
    const [approveError, setApproveError] = useState<string | null>(null);
    const [note, setNote] = useState('');
    const canPublish = session.can.publish;
    const live = status === 'published';
    const pending = review?.state === 'pending' ? review : null;
    const problem =
        schedule !== null &&
        (schedule.failedReason !== null || schedule.heldReason !== null);
    const waiting = schedule !== null && !problem;
    const disabled = saveDisabled || busy;

    // A set time is checked before anything is saved or sent: empty or past
    // is said under the field, which takes the focus.
    const scheduleChosen = () => {
        const runAt = futureIso(when.at, siteTimezone);

        if (runAt === null) {
            onWhenChange({ ...when, error: SCHEDULE_WORDS.past });
            document.getElementById('editor-when-at')?.focus();

            return;
        }

        actions.schedule(runAt, live ? 'update' : 'publish');
    };

    // The bar's one decision outside a review, by the Publish row's choice:
    // nothing while a schedule waits, unless it is being moved.
    const decision: { label: string; run: () => void } | null =
        pending !== null
            ? null
            : !canPublish
              ? {
                    label: live
                        ? 'Submit update for review'
                        : 'Submit for review',
                    run: actions.submit,
                }
              : when.later
                ? {
                      label: live ? 'Schedule update' : 'Schedule',
                      run: scheduleChosen,
                  }
                : waiting
                  ? null
                  : {
                        label: live ? 'Update' : 'Publish',
                        run: actions.publish,
                    };

    const reviewButtons = (
        <>
            <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={busy}
                aria-expanded={returning}
                onClick={() => setReturning((open) => !open)}
            >
                Return
            </Button>
            <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={busy}
                aria-expanded={approvingLater}
                onClick={() => {
                    // Opens on the next whole hour, as built.
                    const next = nextWholeHour(siteTimezone);
                    setApproveAt(`${next.date}T${next.time}`);
                    setApproveError(null);
                    setApprovingLater((open) => !open);
                }}
            >
                Approve and schedule…
            </Button>
            <Button
                type="button"
                size="sm"
                disabled={busy}
                onClick={() => actions.approve()}
            >
                Approve
            </Button>
        </>
    );

    return (
        <div
            data-slot="action-bar"
            className="bg-chrome sticky top-0 z-(--z-sticky) grid gap-2 p-4"
        >
            <div className="flex flex-wrap items-center gap-2">
                {onPreview !== null && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        data-test="editor-preview"
                        disabled={disabled}
                        onClick={onPreview}
                    >
                        <Eye />
                        Preview
                        <span className="sr-only">, opens in a new tab</span>
                    </Button>
                )}
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={saveDisabled}
                    onClick={onSave}
                >
                    Save draft
                </Button>
                <span className="flex-1" />
                {pending !== null
                    ? canPublish && reviewButtons
                    : decision !== null && (
                          <Button
                              type="button"
                              size="sm"
                              disabled={disabled}
                              onClick={decision.run}
                          >
                              {decision.label}
                          </Button>
                      )}
            </div>

            {pending !== null && (
                <div className="grid gap-1 text-xs">
                    <p>
                        {canPublish
                            ? 'Submitted'
                            : 'Waiting for review. Submitted'}{' '}
                        by {pending.submittedBy ?? 'a removed account'}.
                    </p>
                    <Link
                        href={
                            compare(session.id, {
                                query: { to: pending.revisionId },
                            }).url
                        }
                        className="text-accent-ink underline-offset-2 hover:underline"
                    >
                        Compare with the published version
                    </Link>
                </div>
            )}

            {pending !== null && canPublish && approvingLater && (
                <div className="grid gap-2">
                    <Label htmlFor="editor-approve-at">Date and time</Label>
                    <ScheduleControl
                        id="editor-approve-at"
                        siteTimezone={siteTimezone}
                        value={approveAt}
                        onChange={(at) => {
                            setApproveAt(at);
                            setApproveError(null);
                        }}
                        error={approveError}
                    />
                    <div className="flex gap-2">
                        <Button
                            type="button"
                            size="sm"
                            disabled={busy}
                            onClick={() => {
                                const runAt = futureIso(
                                    approveAt,
                                    siteTimezone,
                                );

                                if (runAt === null) {
                                    setApproveError(SCHEDULE_WORDS.past);
                                    document
                                        .getElementById('editor-approve-at')
                                        ?.focus();

                                    return;
                                }

                                actions.approve(runAt);
                                setApprovingLater(false);
                            }}
                        >
                            Approve and schedule
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => setApprovingLater(false)}
                        >
                            Not now
                        </Button>
                    </div>
                </div>
            )}

            {pending !== null && canPublish && returning && (
                <form
                    className="grid gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        actions.sendBack(note);
                        setReturning(false);
                        setNote('');
                    }}
                >
                    <label htmlFor="editor-return-note" className="text-xs">
                        What needs to change? The writer sees this.
                    </label>
                    <textarea
                        id="editor-return-note"
                        required
                        maxLength={2000}
                        rows={3}
                        value={note}
                        onChange={(event) => setNote(event.target.value)}
                        onKeyDown={(event) => event.stopPropagation()}
                        className="border-edge bg-surface focus-visible:ring-ring rounded-md border px-2 py-1.5 text-[13px] outline-none focus-visible:ring-2"
                    />
                    <div className="flex gap-2">
                        <Button
                            type="submit"
                            size="sm"
                            disabled={busy || note.trim() === ''}
                        >
                            Return to the writer
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => setReturning(false)}
                        >
                            Not now
                        </Button>
                    </div>
                </form>
            )}

            {review?.state === 'returned' && (
                <div className="bg-warn-soft rounded-md px-2 py-1.5 text-xs">
                    <p className="font-medium">Returned for changes</p>
                    <p className="whitespace-pre-line">{review.note}</p>
                </div>
            )}

            {notice && (
                <p role="status" className="text-xs">
                    {notice}
                </p>
            )}
        </div>
    );
}

/** The pane's body, rendered once into a node that moves between the rail and the larger dialog, so its state survives (DS 4.39). */
function PaneBody({
    pane,
    large,
    railSlot,
    dialogSlot,
}: {
    pane: RailPane;
    large: boolean;
    railSlot: HTMLElement | null;
    dialogSlot: HTMLElement | null;
}) {
    const [host] = useState(() => document.createElement('div'));

    useLayoutEffect(() => {
        const slot = large ? dialogSlot : railSlot;

        slot?.appendChild(host);

        return () => {
            host.remove();
        };
    }, [host, large, railSlot, dialogSlot]);

    return createPortal(pane.render(), host);
}

/**
 * One pane in the rail (docs/architecture/design-system.md, 4.39): a
 * sticky head with Back, the title (focused as the pane opens, unless it
 * opened from the text, as a selected card's does), the plug mark for a
 * plugin's, "Open larger" where the pane offers it, and Close. Open larger
 * moves the same body into a large dialog and back, its state kept.
 */
function PaneView({
    pane,
    noun,
    onBack,
}: {
    pane: RailPane;
    noun: string;
    onBack: () => void;
}) {
    const [large, setLarge] = useState(false);
    const [railSlot, setRailSlot] = useState<HTMLElement | null>(null);
    const [dialogSlot, setDialogSlot] = useState<HTMLElement | null>(null);
    const title = useRef<HTMLHeadingElement>(null);
    const larger = useRef<HTMLButtonElement>(null);
    const plugin = pane.owner !== 'core';

    useEffect(() => {
        const fromText =
            document.activeElement?.closest('.ProseMirror') !== null &&
            document.activeElement?.closest('.ProseMirror') !== undefined;

        if (!fromText) {
            title.current?.focus();
        }
    }, []);

    return (
        <div className="flex min-h-full flex-col">
            <div
                data-slot="pane-head"
                className="bg-chrome sticky top-0 z-(--z-sticky) flex h-14 shrink-0 items-center gap-1 px-2"
            >
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    aria-label={__('Back to the :noun settings', { noun })}
                    title={__('Back to the :noun settings', { noun })}
                    onClick={onBack}
                >
                    <ArrowLeft />
                </Button>
                <h2
                    ref={title}
                    tabIndex={-1}
                    className="text-body focus-visible:ring-ring min-w-0 flex-1 truncate rounded-sm font-semibold outline-none focus-visible:ring-2"
                >
                    {pane.title}
                </h2>
                {plugin && <PluginMark name={pane.owner} iconOnly />}
                {pane.openLarger === true && (
                    <Button
                        ref={larger}
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => setLarge(true)}
                    >
                        {__('Open larger')}
                    </Button>
                )}
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    aria-label={__('Close :name', { name: pane.title })}
                    title={__('Close :name', { name: pane.title })}
                    onClick={onBack}
                >
                    <X />
                </Button>
            </div>
            <div ref={setRailSlot} className="min-h-0 flex-1 pb-6" />
            <PaneBody
                pane={pane}
                large={large}
                railSlot={railSlot}
                dialogSlot={dialogSlot}
            />
            {pane.openLarger === true && (
                <Dialog open={large} onOpenChange={setLarge}>
                    <DialogContent
                        size="xl"
                        onCloseAutoFocus={(event) => {
                            event.preventDefault();
                            larger.current?.focus();
                        }}
                    >
                        <div className="flex items-center gap-2 pe-10">
                            <DialogTitle className="min-w-0 flex-1 truncate">
                                {pane.title}
                            </DialogTitle>
                            {plugin && <PluginMark name={pane.owner} />}
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => setLarge(false)}
                            >
                                {__('Back to the pane')}
                            </Button>
                        </div>
                        <DialogDescription className="sr-only">
                            {pane.title}
                        </DialogDescription>
                        <ScrollArea className="min-h-0 flex-1">
                            <div ref={setDialogSlot} />
                        </ScrollArea>
                    </DialogContent>
                </Dialog>
            )}
        </div>
    );
}

/**
 * The editor's rail: a pinned action bar over labelled sections, or one
 * pane in their place (docs/architecture/admin-design.md, Layout). Publish
 * never collapses (I-230); the others may. Delete sits last, behind a rule.
 */
export function EditorRail({
    session,
    editor,
    cards,
    siteUrl,
    typeSegment,
    status,
    publishedAt,
    slug,
    onChangeSlug,
    saveDisabled,
    onSave,
    onPreview,
    actions,
    busy,
    schedule,
    review,
    siteTimezone,
    notice,
    checks,
    onGoToCheck,
    onGoToSearch,
    chosenTerms,
    onTermsChange,
    primaryCategory,
    onOpenHistory,
    template,
    onChangeTemplate,
    discussion,
    onChangeDiscussion,
    visibility,
    readerPassword,
    passwordUnreadable,
    onChangeVisibility,
    themeRegion = false,
    unpublishedChanges,
    author,
    authors,
    onChangeAuthor,
    noun,
    addressPattern,
    url,
}: {
    session: EditorSession;
    editor: Editor | null;
    cards: CardManifestEntry[];
    siteUrl: string;
    typeSegment: string | null;
    status: string;
    publishedAt: string | null;
    slug: string;
    onChangeSlug: (slug: string) => Promise<string | null>;
    saveDisabled: boolean;
    onSave: () => void;
    /** Saves, then opens the latest saved version in its own tab; null for a theme region, which has no page. */
    onPreview: (() => void) | null;
    actions: PublishingActions;
    busy: boolean;
    schedule: EditorSchedule | null;
    review: EditorReview | null;
    siteTimezone: string;
    notice: string | null;
    checks: CoreCheck[];
    onGoToCheck: (check: CheckId) => void;
    /** Goes to Search and social under the text; undefined for a theme's region, which has none. */
    onGoToSearch?: () => void;
    chosenTerms: ChosenTerm[];
    onTermsChange: (next: ChosenTerm[]) => void;
    /** The primary category's choice, drawn under the first hierarchical taxonomy (I-881). */
    primaryCategory?: ReactNode;
    onOpenHistory: () => void;
    /** The chosen template, '' for the default; null when the type has no template field. */
    template: string | null;
    onChangeTemplate: (value: string) => void;
    /** The Discussion row (I-687); null while comments are off or for a type that takes none. */
    discussion: { value: Discussion; closeAfterDays: number } | null;
    onChangeDiscussion: (value: Discussion) => Promise<string | null>;
    /** Who may read the item, and the password for whoever may set it (I-1700). */
    visibility: Visibility;
    readerPassword: string | null;
    passwordUnreadable: boolean;
    onChangeVisibility: (
        next: Visibility,
        password: string | null,
    ) => Promise<VisibilityRefusal | null>;
    /** A theme region, whose Publish panel keeps only its status, schedule and revisions. */
    themeRegion?: boolean;
    /** Whether a live item has changes readers don't see yet (I-1316). */
    unpublishedChanges: boolean;
    /** The item's author: the session's, or the one just chosen. */
    author: EditorPerson | null;
    /** The people the Author select offers; null for someone who may not change who wrote it. */
    authors: EditorAuthorChoice[] | null;
    /** Saves the author at once; answers the refusal's reason, or null. */
    onChangeAuthor: (id: number) => Promise<string | null>;
    /** The type's singular in lower case. */
    noun: string;
    /** The address pattern: it follows a change of author. */
    addressPattern: string;
    /** The live address, for View on site; null while it has none. */
    url: string | null;
}) {
    const { pane, leavePane } = useRail();
    const [when, setWhen] = useState<WhenChoice>(() => ({
        later: false,
        at: defaultAt(siteTimezone),
        error: null,
    }));

    // A decision that lands puts the choice back to Immediately; a cancelled
    // confirmation leaves it as it was.
    useEffect(() => {
        setWhen((current) => ({ ...current, later: false, error: null }));
    }, [status, schedule?.runAt]);

    useCardPane(editor, cards);

    if (pane !== null) {
        return (
            <PaneView
                key={pane.id}
                pane={pane}
                noun={noun}
                onBack={leavePane}
            />
        );
    }

    return (
        <div className="flex min-h-full flex-col">
            <ActionBar
                session={session}
                status={status}
                schedule={schedule}
                review={review}
                siteTimezone={siteTimezone}
                saveDisabled={saveDisabled}
                onSave={onSave}
                onPreview={onPreview}
                actions={actions}
                busy={busy}
                notice={notice}
                when={when}
                onWhenChange={setWhen}
            />
            <PublishPanel
                status={status}
                review={review}
                unpublishedChanges={unpublishedChanges}
                publishedAt={publishedAt}
                slug={slug}
                addressPattern={addressPattern}
                siteUrl={siteUrl}
                url={url}
                templates={session.templates}
                template={template}
                onChangeTemplate={onChangeTemplate}
                themeRegion={themeRegion}
                author={author}
                authors={authors}
                onChangeAuthor={onChangeAuthor}
                noun={noun}
                revisionCount={session.revisionCount}
                canEdit={session.can.edit}
                onChangeSlug={onChangeSlug}
                onOpenHistory={onOpenHistory}
                schedule={schedule}
                siteTimezone={siteTimezone}
                discussion={discussion}
                canSetDiscussion={session.can.publish}
                onChangeDiscussion={onChangeDiscussion}
                visibility={visibility}
                readerPassword={readerPassword}
                passwordUnreadable={passwordUnreadable}
                canSetVisibility={session.can.publish}
                onChangeVisibility={onChangeVisibility}
                canPublish={session.can.publish}
                when={when}
                onWhenChange={setWhen}
                onCancelSchedule={actions.cancelSchedule}
                busy={busy}
            />
            <TaxonomyPanel
                taxonomies={session.taxonomies}
                chosen={chosenTerms}
                onChange={onTermsChange}
                canAdd={session.can.manageTerms}
                primaryCategory={primaryCategory}
            />
            <ChecksPanel
                checks={checks}
                onGoTo={onGoToCheck}
                onGoToSearch={onGoToSearch}
                noun={noun}
            />
            {session.can.delete && typeSegment !== null && (
                <div className="border-line mt-auto border-t px-4 py-4">
                    <Form
                        {...ContentActionController.action.form({
                            type: typeSegment,
                            content: session.id,
                            action: 'trash',
                        })}
                    >
                        {({ errors }) => (
                            <>
                                <Button
                                    type="submit"
                                    variant="ghost"
                                    size="sm"
                                    className="text-danger-ink"
                                >
                                    Move to trash
                                </Button>
                                <InputError message={errors.action} />
                            </>
                        )}
                    </Form>
                </div>
            )}
        </div>
    );
}
