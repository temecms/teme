import { useEffect, useState } from 'react';
import ExtensionsController from '@/actions/App/Http/Admin/ExtensionsController';
import { send } from '@/components/editor/editor-http';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Notice } from '@/components/ui/notice';
import { PluginIcon } from '@/components/ui/plugin-icon';
import { ScrollArea } from '@/components/ui/scroll-area';
import { SelectField } from '@/components/ui/select-field';
import { StatusPill } from '@/components/ui/status-pill';
import { __ } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { ExtensionKind } from '@/types';

/** One package the directory lists, as the search answers it. */
type Listed = {
    name: string;
    label: string;
    summary: string;
    author: string;
    topics: string[];
    picked: boolean;
    latest: string;
    updated: string;
    worksWith: string[];
    icon: string | null;
};

/** One package's detail, read from its file once it matched the signed list. */
type Detail = Listed & {
    readme: string;
    screenshots: { url: string; caption: string }[];
    permissions: { key: string; words: string }[];
    links: Record<'source' | 'issues' | 'docs' | 'support', string | null>;
    stars: number | null;
    issues: number | null;
    releases: { version: string; date: string | null; worksWith: string[] }[];
};

/** How long typing rests before the list is searched again. */
const SEARCH_MS = 250;

/**
 * One package's detail under its card: screenshots, the permissions it asks
 * for in plain words, its links and the versions it can be installed at.
 */
function PackageDetail({
    detail,
    constraint,
    onConstraint,
}: {
    detail: Detail;
    constraint: string;
    onConstraint: (constraint: string) => void;
}) {
    const words: Record<keyof Detail['links'], string> = {
        source: __('Source code'),
        issues: __('Issues'),
        docs: __('Documentation'),
        support: __('Support'),
    };
    const links = (Object.keys(words) as (keyof Detail['links'])[]).filter(
        (key) => detail.links[key] !== null,
    );

    return (
        <div
            className="border-line grid min-w-0 basis-full gap-4 border-t pt-3"
            data-test="directory-detail"
        >
            {detail.screenshots.length > 0 && (
                <ScrollArea axis="x" label={__('Screenshots')}>
                    <ul className="flex min-w-0 gap-3 pb-1">
                        {detail.screenshots.map((shot) => (
                            <li
                                key={shot.url}
                                className="grid w-56 shrink-0 gap-1"
                            >
                                <img
                                    src={shot.url}
                                    alt={
                                        shot.caption === ''
                                            ? __('A screenshot of :name', {
                                                  name: detail.label,
                                              })
                                            : ''
                                    }
                                    loading="lazy"
                                    className="border-line aspect-video w-full rounded-md border object-cover"
                                />
                                {shot.caption !== '' && (
                                    <span className="text-caption text-ink-2">
                                        {shot.caption}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                </ScrollArea>
            )}
            <div className="grid gap-1">
                <h3 className="text-small text-ink font-semibold">
                    {__('What it asks to do')}
                </h3>
                {detail.permissions.length === 0 ? (
                    <p className="text-small text-ink-2">
                        {__('It asks for no permissions.')}
                    </p>
                ) : (
                    <ul
                        className="text-small text-ink-2 grid list-disc gap-1 ps-5"
                        data-test="directory-permissions"
                    >
                        {detail.permissions.map((permission) => (
                            <li key={permission.key}>{permission.words}</li>
                        ))}
                    </ul>
                )}
                <p className="text-caption text-ink-2">
                    {__(
                        'You agree to these again before you enable it, and whenever an update asks for more.',
                    )}
                </p>
            </div>
            {links.length > 0 && (
                <p className="text-small flex flex-wrap gap-x-4 gap-y-1">
                    {links.map((key) => (
                        <a
                            key={key}
                            href={detail.links[key] ?? undefined}
                            target="_blank"
                            rel="noreferrer noopener"
                            className="text-accent-ink underline underline-offset-2"
                        >
                            {words[key]}
                        </a>
                    ))}
                </p>
            )}
            {detail.releases.length > 0 && (
                <Field
                    htmlFor="directory-version"
                    label={__('Version')}
                    help={__(
                        'The newest that fits also takes later compatible versions when you update.',
                    )}
                >
                    <SelectField
                        id="directory-version"
                        value={constraint}
                        onValueChange={onConstraint}
                        options={[
                            {
                                value: `^${detail.releases[0].version}`,
                                label: __(
                                    'The newest that fits (:version now)',
                                    { version: detail.releases[0].version },
                                ),
                            },
                            ...detail.releases.map((release) => ({
                                value: release.version,
                                label: __('Exactly :version', {
                                    version: release.version,
                                }),
                            })),
                        ]}
                    />
                </Field>
            )}
        </div>
    );
}

/**
 * Teme's directory: the plugins or themes it lists, searched as you type,
 * each list checked against the directory's signature by the server; a
 * package's detail opens under its card when it is chosen.
 */
export function DirectoryTab({
    kind,
    installed,
    chosen,
    onChoose,
    constraint,
    onConstraint,
}: {
    kind: ExtensionKind;
    installed: string[];
    chosen: string | null;
    onChoose: (name: string | null) => void;
    constraint: string;
    onConstraint: (constraint: string) => void;
}) {
    const [q, setQ] = useState('');
    const [results, setResults] = useState<Listed[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [detail, setDetail] = useState<Detail | null>(null);
    const [detailError, setDetailError] = useState<string | null>(null);
    const words = q.trim();

    useEffect(() => {
        let stopped = false;
        const timer = window.setTimeout(() => {
            void send(
                ExtensionsController.directory({ query: { q: words, kind } }),
            ).then((answer) => {
                if (stopped) {
                    return;
                }

                setResults((answer.body.results as Listed[] | undefined) ?? []);
                setError(
                    (answer.body.error as string | null | undefined) ??
                        (answer.status === 200
                            ? null
                            : __(
                                  'The directory could not be read. Try again.',
                              )),
                );
            });
        }, SEARCH_MS);

        return () => {
            stopped = true;
            window.clearTimeout(timer);
        };
    }, [words, kind]);

    useEffect(() => {
        setDetail(null);
        setDetailError(null);

        if (chosen === null) {
            return;
        }

        let stopped = false;

        void send(
            ExtensionsController.directoryPackage({
                query: { package: chosen, kind },
            }),
        ).then((answer) => {
            if (stopped) {
                return;
            }

            const found =
                (answer.body.package as Detail | null | undefined) ?? null;
            setDetail(found);
            setDetailError(
                (answer.body.error as string | null | undefined) ??
                    (found === null
                        ? __('The directory could not be read. Try again.')
                        : null),
            );
            onConstraint(
                found !== null && found.releases.length > 0
                    ? `^${found.releases[0].version}`
                    : '',
            );
        });

        return () => {
            stopped = true;
        };
        // onConstraint is the sheet's own setter, the same on every draw.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [chosen, kind]);

    return (
        <div className="grid gap-4">
            <Field
                htmlFor="directory-search"
                label={__('Search the directory')}
                help={
                    kind === 'plugin'
                        ? __(
                              'Plugins checked and listed by Teme’s directory. By name or a word.',
                          )
                        : __(
                              'Themes checked and listed by Teme’s directory. By name or a word.',
                          )
                }
            >
                <Input
                    id="directory-search"
                    type="search"
                    value={q}
                    autoComplete="off"
                    spellCheck={false}
                    data-test="directory-search"
                    onChange={(event) => {
                        setQ(event.target.value);
                        onChoose(null);
                    }}
                />
            </Field>
            <p className="sr-only" role="status" aria-live="polite">
                {results === null
                    ? __('Searching…')
                    : __(':count found', { count: results.length })}
            </p>
            {error !== null && (
                <Notice tone="danger" data-test="directory-error">
                    {error}
                </Notice>
            )}
            {results !== null && results.length === 0 && error === null && (
                <p className="text-small text-ink-2">
                    {__(
                        'Nothing found. Try another word, or the package’s name.',
                    )}
                </p>
            )}
            {results !== null && results.length > 0 && (
                <ul className="grid gap-2" data-test="directory-results">
                    {results.map((found) => {
                        const isChosen = found.name === chosen;
                        const isInstalled = installed.includes(found.name);

                        return (
                            <li key={found.name} className="min-w-0">
                                <div
                                    data-test="directory-result"
                                    className={cn(
                                        'border-line flex min-w-0 flex-wrap items-start gap-x-3 gap-y-2 rounded-md border px-3 py-2',
                                        isChosen &&
                                            'border-accent bg-accent-soft',
                                    )}
                                >
                                    <PluginIcon
                                        name={found.label}
                                        src={found.icon}
                                        size={56}
                                    />
                                    <div className="grid min-w-0 flex-1 basis-40 gap-0.5">
                                        <p className="text-body text-ink flex flex-wrap items-center gap-2 font-semibold">
                                            {found.label}
                                            {found.picked && (
                                                <StatusPill tone="info" flat>
                                                    {__('Teme’s pick')}
                                                </StatusPill>
                                            )}
                                        </p>
                                        <p className="text-caption text-ink-2 font-mono break-all">
                                            {found.name}
                                        </p>
                                        {found.summary !== '' && (
                                            <p className="text-small text-ink-2 break-words">
                                                {found.summary}
                                            </p>
                                        )}
                                        <p className="text-caption text-ink-2 flex flex-wrap gap-x-3">
                                            {found.author !== '' && (
                                                <span>
                                                    {__('By :name', {
                                                        name: found.author,
                                                    })}
                                                </span>
                                            )}
                                            {found.latest !== '' && (
                                                <span>
                                                    {__('Newest :version', {
                                                        version: found.latest,
                                                    })}
                                                </span>
                                            )}
                                            {found.worksWith.length > 0 && (
                                                <span>
                                                    {__(
                                                        'Works with Teme :versions',
                                                        {
                                                            versions:
                                                                found.worksWith.join(
                                                                    ', ',
                                                                ),
                                                        },
                                                    )}
                                                </span>
                                            )}
                                            {isInstalled && (
                                                <StatusPill tone="ok" flat>
                                                    {__('Installed')}
                                                </StatusPill>
                                            )}
                                        </p>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="neutral"
                                        size="sm"
                                        aria-pressed={isChosen}
                                        aria-expanded={isChosen}
                                        disabled={isInstalled}
                                        data-test="directory-choose"
                                        onClick={() =>
                                            onChoose(
                                                isChosen ? null : found.name,
                                            )
                                        }
                                    >
                                        {isChosen ? __('Chosen') : __('Choose')}
                                        <span className="sr-only">
                                            {' '}
                                            {found.label}
                                        </span>
                                    </Button>
                                    {isChosen && detailError !== null && (
                                        <Notice
                                            tone="danger"
                                            className="basis-full"
                                        >
                                            {detailError}
                                        </Notice>
                                    )}
                                    {isChosen && detail !== null && (
                                        <PackageDetail
                                            detail={detail}
                                            constraint={constraint}
                                            onConstraint={onConstraint}
                                        />
                                    )}
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}
