import { Form, Head, setLayoutProps } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import SiteCodeController from '@/actions/App/Http/Admin/Settings/SiteCodeController';
import { ConfirmAction } from '@/components/confirm-action';
import { SettingsPanel } from '@/components/fields/settings-panel';
import InputError from '@/components/input-error';
import { SettingsPage } from '@/components/patterns/settings-page';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Notice } from '@/components/ui/notice';
import { Textarea } from '@/components/ui/textarea';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { __ } from '@/lib/i18n';
import { formatInZone } from '@/lib/zoned-time';
import type { SiteCodeChange, SiteCodeEntry, SiteCodePage } from '@/types';

const controlClass =
    'border-edge bg-page focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

const codeClass =
    'bg-surface-2 text-ink overflow-x-auto rounded-md p-3 font-mono text-xs whitespace-pre-wrap break-all';

/** What is open in the drawer: nothing, a new entry, or one being edited. */
type Editing = null | 'new' | SiteCodeEntry;

function SiteCodeNotice({ locked }: { locked: boolean }) {
    return (
        <Notice
            tone={locked ? 'warn' : 'info'}
            data-test="site-code-notice"
            className="mb-4"
        >
            {locked ? (
                <p>
                    <strong className="font-medium">
                        The host has switched site code off.
                    </strong>{' '}
                    None of the code below is added to any page, and none of it
                    can be changed here. It’s kept as it was, for when the host
                    allows site code again.
                </p>
            ) : (
                <p>
                    <strong className="font-medium">
                        Code here runs in every reader’s browser.
                    </strong>{' '}
                    It runs in yours too when you view the site, and in
                    everyone’s who’s signed in. Add only code you trust. Saving
                    may ask for your password, and every change is logged, with
                    what it was before.
                </p>
            )}
        </Notice>
    );
}

function EntryRow({
    entry,
    locked,
    siteTimezone,
    onEdit,
}: {
    entry: SiteCodeEntry;
    locked: boolean;
    siteTimezone: string;
    onEdit: (entry: SiteCodeEntry) => void;
}) {
    const status = entry.on
        ? locked
            ? 'On, but switched off by the host'
            : 'On'
        : 'Off';

    return (
        <div
            data-test="site-code-entry"
            className="border-line space-y-3 rounded-md border p-4"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="flex flex-wrap items-center gap-2 text-sm">
                    <strong className="font-medium">{entry.name}</strong>
                    <span className="text-ink-2">{status}</span>
                </p>

                {!locked && (
                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            data-test="site-code-edit"
                            onClick={() => onEdit(entry)}
                        >
                            Edit<span className="sr-only"> {entry.name}</span>
                        </Button>

                        <Form
                            {...SiteCodeController.toggle.form({
                                entry: entry.id,
                            })}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="on"
                                        value={entry.on ? '0' : '1'}
                                    />
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        size="sm"
                                        disabled={processing}
                                        data-test="site-code-switch"
                                    >
                                        {entry.on ? 'Switch off' : 'Switch on'}
                                        <span className="sr-only">
                                            {' '}
                                            {entry.name}
                                        </span>
                                    </Button>
                                </>
                            )}
                        </Form>

                        <Form
                            {...SiteCodeController.destroy.form({
                                entry: entry.id,
                            })}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <ConfirmAction
                                    title={`Delete “${entry.name}”?`}
                                    description="It’s taken out of every public page. The list of changes keeps a copy of its code."
                                    confirmLabel="Delete code"
                                >
                                    <Button
                                        type="submit"
                                        variant="ghost"
                                        size="sm"
                                        disabled={processing}
                                        data-test="site-code-delete"
                                        className="text-danger-ink"
                                    >
                                        Delete
                                        <span className="sr-only">
                                            {' '}
                                            {entry.name}
                                        </span>
                                    </Button>
                                </ConfirmAction>
                            )}
                        </Form>
                    </div>
                )}
            </div>

            <pre className={codeClass}>{entry.code}</pre>

            {entry.structuredData && (
                <Notice tone="warn" data-test="site-code-structured-data">
                    <p>
                        <strong className="font-medium">
                            This code holds structured data.
                        </strong>{' '}
                        Teme already describes each page to search engines. A
                        script of type application/ld+json here adds a second
                        description, which search engines read separately and
                        may find at odds with Teme’s.
                    </p>
                </Notice>
            )}

            <p className="text-ink-2 text-xs">
                Last changed by {entry.changedBy},{' '}
                {formatInZone(entry.changedAt, siteTimezone)}
            </p>
        </div>
    );
}

/** One side of a change's declared hosts, under its code: the hosts, or "nothing". */
function ChangeOrigins({ origins }: { origins: string[] | null }) {
    if (origins === null) {
        return null;
    }

    return (
        <p className="text-xs [overflow-wrap:anywhere]">
            <span className="text-ink-2">{__('Also connects to')}</span>{' '}
            {origins.length === 0 ? (
                __('nothing')
            ) : (
                <span className="font-mono">{origins.join(', ')}</span>
            )}
        </p>
    );
}

function ChangeRow({
    change,
    siteTimezone,
}: {
    change: SiteCodeChange;
    siteTimezone: string;
}) {
    return (
        <li className="border-line space-y-2 border-b py-3 text-sm last:border-b-0">
            <p>
                <strong className="font-medium">
                    {change.what} “{change.name}”
                </strong>{' '}
                <span className="text-ink-2">
                    · {change.who}, {formatInZone(change.when, siteTimezone)}
                </span>
            </p>
            <details>
                <summary className="inline-flex min-h-6 cursor-pointer items-center text-xs underline underline-offset-4">
                    Before and after
                    <span className="sr-only">
                        : {change.what.toLowerCase()} “{change.name}”
                    </span>
                </summary>
                <div className="mt-2 grid gap-3 sm:grid-cols-2">
                    <div className="space-y-1">
                        <p className="text-ink-2 text-xs">Before</p>
                        <pre className={codeClass}>
                            {change.before ?? 'Nothing'}
                        </pre>
                        <ChangeOrigins origins={change.beforeOrigins} />
                    </div>
                    <div className="space-y-1">
                        <p className="text-ink-2 text-xs">After</p>
                        <pre className={codeClass}>
                            {change.after ?? 'Nothing'}
                        </pre>
                        <ChangeOrigins origins={change.afterOrigins} />
                    </div>
                </div>
            </details>
        </li>
    );
}

/**
 * The hosts an entry's code also connects to (I-3375), one a line, and for
 * a saved entry the addresses found in its code, which are allowed on
 * their own.
 */
function OriginsField({
    entry,
    error,
}: {
    entry: SiteCodeEntry | null;
    error?: string;
}) {
    const [before, after] = __('Found in the code: :origins.', {
        origins: '\u0000',
    }).split('\u0000');
    const describedBy = [
        'site-code-origins-hint',
        entry !== null && 'site-code-found',
        error && 'site-code-origins-error',
    ]
        .filter(Boolean)
        .join(' ');

    return (
        <div className="grid gap-2">
            <Label htmlFor="site-code-origins">
                {__('This code also connects to')}
            </Label>
            <textarea
                id="site-code-origins"
                name="origins"
                rows={3}
                spellCheck={false}
                autoComplete="off"
                defaultValue={entry?.origins.join('\n') ?? ''}
                aria-describedby={describedBy}
                aria-invalid={error ? true : undefined}
                className={`${controlClass} font-mono`}
            />
            <p id="site-code-origins-hint" className="text-ink-2 text-xs">
                {__(
                    'Analytics and chat code often send data to, or load more from, addresses that aren’t written in it. List each host, one a line: plausible.io, or *.example.com for every address under it. Addresses written in the code are found on their own.',
                )}
            </p>
            <InputError id="site-code-origins-error" message={error} />
            {entry !== null && (
                <p
                    id="site-code-found"
                    data-test="site-code-found"
                    className="text-ink-2 text-xs [overflow-wrap:anywhere]"
                >
                    {entry.found.length === 0 ? (
                        __('No addresses found in the code.')
                    ) : (
                        <>
                            {before}
                            {entry.found.map((origin, index) => (
                                <span key={origin}>
                                    {index > 0 && ', '}
                                    <code className="font-mono">{origin}</code>
                                </span>
                            ))}
                            {after}
                        </>
                    )}
                </p>
            )}
        </div>
    );
}

function CodeForm({
    entry,
    places,
    onClose,
}: {
    entry: SiteCodeEntry | null;
    places: SiteCodePage['places'];
    onClose: () => void;
}) {
    const form =
        entry === null
            ? SiteCodeController.store.form()
            : SiteCodeController.update.form({ entry: entry.id });

    return (
        <Form
            {...form}
            options={{ preserveScroll: true }}
            onSuccess={onClose}
            className="grid gap-4 overflow-y-auto px-4 pb-4"
        >
            {({ processing, errors }) => (
                <>
                    <InputError
                        id="site-code-entry-error"
                        message={errors.entry}
                    />

                    <div className="grid gap-2">
                        <Label htmlFor="site-code-name">Name</Label>
                        <Input
                            id="site-code-name"
                            name="name"
                            maxLength={120}
                            required
                            defaultValue={entry?.name ?? ''}
                            aria-describedby="site-code-name-hint site-code-name-error"
                        />
                        <p
                            id="site-code-name-hint"
                            className="text-ink-2 text-xs"
                        >
                            So everyone can tell what it is, such as “Visitor
                            analytics”.
                        </p>
                        <InputError
                            id="site-code-name-error"
                            message={errors.name}
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="site-code-placement">
                            Where it goes
                        </Label>
                        <select
                            id="site-code-placement"
                            name="placement"
                            defaultValue={entry?.placement ?? 'head'}
                            aria-describedby="site-code-placement-error"
                            className={controlClass}
                        >
                            {places.map((place) => (
                                <option key={place.key} value={place.key}>
                                    {place.label}
                                </option>
                            ))}
                        </select>
                        <InputError
                            id="site-code-placement-error"
                            message={errors.placement}
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="site-code-code">Code</Label>
                        <textarea
                            id="site-code-code"
                            name="code"
                            rows={8}
                            spellCheck={false}
                            defaultValue={entry?.code ?? ''}
                            aria-describedby="site-code-code-hint site-code-code-error"
                            className={`${controlClass} font-mono`}
                        />
                        <p
                            id="site-code-code-hint"
                            className="text-ink-2 text-xs"
                        >
                            Added to every public page, in the place you chose.
                        </p>
                        <InputError
                            id="site-code-code-error"
                            message={errors.code}
                        />
                    </div>

                    <OriginsField entry={entry} error={errors.origins} />

                    <div className="grid gap-2">
                        <div className="flex items-center gap-2">
                            <input type="hidden" name="on" value="0" />
                            <Checkbox
                                id="site-code-on"
                                name="on"
                                value="1"
                                defaultChecked={
                                    entry === null ? true : entry.on
                                }
                                aria-describedby="site-code-on-hint"
                            />
                            <Label htmlFor="site-code-on">Switched on</Label>
                        </div>
                        <p
                            id="site-code-on-hint"
                            className="text-ink-2 text-xs"
                        >
                            Off keeps it here without adding it to any page.
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        <Button
                            type="submit"
                            disabled={processing}
                            data-test="site-code-save"
                        >
                            {entry === null ? 'Add code' : 'Save code'}
                        </Button>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

/**
 * Settings › Site code (I-863 to I-866): the entries by placement, each
 * switched on or off, edited in a drawer, deleted after a confirmation; the
 * changes with their before and after; read-only while the host disallows
 * site code.
 */
export default function SiteCodeSettings({
    locked,
    siteTimezone,
    places,
    changes,
    adsTxt,
    adsTxtVersion,
}: SiteCodePage) {
    const [editing, setEditing] = useState<Editing>(null);

    setLayoutProps({
        breadcrumbs: [
            { title: __('Site code'), href: SiteCodeController.show.url() },
        ],
    });

    return (
        <>
            <Head title="Site code settings" />

            <SettingsPage
                title="Site code"
                description="Code added to every public page, such as analytics. To show something from another site in one item, add an Embed card to that item instead."
                actions={
                    !locked && (
                        <Button
                            type="button"
                            data-test="site-code-add"
                            onClick={() => setEditing('new')}
                        >
                            <Plus aria-hidden="true" />
                            Add code
                        </Button>
                    )
                }
            >
                <SiteCodeNotice locked={locked} />

                <div className="grid gap-4">
                    {places.map((place) => (
                        <SettingsPanel
                            key={place.key}
                            as="section"
                            title={place.label}
                            description={place.where}
                        >
                            {place.entries.length === 0 ? (
                                <p className="text-ink-2 text-sm">
                                    Nothing here.
                                </p>
                            ) : (
                                place.entries.map((entry) => (
                                    <EntryRow
                                        key={entry.id}
                                        entry={entry}
                                        locked={locked}
                                        siteTimezone={siteTimezone}
                                        onEdit={setEditing}
                                    />
                                ))
                            )}
                        </SettingsPanel>
                    ))}

                    <SettingsPanel
                        as="section"
                        title={__('ads.txt')}
                        description={__(
                            'Shown at /ads.txt for advertising buyers. Leave empty for a 404.',
                        )}
                    >
                        <Form
                            {...SiteCodeController.updateAdsTxt.form()}
                            options={{ preserveScroll: true }}
                            className="grid gap-3"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="expected_version"
                                        value={adsTxtVersion}
                                    />
                                    <Label htmlFor="site-code-ads-txt">
                                        {__('ads.txt')}
                                    </Label>
                                    <Textarea
                                        id="site-code-ads-txt"
                                        name="ads_txt"
                                        rows={6}
                                        spellCheck={false}
                                        defaultValue={adsTxt}
                                        data-test="site-code-ads-txt"
                                        aria-describedby="site-code-ads-txt-hint site-code-ads-txt-error"
                                        className="font-mono"
                                    />
                                    <p
                                        id="site-code-ads-txt-hint"
                                        className="text-ink-2 text-small"
                                    >
                                        {__(
                                            'One record a line, as the ads.txt specification asks.',
                                        )}
                                    </p>
                                    <InputError
                                        id="site-code-ads-txt-error"
                                        message={
                                            errors.ads_txt ??
                                            errors.expected_version
                                        }
                                    />
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        data-test="site-code-ads-txt-save"
                                    >
                                        {__('Save ads.txt')}
                                    </Button>
                                </>
                            )}
                        </Form>
                    </SettingsPanel>

                    <SettingsPanel
                        as="section"
                        title="Changes"
                        description="Newest first. The security log keeps each one for a year."
                    >
                        {changes.length === 0 ? (
                            <p className="text-ink-2 text-sm">
                                No changes yet.
                            </p>
                        ) : (
                            <ul>
                                {changes.map((change) => (
                                    <ChangeRow
                                        key={change.id}
                                        change={change}
                                        siteTimezone={siteTimezone}
                                    />
                                ))}
                            </ul>
                        )}
                    </SettingsPanel>
                </div>
            </SettingsPage>

            <Sheet
                open={editing !== null}
                onOpenChange={(open) => !open && setEditing(null)}
            >
                <SheetContent side="right" className="w-full sm:max-w-[560px]">
                    <SheetHeader>
                        <SheetTitle>
                            {editing === null || editing === 'new'
                                ? 'Add code to every page'
                                : `Edit “${editing.name}”`}
                        </SheetTitle>
                        <SheetDescription>
                            Name it, choose where it goes, and paste the code.
                        </SheetDescription>
                    </SheetHeader>
                    {editing !== null && (
                        <CodeForm
                            key={editing === 'new' ? 'new' : editing.id}
                            entry={editing === 'new' ? null : editing}
                            places={places}
                            onClose={() => setEditing(null)}
                        />
                    )}
                </SheetContent>
            </Sheet>
        </>
    );
}
