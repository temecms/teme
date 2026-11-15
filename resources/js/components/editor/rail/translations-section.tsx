import { Link, router, useHttp } from '@inertiajs/react';
import { MoreHorizontal } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import TranslationsController from '@/actions/App/Http/Admin/Contents/TranslationsController';
import { ResponsiveDialogContent } from '@/components/patterns/responsive-dialog';
import { Button } from '@/components/ui/button';
import { Combobox } from '@/components/ui/combobox';
import type { ComboboxOption } from '@/components/ui/combobox';
import {
    Dialog,
    DialogClose,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Field } from '@/components/ui/field';
import { Notice } from '@/components/ui/notice';
import { StatusPill } from '@/components/ui/status-pill';
import { __, __n } from '@/lib/i18n';
import type {
    EditorLanguage,
    EditorSession,
    EditorTranslation,
} from '@/types/editor';
import { RailSection } from './rail-section';

/** A version's status in a word (the status pill's). */
export function versionStatus(status: string): string {
    switch (status) {
        case 'published':
            return __('Published');
        case 'scheduled':
            return __('Scheduled');
        case 'review':
            return __('In review');
        default:
            return __('Draft');
    }
}

/** What the section asks before acting, in one dialog at a time. */
type Asking =
    | { kind: 'translate'; language: EditorLanguage }
    | { kind: 'unlink'; language: EditorLanguage; version: EditorTranslation }
    | {
          kind: 'link';
          language: EditorLanguage;
          version: EditorTranslation | null;
      };

/** A post the person may link as a version, as the server finds it. */
type Candidate = { id: string; title: string; status: string };

/** The language's versions to link, searched by title while the dialog is open. */
function useCandidates(
    typeSegment: string,
    contentId: string,
    language: string,
    query: string,
): { found: Candidate[]; loading: boolean } {
    const [found, setFound] = useState<Candidate[]>([]);
    const [loading, setLoading] = useState(true);
    const { submit } = useHttp();

    useEffect(() => {
        const timer = window.setTimeout(() => {
            setLoading(true);
            void submit(
                TranslationsController.candidates(
                    { type: typeSegment, content: contentId },
                    { query: { language, q: query } },
                ),
            )
                .then((answer) => setFound(answer as Candidate[]))
                .finally(() => setLoading(false));
        }, 200);

        return () => window.clearTimeout(timer);
    }, [typeSegment, contentId, language, query, submit]);

    return { found, loading };
}

/** The "Link a different post…" dialog's body: a search, then Link. */
function LinkDialog({
    asking,
    typeSegment,
    contentId,
    onDone,
}: {
    asking: Extract<Asking, { kind: 'link' }>;
    typeSegment: string;
    contentId: string;
    onDone: () => void;
}) {
    const [query, setQuery] = useState('');
    const [chosen, setChosen] = useState<ComboboxOption | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const { found, loading } = useCandidates(
        typeSegment,
        contentId,
        asking.language.tag,
        query,
    );

    const link = (withId: string) =>
        router.post(
            TranslationsController.link.url({
                type: typeSegment,
                content: contentId,
            }),
            { with: withId },
            {
                preserveScroll: true,
                onError: (errors) => setError(Object.values(errors)[0] ?? null),
                onSuccess: onDone,
                onFinish: () => setBusy(false),
            },
        );

    const submitChoice = () => {
        if (chosen === null) {
            setError(__('Choose a post to link.'));

            return;
        }

        setBusy(true);

        // The language's version leaves the group first: a group holds one item a language.
        if (asking.version?.id) {
            router.delete(
                TranslationsController.unlink.url({
                    type: typeSegment,
                    content: contentId,
                    other: asking.version.id,
                }),
                {
                    preserveScroll: true,
                    onError: (errors) => {
                        setError(Object.values(errors)[0] ?? null);
                        setBusy(false);
                    },
                    onSuccess: () => link(chosen.value),
                },
            );

            return;
        }

        link(chosen.value);
    };

    return (
        <>
            <DialogHeader>
                <DialogTitle>
                    {__('Link a :language version', {
                        language: asking.language.name,
                    })}
                </DialogTitle>
                <DialogDescription>
                    {asking.version?.title
                        ? __(
                              '“:title” is unlinked, and the post you choose becomes the :language version.',
                              {
                                  title: asking.version.title,
                                  language: asking.language.name,
                              },
                          )
                        : __(
                              'The post you choose becomes the :language version.',
                              { language: asking.language.name },
                          )}
                </DialogDescription>
            </DialogHeader>
            <div className="grid gap-4">
                {error !== null && (
                    <Notice tone="danger" role="alert">
                        {error}
                    </Notice>
                )}
                <Field
                    htmlFor="translation-link"
                    label={__('Post in :language', {
                        language: asking.language.name,
                    })}
                >
                    <Combobox
                        id="translation-link"
                        options={found.map((candidate) => ({
                            value: candidate.id,
                            label: candidate.title,
                            description: versionStatus(candidate.status),
                        }))}
                        value={chosen}
                        onValueChange={setChosen}
                        search={{ value: query, onChange: setQuery, loading }}
                        placeholder={__('Choose a post')}
                        searchPlaceholder={__('Find by title')}
                        emptyText={__('No post in this language matches.')}
                    />
                </Field>
            </div>
            <DialogFooter>
                <DialogClose asChild>
                    <Button variant="neutral">{__('Cancel')}</Button>
                </DialogClose>
                <Button
                    variant="primary"
                    loading={busy}
                    data-test="translation-link-confirm"
                    onClick={submitChoice}
                >
                    {__('Link')}
                </Button>
            </DialogFooter>
        </>
    );
}

/** A version's "⋯": Mark up to date, Link a different post…, Unlink…. */
function VersionMenu({
    language,
    version,
    typeSegment,
    canLink,
    onAsk,
    onRefused,
}: {
    language: EditorLanguage;
    version: EditorTranslation | null;
    typeSegment: string;
    canLink: boolean;
    onAsk: (asking: Asking, opener: HTMLElement | null) => void;
    onRefused: (words: string | null) => void;
}) {
    const trigger = useRef<HTMLButtonElement>(null);
    const opens = version?.id != null;

    if (!canLink && !(opens && version?.outOfDate)) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    ref={trigger}
                    type="button"
                    variant="ghost"
                    size="icon-xs"
                    data-test="translation-menu"
                    aria-label={__('More actions for :language', {
                        language: language.name,
                    })}
                >
                    <MoreHorizontal aria-hidden="true" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                {opens && version.outOfDate && (
                    <DropdownMenuItem
                        data-test="translation-up-to-date"
                        onSelect={() =>
                            router.post(
                                TranslationsController.upToDate.url({
                                    type: typeSegment,
                                    content: version.id as string,
                                }),
                                {
                                    expected_revision_id:
                                        version.workingRevisionId ?? '',
                                },
                                {
                                    preserveScroll: true,
                                    onError: (errors) =>
                                        onRefused(
                                            Object.values(errors)[0] ?? null,
                                        ),
                                    onSuccess: () => onRefused(null),
                                },
                            )
                        }
                    >
                        {__('Mark up to date')}
                    </DropdownMenuItem>
                )}
                {canLink && (
                    <DropdownMenuItem
                        data-test="translation-link"
                        onSelect={() =>
                            onAsk(
                                { kind: 'link', language, version },
                                trigger.current,
                            )
                        }
                    >
                        {version === null
                            ? __('Link a post…')
                            : __('Link a different post…')}
                    </DropdownMenuItem>
                )}
                {canLink && opens && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            variant="destructive"
                            data-test="translation-unlink"
                            onSelect={() =>
                                onAsk(
                                    { kind: 'unlink', language, version },
                                    trigger.current,
                                )
                            }
                        >
                            {__('Unlink…')}
                        </DropdownMenuItem>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * The editor's Translations section (docs/proposals/multilingual-screens.md,
 * 3), directly under Publish: every site language as one row, this item
 * included. Each other row has one status pill, "out of date" inside it in
 * the warning tone, and one worded action, Open or Translate; the rare
 * actions sit under "⋯". Not drawn for a type that isn't translated.
 */
export function TranslationsSection({
    session,
    typeSegment,
}: {
    session: EditorSession;
    typeSegment: string | null;
}) {
    const [asking, setAsking] = useState<Asking | null>(null);
    const [refusal, setRefusal] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const opener = useRef<HTMLElement | null>(null);
    const languages = session.languages ?? [];
    const translations = session.translations ?? [];

    if (languages.length === 0 || typeSegment === null) {
        return null;
    }

    const current = session.language ?? languages[0].tag;
    const byLanguage = new Map(
        translations.map((version) => [version.language, version]),
    );
    const have = languages.filter(
        (language) => language.tag === current || byLanguage.has(language.tag),
    ).length;
    const stale = translations.filter(
        (version) => !version.current && version.outOfDate,
    ).length;
    const isSource = translations.some(
        (version) => !version.current && version.sourceLanguage === current,
    );
    const canLink = session.can.edit;
    const summary = [
        __(':have of :all', { have, all: languages.length }),
        stale > 0 && __n(':count out of date|:count out of date', stale),
    ]
        .filter(Boolean)
        .join(' · ');

    const ask = (next: Asking, from: HTMLElement | null) => {
        opener.current = from;
        setAsking(next);
    };

    const answered = {
        preserveScroll: true,
        onError: (errors: Record<string, string>) =>
            setRefusal(Object.values(errors)[0] ?? null),
        onSuccess: () => setRefusal(null),
        onFinish: () => setBusy(false),
    };

    const confirm = () => {
        if (asking === null) {
            return;
        }

        setBusy(true);

        if (asking.kind === 'translate') {
            router.post(
                TranslationsController.store.url({
                    type: typeSegment,
                    content: session.id,
                }),
                { language: asking.language.tag },
                answered,
            );
        } else if (asking.kind === 'unlink' && asking.version.id) {
            router.delete(
                TranslationsController.unlink.url({
                    type: typeSegment,
                    content: session.id,
                    other: asking.version.id,
                }),
                answered,
            );
        }

        setAsking(null);
    };

    return (
        <RailSection
            title={__('Translations')}
            triggerId="translations-section"
        >
            <div data-test="translations-section" className="grid gap-2">
                <p
                    data-test="translations-summary"
                    className="text-small text-ink-3"
                >
                    {summary}
                </p>
                {refusal !== null && (
                    <Notice
                        tone="danger"
                        role="alert"
                        data-test="translations-refusal"
                        onDismiss={() => setRefusal(null)}
                    >
                        {refusal}
                    </Notice>
                )}
                <ul className="grid gap-1">
                    {languages.map((language) => {
                        const version = byLanguage.get(language.tag) ?? null;
                        const mine = language.tag === current;

                        return (
                            <li
                                key={language.tag}
                                data-test="translation-row"
                                data-language={language.tag}
                                className="flex min-h-9 items-center gap-2"
                            >
                                <span className="text-body text-ink min-w-0 flex-1 truncate">
                                    {language.name}
                                </span>
                                {mine ? (
                                    <span className="text-small text-ink-3">
                                        {isSource
                                            ? __('Editing · source')
                                            : __('Editing')}
                                    </span>
                                ) : version === null ? (
                                    <>
                                        <span className="text-small text-ink-3">
                                            {__('Not translated')}
                                        </span>
                                        {session.can.translate && (
                                            <Button
                                                type="button"
                                                variant="neutral"
                                                size="xs"
                                                data-test="translation-translate"
                                                onClick={(event) =>
                                                    ask(
                                                        {
                                                            kind: 'translate',
                                                            language,
                                                        },
                                                        event.currentTarget,
                                                    )
                                                }
                                            >
                                                {__('Translate')}
                                                <span className="sr-only">
                                                    {' '}
                                                    {__('into :language', {
                                                        language: language.name,
                                                    })}
                                                </span>
                                            </Button>
                                        )}
                                    </>
                                ) : (
                                    <>
                                        <StatusPill
                                            tone={
                                                version.outOfDate
                                                    ? 'warn'
                                                    : version.status ===
                                                        'published'
                                                      ? 'ok'
                                                      : 'neutral'
                                            }
                                            data-test="translation-status"
                                        >
                                            {version.outOfDate
                                                ? __(':status · out of date', {
                                                      status: versionStatus(
                                                          version.status,
                                                      ),
                                                  })
                                                : versionStatus(version.status)}
                                        </StatusPill>
                                        {version.editUrl !== null && (
                                            <Button
                                                asChild
                                                variant="neutral"
                                                size="xs"
                                            >
                                                <Link
                                                    href={version.editUrl}
                                                    data-test="translation-open"
                                                >
                                                    {__('Open')}
                                                    <span className="sr-only">
                                                        {' '}
                                                        {version.title ??
                                                            language.name}
                                                    </span>
                                                </Link>
                                            </Button>
                                        )}
                                    </>
                                )}
                                {!mine && (
                                    <VersionMenu
                                        language={language}
                                        version={version}
                                        typeSegment={typeSegment}
                                        canLink={canLink}
                                        onAsk={ask}
                                        onRefused={setRefusal}
                                    />
                                )}
                            </li>
                        );
                    })}
                </ul>
            </div>

            <Dialog
                open={asking !== null}
                onOpenChange={(open) => !open && setAsking(null)}
            >
                <ResponsiveDialogContent
                    phone="foot"
                    data-test="translations-dialog"
                    onCloseAutoFocus={(event) => {
                        if (opener.current?.isConnected) {
                            event.preventDefault();
                            opener.current.focus();
                        }
                    }}
                >
                    {asking?.kind === 'link' ? (
                        <LinkDialog
                            asking={asking}
                            typeSegment={typeSegment}
                            contentId={session.id}
                            onDone={() => setAsking(null)}
                        />
                    ) : (
                        asking !== null && (
                            <>
                                <DialogHeader>
                                    <DialogTitle>
                                        {asking.kind === 'translate'
                                            ? __('Make the :language draft?', {
                                                  language:
                                                      asking.language.name,
                                              })
                                            : __(
                                                  'Unlink the :language version?',
                                                  {
                                                      language:
                                                          asking.language.name,
                                                  },
                                              )}
                                    </DialogTitle>
                                    <DialogDescription>
                                        {asking.kind === 'translate'
                                            ? __(
                                                  'A draft is made from this post, in :language, and opened. Nothing is published.',
                                                  {
                                                      language:
                                                          asking.language.name,
                                                  },
                                              )
                                            : __(
                                                  'It stays as it is, but is no longer a translation of this post, and readers are no longer offered it as one.',
                                              )}
                                    </DialogDescription>
                                </DialogHeader>
                                <DialogFooter>
                                    <DialogClose asChild>
                                        <Button variant="neutral">
                                            {__('Cancel')}
                                        </Button>
                                    </DialogClose>
                                    <Button
                                        variant={
                                            asking.kind === 'unlink'
                                                ? 'danger'
                                                : 'primary'
                                        }
                                        loading={busy}
                                        data-test="translations-confirm"
                                        onClick={confirm}
                                    >
                                        {asking.kind === 'translate'
                                            ? __('Make the :language draft', {
                                                  language:
                                                      asking.language.name,
                                              })
                                            : __('Unlink')}
                                    </Button>
                                </DialogFooter>
                            </>
                        )
                    )}
                </ResponsiveDialogContent>
            </Dialog>
        </RailSection>
    );
}
