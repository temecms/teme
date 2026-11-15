import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';
import { SelectField } from '@/components/ui/select-field';
import { choiceOf, closingNote, DISCUSSION_CHOICES } from '@/lib/discussion';
import type { Discussion } from '@/lib/discussion';
import type {
    EditorAuthorChoice,
    EditorPerson,
    EditorReview,
    EditorSchedule,
} from '@/types';
import { TextButton } from '@/components/ui/text-button';
import { __ } from '@/lib/i18n';
import { StatusChip } from '../status-chip';
import { PublishWhen } from './publish-when';
import type { WhenChoice } from './publish-when';
import { templateChoice } from './template-choice';
import type { ThemeTemplate } from './template-choice';

/** The panel's own sentences, as strings, so a formatter never splits them. */
const PUBLISH_WORDS = {
    public: 'Anyone can read it once it’s published.',
    publicLive: 'Anyone can read it.',
    authorLive: (noun: string) =>
        `Changing it changes the published ${noun} at once.`,
};

export type Visibility = 'public' | 'private' | 'protected';

/** Who may read the item, and what each choice means (I-1700). */
function visibilityChoices(): {
    value: Visibility;
    label: string;
    meaning: string;
}[] {
    return [
        { value: 'public', label: __('Public'), meaning: PUBLISH_WORDS.public },
        {
            value: 'private',
            label: __('Private'),
            meaning: __(
                'Only people who can edit it can read it, here in the admin. On the site its address answers “not found”.',
            ),
        },
        {
            value: 'protected',
            label: __('Password protected'),
            meaning: __(
                'Readers need the password to read it. Lists on the site show its title, without its excerpt or image.',
            ),
        },
    ];
}

/** What Discussion adds when the item can't take comments for its visibility. */
function visibilityNotes() {
    return {
        private: __('Private items never take comments.'),
        protected: __('Items protected by a password take no new comments.'),
    };
}

/** A refusal of the Visibility row: which part of it, and why. */
export type VisibilityRefusal = {
    field: 'visibility' | 'password';
    message: string;
};

/**
 * The people an item may be credited to, its current author kept even when
 * they may no longer write, as WordPress's author box keeps them.
 */
function authorChoices(
    authors: EditorAuthorChoice[],
    author: EditorPerson | null,
): EditorAuthorChoice[] {
    return author === null ||
        authors.some((choice) => choice.value === author.id)
        ? authors
        : [{ value: author.id, label: author.name }, ...authors];
}

/** The article's address on the site, worked out now and never stored (rule 9). */
export function addressOf(siteUrl: string, pattern: string, slug: string) {
    return siteUrl.replace(/\/$/, '') + pattern.replace('{slug}', slug);
}

/**
 * The article's summary, as Gutenberg's Summary panel holds it: status,
 * visibility, discussion, date, address, template, author and revisions. It
 * never collapses (I-230).
 */
export function PublishPanel({
    status,
    review,
    unpublishedChanges,
    publishedAt,
    slug,
    addressPattern,
    siteUrl,
    url,
    templates,
    template,
    onChangeTemplate,
    author,
    authors,
    onChangeAuthor,
    noun,
    revisionCount,
    canEdit,
    onChangeSlug,
    onOpenHistory,
    schedule,
    siteTimezone,
    canPublish,
    when,
    onWhenChange,
    onCancelSchedule,
    busy: deciding,
    discussion,
    canSetDiscussion,
    onChangeDiscussion,
    visibility,
    readerPassword,
    passwordUnreadable,
    canSetVisibility,
    onChangeVisibility,
    themeRegion = false,
}: {
    status: string;
    /** The pending or returned review, which the Status row's words may mention. */
    review: EditorReview | null;
    /** Whether a live item has changes readers don't see yet (I-1316). */
    unpublishedChanges: boolean;
    publishedAt: string | null;
    slug: string;
    addressPattern: string;
    siteUrl: string;
    /** The live address, for View on site; null while it has none. */
    url: string | null;
    /** The active theme's templates for the item's type (I-443). */
    templates: ThemeTemplate[];
    /** The chosen template, '' for the default; null when the type has no template field. */
    template: string | null;
    onChangeTemplate: (value: string) => void;
    author: EditorPerson | null;
    /** The people the Author select offers; null for someone who may not change who wrote it. */
    authors: EditorAuthorChoice[] | null;
    /** Saves the author at once; answers the refusal's reason, or null. */
    onChangeAuthor: (id: number) => Promise<string | null>;
    /** The type's singular in lower case, for "the published post". */
    noun: string;
    revisionCount: number;
    canEdit: boolean;
    onChangeSlug: (slug: string) => Promise<string | null>;
    onOpenHistory: () => void;
    schedule: EditorSchedule | null;
    siteTimezone: string;
    /** Whether this person may publish the item. */
    canPublish: boolean;
    /** The Publish row's choice: now, or a set time. */
    when: WhenChoice;
    onWhenChange: (next: WhenChoice) => void;
    /** Cancels the waiting schedule. */
    onCancelSchedule: () => void;
    /** Whether a publishing decision is being sent. */
    busy: boolean;
    /** The Discussion row (I-687); null while comments are off or for a type that takes none. */
    discussion: { value: Discussion; closeAfterDays: number } | null;
    /** Whether this person may publish the item, and so set its Discussion (I-571). */
    canSetDiscussion: boolean;
    /** Saves the Discussion at once; answers the refusal's reason, or null. */
    onChangeDiscussion: (value: Discussion) => Promise<string | null>;
    /** Who may read the item once it is published (I-1700). */
    visibility: Visibility;
    /** The password readers give, for whoever may set it; null otherwise. */
    readerPassword: string | null;
    /** Whether a protected item's password can't be read on this server. */
    passwordUnreadable: boolean;
    /** Whether this person may publish the item, and so set who reads it. */
    canSetVisibility: boolean;
    /** Saves the visibility at once; answers the refusal, or null. */
    onChangeVisibility: (
        next: Visibility,
        password: string | null,
    ) => Promise<VisibilityRefusal | null>;
    /** A theme region: no page of its own, so no visibility, address, template or author. */
    themeRegion?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState(slug);
    const [problem, setProblem] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [discussionSaving, setDiscussionSaving] = useState(false);
    const [authorSaving, setAuthorSaving] = useState(false);
    const [authorProblem, setAuthorProblem] = useState<string | null>(null);
    const [discussionProblem, setDiscussionProblem] = useState<string | null>(
        null,
    );
    // Password protected, chosen but not yet saved: nothing protects the item
    // until its button is pressed, so a half-typed password never does.
    const [pending, setPending] = useState(false);
    const [typed, setTyped] = useState(readerPassword ?? '');
    const [visibilitySaving, setVisibilitySaving] = useState(false);
    const [visibilityProblem, setVisibilityProblem] = useState<string | null>(
        null,
    );
    const [passwordProblem, setPasswordProblem] = useState<string | null>(null);
    const shown: Visibility = pending ? 'protected' : visibility;
    const visibilityOptions = visibilityChoices();
    const visibilityChoice =
        visibilityOptions.find((option) => option.value === shown) ??
        visibilityOptions[0];
    const visibilityMeaning =
        shown === 'public' && status === 'published'
            ? PUBLISH_WORDS.publicLive
            : visibilityChoice.meaning;
    const visibilityNote =
        visibility === 'public' ? null : visibilityNotes()[visibility];
    const saveVisibility = (next: Visibility, password: string | null) => {
        setVisibilitySaving(true);
        setVisibilityProblem(null);
        setPasswordProblem(null);
        void onChangeVisibility(next, password).then((refusal) => {
            setVisibilitySaving(false);

            if (refusal === null) {
                setPending(false);
                setTyped(password ?? '');

                return;
            }

            if (refusal.field === 'password') {
                setPasswordProblem(refusal.message);

                return;
            }

            setPending(false);
            setVisibilityProblem(refusal.message);
        });
    };
    const protectedAlready = visibility === 'protected';
    const passwordDiffers = typed !== (readerPassword ?? '');
    const discussionNote =
        discussion === null
            ? null
            : closingNote(
                  discussion.value,
                  status === 'published',
                  publishedAt,
                  discussion.closeAfterDays,
                  siteTimezone,
              );
    const choice = templateChoice(templates, template ?? '');
    const missingNote =
        choice.missing === null
            ? null
            : __(
                  "The template “:template” isn't in the current theme, so the default layout is used. It stays chosen, and comes back if the theme does.",
                  { template: choice.missing },
              );

    return (
        <Panel data-test="publish-section">
            <PanelHeader>
                <h2>{__('Publish')}</h2>
            </PanelHeader>
            <PanelBody>
                <dl className="text-small grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-2">
                    <dt className="text-ink-2">{__('Status')}</dt>
                    <dd className="min-w-0">
                        <StatusChip
                            named={false}
                            status={status}
                            review={review}
                            schedule={schedule}
                            unpublishedChanges={unpublishedChanges}
                            visibility={visibility}
                        />
                    </dd>
                    {!themeRegion && (
                        <>
                            <dt className="text-ink-2">{__('Visibility')}</dt>
                            <dd className="min-w-0">
                                {canSetVisibility ? (
                                    <>
                                        <SelectField
                                            id="editor-visibility"
                                            aria-label={__('Visibility')}
                                            aria-describedby={
                                                visibilityProblem !== null
                                                    ? 'editor-visibility-meaning editor-visibility-error'
                                                    : 'editor-visibility-meaning'
                                            }
                                            options={visibilityOptions.map(
                                                (option) => ({
                                                    value: option.value,
                                                    label: option.label,
                                                }),
                                            )}
                                            value={shown}
                                            disabled={visibilitySaving}
                                            onValueChange={(next) => {
                                                setVisibilityProblem(null);
                                                setPasswordProblem(null);

                                                if (next === 'protected') {
                                                    if (!protectedAlready) {
                                                        setPending(true);
                                                        setTyped('');
                                                    }

                                                    return;
                                                }

                                                setPending(false);

                                                if (next !== visibility) {
                                                    saveVisibility(
                                                        next as Visibility,
                                                        null,
                                                    );
                                                }
                                            }}
                                            className="h-[34px] w-full"
                                        />
                                        <p
                                            id="editor-visibility-meaning"
                                            className="text-ink-2 text-caption mt-1"
                                        >
                                            {visibilityMeaning}
                                            {protectedAlready &&
                                                passwordUnreadable &&
                                                ` ${__('Its password can’t be read on this server, so nobody can open the :noun. Set a new one.', { noun })}`}
                                        </p>
                                        {visibilityProblem !== null && (
                                            <p
                                                id="editor-visibility-error"
                                                role="alert"
                                                className="text-danger-ink text-caption mt-1"
                                            >
                                                {visibilityProblem}
                                            </p>
                                        )}
                                        {shown === 'protected' && (
                                            <div className="mt-2 grid gap-2">
                                                <Label htmlFor="editor-password">
                                                    {__('Password')}
                                                </Label>
                                                <Input
                                                    id="editor-password"
                                                    type="text"
                                                    autoComplete="off"
                                                    spellCheck={false}
                                                    maxLength={255}
                                                    value={typed}
                                                    disabled={visibilitySaving}
                                                    aria-invalid={
                                                        passwordProblem !== null
                                                            ? true
                                                            : undefined
                                                    }
                                                    aria-describedby={
                                                        passwordProblem !== null
                                                            ? 'editor-password-hint editor-password-error'
                                                            : 'editor-password-hint'
                                                    }
                                                    onChange={(event) =>
                                                        setTyped(
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                                <p
                                                    id="editor-password-hint"
                                                    className="text-ink-2 text-caption"
                                                >
                                                    {__(
                                                        'Give it to the people who should read this :noun. Changing it asks everyone for the new one.',
                                                        { noun },
                                                    )}
                                                </p>
                                                {passwordProblem !== null && (
                                                    <p
                                                        id="editor-password-error"
                                                        role="alert"
                                                        className="text-danger-ink text-caption"
                                                    >
                                                        {passwordProblem}
                                                    </p>
                                                )}
                                                {(!protectedAlready ||
                                                    passwordDiffers) && (
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                        data-test="visibility-protect"
                                                        disabled={
                                                            visibilitySaving
                                                        }
                                                        onClick={() =>
                                                            saveVisibility(
                                                                'protected',
                                                                typed,
                                                            )
                                                        }
                                                    >
                                                        {protectedAlready
                                                            ? __(
                                                                  'Change password',
                                                              )
                                                            : __(
                                                                  'Protect with this password',
                                                              )}
                                                    </Button>
                                                )}
                                            </div>
                                        )}
                                    </>
                                ) : (
                                    <>
                                        {visibilityChoice.label}
                                        <p className="text-ink-2 text-caption mt-1">
                                            {visibilityMeaning}{' '}
                                            {__(
                                                'Whoever publishes it sets this.',
                                            )}
                                        </p>
                                    </>
                                )}
                            </dd>
                        </>
                    )}
                    {!themeRegion && discussion !== null && (
                        <>
                            <dt className="text-ink-2">{__('Discussion')}</dt>
                            <dd className="min-w-0">
                                {canSetDiscussion ? (
                                    <>
                                        <SelectField
                                            id="editor-discussion"
                                            aria-label={__('Discussion')}
                                            aria-describedby={
                                                discussionProblem !== null
                                                    ? 'editor-discussion-meaning editor-discussion-error'
                                                    : 'editor-discussion-meaning'
                                            }
                                            options={DISCUSSION_CHOICES.map(
                                                (option) => ({
                                                    value: option.value,
                                                    label: option.label,
                                                }),
                                            )}
                                            value={discussion.value}
                                            disabled={discussionSaving}
                                            onValueChange={(next) => {
                                                setDiscussionSaving(true);
                                                setDiscussionProblem(null);
                                                void onChangeDiscussion(
                                                    next as Discussion,
                                                ).then((error) => {
                                                    setDiscussionSaving(false);
                                                    setDiscussionProblem(error);
                                                });
                                            }}
                                            className="h-[34px] w-full"
                                        />
                                        <p
                                            id="editor-discussion-meaning"
                                            className="text-ink-2 text-caption mt-1"
                                        >
                                            {choiceOf(discussion.value).meaning}
                                            {discussionNote !== null &&
                                                ` ${discussionNote}`}
                                            {visibilityNote !== null &&
                                                ` ${visibilityNote}`}
                                        </p>
                                        {discussionProblem !== null && (
                                            <p
                                                id="editor-discussion-error"
                                                role="alert"
                                                className="text-danger-ink text-caption mt-1"
                                            >
                                                {discussionProblem}
                                            </p>
                                        )}
                                    </>
                                ) : (
                                    <>
                                        {choiceOf(discussion.value).label}
                                        <p className="text-ink-2 text-caption mt-1">
                                            {choiceOf(discussion.value).meaning}
                                            {discussionNote !== null &&
                                                ` ${discussionNote}`}
                                            {visibilityNote !== null &&
                                                ` ${visibilityNote}`}{' '}
                                            {__(
                                                'Whoever publishes it sets this.',
                                            )}
                                        </p>
                                    </>
                                )}
                            </dd>
                        </>
                    )}
                    {status === 'published' && (
                        <>
                            <dt className="text-ink-2">{__('Published')}</dt>
                            <dd>
                                {publishedAt
                                    ? new Date(publishedAt).toLocaleString([], {
                                          dateStyle: 'medium',
                                          timeStyle: 'short',
                                      })
                                    : __('Not yet')}
                            </dd>
                        </>
                    )}
                    <PublishWhen
                        live={status === 'published'}
                        canPublish={canPublish}
                        review={review}
                        schedule={schedule}
                        siteTimezone={siteTimezone}
                        when={when}
                        onWhenChange={onWhenChange}
                        onCancelSchedule={onCancelSchedule}
                        busy={deciding}
                    />
                    {!themeRegion && (
                        <>
                            <dt className="text-ink-2">{__('Address')}</dt>
                            <dd className="min-w-0">
                                <DropdownMenu
                                    open={open}
                                    onOpenChange={(next) => {
                                        setOpen(next);
                                        setDraft(slug);
                                        setProblem(null);
                                    }}
                                >
                                    <DropdownMenuTrigger
                                        asChild
                                        disabled={!canEdit}
                                    >
                                        <TextButton className="block max-w-full truncate text-left font-normal">
                                            /{slug}
                                        </TextButton>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        align="start"
                                        className="w-80 max-w-(--radix-dropdown-menu-content-available-width) p-3"
                                    >
                                        <form
                                            className="grid gap-2"
                                            onSubmit={(event) => {
                                                event.preventDefault();
                                                setBusy(true);
                                                void onChangeSlug(
                                                    draft.trim(),
                                                ).then((error) => {
                                                    setBusy(false);
                                                    setProblem(error);

                                                    if (error === null) {
                                                        setOpen(false);
                                                    }
                                                });
                                            }}
                                        >
                                            <Label htmlFor="editor-slug">
                                                {__('Address')}
                                            </Label>
                                            <Input
                                                id="editor-slug"
                                                aria-describedby="editor-slug-url"
                                                value={draft}
                                                onChange={(event) =>
                                                    setDraft(event.target.value)
                                                }
                                                onKeyDown={(event) =>
                                                    event.stopPropagation()
                                                }
                                            />
                                            <p
                                                id="editor-slug-url"
                                                className="text-ink-2 text-caption break-all"
                                            >
                                                {addressOf(
                                                    siteUrl,
                                                    addressPattern,
                                                    draft || slug,
                                                )}
                                            </p>
                                            {problem && (
                                                <p
                                                    role="alert"
                                                    className="text-danger-ink text-caption"
                                                >
                                                    {problem}
                                                </p>
                                            )}
                                            <Button
                                                type="submit"
                                                size="sm"
                                                disabled={
                                                    busy || draft.trim() === ''
                                                }
                                                className="justify-self-start"
                                            >
                                                {__('Change address')}
                                            </Button>
                                        </form>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                                {url !== null && (
                                    <a
                                        href={url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="text-accent-ink text-caption underline-offset-2 hover:underline"
                                    >
                                        {__('View on site')}
                                    </a>
                                )}
                            </dd>
                        </>
                    )}
                    {!themeRegion &&
                        template !== null &&
                        (templates.length > 0 || missingNote !== null) && (
                            <>
                                <dt className="text-ink-2">{__('Template')}</dt>
                                <dd className="min-w-0">
                                    {templates.length > 0 && (
                                        <SelectField
                                            aria-label={__('Template')}
                                            aria-describedby={
                                                missingNote !== null
                                                    ? 'editor-template-missing'
                                                    : undefined
                                            }
                                            emptyLabel="Default"
                                            options={choice.options}
                                            value={template}
                                            onValueChange={onChangeTemplate}
                                            className="h-[34px] w-full"
                                        />
                                    )}
                                    {missingNote !== null && (
                                        <p
                                            id="editor-template-missing"
                                            className="text-ink-2 text-caption mt-1"
                                        >
                                            {missingNote}
                                        </p>
                                    )}
                                </dd>
                            </>
                        )}
                    {!themeRegion && (
                        <>
                            <dt className="text-ink-2">{__('Author')}</dt>
                            <dd className="min-w-0">
                                {authors !== null ? (
                                    <>
                                        <SelectField
                                            id="editor-author"
                                            aria-label={__('Author')}
                                            aria-describedby={
                                                [
                                                    status === 'published'
                                                        ? 'editor-author-live'
                                                        : null,
                                                    authorProblem !== null
                                                        ? 'editor-author-error'
                                                        : null,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' ') || undefined
                                            }
                                            options={authorChoices(
                                                authors,
                                                author,
                                            )}
                                            emptyLabel={
                                                author === null
                                                    ? __('Nobody')
                                                    : undefined
                                            }
                                            value={
                                                author === null
                                                    ? ''
                                                    : String(author.id)
                                            }
                                            disabled={authorSaving}
                                            onValueChange={(next) => {
                                                if (next === '') {
                                                    return;
                                                }

                                                setAuthorSaving(true);
                                                setAuthorProblem(null);
                                                void onChangeAuthor(
                                                    Number(next),
                                                ).then((error) => {
                                                    setAuthorSaving(false);
                                                    setAuthorProblem(error);
                                                });
                                            }}
                                            className="h-[34px] w-full"
                                        />
                                        {status === 'published' && (
                                            <p
                                                id="editor-author-live"
                                                className="text-ink-2 text-caption mt-1"
                                            >
                                                {PUBLISH_WORDS.authorLive(noun)}
                                            </p>
                                        )}
                                        {authorProblem !== null && (
                                            <p
                                                id="editor-author-error"
                                                role="alert"
                                                className="text-danger-ink text-caption mt-1"
                                            >
                                                {authorProblem}
                                            </p>
                                        )}
                                    </>
                                ) : (
                                    (author?.name ?? __('Nobody'))
                                )}
                            </dd>
                        </>
                    )}
                    <dt className="text-ink-2">History</dt>
                    <dd>
                        <TextButton
                            onClick={onOpenHistory}
                            className="font-normal"
                        >
                            {revisionCount === 1
                                ? __('1 revision')
                                : __(':count revisions', {
                                      count: revisionCount,
                                  })}
                        </TextButton>
                    </dd>
                </dl>
            </PanelBody>
        </Panel>
    );
}
