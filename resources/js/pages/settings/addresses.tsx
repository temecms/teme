import { Head, Link, setLayoutProps, useHttp } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AddressesController from '@/actions/App/Http/Admin/Settings/AddressesController';
import { SettingsForm } from '@/components/fields/settings-form';
import { SettingsPanel } from '@/components/fields/settings-panel';
import { SettingsPage } from '@/components/patterns/settings-page';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Notice } from '@/components/ui/notice';
import { RadioGroup, RadioGroupCard } from '@/components/ui/radio-group';
import { StatusPill } from '@/components/ui/status-pill';
import { TextButton } from '@/components/ui/text-button';
import { __ } from '@/lib/i18n';
import {
    index as settingsIndex,
    show as showSettings,
} from '@/routes/settings';

type AddressType = {
    key: string;
    singular: string;
    plural: string;
    main: boolean;
    /** Whether an item may sit under another, as a page under a page. */
    hierarchical: boolean;
    pattern: string;
};

type AddressTaxonomy = {
    key: string;
    singular: string;
    plural: string;
    hierarchical: boolean;
    base: string;
};

type AddressArchive = {
    key: string;
    plural: string;
    base: string;
    address: string;
    feed: string;
    holder: string | null;
};

type AddressesPage = {
    types: AddressType[];
    taxonomies: AddressTaxonomy[];
    authorBase: string;
    /** The site's own address, with no trailing slash, for the previews. */
    site: string;
    example: Record<string, string> | null;
    archives: AddressArchive[];
};

/** The presets for the post address, then Custom. */
function presets(): { label: string; pattern: string }[] {
    return [
        { label: __('Post name'), pattern: '/{slug}/' },
        { label: __('Day and name'), pattern: '/{year}/{month}/{day}/{slug}/' },
        { label: __('Month and name'), pattern: '/{year}/{month}/{slug}/' },
        { label: __('Numeric'), pattern: '/archives/{id}/' },
    ];
}

/** A pattern for items that stand alone, or for items that may sit under another (pages). */
type PatternKind = 'flat' | 'nested';

const TOKENS = ['slug', 'year', 'month', 'day', 'id', 'category', 'author'];
const PAGE_TOKENS = ['parent', 'slug'];

/** What the previews show when the site has no post yet. */
const SAMPLE: Record<string, string> = {
    slug: 'my-first-post',
    year: '2026',
    month: '11',
    day: '13',
    id: '42',
    category: 'news',
    author: 'your-name',
    parent: 'about',
};

/** A pattern with the example's values in its tokens. */
function render(pattern: string, values: Record<string, string>): string {
    const path = pattern
        .replace(/\{([a-z]+)\}/g, (_, token: string) => values[token] ?? '')
        .replace(/\/{2,}/g, '/');

    return path.startsWith('/') ? path : `/${path}`;
}

/** What is wrong with a pattern, or an empty string when it is valid. */
function problem(pattern: string, kind: PatternKind): string {
    const allowed = kind === 'nested' ? PAGE_TOKENS : TOKENS;

    if (pattern.trim() === '') {
        return __('Write a pattern, such as /{slug}/.');
    }

    if (!pattern.startsWith('/')) {
        return __('Start the pattern with a slash.');
    }

    if (!pattern.endsWith('/')) {
        return __('End the pattern with a slash.');
    }

    if (/\s/.test(pattern) || pattern.includes('//')) {
        return __('Leave out spaces and double slashes.');
    }

    const bad = (pattern.match(/\{(\w*)\}/g) ?? []).find(
        (token) => !allowed.includes(token.slice(1, -1)),
    );

    if (bad !== undefined) {
        return __(':token isn’t a token. Use :tokens.', {
            token: bad,
            tokens: allowed
                .map((token) => `{${token}}`)
                .join(', ')
                .replace(/, ([^,]*)$/, ` ${__('or')} $1`),
        });
    }

    if (/[{}]/.test(pattern.replace(/\{\w+\}/g, ''))) {
        return __('A token is a word between braces, such as {slug}.');
    }

    if (kind === 'flat' && !/\{(slug|id)\}/.test(pattern)) {
        return __('Add {slug} or {id}, or two posts could share an address.');
    }

    if (kind === 'nested' && !pattern.includes('{slug}')) {
        return __('Add {slug}, or two pages could share an address.');
    }

    return '';
}

/** One pattern's field with its token chips, its preview and its check. */
function PatternField({
    id,
    name,
    label,
    hint,
    value,
    onChange,
    tokens,
    kind,
    previewLabel,
    validLine,
    site,
    values,
    clash,
    error,
}: {
    id: string;
    name: string;
    label: string;
    hint: string;
    value: string;
    onChange: (next: string) => void;
    tokens: string[];
    kind: PatternKind;
    previewLabel: string;
    validLine: string;
    site: string;
    values: Record<string, string>;
    clash?: string;
    error?: string;
}) {
    const wrong = problem(value, kind) || (clash ?? '');

    return (
        <>
            <Field
                htmlFor={id}
                label={label}
                help={hint}
                error={error}
                required
            >
                <Input
                    id={id}
                    name={name}
                    value={value}
                    className="font-mono"
                    autoComplete="off"
                    spellCheck={false}
                    onChange={(event) => onChange(event.target.value)}
                />
            </Field>
            <div className="flex flex-wrap items-center gap-2">
                <span id={`${id}-tokens`} className="text-small text-ink-3">
                    {__('Add a token:')}
                </span>
                {tokens.map((token) => (
                    <Button
                        key={token}
                        type="button"
                        size="sm"
                        variant="neutral"
                        aria-describedby={`${id}-tokens`}
                        onClick={() =>
                            onChange(
                                `/${value.replace(/^\/|\/$/g, '')}/{${token}}/`.replace(
                                    /^\/\//,
                                    '/',
                                ),
                            )
                        }
                    >
                        {`{${token}}`}
                    </Button>
                ))}
            </div>
            <div className="bg-surface-3 grid gap-1 rounded-md px-4 py-3">
                <span className="text-caption text-ink-3 font-semibold uppercase">
                    {previewLabel}
                </span>
                <span className="text-small font-mono break-words">
                    {`${site}${render(value, values)}`}
                </span>
            </div>
            <div
                role="status"
                className="flex flex-wrap items-center gap-2"
                data-test={`${id}-check`}
            >
                {wrong === '' ? (
                    <StatusPill tone="ok">{__('Valid')}</StatusPill>
                ) : (
                    <StatusPill tone="danger">{__('Not valid')}</StatusPill>
                )}
                <span className="text-small text-ink-2">
                    {wrong === '' ? validLine : wrong}
                </span>
            </div>
        </>
    );
}

/**
 * Settings › Addresses: the public addresses of every public type, taxonomy
 * and author, each with a live example and a check. Old addresses keep
 * answering, with a redirect.
 */
export default function Addresses(page: AddressesPage) {
    const [progress, setProgress] = useState<{
        done: number;
        total: number;
        running: boolean;
    } | null>(null);
    const { submit } = useHttp();

    setLayoutProps({
        breadcrumbs: [
            { title: __('Settings'), href: settingsIndex() },
            {
                title: __('Addresses'),
                href: showSettings({ group: 'addresses' }),
            },
        ],
    });

    // After a save that moves addresses, ask every 2 s until it is done.
    useEffect(() => {
        if (progress === null || !progress.running) {
            return;
        }

        const timer = window.setTimeout(() => {
            void (
                submit(AddressesController.progress()) as Promise<{
                    done: number;
                    total: number;
                    running: boolean;
                }>
            ).then(setProgress);
        }, 2000);

        return () => window.clearTimeout(timer);
    }, [progress, submit]);

    return (
        <>
            <Head title={__('Addresses settings')} />

            <SettingsPage
                title={__('Addresses')}
                description={
                    <>
                        {__(
                            'The public addresses of every public type, taxonomy and author.',
                        )}{' '}
                        {__('The home page is chosen in')}{' '}
                        <TextButton asChild>
                            <Link href={showSettings({ group: 'reading' })}>
                                {__('Reading')}
                            </Link>
                        </TextButton>
                        .
                    </>
                }
            >
                {progress !== null && (
                    <p role="status" className="text-small text-ink-2 mb-4">
                        {progress.running
                            ? __('Updating addresses: :done of :total', {
                                  done: progress.done.toLocaleString(),
                                  total: progress.total.toLocaleString(),
                              })
                            : __('Every address is up to date.')}
                    </p>
                )}

                <SettingsForm
                    action={AddressesController.update.form()}
                    what={__(':page settings', { page: __('Addresses') })}
                    onSuccess={() =>
                        setProgress({ done: 0, total: 0, running: true })
                    }
                >
                    {({ errors }) => (
                        <AddressSections page={page} errors={errors} />
                    )}
                </SettingsForm>
            </SettingsPage>
        </>
    );
}

/**
 * The form's sections, holding what is typed: drawn inside SettingsForm, so
 * Discard draws them again from the page's props.
 */
function AddressSections({
    page,
    errors,
}: {
    page: AddressesPage;
    errors: Record<string, string>;
}) {
    const archive = page.types.find((type) => type.main);
    const pageType = page.types.find((type) => !type.main && type.hierarchical);
    const others = page.types.filter(
        (type) => !type.main && !type.hierarchical,
    );
    const values = { ...SAMPLE, ...page.example };
    const [patterns, setPatterns] = useState<Record<string, string>>(
        Object.fromEntries(page.types.map((type) => [type.key, type.pattern])),
    );
    const [forcedCustom, setForcedCustom] = useState(false);
    const [bases, setBases] = useState<Record<string, string>>(
        Object.fromEntries(
            page.taxonomies.map((taxonomy) => [taxonomy.key, taxonomy.base]),
        ),
    );
    const [authorBase, setAuthorBase] = useState(page.authorBase);

    const setPattern = (key: string, next: string) =>
        setPatterns((current) => ({ ...current, [key]: next }));

    const archivePattern = archive === undefined ? '' : patterns[archive.key];
    const preset =
        forcedCustom ||
        !presets().some((candidate) => candidate.pattern === archivePattern)
            ? 'custom'
            : archivePattern;
    const clash =
        archive !== undefined &&
        pageType !== undefined &&
        patterns[archive.key] === patterns[pageType.key]
            ? __(
                  'Pages already use this shape, so a post and a page could share an address. Put a fixed word or {parent} in one of them.',
              )
            : '';

    // What a change would move, for the warning above the sections.
    const moved = [
        ...page.types
            .filter((type) => patterns[type.key] !== type.pattern)
            .map((type) => type.plural.toLowerCase()),
        ...page.taxonomies
            .filter((taxonomy) => bases[taxonomy.key] !== taxonomy.base)
            .map((taxonomy) => taxonomy.plural.toLowerCase()),
        ...(authorBase !== page.authorBase ? [__('authors')] : []),
    ];

    const clashes = Object.entries(errors)
        .filter(([key]) => key.startsWith('addresses'))
        .map(([, message]) => message);

    return (
        <>
            {moved.length > 0 && (
                <Notice tone="warn" role="status" data-test="addresses-moving">
                    <p>
                        <strong>
                            {__('Changing an address moves it for good.')}
                        </strong>{' '}
                        {__(':what would get new addresses.', {
                            what: moved.join(` ${__('and')} `),
                        })}{' '}
                        {__(
                            'Each old address keeps working and sends readers on to the new one, but search engines take some days to learn the change.',
                        )}
                    </p>
                </Notice>
            )}

            {clashes.length > 0 && (
                <Notice
                    tone="danger"
                    role="alert"
                    title={__('These would share an address:')}
                >
                    <ul className="list-disc pl-5">
                        {clashes.map((message) => (
                            <li key={message}>{message}</li>
                        ))}
                    </ul>
                </Notice>
            )}

            {archive && (
                <SettingsPanel
                    title={archive.plural}
                    description={__(
                        'The shape of every post’s address. Changing it moves every post: the old addresses redirect to the new.',
                    )}
                >
                    <RadioGroup
                        aria-label={__('Address shape')}
                        className="grid-cols-1"
                        value={preset}
                        onValueChange={(next) => {
                            if (next === 'custom') {
                                setForcedCustom(true);

                                return;
                            }

                            setForcedCustom(false);
                            setPattern(archive.key, next);
                        }}
                    >
                        {presets().map((candidate) => (
                            <RadioGroupCard
                                key={candidate.pattern}
                                id={`preset-${candidate.pattern}`}
                                value={candidate.pattern}
                                label={candidate.label}
                                description={candidate.pattern}
                                mono
                            />
                        ))}
                        <RadioGroupCard
                            id="preset-custom"
                            value="custom"
                            label={__('Custom')}
                            description={__('Write your own pattern below.')}
                        />
                    </RadioGroup>
                    <PatternField
                        id={`pattern-${archive.key}`}
                        name={`patterns[${archive.key}]`}
                        label={__('Pattern')}
                        hint={__('The shape of every post’s address.')}
                        value={patterns[archive.key]}
                        onChange={(next) => {
                            setForcedCustom(false);
                            setPattern(archive.key, next);
                        }}
                        tokens={TOKENS}
                        kind="flat"
                        previewLabel={__('A post would be at')}
                        validLine={__('Every post gets an address of its own.')}
                        site={page.site}
                        values={values}
                        clash={clash}
                        error={errors[`patterns.${archive.key}`]}
                    />
                </SettingsPanel>
            )}

            {pageType && (
                <SettingsPanel
                    title={pageType.plural}
                    description={__(
                        'The shape of every page’s address. A page under another page carries its parent’s name.',
                    )}
                >
                    <PatternField
                        id={`pattern-${pageType.key}`}
                        name={`patterns[${pageType.key}]`}
                        label={__('Pattern')}
                        hint={__('The shape of every page’s address.')}
                        value={patterns[pageType.key]}
                        onChange={(next) => setPattern(pageType.key, next)}
                        tokens={PAGE_TOKENS}
                        kind="nested"
                        previewLabel={__('A page would be at')}
                        validLine={__('Every page gets an address of its own.')}
                        site={page.site}
                        values={values}
                        clash={clash}
                        error={errors[`patterns.${pageType.key}`]}
                    />
                </SettingsPanel>
            )}

            <SettingsPanel
                title={__('Archives and authors')}
                description={__(
                    'The word before the address of a category, a tag or an author.',
                )}
            >
                {page.taxonomies.length > 0 && (
                    <div className="grid gap-5 sm:grid-cols-2">
                        {page.taxonomies.map((taxonomy) => (
                            <Field
                                key={taxonomy.key}
                                htmlFor={`base-${taxonomy.key}`}
                                label={__(':name base', {
                                    name: taxonomy.singular,
                                })}
                                help={__(
                                    'The word before a :name’s address. Empty puts them at the root.',
                                    { name: taxonomy.singular.toLowerCase() },
                                )}
                                error={errors[`bases.${taxonomy.key}`]}
                            >
                                <Input
                                    id={`base-${taxonomy.key}`}
                                    name={`bases[${taxonomy.key}]`}
                                    value={bases[taxonomy.key] ?? ''}
                                    autoComplete="off"
                                    spellCheck={false}
                                    onChange={(event) =>
                                        setBases((current) => ({
                                            ...current,
                                            [taxonomy.key]: event.target.value,
                                        }))
                                    }
                                />
                            </Field>
                        ))}
                    </div>
                )}
                <Field
                    htmlFor="author-base"
                    label={__('Author base')}
                    help={__('The word before an author’s address.')}
                    error={errors.authorBase ?? errors.author_base}
                    required
                    className="sm:max-w-1/2"
                >
                    <Input
                        id="author-base"
                        name="author_base"
                        value={authorBase}
                        autoComplete="off"
                        spellCheck={false}
                        onChange={(event) => setAuthorBase(event.target.value)}
                    />
                </Field>
                <div className="bg-surface-3 grid gap-1 rounded-md px-4 py-3">
                    <span className="text-caption text-ink-3 font-semibold uppercase">
                        {__('They would be at')}
                    </span>
                    {page.taxonomies.map((taxonomy) => (
                        <span
                            key={taxonomy.key}
                            className="text-small font-mono break-words"
                        >
                            {`${page.site}/${bases[taxonomy.key] ? `${bases[taxonomy.key]}/` : ''}${
                                taxonomy.hierarchical
                                    ? values.category
                                    : 'photos'
                            }/`}
                        </span>
                    ))}
                    <span className="text-small font-mono break-words">
                        {`${page.site}/${authorBase || '…'}/${values.author}/`}
                    </span>
                </div>
            </SettingsPanel>

            {others.map((type) => {
                const typeArchive = page.archives.find(
                    (entry) => entry.key === type.key,
                );

                return (
                    <SettingsPanel
                        key={type.key}
                        title={type.plural}
                        description={__(
                            'A type added to this site. Each public type has its own archive and its own pattern.',
                        )}
                    >
                        {typeArchive && (
                            <Field
                                htmlFor={`archive-${type.key}`}
                                label={__('Archive address')}
                                help={__(
                                    'The :plural archive is at :address. Its feed is at :feed.',
                                    {
                                        plural: typeArchive.plural.toLowerCase(),
                                        address: typeArchive.address,
                                        feed: typeArchive.feed,
                                    },
                                )}
                                error={errors[`archives.${type.key}`]}
                                className="sm:max-w-1/2"
                            >
                                <Input
                                    id={`archive-${type.key}`}
                                    name={`archives[${type.key}]`}
                                    defaultValue={typeArchive.base}
                                    autoComplete="off"
                                    spellCheck={false}
                                />
                            </Field>
                        )}
                        {typeArchive?.holder != null && (
                            <p className="text-caption text-warn-ink">
                                {__(
                                    ':address shows :holder instead, because it had the address first. Give the archive page another address, or move :holder.',
                                    {
                                        address: typeArchive.address,
                                        holder: typeArchive.holder,
                                    },
                                )}
                            </p>
                        )}
                        <PatternField
                            id={`pattern-${type.key}`}
                            name={`patterns[${type.key}]`}
                            label={__('Pattern')}
                            hint={__(':singular address shape.', {
                                singular: type.singular,
                            })}
                            value={patterns[type.key]}
                            onChange={(next) => setPattern(type.key, next)}
                            tokens={TOKENS}
                            kind="flat"
                            previewLabel={__(':singular would be at', {
                                singular: `A ${type.singular.toLowerCase()}`,
                            })}
                            validLine={__(
                                'Every :singular gets an address of its own.',
                                { singular: type.singular.toLowerCase() },
                            )}
                            site={page.site}
                            values={values}
                            error={errors[`patterns.${type.key}`]}
                        />
                    </SettingsPanel>
                );
            })}
        </>
    );
}
