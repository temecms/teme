import type { FlashData, RequestPayload } from '@inertiajs/core';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import ExtensionsController from '@/actions/App/Http/Admin/ExtensionsController';
import { send } from '@/components/editor/editor-http';
import { DirectoryTab } from '@/components/extensions/directory-tab';
import { useAsk } from '@/components/extensions/use-ask';
import { ResponsiveSheetContent } from '@/components/patterns/responsive-sheet';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Notice } from '@/components/ui/notice';
import { ScrollArea } from '@/components/ui/scroll-area';
import { SelectField } from '@/components/ui/select-field';
import {
    Sheet,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { __ } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type {
    ExtensionKind,
    ExtensionSourceLine,
    ExtensionsData,
    ExtensionUpload,
} from '@/types';

type Tab = 'directory' | 'packagist' | 'git' | 'composer' | 'upload';

/** One Packagist result, as the search answers it. */
type Found = {
    name: string;
    description: string;
    downloads: number;
    url: string | null;
};

/** A package installed here from the admin, so an upload of it reads as an update. */
export type Installed = { package: string; version: string };

/** How long typing rests before Packagist is asked. */
const SEARCH_MS = 350;

/** A package's display name: the part after the vendor, as words. */
function title(name: string): string {
    const last = name.split('/').pop() ?? name;
    const words = last
        .replace(/^teme-(plugin|theme)-/, '')
        .replace(/[-_]+/g, ' ');

    return words.charAt(0).toUpperCase() + words.slice(1);
}

/** One value the server flashed with its answer. */
export function flashed(flash: FlashData, key: string): unknown {
    return (flash as Record<string, unknown>)[key];
}

/**
 * Packagist: search by name or word, only Teme's own package type, then a
 * version, defaulting to the newest that fits.
 */
function PackagistTab({
    kind,
    enabled,
    chosen,
    onChoose,
    constraint,
    onConstraint,
}: {
    kind: ExtensionKind;
    enabled: boolean;
    chosen: string | null;
    onChoose: (name: string | null) => void;
    constraint: string;
    onConstraint: (constraint: string) => void;
}) {
    const [q, setQ] = useState('');
    const [results, setResults] = useState<Found[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [searching, setSearching] = useState(false);
    const [versions, setVersions] = useState<string[] | null>(null);
    const words = q.trim();

    useEffect(() => {
        if (!enabled || words.length < 2) {
            setResults([]);
            setError(null);

            return;
        }

        let stopped = false;
        const timer = window.setTimeout(() => {
            setSearching(true);
            void send(
                ExtensionsController.search({ query: { q: words, kind } }),
            ).then((answer) => {
                if (stopped) {
                    return;
                }

                setSearching(false);
                setResults((answer.body.results as Found[] | undefined) ?? []);
                setError(
                    (answer.body.error as string | null | undefined) ??
                        (answer.status === 200
                            ? null
                            : __(
                                  'Packagist could not be searched. Try again.',
                              )),
                );
            });
        }, SEARCH_MS);

        return () => {
            stopped = true;
            window.clearTimeout(timer);
        };
    }, [words, kind, enabled]);

    useEffect(() => {
        setVersions(null);

        if (chosen === null) {
            return;
        }

        let stopped = false;

        void send(
            ExtensionsController.search({ query: { package: chosen } }),
        ).then((answer) => {
            if (stopped) {
                return;
            }

            const list = (answer.body.versions as string[] | undefined) ?? [];
            setVersions(list);
            onConstraint(list.length > 0 ? `^${list[0]}` : '*');
        });

        return () => {
            stopped = true;
        };
        // onConstraint is the sheet's own setter, the same on every draw.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [chosen]);

    if (!enabled) {
        return (
            <Notice tone="info">
                {__('Searching Packagist is turned off on this server.')}
            </Notice>
        );
    }

    const newest =
        versions !== null && versions.length > 0 ? versions[0] : null;

    return (
        <div className="grid gap-4">
            <Field
                htmlFor="extension-search"
                label={__('Search Packagist')}
                help={
                    kind === 'plugin'
                        ? __('By name or a word. Only Teme plugins are listed.')
                        : __('By name or a word. Only Teme themes are listed.')
                }
            >
                <Input
                    id="extension-search"
                    type="search"
                    value={q}
                    autoComplete="off"
                    spellCheck={false}
                    data-test="extension-search"
                    onChange={(event) => {
                        setQ(event.target.value);
                        onChoose(null);
                    }}
                />
            </Field>
            <p className="sr-only" role="status" aria-live="polite">
                {searching
                    ? __('Searching…')
                    : words.length >= 2
                      ? __(':count found', { count: results.length })
                      : ''}
            </p>
            {error !== null && <Notice tone="danger">{error}</Notice>}
            {results.length > 0 && (
                <ul className="grid gap-2" data-test="extension-results">
                    {results.map((found) => {
                        const isChosen = found.name === chosen;

                        return (
                            <li key={found.name} className="min-w-0">
                                <div
                                    data-test="extension-result"
                                    className={cn(
                                        'border-line flex min-w-0 flex-wrap items-start gap-x-3 gap-y-2 rounded-md border px-3 py-2',
                                        isChosen &&
                                            'border-accent bg-accent-soft',
                                    )}
                                >
                                    <div className="grid min-w-0 flex-1 basis-48 gap-0.5">
                                        <p className="text-body text-ink font-semibold">
                                            {title(found.name)}
                                        </p>
                                        <p className="text-caption text-ink-2 font-mono break-all">
                                            {found.name}
                                        </p>
                                        {found.description !== '' && (
                                            <p className="text-small text-ink-2 break-words">
                                                {found.description}
                                            </p>
                                        )}
                                        <p className="text-caption text-ink-2 flex flex-wrap gap-x-3">
                                            <span>
                                                {__(':count installs', {
                                                    count: found.downloads.toLocaleString(),
                                                })}
                                            </span>
                                            {isChosen && newest !== null && (
                                                <span>
                                                    {__('Newest :version', {
                                                        version: newest,
                                                    })}
                                                </span>
                                            )}
                                        </p>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="neutral"
                                        size="sm"
                                        aria-pressed={isChosen}
                                        data-test="extension-choose"
                                        onClick={() =>
                                            onChoose(
                                                isChosen ? null : found.name,
                                            )
                                        }
                                    >
                                        {isChosen ? __('Chosen') : __('Choose')}
                                        <span className="sr-only">
                                            {' '}
                                            {found.name}
                                        </span>
                                    </Button>
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}
            {chosen !== null && versions !== null && (
                <Field
                    htmlFor="extension-version"
                    label={__('Version')}
                    help={__(
                        'The newest that fits also takes later compatible versions when you update.',
                    )}
                >
                    <SelectField
                        id="extension-version"
                        value={constraint}
                        onValueChange={onConstraint}
                        options={[
                            {
                                value: newest !== null ? `^${newest}` : '*',
                                label:
                                    newest !== null
                                        ? __(
                                              'The newest that fits (:version now)',
                                              { version: newest },
                                          )
                                        : __('The newest that fits'),
                            },
                            ...versions.map((version) => ({
                                value: version,
                                label: __('Exactly :version', { version }),
                            })),
                        ]}
                    />
                </Field>
            )}
        </div>
    );
}

/**
 * A Git address or a vendor's Composer source: a saved source, or a new one
 * saved first, then the package and its version.
 */
function SourceTab({
    sourceKind,
    offered,
    sources,
    sourceId,
    onSource,
    pkg,
    onPackage,
    constraint,
    onConstraint,
}: {
    sourceKind: 'git' | 'composer';
    offered: boolean;
    sources: ExtensionSourceLine[];
    sourceId: string;
    onSource: (id: string) => void;
    pkg: string;
    onPackage: (name: string) => void;
    constraint: string;
    onConstraint: (constraint: string) => void;
}) {
    const { ask, busy, password } = useAsk();
    const [label, setLabel] = useState('');
    const [url, setUrl] = useState('');
    const [username, setUsername] = useState('');
    const [credential, setCredential] = useState('');
    const prefix = `extension-${sourceKind}`;

    if (!offered) {
        return (
            <Notice tone="info">
                {__(
                    'The installer on this server doesn’t offer this source yet.',
                )}
            </Notice>
        );
    }

    const save = () =>
        ask({
            url: ExtensionsController.storeSource.url(),
            data: {
                kind: sourceKind,
                label,
                url,
                username: username === '' ? null : username,
                credential: credential === '' ? null : credential,
            },
            onDone: () => {
                setLabel('');
                setUrl('');
                setUsername('');
                setCredential('');
            },
        });

    return (
        <div className="grid gap-4">
            {sources.length > 0 && (
                <Field htmlFor={`${prefix}-source`} label={__('Saved source')}>
                    <SelectField
                        id={`${prefix}-source`}
                        value={sourceId}
                        onValueChange={onSource}
                        emptyLabel={__('Choose a source')}
                        options={sources.map((source) => ({
                            value: source.id,
                            label:
                                source.url === null
                                    ? source.label
                                    : `${source.label} (${source.url})`,
                        }))}
                    />
                </Field>
            )}
            <fieldset className="border-line grid min-w-0 gap-3 rounded-md border p-4">
                <legend className="text-small px-1 font-semibold">
                    {sourceKind === 'git'
                        ? __('Add a Git address')
                        : __('Add a vendor source')}
                </legend>
                <Field htmlFor={`${prefix}-label`} label={__('Name')}>
                    <Input
                        id={`${prefix}-label`}
                        value={label}
                        maxLength={100}
                        onChange={(event) => setLabel(event.target.value)}
                    />
                </Field>
                <Field
                    htmlFor={`${prefix}-url`}
                    label={__('Address')}
                    help={__(
                        'An https address, with no name or password in it.',
                    )}
                >
                    <Input
                        id={`${prefix}-url`}
                        type="url"
                        value={url}
                        spellCheck={false}
                        onChange={(event) => setUrl(event.target.value)}
                    />
                </Field>
                {sourceKind === 'composer' && (
                    <>
                        <Field
                            htmlFor={`${prefix}-username`}
                            label={__('Username')}
                        >
                            <Input
                                id={`${prefix}-username`}
                                value={username}
                                autoComplete="off"
                                spellCheck={false}
                                onChange={(event) =>
                                    setUsername(event.target.value)
                                }
                            />
                        </Field>
                        <Field
                            htmlFor={`${prefix}-credential`}
                            label={__('License key')}
                            help={__(
                                'Kept encrypted. Teme never shows it again.',
                            )}
                        >
                            <Input
                                id={`${prefix}-credential`}
                                type="password"
                                value={credential}
                                autoComplete="off"
                                onChange={(event) =>
                                    setCredential(event.target.value)
                                }
                            />
                        </Field>
                    </>
                )}
                <div>
                    <Button
                        type="button"
                        variant="neutral"
                        size="sm"
                        loading={busy}
                        disabled={label === '' || url === ''}
                        onClick={save}
                        data-test={`${prefix}-save`}
                    >
                        {__('Save source')}
                    </Button>
                </div>
            </fieldset>
            <Field
                htmlFor={`${prefix}-package`}
                label={__('Package')}
                help={__('Its Composer name, such as vendor/name.')}
            >
                <Input
                    id={`${prefix}-package`}
                    value={pkg}
                    spellCheck={false}
                    autoComplete="off"
                    onChange={(event) => onPackage(event.target.value.trim())}
                />
            </Field>
            <Field
                htmlFor={`${prefix}-constraint`}
                label={__('Version')}
                help={__('Leave it empty for the newest that fits.')}
            >
                <Input
                    id={`${prefix}-constraint`}
                    value={constraint}
                    spellCheck={false}
                    autoComplete="off"
                    className="font-mono"
                    onChange={(event) =>
                        onConstraint(event.target.value.trim())
                    }
                />
            </Field>
            {password}
        </div>
    );
}

/** Upload ZIP: Teme reads the package and version from the archive's composer.json. */
function UploadTab({
    uploadMax,
    installed,
    uploaded,
    onUploaded,
}: {
    uploadMax: number;
    installed: Installed[];
    uploaded: ExtensionUpload | null;
    onUploaded: (upload: ExtensionUpload | null) => void;
}) {
    const { ask, busy, password } = useAsk();
    const megabytes = Math.max(1, Math.round(uploadMax / 1048576));
    const current =
        uploaded === null
            ? undefined
            : installed.find((one) => one.package === uploaded.package);

    const choose = (file: File | undefined) => {
        onUploaded(null);

        if (file === undefined) {
            return;
        }

        if (file.size > uploadMax) {
            toast.error(
                __('The archive is larger than :size MB.', {
                    size: megabytes,
                }),
            );

            return;
        }

        ask({
            url: ExtensionsController.upload.url(),
            data: { archive: file },
            onFlash: (flash) => {
                const kept = flashed(flash, 'extensionUpload');

                if (kept !== null && typeof kept === 'object') {
                    onUploaded(kept as ExtensionUpload);
                }
            },
        });
    };

    return (
        <div className="grid gap-4">
            <Field
                htmlFor="extension-upload"
                label={__('ZIP archive')}
                help={__(
                    'A Composer package with its composer.json, at most :size MB.',
                    { size: megabytes },
                )}
            >
                <Input
                    id="extension-upload"
                    type="file"
                    accept=".zip,application/zip"
                    disabled={busy}
                    data-test="extension-upload"
                    onChange={(event) => choose(event.target.files?.[0])}
                />
            </Field>
            {uploaded !== null && (
                <Notice tone="info" data-test="extension-uploaded">
                    <p className="break-words">
                        {__('Read from the archive: :package :version', {
                            package: uploaded.package,
                            version: uploaded.version,
                        })}
                    </p>
                    {current !== undefined && (
                        <p className="text-small text-ink-2">
                            {__('It replaces :version, installed now.', {
                                version: current.version,
                            })}
                        </p>
                    )}
                </Notice>
            )}
            {password}
        </div>
    );
}

/**
 * The Add plugin or Add theme sheet (I-211): Packagist, a Git address, a
 * vendor's source or an uploaded ZIP, or Teme's own directory. Asking records a request; the
 * request's own sheet then follows the installer.
 */
export function AddSheet({
    kind,
    open,
    data,
    installed,
    onClose,
    onRequested,
}: {
    kind: ExtensionKind;
    open: boolean;
    data: ExtensionsData;
    installed: Installed[];
    onClose: () => void;
    onRequested: (id: string) => void;
}) {
    const { ask, busy, password } = useAsk();
    const [tab, setTab] = useState<Tab>(
        data.directory ? 'directory' : data.packagist ? 'packagist' : 'upload',
    );
    const [chosen, setChosen] = useState<string | null>(null);
    const [pkg, setPkg] = useState('');
    const [sourceId, setSourceId] = useState('');
    const [constraint, setConstraint] = useState('');
    const [uploaded, setUploaded] = useState<ExtensionUpload | null>(null);

    const switchTo = (next: Tab) => {
        setTab(next);
        setChosen(null);
        setPkg('');
        setSourceId('');
        setConstraint('');
    };

    const payload = (): RequestPayload | null => {
        switch (tab) {
            case 'directory':
                return chosen === null
                    ? null
                    : {
                          action: 'install',
                          kind,
                          package: chosen,
                          constraint: constraint === '' ? null : constraint,
                          sourceKind: 'directory',
                      };
            case 'packagist':
                return chosen === null
                    ? null
                    : {
                          action: 'install',
                          kind,
                          package: chosen,
                          constraint: constraint === '' ? null : constraint,
                          sourceKind: 'packagist',
                      };
            case 'git':
            case 'composer':
                return pkg === '' || sourceId === ''
                    ? null
                    : {
                          action: 'install',
                          kind,
                          package: pkg,
                          constraint: constraint === '' ? null : constraint,
                          sourceKind: tab,
                          sourceId,
                      };
            case 'upload':
                return uploaded === null
                    ? null
                    : {
                          action: installed.some(
                              (one) => one.package === uploaded.package,
                          )
                              ? 'update'
                              : 'install',
                          kind,
                          package: uploaded.package,
                          sourceKind: 'upload',
                          uploadId: uploaded.id,
                      };
        }
    };

    const body = payload();

    const submit = () => {
        if (body === null) {
            return;
        }

        ask({
            url: ExtensionsController.store.url(),
            data: body,
            onFlash: (flash) => {
                const id = flashed(flash, 'extensionRequest');

                if (typeof id === 'string') {
                    onRequested(id);
                }
            },
        });
    };

    const sourcesOf = (sourceKind: string) =>
        data.sources.filter((source) => source.kind === sourceKind);

    return (
        <Sheet open={open} onOpenChange={(next) => !next && onClose()}>
            <ResponsiveSheetContent
                side="right"
                data-test="extension-add-sheet"
            >
                <SheetHeader>
                    <SheetTitle>
                        {kind === 'plugin' ? __('Add plugin') : __('Add theme')}
                    </SheetTitle>
                    <SheetDescription>
                        {kind === 'plugin'
                            ? __('Choose where the plugin comes from.')
                            : __('Choose where the theme comes from.')}
                    </SheetDescription>
                </SheetHeader>
                <ScrollArea className="min-h-0 flex-1">
                    <Tabs
                        value={tab}
                        onValueChange={(value) => switchTo(value as Tab)}
                        className="min-w-0 px-6 pb-4"
                    >
                        <TabsList
                            aria-label={__('Where it comes from')}
                            className="flex-wrap"
                        >
                            {data.directory && (
                                <TabsTrigger
                                    value="directory"
                                    data-test="extension-tab-directory"
                                >
                                    {__('Directory')}
                                </TabsTrigger>
                            )}
                            <TabsTrigger
                                value="packagist"
                                data-test="extension-tab-packagist"
                            >
                                {__('Packagist')}
                            </TabsTrigger>
                            <TabsTrigger
                                value="git"
                                data-test="extension-tab-git"
                            >
                                {__('Git address')}
                            </TabsTrigger>
                            <TabsTrigger
                                value="composer"
                                data-test="extension-tab-composer"
                            >
                                {__('Vendor source')}
                            </TabsTrigger>
                            <TabsTrigger
                                value="upload"
                                data-test="extension-tab-upload"
                            >
                                {__('Upload ZIP')}
                            </TabsTrigger>
                        </TabsList>
                        {data.directory && (
                            <TabsContent value="directory">
                                <DirectoryTab
                                    kind={kind}
                                    installed={installed.map(
                                        (one) => one.package,
                                    )}
                                    chosen={chosen}
                                    onChoose={setChosen}
                                    constraint={constraint}
                                    onConstraint={setConstraint}
                                />
                            </TabsContent>
                        )}
                        <TabsContent value="packagist">
                            <PackagistTab
                                kind={kind}
                                enabled={data.packagist}
                                chosen={chosen}
                                onChoose={setChosen}
                                constraint={constraint}
                                onConstraint={setConstraint}
                            />
                        </TabsContent>
                        <TabsContent value="git">
                            <SourceTab
                                sourceKind="git"
                                offered={data.sourceKinds.includes('git')}
                                sources={sourcesOf('git')}
                                sourceId={sourceId}
                                onSource={setSourceId}
                                pkg={pkg}
                                onPackage={setPkg}
                                constraint={constraint}
                                onConstraint={setConstraint}
                            />
                        </TabsContent>
                        <TabsContent value="composer">
                            <SourceTab
                                sourceKind="composer"
                                offered={data.sourceKinds.includes('composer')}
                                sources={sourcesOf('composer')}
                                sourceId={sourceId}
                                onSource={setSourceId}
                                pkg={pkg}
                                onPackage={setPkg}
                                constraint={constraint}
                                onConstraint={setConstraint}
                            />
                        </TabsContent>
                        <TabsContent value="upload">
                            <UploadTab
                                uploadMax={data.uploadMax}
                                installed={installed}
                                uploaded={uploaded}
                                onUploaded={setUploaded}
                            />
                        </TabsContent>
                    </Tabs>
                </ScrollArea>
                <SheetFooter className="sm:flex-col sm:justify-start">
                    <p className="text-small text-ink-2">
                        {__(
                            'Teme asks for your password, records the request, and the installer carries it out. Nothing is enabled until you enable it.',
                        )}
                    </p>
                    <div className="flex flex-wrap justify-end gap-2">
                        <Button
                            type="button"
                            variant="neutral"
                            onClick={onClose}
                        >
                            {__('Cancel')}
                        </Button>
                        <Button
                            type="button"
                            loading={busy}
                            disabled={body === null}
                            onClick={submit}
                            data-test="extension-ask"
                        >
                            {__('Ask to install')}
                        </Button>
                    </div>
                </SheetFooter>
                {password}
            </ResponsiveSheetContent>
        </Sheet>
    );
}
