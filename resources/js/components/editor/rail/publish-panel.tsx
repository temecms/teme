import { useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { FieldInline } from '@/components/ui/field';
import { SelectField } from '@/components/ui/select-field';
import { choiceOf, DISCUSSION_CHOICES } from '@/lib/discussion';
import type { Discussion } from '@/lib/discussion';
import type {
    EditorAuthorChoice,
    EditorPerson,
    EditorReview,
    EditorSchedule,
} from '@/types';
import { TextButton } from '@/components/ui/text-button';
import { __ } from '@/lib/i18n';
import { PublishWhen } from './publish-when';
import type { WhenChoice } from './publish-when';
import { templateChoice } from './template-choice';
import type { ThemeTemplate } from './template-choice';
import { formatDateTime } from '@/lib/dates';

/** The panel's own sentences, as strings, so a formatter never splits them. */
const PUBLISH_WORDS = {
    public: 'Anyone can read it once it’s published.',
    publicLive: 'Anyone can read it.',
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

/**
 * One row of the Publish section (4.39): its key in a 96px column, the
 * value or its 32px control beside it.
 */
export function Row({
    label,
    htmlFor,
    children,
}: {
    label: string;
    /** The control the key names, when it has no name of its own. */
    htmlFor?: string;
    children: ReactNode;
}) {
    return (
        <div className="flex min-w-0 items-start gap-3">
            <dt className="text-small text-ink-3 flex min-h-8 w-24 shrink-0 items-center">
                {htmlFor === undefined ? (
                    label
                ) : (
                    <Label htmlFor={htmlFor} className="text-ink-3 font-normal">
                        {label}
                    </Label>
                )}
            </dt>
            <dd className="text-small flex min-h-8 min-w-0 flex-1 flex-col justify-center">
                {children}
            </dd>
        </div>
    );
}

/** The article's address on the site, worked out now and never stored (rule 9). */
export function addressOf(siteUrl: string, pattern: string, slug: string) {
    return siteUrl.replace(/\/$/, '') + pattern.replace('{slug}', slug);
}

/**
 * The Publish section under the action bar (docs/architecture/design-system.md,
 * 4.39): no heading and no help lines, one row per setting with its key in a
 * 96px column: Visibility, Discussion, when a live item was published or when
 * this one publishes, the Address, the Template where the theme offers one,
 * the Author and History, then "Stick to the top of the blog" for a type that
 * pins. It never collapses (I-230).
 */
export function PublishPanel({
    status,
    review,
    publishedAt,
    slug,
    addressPattern,
    siteUrl,
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
    sticky,
    onChangeSticky,
}: {
    status: string;
    /** The pending or returned review: who decides when it publishes. */
    review: EditorReview | null;
    publishedAt: string | null;
    slug: string;
    addressPattern: string;
    siteUrl: string;
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
    /** Whether the item sticks to the top of the blog; null for a type that has no pinning. */
    sticky: boolean | null;
    onChangeSticky: (on: boolean) => void;
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
    const choice = templateChoice(templates, template ?? '');
    const missingNote =
        choice.missing === null
            ? null
            : __(
                  "The template “:template” isn't in the current theme, so the default layout is used. It stays chosen, and comes back if the theme does.",
                  { template: choice.missing },
              );

    return (
        <section
            aria-label={__('Publish')}
            data-test="publish-section"
            className="px-2"
        >
            <dl className="grid gap-2 px-3 pb-2">
                {!themeRegion && (
                    <>
                        <Row label={__('Visibility')}>
                            {canSetVisibility ? (
                                <>
                                    {/* The row draws no help line (4.39); screen readers still hear what the choice means. */}
                                    <p
                                        id="editor-visibility-meaning"
                                        className="sr-only"
                                    >
                                        {visibilityMeaning}
                                    </p>
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
                                        className="text-small h-8 w-full"
                                    />
                                    {protectedAlready && passwordUnreadable && (
                                        <p className="text-warn-ink text-caption mt-1">
                                            {__(
                                                'Its password can’t be read on this server, so nobody can open the :noun. Set a new one.',
                                                { noun },
                                            )}
                                        </p>
                                    )}
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
                                                    setTyped(event.target.value)
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
                                                    disabled={visibilitySaving}
                                                    onClick={() =>
                                                        saveVisibility(
                                                            'protected',
                                                            typed,
                                                        )
                                                    }
                                                >
                                                    {protectedAlready
                                                        ? __('Change password')
                                                        : __(
                                                              'Protect with this password',
                                                          )}
                                                </Button>
                                            )}
                                        </div>
                                    )}
                                </>
                            ) : (
                                <span title={visibilityMeaning}>
                                    {visibilityChoice.label}
                                </span>
                            )}
                        </Row>
                    </>
                )}
                {!themeRegion && discussion !== null && (
                    <>
                        <Row label={__('Discussion')}>
                            {canSetDiscussion ? (
                                <>
                                    <SelectField
                                        id="editor-discussion"
                                        aria-label={__('Discussion')}
                                        aria-describedby={
                                            discussionProblem !== null
                                                ? 'editor-discussion-error'
                                                : undefined
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
                                        className="text-small h-8 w-full"
                                    />

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
                                <span>{choiceOf(discussion.value).label}</span>
                            )}
                        </Row>
                    </>
                )}
                {status === 'published' && (
                    <Row label={__('Published')}>
                        <span data-test="published-at">
                            {publishedAt
                                ? formatDateTime(publishedAt)
                                : __('Not yet')}
                        </span>
                    </Row>
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
                        <Row label={__('Address')}>
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
                        </Row>
                    </>
                )}
                {!themeRegion &&
                    template !== null &&
                    (templates.length > 0 || missingNote !== null) && (
                        <>
                            <Row label={__('Template')}>
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
                                        className="text-small h-8 w-full"
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
                            </Row>
                        </>
                    )}
                {!themeRegion && (
                    <>
                        <Row label={__('Author')}>
                            {authors !== null ? (
                                <>
                                    <SelectField
                                        id="editor-author"
                                        aria-label={__('Author')}
                                        aria-describedby={
                                            authorProblem !== null
                                                ? 'editor-author-error'
                                                : undefined
                                        }
                                        options={authorChoices(authors, author)}
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
                                        className="text-small h-8 w-full"
                                    />

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
                        </Row>
                    </>
                )}
                <Row label={__('History')}>
                    <TextButton onClick={onOpenHistory} className="font-normal">
                        {revisionCount === 1
                            ? __('1 revision')
                            : __(':count revisions', {
                                  count: revisionCount,
                              })}
                    </TextButton>
                </Row>
            </dl>
            {sticky !== null && (
                <FieldInline
                    htmlFor="editor-sticky"
                    label={__('Stick to the top of the blog')}
                    className="px-3 pt-1 pb-4"
                >
                    <Checkbox
                        id="editor-sticky"
                        checked={sticky}
                        onCheckedChange={(on) => onChangeSticky(on === true)}
                    />
                </FieldInline>
            )}
        </section>
    );
}
