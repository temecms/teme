import { Head, http, usePage } from '@inertiajs/react';
import type { Editor as TiptapEditor } from '@tiptap/core';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { CSSProperties } from 'react';
import { flushSync } from 'react-dom';
import { toast } from 'sonner';
import ContentEditorController from '@/actions/App/Http/Admin/Contents/ContentEditorController';
import { CardEnvironmentContext } from '@/components/editor/cards/card-environment';
import type { CardEnvironment } from '@/components/editor/cards/card-environment';
import ReviewController from '@/actions/App/Http/Admin/Contents/ReviewController';
import ScheduleController from '@/actions/App/Http/Admin/Contents/ScheduleController';
import {
    addressRefusalOf,
    post,
    reasonOf,
    send,
} from '@/components/editor/editor-http';
import type { Answer, Route } from '@/components/editor/editor-http';
import { coreTools, EditorHeader } from '@/components/editor/editor-header';
import type { ToolPanes } from '@/components/editor/editor-header';
import { pluginTools } from '@/components/editor/plugin-panels/plugin-tools';
import { usePanelApply } from '@/components/editor/plugin-panels/use-panel-apply';
import { offeredTools } from '@/components/editor/tools/editor-tools';
import {
    EditorRail,
    RailScope,
    useRailPanes,
} from '@/components/editor/editor-rail';
import type { PublishingActions } from '@/components/editor/editor-rail';
import { FeaturedImage } from '@/components/editor/featured-image';
import { DetailsSection } from '@/components/editor/fields/details-section';
import { FieldControlContext } from '@/components/editor/fields/field-control';
import { TextareaControl } from '@/components/editor/fields/textarea-control';
import {
    applyLinkChanges,
    linkChangesNotice,
} from '@/components/editor/link-changes';
import { LeaveDialog } from '@/components/editor/leave-dialog';
import { PresenceLine } from '@/components/editor/presence-line';
import { ProseEditor } from '@/components/editor/prose-editor';
import { PublishConfirmation } from '@/components/editor/publish-confirmation';
import { searchToCheck } from '@/components/editor/rail/search-box';
import type { Confirming } from '@/components/editor/publish-confirmation';
import {
    cardsMissingAlt,
    documentChecks,
    featuredMissingAlt,
    regionFacts,
} from '@/components/editor/rail/checks';
import { writingChecks } from '@/components/editor/rail/writing-checks';
import type { CheckId } from '@/components/editor/rail/checks';
import { AssistantProvider } from '@/components/editor/assistant/assistant-context';
import { DocumentOverview } from '@/components/editor/rail/document-panel';
import { AssistantPanel } from '@/components/editor/rail/assistant-panel';
import { HistoryPanel } from '@/components/editor/rail/history-panel';
import { MetadataProposal } from '@/components/editor/rail/metadata-proposal';
import { PrimaryCategory } from '@/components/editor/rail/primary-category';
import { addressOf } from '@/components/editor/rail/publish-panel';
import { SearchPanel } from '@/components/editor/rail/search-panel';
import type { ChosenTerm } from '@/components/editor/rail/taxonomy-panel';
import { templateChoice } from '@/components/editor/rail/template-choice';
import { useMetadataBinding } from '@/components/editor/rail/use-metadata';
import { SaveState } from '@/components/editor/save-state';
import { SharePane } from '@/components/editor/share-panel';
import { StaleDialog } from '@/components/editor/stale-dialog';
import { LanguageChip } from '@/components/editor/language-chip';
import { StatusChip } from '@/components/editor/status-chip';
import { useAutosave } from '@/components/editor/use-autosave';
import { useElevated } from '@/components/editor/use-elevated';
import { usePresence } from '@/components/editor/use-presence';
import { useCardMedia } from '@/components/editor/use-card-media';
import { useLinkedContent } from '@/components/editor/use-linked-content';
import { useSearchPreview } from '@/components/editor/use-search-preview';
import { useStructuredData } from '@/components/editor/use-structured-data';
import { usePluginChecks } from '@/components/editor/use-plugin-checks';
import { pluginFieldId } from '@/components/editor/search-social/plugin-tab';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/list/page-header';
import { EditorPage } from '@/components/patterns/editor-page';
import type { PaletteCommand } from '@/components/palette/palette-context';
import { usePaletteCommands } from '@/components/palette/use-palette';
import { useSidebar } from '@/components/ui/sidebar';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { __ } from '@/lib/i18n';
import { useIsMobile } from '@/hooks/use-mobile';
import {
    index as contentIndex,
    preview as contentPreview,
} from '@/routes/content';
import { index as regionsIndex } from '@/routes/regions';
import type { Discussion } from '@/lib/discussion';
import type {
    Visibility,
    VisibilityRefusal,
} from '@/components/editor/rail/publish-panel';
import { withoutGoneCanonical } from '@/lib/search-preview';
import { shownTitle } from '@/lib/titles';
import type {
    ContentEditPage,
    EditorDocument,
    EditorField,
    EditorPerson,
    EditorReview,
    EditorSchedule,
    PluginCheckResult,
} from '@/types';

/** The field of this type with the given handle, when the type has it. */
function fieldBy(
    fields: EditorField[],
    handle: string,
): EditorField | undefined {
    return fields.find((field) => field.handle === handle);
}

/** A text field's value, or nothing. */
function textOf(value: unknown): string {
    return typeof value === 'string' ? value : '';
}

/** The title's and the excerpt's focus bar sits 14 pixels before their start (docs/architecture/design-system.md, 4.39). */
const FOCUS_BAR = { '--focus-bar': '14px' } as CSSProperties;

/** The one toast a failed autosave shows; a new failure replaces it. */
const SAVE_NOTICE = 'editor-save-failure';

/** Preview's own sentences, on the action bar's notice line. */
const PREVIEW_WORDS = {
    notSaved:
        'Your work couldn’t be saved, so the preview didn’t open. The save state says why.',
    blocked:
        'The preview couldn’t open a new tab. Let this site open tabs, then try again.',
};

/**
 * The editor screen (slice 1.5c): the writing column and the rail on the
 * EditorPage pattern. It opens on the person's own unsaved work when there
 * is some, the working revision otherwise.
 */
export default function ContentEdit(page: ContentEditPage) {
    const { session, fields } = page;
    const templateField = fieldBy(fields, 'template');
    const { props: shared, version: inertiaVersion } = usePage();
    const { contentTypes, preferences } = shared;
    const spellcheck = preferences['editor.spellcheck'] !== false;
    const type = contentTypes.find((item) => item.key === session.type);
    const isMobile = useIsMobile();
    const rail = useRailPanes();
    const { pane, openPane, closePane } = rail;
    // A theme's region (1.6e) opens in its own mode: no title, address,
    // fields or sharing, and menus offering only its allowed cards. Named
    // "theme region" here, apart from the editor's own regions (region-entry.ts).
    const themeRegion = page.kind === 'region' ? page.themeRegion : null;
    const allowed = page.allowedCards;
    // Custom HTML is offered only to someone who may write its code.
    const menuCards =
        allowed === null
            ? undefined
            : page.manifest.cards.filter(
                  (card) =>
                      allowed.includes(card.name) &&
                      (card.name !== 'site-code' ||
                          (page.canManageSiteCode === true &&
                              page.siteCodeLocked !== true)),
              );
    // A plugin's card is allowed by its key, "{code}:{id}" (docs/guides/developers/plugins/cards.md).
    const menuPluginCards =
        allowed === null
            ? undefined
            : page.manifest.pluginCards.filter((card) =>
                  allowed.includes(card.key),
              );
    // A theme's own card, by its key, only in that theme's regions (docs/guides/developers/themes/regions.md).
    const menuThemeCards =
        allowed === null
            ? []
            : page.manifest.themeCards.filter((card) =>
                  allowed.includes(card.key),
              );

    const [title, setTitle] = useState(session.title);
    // A canonical naming an item gone from the site is let go as the editor
    // opens: the server refuses to save one, which would stop every save (I-292).
    const [values, setValues] = useState<Record<string, unknown>>(() =>
        withoutGoneCanonical(session.fields, page.seo.canonicalItem),
    );
    const [body, setDocument] = useState<EditorDocument>(session.document);
    const [chosenTerms, setChosenTerms] = useState<ChosenTerm[]>(session.terms);
    const termIds = useMemo(
        () => chosenTerms.map((term) => term.id),
        [chosenTerms],
    );
    const [editor, setEditor] = useState<TiptapEditor | null>(null);
    const [status, setStatus] = useState(session.status);
    const [publishedAt, setPublishedAt] = useState(session.publishedAt);
    const [author, setAuthor] = useState(session.author);
    const [addressPattern, setAddressPattern] = useState(
        session.addressPattern,
    );
    const [slug, setSlug] = useState(session.slug);
    const [savedSlug, setSavedSlug] = useState(session.slug);
    const [slugProblem, setSlugProblem] = useState<string | null>(null);
    const [discussion, setDiscussion] = useState(page.discussion);
    const [visibility, setVisibility] = useState(session.visibility);
    const [readerPassword, setReaderPassword] = useState(
        session.readerPassword,
    );
    const [passwordUnreadable, setPasswordUnreadable] = useState(
        session.passwordUnreadable,
    );
    const [busy, setBusy] = useState(false);
    const [schedule, setSchedule] = useState<EditorSchedule | null>(
        session.schedule,
    );
    const [review, setReview] = useState<EditorReview | null>(session.review);
    const [notice, setNotice] = useState<string | null>(null);
    // Each field's own error from a refused publish or schedule, by its key.
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const { media, loading, addMedia } = useCardMedia(session.media);
    const [railOpen, setRailOpen] = useState(true);
    // Search and social starts closed on every visit (I-3124).
    const [searchOpen, setSearchOpen] = useState(false);
    // The box's tab shown, so a check can lead to its field (I-3415).
    const [searchTab, setSearchTab] = useState('search');
    const [focus, setFocus] = useState(
        preferences['editor.focus_mode_default'] === true,
    );

    // The cards, the featured image and the rail's inspector share one map.
    const environment = useMemo<CardEnvironment>(
        () => ({
            media,
            loading,
            manifest: page.manifest,
            addMedia,
            // Every upload made here records this item (I-553).
            uploadedFor: {
                id: session.id,
                noun:
                    themeRegion !== null
                        ? 'region'
                        : (type?.singular ?? 'item').toLowerCase(),
            },
        }),
        [
            media,
            loading,
            page.manifest,
            addMedia,
            session.id,
            themeRegion,
            type,
        ],
    );

    // The editor folds the admin menu to its icons as it opens, unless the
    // person unfolded it here: a change made here is remembered in the
    // editor's own cookie, and every other screen opens as the person left it.
    const { setOpen: setSidebarOpen, rememberIn } = useSidebar();

    useEffect(() => {
        // The person's own choice, read as the server reads it, never the
        // menu's state now: from one editor to another the editor remounts,
        // and the menu is then still folded by the editor before it.
        const was = !/(?:^|;\s*)sidebar_state=false(?:;|$)/.test(
            document.cookie,
        );
        rememberIn('editor_sidebar_state');
        setSidebarOpen(
            /(?:^|;\s*)editor_sidebar_state=true(?:;|$)/.test(document.cookie),
            { persist: false },
        );

        return () => {
            rememberIn(null);
            setSidebarOpen(was, { persist: false });
        };
        // Only on arriving and leaving; the person may unfold it meanwhile.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // A small screen opens on the writing column; the rail is a sheet.
    useEffect(() => {
        if (isMobile) {
            setRailOpen(false);
        }
    }, [isMobile]);

    // Focus mode hides the admin's chrome, and brings it back on the way out.
    useEffect(() => {
        document.documentElement.classList.toggle('editor-focus', focus);

        return () => document.documentElement.classList.remove('editor-focus');
    }, [focus]);

    // A change to Custom HTML code asks for the password in place, on a save and on a publish.
    const elevated = useElevated();
    const saving = useAutosave({
        contentId: session.id,
        askPassword: elevated.ask,
        title,
        document: body,
        fields: values,
        terms: termIds,
        basedOn: session.workingRevisionId,
        idleMs: page.editor.autosaveIdleMs,
        maxMs: page.editor.autosaveMaxMs,
        checkpointAfterMinutes: page.editor.checkpointAfterMinutes,
        initialSavedAt: session.savedAt,
        initialOthers: session.otherEditors,
        hasAutosave: session.fromAutosave,
        // Pending while the typed address differs from the saved one; an
        // emptied field is sent as '' and takes the server's address on Save.
        sidePending: slug !== savedSlug,
        // A first title may have given the draft its address (I-425). Never
        // overwrite a pending typed address the person has not saved yet.
        onSlug: (next, sent) => {
            // A save that carried the typed address (even an emptied one)
            // shows what the server stored; otherwise only an untouched field
            // follows it.
            setSlug((current) =>
                current === savedSlugRef.current || current === sent
                    ? next
                    : current,
            );
            setSavedSlug(next);
            setSlugProblem(null);
        },
        onSlugRefused: (message) => {
            setSlugProblem(message);
            toast.error(message);
        },
        // The server stored some links differently (I-452): the editor
        // shows what is saved, and says which links it removed.
        onLinksChanged: (changes) => {
            const tr =
                editor === null || editor.isDestroyed
                    ? null
                    : applyLinkChanges(editor.state, changes);

            if (editor !== null && tr !== null) {
                editor.view.dispatch(tr);
            }

            const note = linkChangesNotice(changes);

            if (note !== null) {
                toast.info(note, { duration: 10_000 });
            }
        },
    });
    const {
        setOtherEditors,
        checkpoint: checkpointOnly,
        workingRevisionId,
    } = saving;
    const savedSlugRef = useRef(savedSlug);
    savedSlugRef.current = savedSlug;
    const live = status === 'published';

    /**
     * A revision save. Draft Save draft may carry a pending address; preview,
     * share, plugins, Ctrl+S on a live post, and the gather before Update
     * never do — a live address moves only with Update / Publish.
     */
    const checkpoint = async (
        withDraftAddress = false,
    ): Promise<string | null> => {
        const address =
            withDraftAddress && !live && slug !== savedSlug ? slug : undefined;

        return checkpointOnly(address);
    };

    // A failed autosave stays on screen as one notice until a save succeeds.
    // One the person closed comes back only for a different failure.
    const failure = saving.failure;
    const noticeShown = useRef<string | null>(null);

    useEffect(() => {
        if (failure === null) {
            if (noticeShown.current !== null) {
                toast.dismiss(SAVE_NOTICE);
                noticeShown.current = null;
            }

            return;
        }

        if (failure.title === null) {
            return;
        }

        const key = `${failure.title}\n${failure.description ?? ''}`;

        if (noticeShown.current === key) {
            return;
        }

        noticeShown.current = key;
        toast.error(failure.title, {
            id: SAVE_NOTICE,
            description: failure.description,
            duration: Infinity,
            closeButton: true,
        });
    }, [failure]);

    // The notice is this editor's: it goes when the editor is left.
    useEffect(() => () => void toast.dismiss(SAVE_NOTICE), []);
    // Whether a live item has changes readers don't see yet: the server's
    // word as the editor opens and after each decision, and anything typed,
    // saved or used from the assistant since (I-1316).
    const [unpublished, setUnpublished] = useState(session.unpublishedChanges);
    // The save time the server's word last held for; a decision moves it,
    // so a later re-run of the effect doesn't undo the answer.
    const openedSavedAt = useRef(saving.savedAt);
    const latestSavedAt = useRef(saving.savedAt);
    latestSavedAt.current = saving.savedAt;

    useEffect(() => {
        if (saving.pending || saving.savedAt !== openedSavedAt.current) {
            setUnpublished(true);
        }
    }, [saving.pending, saving.savedAt]);
    // What a plugin's panel may change in the open draft, as the person would.
    const panelApply = usePanelApply({
        editor,
        title,
        setTitle,
        values,
        setValues,
        chosenTerms,
        setChosenTerms,
        taxonomies: session.taxonomies,
        checkpoint,
        markStale: saving.markStale,
        unsaved: saving.pending,
    });
    const assistantSaving = useMemo(
        () => ({
            checkpoint,
            adopt: saving.adopt,
            markStale: saving.markStale,
        }),
        [checkpoint, saving.adopt, saving.markStale],
    );

    // What the metadata proposals need of the editor (I-1313).
    const metadata = useMetadataBinding({
        ai: page.ai,
        fields,
        values,
        setValues,
        saving: assistantSaving,
    });

    // The assistant pane, from its icon, Ctrl/⌘ J, or an ask (I-238).
    const ai = page.ai;
    const openAssistant = useCallback(() => {
        if (ai === null) {
            return;
        }

        setRailOpen(true);
        openPane({
            id: 'assistant',
            owner: 'core',
            title: __('Assistant'),
            render: () => <AssistantPanel ai={ai} />,
        });
    }, [ai, openPane]);

    // The structured-data check follows the working revision. The hook keeps
    // it in a ref, so a save settling is when it can have changed: an explicit
    // save, the periodic checkpoint, a publish (I-703).
    const [revision, setRevision] = useState(session.workingRevisionId);

    useEffect(() => {
        setRevision(workingRevisionId());
    }, [saving.savedAt, saving.state, workingRevisionId]);

    const structured = useStructuredData(session.id, revision);
    // The plugins' checks follow the same revision, never the typing (docs/guides/developers/plugins/seo.md).
    const pluginChecks = usePluginChecks(
        session.id,
        revision,
        themeRegion === null,
    );

    // Someone reading without typing still learns who else is here; an ended
    // session shows as signed out and never replaces the editor.
    usePresence({
        everyMs: page.editor.presenceWindow * 500,
        version: inertiaVersion,
        failure: saving.failure,
        onOthers: setOtherEditors,
        onSignedOut: saving.signedOut,
        onSignedIn: saving.signedIn,
    });

    // Ctrl+S or Cmd+S is an explicit save, as everywhere else; Ctrl/⌘ J
    // opens and closes the assistant.
    useEffect(() => {
        const keys = (event: KeyboardEvent) => {
            if ((event.ctrlKey || event.metaKey) && event.key === 's') {
                event.preventDefault();
                // Draft Save carries a pending address; a live Save does not.
                void checkpoint(!live);
            }

            if (
                (event.ctrlKey || event.metaKey) &&
                event.key.toLowerCase() === 'j' &&
                ai !== null
            ) {
                event.preventDefault();

                if (pane?.id === 'assistant') {
                    closePane('assistant');
                } else {
                    openAssistant();
                }
            }
        };

        window.addEventListener('keydown', keys);

        return () => window.removeEventListener('keydown', keys);
    }, [checkpoint, ai, pane, closePane, openAssistant]);

    // A new draft is a new choice, so a template the theme lacks would be
    // refused and the person's text lost with it; it is left out (I-443).
    const newDraftFields = (): Record<string, unknown> => {
        if (
            templateField === undefined ||
            templateChoice(session.templates, textOf(values[templateField.key]))
                .missing === null
        ) {
            return values;
        }

        const kept = { ...values };
        delete kept[templateField.key];

        return kept;
    };

    const saveAsDraft = async (): Promise<string | null> => {
        try {
            const response = await http.getClient().request({
                method: 'post',
                url: ContentEditorController.saveAsDraft(session.id).url,
                data: {
                    title,
                    document: body,
                    fields: newDraftFields(),
                    terms: termIds,
                },
                headers: { Accept: 'application/json' },
            });
            const created = JSON.parse(response.data) as { editUrl: string };
            // The person chose to take their work to a new draft.
            saving.release();
            window.location.assign(created.editUrl);

            return null;
        } catch {
            return 'The new draft could not be saved. Copy your text instead, so it is not lost.';
        }
    };

    // What is published, submitted or scheduled is what the person sees:
    // checkpoint() gathers a pending autosave into a revision first, and
    // resolves to null when that save was refused or stale, which the save
    // state and the stale dialog already show.
    const checkpointThen = async (
        run: (revisionId: string) => Promise<void>,
    ) => {
        const revisionId = await checkpoint();

        if (revisionId !== null) {
            await run(revisionId);
        }
    };

    // One publishing decision: sent as JSON, so the editor stays open and
    // keeps what is being typed, and the answer's state becomes the page's.
    const decide = async (
        route: Route,
        data: object,
        refused: string,
    ): Promise<Answer | null> => {
        setBusy(true);
        setNotice(null);

        try {
            let answer = await send(route, data);

            if (answer.status === 423 && (await elevated.ask())) {
                answer = await send(route, data);
            }

            // Someone else changed the item since this version: the dialog
            // that explains it, as for a save, not a notice.
            if (answer.status === 409 && answer.body.stale === true) {
                saving.markStale(answer.body);

                return null;
            }

            if (answer.status >= 400) {
                const message = addressRefusalOf(answer.body);

                if (message !== null) {
                    setSlugProblem(message);
                    setNotice(`${refused}: ${message}`);

                    return null;
                }

                setNotice(`${refused}: ${reasonOf(answer.body)}`);
                showFieldErrors(answer.body);

                return null;
            }

            setFieldErrors({});

            setStatus((answer.body.status as string) ?? status);
            setPublishedAt(
                (answer.body.publishedAt as string | null | undefined) ??
                    publishedAt,
            );

            if (typeof answer.body.unpublishedChanges === 'boolean') {
                openedSavedAt.current = latestSavedAt.current;
                setUnpublished(answer.body.unpublishedChanges);
            }

            if ('schedule' in answer.body) {
                setSchedule(answer.body.schedule as EditorSchedule | null);
            }

            if ('review' in answer.body) {
                setReview(answer.body.review as EditorReview | null);
            }

            if (typeof answer.body.message === 'string') {
                setNotice(answer.body.message);
            }

            return answer;
        } catch {
            setNotice(`${refused}: the connection failed. Try again.`);

            return null;
        } finally {
            setBusy(false);
        }
    };

    // Publishing saves first, then publishes the revision that save returned;
    // PublishRevision itself never saves. A pending address travels here so
    // Update applies it with the publish (and its redirect), never earlier.
    const publishRevision = async (revisionId: string) => {
        const data: Record<string, string> = { revision_id: revisionId };

        if (slug !== savedSlug) {
            data.slug = slug;
        }

        const answer = await decide(
            ContentEditorController.publish(session.id),
            data,
            'Not published',
        );

        if (answer === null) {
            return;
        }

        if (typeof answer.body.slug === 'string') {
            setSlug(answer.body.slug);
            setSavedSlug(answer.body.slug);
            setSlugProblem(null);
        }

        // A publish by hand ends any schedule; the answer says so.
        setSchedule(null);
        const missing = (answer.body.missingAlt as string[]) ?? [];
        setNotice(
            missing.length === 0
                ? 'Published.'
                : `Published. ${missing.length === 1 ? 'This image has' : 'These images have'} no alt text: ${missing.join(', ')}.`,
        );
    };

    const scheduleRevision = async (
        revisionId: string,
        runAt: string,
        action: 'publish' | 'update',
    ) => {
        await decide(
            ScheduleController.store(session.id),
            { revision_id: revisionId, action, run_at: runAt },
            'Not scheduled',
        );
    };

    const submitRevision = async (revisionId: string) => {
        await decide(
            ReviewController.store(session.id),
            { revision_id: revisionId },
            'Not submitted',
        );
    };

    // The pending review a decision is about.
    const reviewId = () => review?.id ?? '';

    // A first publication is confirmed (I-880): saved first, that revision's
    // structured data checked, then shown; its button acts on that revision.
    const [confirming, setConfirming] = useState<Confirming | null>(null);

    const confirmFirst = (
        action: Confirming['action'],
        runAt: string | null = null,
    ) =>
        void checkpointThen(async (revisionId) => {
            setBusy(true);
            await structured.ask(revisionId);
            setBusy(false);
            setConfirming({ action, revisionId, runAt });
        });

    const actions: PublishingActions = {
        publish: () =>
            live
                ? void checkpointThen(publishRevision)
                : confirmFirst('publish'),
        schedule: (runAt, action) =>
            live
                ? void checkpointThen((revisionId) =>
                      scheduleRevision(revisionId, runAt, action),
                  )
                : confirmFirst('schedule', runAt),
        cancelSchedule: () =>
            void decide(
                ScheduleController.destroy(session.id),
                {},
                'Not cancelled',
            ),
        submit: () =>
            live ? void checkpointThen(submitRevision) : confirmFirst('submit'),
        // A decision is on the revision that was submitted, never on what
        // is on this screen, so it saves nothing first, and never confirms.
        approve: (runAt) =>
            void decide(
                ReviewController.approve(session.id),
                runAt === undefined
                    ? { review_id: reviewId() }
                    : { review_id: reviewId(), run_at: runAt },
                'Not approved',
            ),
        sendBack: (note) =>
            void decide(
                ReviewController.sendBack(session.id),
                { review_id: reviewId(), note },
                'Not returned',
            ),
    };

    // Cancel publishes nothing; the revision saved before it opened stays, as any save does.
    const confirm = () => {
        if (confirming === null) {
            return;
        }

        const { action, revisionId, runAt } = confirming;
        setConfirming(null);

        void (action === 'publish'
            ? publishRevision(revisionId)
            : action === 'schedule'
              ? scheduleRevision(revisionId, runAt ?? '', 'publish')
              : submitRevision(revisionId));
    };

    /**
     * Commits a tidied address into unsaved changes. Empty means the server
     * will make the title's address on Save draft or Update. Never saved here.
     */
    const changeSlug = async (next: string): Promise<string | null> => {
        setSlug(next);
        setSlugProblem(null);

        return null;
    };

    // Saved at once, outside the revision, as the address is; a refusal puts
    // the row back as it was and says why under it.
    const changeDiscussion = async (
        next: Discussion,
    ): Promise<string | null> => {
        const before = discussion;

        if (before === null) {
            return null;
        }

        setDiscussion({ ...before, value: next });

        try {
            const answer = await post(
                ContentEditorController.discussion(session.id).url,
                { discussion: next },
            );

            if (answer.status >= 400) {
                setDiscussion(before);

                return reasonOf(answer.body);
            }

            return null;
        } catch {
            setDiscussion(before);

            return __('The connection failed. Try again.');
        }
    };

    // Saved at once, outside the revision, as Discussion is; the answer's
    // address goes with it, since a private item has none. A refusal names
    // the part of the row it belongs to.
    const changeVisibility = async (
        next: Visibility,
        password: string | null,
    ): Promise<VisibilityRefusal | null> => {
        try {
            const answer = await post(
                ContentEditorController.visibility(session.id).url,
                password === null
                    ? { visibility: next }
                    : { visibility: next, password },
            );

            if (answer.status >= 400) {
                const errors = answer.body.errors as
                    | Record<string, string[]>
                    | undefined;

                return {
                    field:
                        errors?.password !== undefined
                            ? 'password'
                            : 'visibility',
                    message: reasonOf(answer.body),
                };
            }

            setVisibility(answer.body.visibility as Visibility);
            setReaderPassword(
                (answer.body.readerPassword as string | null) ?? null,
            );
            setPasswordUnreadable(answer.body.passwordUnreadable === true);

            return null;
        } catch {
            return {
                field: 'visibility',
                message: __('The connection failed. Try again.'),
            };
        }
    };

    // Saved at once, outside the revision, as the address is; a refusal is
    // said under the row (UpdateContentDetails).
    const changeAuthor = async (next: number): Promise<string | null> => {
        try {
            const answer = await post(
                ContentEditorController.author(session.id).url,
                {
                    author_id: next,
                    expected_revision_id: workingRevisionId(),
                },
            );

            if (answer.status === 409) {
                return `Someone else changed this ${(type?.singular ?? 'item').toLowerCase()}. Reload it before changing the author.`;
            }

            if (answer.status >= 400) {
                return reasonOf(answer.body);
            }

            setAuthor((answer.body.author as EditorPerson | null) ?? null);
            // A pattern may build the address from the author.
            setAddressPattern(
                (answer.body.addressPattern as string | undefined) ??
                    addressPattern,
            );

            return null;
        } catch {
            return __('The connection failed. Try again.');
        }
    };

    const excerpt = fieldBy(fields, 'excerpt');
    const featured = fieldBy(fields, 'featured_image');
    const noun = (type?.singular ?? 'Item').toLowerCase();
    const plural = (type?.plural ?? 'Items').toLowerCase();
    const setValue = (key: string, value: unknown) => {
        setValues((current) => ({ ...current, [key]: value }));
        setFieldErrors((current) => {
            if (!(key in current)) {
                return current;
            }

            const { [key]: _cleared, ...rest } = current;

            return rest;
        });
    };

    // A 422's messages for fields go under each field, and the page scrolls
    // to the first; the save state already says it too.
    function showFieldErrors(body: Record<string, unknown>) {
        const errors = (body.errors ?? {}) as Record<string, string[]>;
        const found: Record<string, string> = {};

        for (const [name, messages] of Object.entries(errors)) {
            if (name.startsWith('fields.') && messages[0] !== undefined) {
                found[name.slice('fields.'.length)] = messages[0];
            }
        }

        setFieldErrors(found);
        const first = fields.find((field) => field.key in found);

        if (first !== undefined) {
            window.requestAnimationFrame(() =>
                document
                    .getElementById(`editor-field-${first.handle}`)
                    ?.scrollIntoView({ block: 'center' }),
            );
        }
    }

    const primaryField = fieldBy(fields, 'primary_category');
    const stickyField = fieldBy(fields, 'sticky');
    const sections = session.taxonomies.find(
        (taxonomy) => taxonomy.hierarchical,
    );

    const linked = useLinkedContent(session.linked, body);

    const checks = useMemo(
        () =>
            documentChecks({
                title,
                fields: values,
                document: body,
                media,
                linked,
            }),
        [title, values, body, media, linked],
    );

    const search = useSearchPreview({
        contentId: session.id,
        title,
        values,
        document: body,
        media,
        seo: page.seo,
        taxonomies: session.taxonomies,
        terms: chosenTerms,
        hasExcerptField: excerpt !== undefined,
        hasFeaturedImageField: featured !== undefined,
        documentChecks: checks,
        published: status === 'published',
        noun,
        plural,
        withheld: structured.withheld,
    });

    // The rail's writing checks, and how many of Search and social's want a look.
    const writing = useMemo(
        () =>
            writingChecks({
                title,
                document: body,
                checks,
                featuredMissingAlt: featuredMissingAlt(values, media),
                hasExcerptField: excerpt !== undefined,
                hasFeaturedImageField: featured !== undefined,
                noun,
            }),
        [title, body, checks, values, media, excerpt, featured, noun],
    );
    const searchCount = searchToCheck(search.checks);

    // A theme region's own facts, checked in place of a post's search checks (issue 529).
    const ownFacts = useMemo(
        () =>
            themeRegion === null
                ? undefined
                : regionFacts({
                      document: body,
                      media,
                      max: themeRegion.max,
                      allowed: allowed ?? [],
                      cardLabels: Object.fromEntries([
                          ...page.manifest.cards.map((card) => [
                              card.name,
                              card.label,
                          ]),
                          ...page.manifest.pluginCards.map((card) => [
                              card.key,
                              card.label,
                          ]),
                          ...page.manifest.themeCards.map((card) => [
                              card.key,
                              card.label,
                          ]),
                      ]),
                  }),
        [
            themeRegion,
            body,
            media,
            allowed,
            page.manifest.cards,
            page.manifest.pluginCards,
            page.manifest.themeCards,
        ],
    );

    // A new draft opens with the cursor in its empty title (I-425).
    useEffect(() => {
        if (themeRegion === null && session.title.trim() === '') {
            document.getElementById('editor-title')?.focus();
        }
        // Only on arriving.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Set by "Go to Search and social" on a phone, where the sheet must close first.
    const searchAfterSheet = useRef(false);

    /** Brings Search and social's heading to the top of the writing column and focuses it (I-3124). */
    const showSearch = useCallback(() => {
        const heading = document.getElementById('editor-search-label');

        heading?.scrollIntoView({ block: 'start' });
        heading?.focus({ preventScroll: true });
    }, []);

    /** From the side panel's Checks: Search and social under the text, open. */
    const goToSearch = () => {
        if (isMobile) {
            searchAfterSheet.current = true;
            setSearchOpen(true);
            setRailOpen(false);

            return;
        }

        flushSync(() => setSearchOpen(true));
        showSearch();
    };

    // Each check puts the cursor on its problem: the field, the first link
    // to an unreachable article, or the first card with an image that has no
    // alt text, which opens its inspector.
    // A plugin's check puts the cursor on the field it names: one of the
    // plugin's own in its Search and social tab, or one of the item's.
    const goToPlugin = (code: string, result: PluginCheckResult) => {
        const own = page.searchTabs.find((each) => each.code === code);

        if (result.ownField !== null && own !== undefined) {
            flushSync(() => {
                setSearchOpen(true);
                setSearchTab(own.key);
            });
            document
                .getElementById(pluginFieldId(own.key, result.ownField))
                ?.focus();

            return;
        }

        const searchFields: Record<string, string> = {
            seo_title: 'editor-seo-title',
            meta_description: 'editor-seo-description',
        };

        if (result.field === 'title' || result.field === 'excerpt') {
            goToCheck(result.field);
        } else if (result.field !== null && result.field in searchFields) {
            flushSync(() => {
                setSearchOpen(true);
                setSearchTab('search');
            });
            document.getElementById(searchFields[result.field])?.focus();
        } else if (result.field !== null) {
            document.getElementById(`editor-field-${result.field}`)?.focus();
        }
    };

    const goToCheck = (check: CheckId) => {
        if (check === 'title') {
            document.getElementById('editor-title')?.focus();
        } else if (check === 'excerpt') {
            document.getElementById('editor-excerpt')?.focus();
        } else if (check === 'featured') {
            document.getElementById('editor-featured-choose')?.focus();
        } else if (check === 'description' || check === 'title-width') {
            // The field is on Search and social's Search tab, which may be folded (I-3124).
            flushSync(() => {
                setSearchOpen(true);
                setSearchTab('search');
            });
            document
                .getElementById(
                    check === 'description'
                        ? 'editor-seo-description'
                        : 'editor-seo-title',
                )
                ?.focus();
        } else if (check === 'internal-links') {
            editor?.commands.focus();
        } else if (check === 'links' && editor !== null) {
            const [first] = checks.badLinks;
            const href = `teme:content/${first?.id ?? ''}`;
            let found: { pos: number; text: boolean; size: number } | null =
                null;

            editor.state.doc.descendants((node, pos) => {
                if (found !== null || first === undefined) {
                    return false;
                }

                if (
                    node.isText &&
                    node.marks.some(
                        (mark) =>
                            mark.type.name === 'link' &&
                            mark.attrs.href === href,
                    )
                ) {
                    found = { pos, text: true, size: node.nodeSize };
                } else if (
                    node.type.name === 'button' &&
                    node.attrs.contentId === first.id
                ) {
                    found = { pos, text: false, size: node.nodeSize };
                }

                return found === null;
            });

            if (found !== null) {
                const at = found as {
                    pos: number;
                    text: boolean;
                    size: number;
                };
                const chain = editor.chain().focus();

                (at.text
                    ? chain.setTextSelection({
                          from: at.pos,
                          to: at.pos + at.size,
                      })
                    : chain.setNodeSelection(at.pos)
                )
                    .scrollIntoView()
                    .run();
            }
        } else if (check === 'alt' && editor !== null) {
            const [first] = cardsMissingAlt(body, media);
            let found: number | null = null;

            editor.state.doc.descendants((node, pos) => {
                if (found === null && node.attrs.id === first) {
                    found = pos;
                }

                return found === null;
            });

            if (found !== null) {
                editor
                    .chain()
                    .focus()
                    .setNodeSelection(found)
                    .scrollIntoView()
                    .run();
            }
        }
    };

    // What each tool draws in its pane, and the tools this item offers.
    const panes: ToolPanes = {
        overview: () =>
            editor === null ? null : <DocumentOverview editor={editor} />,
        history: () => <HistoryPanel contentId={session.id} />,
        assistant: () => (ai === null ? null : <AssistantPanel ai={ai} />),
        share: () => (
            <SharePane contentId={session.id} saveFirst={checkpoint} />
        ),
    };
    // The plugins' panels: tools in the bar, each opening in the rail (docs/proposals/plugin-sockets-3.md).
    const panelTools = pluginTools(page.panels, session.id, panelApply);
    const tools = offeredTools([
        ...coreTools(panes, {
            assistant: ai !== null,
            canShare: session.can.edit && themeRegion === null,
        }),
        ...panelTools,
    ]);

    // A tool's pane. Asked for by name, so the rail opens with it, even
    // where it is a sheet.
    const openToolPane = (id: string) => {
        const tool = tools.find((each) => each.id === id);

        if (tool === undefined) {
            return;
        }

        setRailOpen(true);
        openPane({
            id: tool.id,
            owner: tool.owner,
            title: tool.label,
            openLarger: tool.openLarger,
            render: tool.render,
        });
    };

    // The History pane, from the header's icon or the revisions count.
    const openHistory = () => openToolPane('history');

    // Preview shows the latest saved version (content.preview), so what is
    // typed is saved first. The tab opens inside the click, where no pop-up
    // blocker stops it, and is pointed at the preview once the save is in;
    // a save that fails closes it, and the save state says why.
    const openPreview = () => {
        const tab = window.open('', `teme-preview-${session.id}`);

        if (tab === null) {
            setNotice(PREVIEW_WORDS.blocked);

            return;
        }

        const failed = () => {
            tab.close();
            setNotice(PREVIEW_WORDS.notSaved);
        };

        checkpoint()
            .then((revisionId) => {
                if (revisionId === null) {
                    failed();

                    return;
                }

                // The preview needs nothing of the editor.
                tab.opener = null;
                tab.location.href = contentPreview(session.id).url;
            })
            .catch(failed);
    };

    // A tool, by its id, from the top bar, Tools or the palette.
    const openTool = (id: string) => {
        if (id === 'history') {
            openHistory();
        } else if (id === 'assistant') {
            openAssistant();
        } else if (id === 'share') {
            openToolPane(id);
        } else if (id !== 'overview' || editor !== null) {
            openToolPane(id);
        }
    };

    // The palette's editor commands, registered while the editor is open.
    // They reach the latest openTool through a ref, so the list is made
    // once and the palette is not told of a change on every keystroke.
    const openToolRef = useRef(openTool);
    const canShareRef = useRef(false);
    const hasAssistantRef = useRef(false);

    useEffect(() => {
        openToolRef.current = openTool;
        canShareRef.current = session.can.edit && themeRegion === null;
        hasAssistantRef.current = ai !== null;
    });

    const paletteCommands = useMemo<PaletteCommand[]>(
        () => [
            {
                id: 'editor.history',
                owner: 'core',
                label: __('Open history'),
                description: __('Every saved version'),
                group: 'Do',
                order: 200,
                keywords: ['revisions', 'versions', 'compare', 'restore'],
                when: (context) => context.contentId !== null,
                run: () => openToolRef.current('history'),
            },
            {
                id: 'editor.outline',
                owner: 'core',
                label: __('Open the outline'),
                description: __('The headings and blocks, to move around'),
                group: 'Do',
                order: 201,
                keywords: ['outline', 'overview', 'headings', 'blocks'],
                when: (context) => context.contentId !== null,
                run: () => openToolRef.current('overview'),
            },
            {
                id: 'editor.share',
                owner: 'core',
                label: __('Open Share'),
                description: __('Preview links for people without an account'),
                group: 'Do',
                order: 202,
                keywords: ['share', 'preview', 'link'],
                when: (context) =>
                    context.contentId !== null && canShareRef.current,
                run: () => openToolRef.current('share'),
            },
            {
                id: 'editor.assistant',
                owner: 'core',
                label: __('Open the assistant'),
                description: __('Ask for a shorter version, a title and more'),
                group: 'Do',
                order: 203,
                keywords: ['assistant', 'ai', 'write'],
                when: (context) =>
                    context.contentId !== null && hasAssistantRef.current,
                run: () => openToolRef.current('assistant'),
            },
            {
                id: 'editor.focus',
                owner: 'core',
                label: __('Toggle focus mode'),
                description: __('Hide or show everything but the writing'),
                group: 'Do',
                order: 210,
                keywords: ['focus', 'distraction', 'chrome'],
                when: (context) => context.contentId !== null,
                run: () => setFocus((on) => !on),
            },
        ],
        [],
    );

    usePaletteCommands(paletteCommands);

    const address = addressOf(page.siteUrl, addressPattern, slug);
    const railTitle =
        themeRegion !== null
            ? __('Region settings')
            : __(':type settings', {
                  type: type?.singular ?? __('Item'),
              });

    return (
        <>
            {elevated.password}
            <Head
                title={
                    themeRegion !== null
                        ? __('Region: :label', { label: themeRegion.label })
                        : __('Edit: :title', {
                              title: shownTitle(session.title),
                          })
                }
            />
            <EditorPage
                around={(frame) => (
                    <RailScope value={rail}>
                        <AssistantProvider
                            contentId={session.id}
                            editor={editor}
                            saving={assistantSaving}
                            ai={ai}
                            onOpen={openAssistant}
                        >
                            <CardEnvironmentContext value={environment}>
                                {frame}
                            </CardEnvironmentContext>
                        </AssistantProvider>
                    </RailScope>
                )}
                focus={focus}
                header={
                    <EditorHeader
                        place={
                            themeRegion !== null
                                ? {
                                      label: __('Regions'),
                                      href: regionsIndex().url,
                                      type: null,
                                      current: session.id,
                                  }
                                : {
                                      label: type?.plural ?? __('Content'),
                                      href: type
                                          ? contentIndex({ type: type.segment })
                                                .url
                                          : '/admin',
                                      type: session.type,
                                      current: session.id,
                                  }
                        }
                        title={themeRegion !== null ? themeRegion.label : title}
                        region={themeRegion !== null}
                        railLabel={railTitle}
                        statusChip={
                            <>
                                <StatusChip
                                    status={status}
                                    review={review}
                                    schedule={schedule}
                                    unpublishedChanges={unpublished}
                                    visibility={visibility}
                                />
                                <LanguageChip session={session} />
                            </>
                        }
                        saveState={
                            <SaveState
                                state={saving.state}
                                pending={saving.pending}
                                savedAt={saving.savedAt}
                                failure={saving.failure}
                            />
                        }
                        presence={<PresenceLine people={saving.otherEditors} />}
                        activePane={pane?.id ?? null}
                        onTogglePane={(id) => {
                            if (pane?.id === id) {
                                closePane(id);
                            } else {
                                openTool(id);
                            }
                        }}
                        railOpen={railOpen}
                        onToggleRail={() => setRailOpen((open) => !open)}
                        focus={focus}
                        onToggleFocus={() => setFocus((on) => !on)}
                        assistant={ai !== null}
                        panes={panes}
                        pluginTools={panelTools}
                        // A preview shows a page, which a theme region never is.
                        canShare={session.can.edit && themeRegion === null}
                    />
                }
                rail={{
                    title: railTitle,
                    noun: themeRegion !== null ? 'region' : noun,
                    open: railOpen,
                    onOpenChange: setRailOpen,
                    onSheetCloseAutoFocus: (event) => {
                        if (searchAfterSheet.current) {
                            searchAfterSheet.current = false;
                            event.preventDefault();
                            showSearch();
                        }
                    },
                    content: (
                        <EditorRail
                            session={session}
                            editor={editor}
                            cards={page.manifest.cards}
                            siteUrl={page.siteUrl}
                            typeSegment={type?.segment ?? null}
                            status={status}
                            publishedAt={publishedAt}
                            slug={slug}
                            savedSlug={savedSlug}
                            title={title}
                            onChangeSlug={changeSlug}
                            slugProblem={slugProblem}
                            saveDisabled={saving.state === 'stale'}
                            onSave={() => void checkpoint(!live)}
                            onPreview={
                                themeRegion === null ? openPreview : null
                            }
                            actions={actions}
                            busy={busy}
                            schedule={schedule}
                            review={review}
                            siteTimezone={page.siteTimezone}
                            notice={notice}
                            writing={writing}
                            searchCount={searchCount}
                            regionFacts={ownFacts}
                            region={
                                themeRegion === null
                                    ? null
                                    : {
                                          themeName: themeRegion.themeName,
                                          inUse: themeRegion.inUse,
                                      }
                            }
                            onGoToWriting={(check) => {
                                if (check !== 'headings') {
                                    goToCheck(check);
                                }
                            }}
                            sticky={
                                stickyField === undefined ||
                                themeRegion !== null
                                    ? null
                                    : values[stickyField.key] === true
                            }
                            onChangeSticky={(on) => {
                                if (stickyField !== undefined) {
                                    setValue(stickyField.key, on);
                                }
                            }}
                            pluginChecks={pluginChecks}
                            onGoToPlugin={goToPlugin}
                            onGoToSearch={
                                themeRegion === null ? goToSearch : undefined
                            }
                            chosenTerms={chosenTerms}
                            onTermsChange={setChosenTerms}
                            primaryCategory={
                                primaryField !== undefined &&
                                sections !== undefined ? (
                                    <PrimaryCategory
                                        field={primaryField}
                                        taxonomy={sections}
                                        chosen={chosenTerms}
                                        value={values[primaryField.key]}
                                        onChange={setValue}
                                        seo={page.seo}
                                        noun={noun}
                                        title={title.trim() || __('Untitled')}
                                        published={status === 'published'}
                                    />
                                ) : undefined
                            }
                            onOpenHistory={openHistory}
                            template={
                                templateField
                                    ? textOf(values[templateField.key])
                                    : null
                            }
                            onChangeTemplate={(next) => {
                                if (templateField) {
                                    setValue(templateField.key, next);
                                }
                            }}
                            discussion={discussion}
                            onChangeDiscussion={changeDiscussion}
                            visibility={visibility}
                            readerPassword={readerPassword}
                            passwordUnreadable={passwordUnreadable}
                            onChangeVisibility={changeVisibility}
                            themeRegion={themeRegion !== null}
                            author={author}
                            authors={page.authors}
                            onChangeAuthor={changeAuthor}
                            noun={noun}
                            addressPattern={addressPattern}
                        />
                    ),
                }}
            >
                <FieldControlContext.Provider
                    value={{
                        media,
                        loading,
                        addMedia,
                        noun,
                        siteTimezone: page.siteTimezone,
                        entries: session.entries,
                    }}
                >
                    <div
                        data-slot="editor-sheet"
                        className="mx-auto grid w-full max-w-(--measure) grid-cols-1 gap-6"
                    >
                        {/* The page's one h1: an item's, for screen readers; a theme region's, drawn with what it takes (I-395). */}
                        {themeRegion === null ? (
                            <h1 className="sr-only">
                                {__('Edit: :title', {
                                    title: shownTitle(title),
                                })}
                            </h1>
                        ) : (
                            <PageHeader
                                title={__('Region: :label, used by :theme', {
                                    label: themeRegion.label,
                                    theme: themeRegion.themeName,
                                })}
                                description={themeRegion.takes}
                            />
                        )}
                        {themeRegion === null && (
                            <div className="group relative grid grid-cols-1 gap-1">
                                <Label
                                    htmlFor="editor-title"
                                    className="text-caption text-ink-3 sr-only font-semibold uppercase group-focus-within:not-sr-only"
                                >
                                    {__('Title')}
                                </Label>
                                <span
                                    aria-hidden
                                    className="bg-accent absolute inset-y-0 -start-(--focus-bar) w-0.5 opacity-0 group-focus-within:opacity-100"
                                    style={FOCUS_BAR}
                                />
                                <Textarea
                                    id="editor-title"
                                    spellCheck={spellcheck}
                                    aria-describedby="editor-title-error"
                                    rows={1}
                                    placeholder={__('Title')}
                                    value={title}
                                    onChange={(event) =>
                                        setTitle(event.target.value)
                                    }
                                    className="text-display field-sizing-content resize-none overflow-hidden border-0 bg-transparent px-0 py-0 font-serif font-semibold text-balance focus-visible:outline-0"
                                />
                                <InputError id="editor-title-error" />
                            </div>
                        )}

                        {excerpt && (
                            <div className="group relative grid grid-cols-1 gap-1">
                                <Label
                                    htmlFor="editor-excerpt"
                                    className="text-caption text-ink-3 sr-only font-semibold uppercase group-focus-within:not-sr-only"
                                >
                                    {excerpt.label}
                                </Label>
                                <span
                                    aria-hidden
                                    className="bg-accent absolute inset-y-0 -start-(--focus-bar) w-0.5 opacity-0 group-focus-within:opacity-100"
                                    style={FOCUS_BAR}
                                />
                                <TextareaControl
                                    field={excerpt}
                                    value={values[excerpt.key]}
                                    onChange={(next) =>
                                        setValue(excerpt.key, next)
                                    }
                                    id="editor-excerpt"
                                    spellCheck={spellcheck}
                                    placeholder={__(
                                        'Add an excerpt: one or two sentences that sell the story',
                                    )}
                                    className="text-lead text-ink-2 placeholder:text-ink-3 field-sizing-content w-full resize-none bg-transparent leading-normal outline-none focus-visible:outline-0"
                                />
                                <MetadataProposal
                                    handle="excerpt"
                                    label={excerpt.label}
                                    metadata={metadata}
                                />
                            </div>
                        )}

                        {featured && (
                            <FeaturedImage
                                field={featured}
                                value={values[featured.key]}
                                onChange={(next) =>
                                    setValue(featured.key, next)
                                }
                            />
                        )}

                        <section
                            aria-labelledby="editor-body-label"
                            className="grid grid-cols-1"
                        >
                            <span id="editor-body-label" className="sr-only">
                                {__('Body')}
                            </span>
                            <div data-slot="editor-body">
                                <ProseEditor
                                    document={session.document}
                                    cards={page.manifest.cards}
                                    menuCards={menuCards}
                                    menuPluginCards={menuPluginCards}
                                    menuThemeCards={menuThemeCards}
                                    contentType={session.type}
                                    status={status}
                                    permissions={session.can}
                                    spellcheck={spellcheck}
                                    smartQuotes={
                                        preferences['editor.smart_quotes'] !==
                                        false
                                    }
                                    siteUrl={page.siteUrl}
                                    onChange={(next) =>
                                        setDocument(next as EditorDocument)
                                    }
                                    onEditor={setEditor}
                                />
                            </div>
                        </section>

                        <DetailsSection
                            fields={fields}
                            values={values}
                            onChange={setValue}
                            errors={fieldErrors}
                            noun={noun}
                            plural={type?.plural ?? 'Items'}
                        />
                    </div>
                    {themeRegion === null && (
                        <SearchPanel
                            fields={fields}
                            values={values}
                            onChange={setValue}
                            seo={page.seo}
                            search={search}
                            address={address}
                            contentId={session.id}
                            noun={noun}
                            plural={plural}
                            media={media}
                            loading={loading}
                            addMedia={addMedia}
                            metadata={metadata}
                            open={searchOpen}
                            onOpenChange={setSearchOpen}
                            onGoToCheck={goToCheck}
                            tab={searchTab}
                            onTabChange={setSearchTab}
                            pluginTabs={page.searchTabs}
                            pluginChecks={pluginChecks}
                            onGoToPlugin={goToPlugin}
                        />
                    )}
                </FieldControlContext.Provider>
                <PublishConfirmation
                    confirming={confirming}
                    title={title.trim() || __('Untitled')}
                    noun={noun}
                    siteTimezone={page.siteTimezone}
                    checks={search.publishingChecks}
                    busy={busy}
                    onConfirm={confirm}
                    onCancel={() => setConfirming(null)}
                />
                <StaleDialog
                    stale={saving.stale}
                    title={title}
                    noun={themeRegion !== null ? 'region' : noun}
                    document={body}
                    // A second region row can't be made, so no new draft is offered.
                    onSaveAsDraft={
                        themeRegion === null ? saveAsDraft : undefined
                    }
                    onLeave={saving.release}
                />
                <LeaveDialog
                    open={saving.asking}
                    onStay={saving.stay}
                    onLeave={saving.leave}
                />
            </EditorPage>
        </>
    );
}
