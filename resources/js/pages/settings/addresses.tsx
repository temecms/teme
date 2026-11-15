import { Head, Link, setLayoutProps, useHttp } from '@inertiajs/react';
import { Fragment, useEffect, useState } from 'react';
import AddressesController from '@/actions/App/Http/Admin/Settings/AddressesController';
import { SettingsForm } from '@/components/fields/settings-form';
import { SettingsPanel } from '@/components/fields/settings-panel';
import InputError from '@/components/input-error';
import { SettingsPage } from '@/components/patterns/settings-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Notice } from '@/components/ui/notice';
import { __ } from '@/lib/i18n';
import { show as showSettings } from '@/routes/settings';

type AddressType = {
    key: string;
    singular: string;
    plural: string;
    main: boolean;
    pattern: string;
};

type AddressTaxonomy = {
    key: string;
    singular: string;
    plural: string;
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
    example: Record<string, string> | null;
    archives: AddressArchive[];
};

/** WordPress's presets for the post address, then Custom. */
const PRESETS = [
    { label: 'Post name', pattern: '/{slug}/' },
    { label: 'Day and name', pattern: '/{year}/{month}/{day}/{slug}/' },
    { label: 'Month and name', pattern: '/{year}/{month}/{slug}/' },
    { label: 'Numeric', pattern: '/archives/{id}/' },
];

const TOKENS = ['slug', 'year', 'month', 'day', 'id', 'category', 'author'];

/** A pattern with the example's values in its tokens. */
function render(pattern: string, values: Record<string, string>): string {
    const path = pattern
        .replace(/\{([a-z]+)\}/g, (_, token: string) => values[token] ?? '')
        .replace(/\/{2,}/g, '/');

    return path.startsWith('/') ? path : `/${path}`;
}

/**
 * Settings › Addresses: WordPress's Permalinks screen. The post address with
 * its presets and custom tokens, every other public type's address, each
 * taxonomy's base and the author base. Old
 * addresses keep answering, with a redirect.
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
            <Head title="Addresses settings" />

            <SettingsPage
                title="Addresses"
                description="The public addresses of every public type, taxonomy and author."
                actions={
                    <Button variant="neutral" asChild>
                        <Link href={showSettings({ group: 'reading' })}>
                            Choose the home page
                        </Link>
                    </Button>
                }
            >
                {progress !== null && (
                    <p role="status" className="text-small text-ink-2 mb-4">
                        {progress.running
                            ? __('Updating addresses: :done of :total', {
                                  done: progress.done.toLocaleString(),
                                  total: progress.total.toLocaleString(),
                              })
                            : 'Every address is up to date.'}
                    </p>
                )}

                <Notice tone="info" className="mb-4">
                    <p>
                        Old addresses keep working: visitors and search engines
                        are sent to the new one.
                    </p>
                    <p>
                        The home page and the posts page are chosen in Reading.
                    </p>
                </Notice>

                <SettingsForm
                    action={AddressesController.update.form()}
                    saveLabel={__('Save address settings')}
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
    const others = page.types.filter((type) => !type.main);
    const [archivePattern, setArchivePattern] = useState(
        archive?.pattern ?? '',
    );
    const [custom, setCustom] = useState(
        !PRESETS.some((preset) => preset.pattern === archive?.pattern),
    );
    const [bases, setBases] = useState(
        Object.fromEntries(
            page.taxonomies.map((taxonomy) => [taxonomy.key, taxonomy.base]),
        ),
    );

    const clashes = Object.entries(errors)
        .filter(([key]) => key.startsWith('addresses'))
        .map(([, message]) => message);

    return (
        <>
            {clashes.length > 0 && (
                <Notice
                    tone="danger"
                    role="alert"
                    title="These would share an address:"
                >
                    <ul className="list-disc pl-5">
                        {clashes.map((clash) => (
                            <li key={clash}>{clash}</li>
                        ))}
                    </ul>
                </Notice>
            )}

            {archive && (
                <SettingsPanel title={`${archive.singular} address`}>
                    {PRESETS.map((preset) => (
                        <label
                            key={preset.pattern}
                            className="text-small flex items-center gap-2"
                        >
                            <input
                                type="radio"
                                name="preset"
                                checked={
                                    !custom && archivePattern === preset.pattern
                                }
                                onChange={() => {
                                    setCustom(false);
                                    setArchivePattern(preset.pattern);
                                }}
                            />
                            <span className="w-32">{preset.label}</span>
                            <code className="text-ink-2">
                                {page.example
                                    ? render(preset.pattern, page.example)
                                    : preset.pattern}
                            </code>
                        </label>
                    ))}
                    <label className="text-small flex items-center gap-2">
                        <input
                            type="radio"
                            name="preset"
                            checked={custom}
                            onChange={() => setCustom(true)}
                        />
                        <span className="w-32">Custom</span>
                    </label>
                    <input
                        type="hidden"
                        name={`patterns[${archive.key}]`}
                        value={archivePattern}
                    />
                    {custom && (
                        <div className="grid gap-2 pl-6">
                            <Input
                                aria-label={`Custom ${archive.singular.toLowerCase()} address`}
                                value={archivePattern}
                                onChange={(event) =>
                                    setArchivePattern(event.target.value)
                                }
                            />
                            <div className="flex flex-wrap gap-1">
                                {TOKENS.map((token) => (
                                    <Button
                                        key={token}
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            setArchivePattern(
                                                (current) =>
                                                    `${current.replace(/\/$/, '')}/{${token}}/`,
                                            )
                                        }
                                    >
                                        {`{${token}}`}
                                    </Button>
                                ))}
                            </div>
                        </div>
                    )}
                    {page.example && (
                        <p className="text-ink-2 text-small">
                            Your newest {archive.singular.toLowerCase()} would
                            be at{' '}
                            <span className="text-ink font-medium">
                                {render(archivePattern, page.example)}
                            </span>
                        </p>
                    )}
                    <InputError message={errors[`patterns.${archive.key}`]} />
                </SettingsPanel>
            )}

            {others.length > 0 && (
                <SettingsPanel label="Other addresses">
                    {others.map((type) => {
                        const typeArchive = page.archives.find(
                            (entry) => entry.key === type.key,
                        );

                        return (
                            <Fragment key={type.key}>
                                <div className="grid gap-2">
                                    <Label htmlFor={`pattern-${type.key}`}>
                                        {type.singular} address
                                    </Label>
                                    <Input
                                        id={`pattern-${type.key}`}
                                        name={`patterns[${type.key}]`}
                                        defaultValue={type.pattern}
                                    />
                                    <InputError
                                        message={errors[`patterns.${type.key}`]}
                                    />
                                </div>
                                {typeArchive && (
                                    <div className="grid gap-2">
                                        <Label htmlFor={`archive-${type.key}`}>
                                            {`${typeArchive.plural} archive page`}
                                        </Label>
                                        <Input
                                            id={`archive-${type.key}`}
                                            name={`archives[${type.key}]`}
                                            defaultValue={typeArchive.base}
                                            aria-describedby={`archive-${type.key}-hint`}
                                        />
                                        <p
                                            id={`archive-${type.key}-hint`}
                                            className="text-caption text-ink-3"
                                        >
                                            {`At ${typeArchive.address}. Its feed is at ${typeArchive.feed}.`}
                                        </p>
                                        {typeArchive.holder !== null && (
                                            <p className="text-caption text-warn-ink">
                                                {`${typeArchive.address} shows ${typeArchive.holder} instead, because it had the address first. Give the archive page another address, or move ${typeArchive.holder}.`}
                                            </p>
                                        )}
                                        <InputError
                                            message={
                                                errors[`archives.${type.key}`]
                                            }
                                        />
                                    </div>
                                )}
                            </Fragment>
                        );
                    })}
                </SettingsPanel>
            )}

            <SettingsPanel title="Bases">
                {page.taxonomies.map((taxonomy) => (
                    <div key={taxonomy.key} className="grid gap-2">
                        <Label htmlFor={`base-${taxonomy.key}`}>
                            {taxonomy.singular} base
                        </Label>
                        <Input
                            id={`base-${taxonomy.key}`}
                            name={`bases[${taxonomy.key}]`}
                            value={bases[taxonomy.key] ?? ''}
                            onChange={(event) =>
                                setBases((current) => ({
                                    ...current,
                                    [taxonomy.key]: event.target.value,
                                }))
                            }
                        />
                        {(bases[taxonomy.key] ?? '') === '' && (
                            <p className="text-caption text-ink-3">
                                {taxonomy.plural} will share the root with other
                                addresses.
                            </p>
                        )}
                        <InputError message={errors[`bases.${taxonomy.key}`]} />
                    </div>
                ))}
                <div className="grid gap-2">
                    <Label htmlFor="author-base">Author base</Label>
                    <Input
                        id="author-base"
                        name="author_base"
                        defaultValue={page.authorBase}
                    />
                    <InputError message={errors.authorBase} />
                </div>
            </SettingsPanel>
        </>
    );
}
