import { Form, Head, router, setLayoutProps } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { CSSProperties, ReactNode } from 'react';
import SiteCodeController from '@/actions/App/Http/Admin/Settings/SiteCodeController';
import { ConfirmAction } from '@/components/confirm-action';
import { SettingsPanel } from '@/components/fields/settings-panel';
import InputError from '@/components/input-error';
import { EmptyState } from '@/components/list/empty-state';
import { FreshPasswordDialog } from '@/components/patterns/fresh-password';
import { ResponsiveSheetContent } from '@/components/patterns/responsive-sheet';
import { SettingsPage } from '@/components/patterns/settings-page';
import { Button } from '@/components/ui/button';
import { Field, FieldInline } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Notice } from '@/components/ui/notice';
import {
    RadioGroup,
    RadioGroupCard,
    RadioGroupChoice,
} from '@/components/ui/radio-group';
import { ScrollArea } from '@/components/ui/scroll-area';
import {
    Sheet,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { StatusPill } from '@/components/ui/status-pill';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { __ } from '@/lib/i18n';
import { formatInZone } from '@/lib/zoned-time';
import { index as settingsIndex } from '@/routes/settings';
import type { SiteCodeChange, SiteCodeEntry, SiteCodePage } from '@/types';

/** A placement as the page draws it: its chip on an entry, and its line in the form. */
type Place = SiteCodePage['places'][number] & { chip: string; hint: string };

/** One inline script a plugin asked to keep, and what was decided about it. */
type PluginScriptRow = {
    id: string;
    plugin: string;
    pluginName: string;
    /** The field it was written in, such as "Sponsor banner: Code". */
    name: string;
    script: string;
    state: 'waiting' | 'approved' | 'refused';
    requestedBy: string;
    requestedAt: string;
    decidedBy: string | null;
    decidedAt: string | null;
};

type Props = Omit<SiteCodePage, 'places'> & {
    places: Place[];
    pluginScripts: PluginScriptRow[];
};

/** What is open in the sheet: nothing, a new entry, or one being edited. */
type Editing = null | 'new' | SiteCodeEntry;

/** The code of an entry or a script, in its box: it wraps, and scrolls past twelve lines. */
function CodeBox({ label, children }: { label: string; children: string }) {
    return (
        <ScrollArea
            label={label}
            className="bg-surface inset-ring-edge max-h-48 rounded-md px-4 py-3 inset-ring"
        >
            <pre className="text-small text-ink font-mono wrap-anywhere whitespace-pre-wrap">
                {children}
            </pre>
        </ScrollArea>
    );
}

/** An entry or a script: a grey card with its head, its code and the line under it. */
function Card({ label, children }: { label: string; children: ReactNode }) {
    return (
        <article
            aria-label={label}
            className="bg-surface-2 grid gap-3 rounded-lg p-4"
        >
            {children}
        </article>
    );
}

/** The hosts an entry connects to: those found in its code, then those declared for it. */
function hostsOf(entry: SiteCodeEntry): string[] {
    const found = entry.found.map((origin) =>
        origin.replace(/^[a-z]+:\/\//i, ''),
    );

    return [...new Set([...found, ...entry.origins])];
}

function EntryCard({
    entry,
    chip,
    locked,
    siteTimezone,
    onEdit,
}: {
    entry: SiteCodeEntry;
    chip: string;
    locked: boolean;
    siteTimezone: string;
    onEdit: (entry: SiteCodeEntry) => void;
}) {
    const hosts = hostsOf(entry);
    const changed = __('Last changed by :who, :when.', {
        who: entry.changedBy,
        when: formatInZone(entry.changedAt, siteTimezone),
    });

    return (
        <Card label={entry.name}>
            <div
                data-test="site-code-entry"
                className="flex flex-wrap items-center gap-3"
            >
                <span className="text-body text-ink font-semibold">
                    {entry.name}
                </span>
                {entry.on ? (
                    <StatusPill tone={locked ? 'warn' : 'ok'}>
                        {locked
                            ? __('On, but switched off by the host')
                            : __('On')}
                    </StatusPill>
                ) : (
                    <StatusPill tone="neutral">{__('Off')}</StatusPill>
                )}
                <StatusPill tone="neutral" flat>
                    {chip}
                </StatusPill>

                {!locked && (
                    <div className="flex flex-wrap items-center gap-2 sm:ms-auto">
                        <Button
                            type="button"
                            variant="neutral"
                            size="sm"
                            data-test="site-code-edit"
                            onClick={() => onEdit(entry)}
                        >
                            {__('Edit')}
                            <span className="sr-only"> {entry.name}</span>
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
                                        variant="neutral"
                                        size="sm"
                                        disabled={processing}
                                        data-test="site-code-switch"
                                    >
                                        {entry.on
                                            ? __('Switch off')
                                            : __('Switch on')}
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
                                    title={__('Delete “:name”?', {
                                        name: entry.name,
                                    })}
                                    description={__(
                                        'It’s taken out of every public page. The list of changes keeps a copy of its code.',
                                    )}
                                    confirmLabel={__('Delete code')}
                                >
                                    <Button
                                        type="submit"
                                        variant="danger-quiet"
                                        size="sm"
                                        disabled={processing}
                                        data-test="site-code-delete"
                                    >
                                        <Trash2 aria-hidden="true" />
                                        {__('Delete')}
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

            <CodeBox label={__('Code of :name', { name: entry.name })}>
                {entry.code}
            </CodeBox>

            {entry.structuredData && <StructuredDataNotice />}

            <p className="text-small text-ink-3 wrap-anywhere">
                {hosts.length === 0
                    ? __('Connects to no other address.')
                    : __('Connects to :hosts.', {
                          hosts: hosts.join(', '),
                      })}{' '}
                {changed}
            </p>
        </Card>
    );
}

function StructuredDataNotice() {
    return (
        <Notice tone="warn" data-test="site-code-structured-data">
            <p>
                <strong className="font-semibold">
                    {__('This code holds structured data.')}
                </strong>{' '}
                {__(
                    'Teme already describes each page to search engines. A script of type application/ld+json here adds a second description, which search engines read separately and may find at odds with Teme’s.',
                )}
            </p>
        </Notice>
    );
}

/** One change as a line: who did what to which entry, and when under it. */
function ChangeLine({
    change,
    siteTimezone,
}: {
    change: SiteCodeChange;
    siteTimezone: string;
}) {
    const who = { who: change.who, name: change.name };
    const words = {
        Added: __(':who added “:name”', who),
        Changed: __(':who edited “:name”', who),
        'Switched on': __(':who switched on “:name”', who),
        'Switched off': __(':who switched off “:name”', who),
        Deleted: __(':who deleted “:name”', who),
    } satisfies Record<SiteCodeChange['what'], string>;

    return (
        <li className="grid gap-0.5">
            <span className="text-body text-ink font-medium">
                {words[change.what]}
            </span>
            <span className="text-small text-ink-3">
                {formatInZone(change.when, siteTimezone)}
            </span>
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
        <div className="grid gap-1">
            <Label htmlFor="site-code-origins">
                {__('This code also connects to')}
            </Label>
            <Textarea
                id="site-code-origins"
                name="origins"
                rows={3}
                spellCheck={false}
                autoComplete="off"
                defaultValue={entry?.origins.join('\n') ?? ''}
                aria-describedby={describedBy}
                aria-invalid={error ? true : undefined}
                className="font-mono"
            />
            <p id="site-code-origins-hint" className="text-caption text-ink-3">
                {__(
                    'Analytics and chat code often send data to, or load more from, addresses that aren’t written in it. List each host, one a line: plausible.io, or *.example.com for every address under it. Addresses written in the code are found on their own.',
                )}
            </p>
            <InputError id="site-code-origins-error" message={error} />
            {entry !== null && (
                <p
                    id="site-code-found"
                    data-test="site-code-found"
                    className="text-caption text-ink-3 wrap-anywhere"
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
    places: Place[];
    onClose: () => void;
}) {
    const form =
        entry === null
            ? SiteCodeController.store.form()
            : SiteCodeController.update.form({ entry: entry.id });
    const [placement, setPlacement] = useState<string>(
        entry?.placement ?? 'head',
    );
    const [on, setOn] = useState(entry === null ? true : entry.on);

    return (
        <Form
            {...form}
            options={{ preserveScroll: true }}
            onSuccess={onClose}
            className="grid gap-5 px-4 pb-4"
        >
            {({ processing, errors }) => (
                <>
                    {errors.entry && (
                        <Notice tone="danger" role="alert">
                            <p>{errors.entry}</p>
                        </Notice>
                    )}

                    <Field
                        htmlFor="site-code-name"
                        label={__('Name')}
                        required
                        help={__(
                            'So you can find it later, such as “Plausible analytics”.',
                        )}
                        error={errors.name}
                    >
                        <Input
                            id="site-code-name"
                            name="name"
                            maxLength={120}
                            required
                            defaultValue={entry?.name ?? ''}
                        />
                    </Field>

                    <div className="grid gap-2">
                        <Label id="site-code-placement-label">
                            {__('Where it goes')}
                        </Label>
                        <input
                            type="hidden"
                            name="placement"
                            value={placement}
                        />
                        <RadioGroup
                            value={placement}
                            onValueChange={setPlacement}
                            aria-labelledby="site-code-placement-label"
                            aria-describedby="site-code-placement-error"
                            className="sm:grid-cols-2"
                        >
                            {places.map((place) => (
                                <RadioGroupCard
                                    key={place.key}
                                    id={`site-code-placement-${place.key}`}
                                    value={place.key}
                                    label={place.chip}
                                    description={place.hint}
                                />
                            ))}
                        </RadioGroup>
                        <InputError
                            id="site-code-placement-error"
                            message={errors.placement}
                        />
                    </div>

                    <Field
                        htmlFor="site-code-code"
                        label={__('Code')}
                        required
                        help={__(
                            'A script, style or link tag, as the service gives it to you.',
                        )}
                        error={errors.code}
                    >
                        <Textarea
                            id="site-code-code"
                            name="code"
                            rows={7}
                            spellCheck={false}
                            defaultValue={entry?.code ?? ''}
                            className="font-mono"
                        />
                    </Field>

                    <OriginsField entry={entry} error={errors.origins} />

                    <FieldInline
                        htmlFor="site-code-on"
                        label={__('Switched on')}
                        help={__(
                            'Off keeps the code here but leaves it off every page.',
                        )}
                    >
                        <Switch
                            id="site-code-on"
                            checked={on}
                            onCheckedChange={setOn}
                        />
                    </FieldInline>
                    <input type="hidden" name="on" value={on ? '1' : '0'} />

                    <div className="flex flex-wrap items-center justify-end gap-2">
                        <Button
                            type="button"
                            variant="neutral"
                            onClick={onClose}
                        >
                            {__('Cancel')}
                        </Button>
                        <Button
                            type="submit"
                            disabled={processing}
                            data-test="site-code-save"
                        >
                            {__('Save code')}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

/**
 * Decides on a plugin's script, asking for the password in place when its
 * window has closed (bootstrap/app.php answers 423), then sending the same
 * decision again.
 */
function useScriptDecision(): {
    decide: (url: string) => void;
    busy: boolean;
    password: ReactNode;
} {
    const [busy, setBusy] = useState(false);
    const [asking, setAsking] = useState(false);
    const again = useRef<string | null>(null);

    const decide = (url: string) => {
        setBusy(true);
        router.post(
            url,
            {},
            {
                preserveScroll: true,
                headers: { 'X-Teme-Ask-In-Place': '1' },
                onHttpException: (response) => {
                    if (response.status === 423) {
                        again.current = url;
                        setAsking(true);

                        return false;
                    }
                },
                onFinish: () => setBusy(false),
            },
        );
    };

    return {
        decide,
        busy,
        password: (
            <FreshPasswordDialog
                open={asking}
                onOpenChange={setAsking}
                title={__('Confirm your password')}
                text={__(
                    'Deciding on a script for the site’s pages asks for your password.',
                )}
                onConfirmed={() => {
                    const url = again.current;
                    again.current = null;

                    if (url !== null) {
                        decide(url);
                    }
                }}
            />
        ),
    };
}

function ScriptCard({
    script,
    locked,
    siteTimezone,
    busy,
    onDecide,
}: {
    script: PluginScriptRow;
    locked: boolean;
    siteTimezone: string;
    busy: boolean;
    onDecide: (url: string) => void;
}) {
    const label = __(':name, from :plugin', {
        name: script.name,
        plugin: script.pluginName,
    });
    const asked = __('Asked for by :who, :when.', {
        who: script.requestedBy,
        when: formatInZone(script.requestedAt, siteTimezone),
    });
    const decided =
        script.decidedAt === null
            ? null
            : script.state === 'approved'
              ? __('Approved by :who, :when.', {
                    who: script.decidedBy ?? '',
                    when: formatInZone(script.decidedAt, siteTimezone),
                })
              : __('Refused by :who, :when.', {
                    who: script.decidedBy ?? '',
                    when: formatInZone(script.decidedAt, siteTimezone),
                });

    return (
        <Card label={label}>
            <div
                data-test="site-code-script"
                className="flex flex-wrap items-center gap-3"
            >
                <span className="text-body text-ink font-semibold">
                    {script.name}
                </span>
                {script.state === 'waiting' && (
                    <StatusPill tone="warn">{__('Waiting')}</StatusPill>
                )}
                {script.state === 'approved' && (
                    <StatusPill tone="ok">{__('Approved')}</StatusPill>
                )}
                {script.state === 'refused' && (
                    <StatusPill tone="danger">{__('Refused')}</StatusPill>
                )}
                <StatusPill tone="neutral" flat>
                    {__('From :plugin', { plugin: script.pluginName })}
                </StatusPill>

                {!locked && (
                    <div className="flex flex-wrap items-center gap-2 sm:ms-auto">
                        {script.state === 'waiting' ? (
                            <>
                                <Button
                                    type="button"
                                    variant="neutral"
                                    size="sm"
                                    disabled={busy}
                                    data-test="site-code-script-approve"
                                    onClick={() =>
                                        onDecide(
                                            SiteCodeController.approveScript.url(
                                                { script: script.id },
                                            ),
                                        )
                                    }
                                >
                                    {__('Approve')}
                                    <span className="sr-only"> {label}</span>
                                </Button>
                                <Button
                                    type="button"
                                    variant="neutral"
                                    size="sm"
                                    disabled={busy}
                                    data-test="site-code-script-refuse"
                                    onClick={() =>
                                        onDecide(
                                            SiteCodeController.refuseScript.url(
                                                { script: script.id },
                                            ),
                                        )
                                    }
                                >
                                    {__('Refuse')}
                                    <span className="sr-only"> {label}</span>
                                </Button>
                            </>
                        ) : (
                            <Button
                                type="button"
                                variant="neutral"
                                size="sm"
                                disabled={busy}
                                data-test="site-code-script-revoke"
                                onClick={() =>
                                    onDecide(
                                        SiteCodeController.revokeScript.url({
                                            script: script.id,
                                        }),
                                    )
                                }
                            >
                                {__('Revoke')}
                                <span className="sr-only"> {label}</span>
                            </Button>
                        )}
                    </div>
                )}
            </div>

            <CodeBox label={__('Script of :name', { name: label })}>
                {script.script}
            </CodeBox>

            <p className="text-small text-ink-3">
                {asked}
                {decided !== null && ` ${decided}`}
            </p>
        </Card>
    );
}

/** ads.txt (not drawn in v10): what /ads.txt answers, as text or a redirect. */
function AdsTxtPanel({
    adsTxt,
    adsTxtMode,
    adsTxtRedirect,
    adsTxtVersion,
}: Pick<Props, 'adsTxt' | 'adsTxtMode' | 'adsTxtRedirect' | 'adsTxtVersion'>) {
    const [mode, setMode] = useState<'text' | 'redirect'>(adsTxtMode);

    useEffect(() => {
        setMode(adsTxtMode);
    }, [adsTxtMode]);

    return (
        <SettingsPanel
            as="section"
            title={__('ads.txt')}
            description={__(
                'Shown at /ads.txt for advertising buyers. Leave empty for a 404, or send them to another host’s file.',
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
                        <input type="hidden" name="ads_txt_mode" value={mode} />
                        <RadioGroup
                            value={mode}
                            onValueChange={(next) =>
                                setMode(
                                    next === 'redirect' ? 'redirect' : 'text',
                                )
                            }
                            className="grid gap-2"
                            aria-label={__('ads.txt')}
                            aria-describedby="site-code-ads-txt-mode-error"
                        >
                            <RadioGroupChoice
                                id="site-code-ads-txt-mode-text"
                                value="text"
                                label={__('Text')}
                                data-test="site-code-ads-txt-mode-text"
                            />
                            <RadioGroupChoice
                                id="site-code-ads-txt-mode-redirect"
                                value="redirect"
                                label={__('Send to another address')}
                                data-test="site-code-ads-txt-mode-redirect"
                            />
                        </RadioGroup>
                        <InputError
                            id="site-code-ads-txt-mode-error"
                            message={errors.ads_txt_mode}
                        />
                        {mode === 'text' ? (
                            <>
                                <input
                                    type="hidden"
                                    name="ads_txt_redirect"
                                    value={adsTxtRedirect}
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
                            </>
                        ) : (
                            <>
                                <input
                                    type="hidden"
                                    name="ads_txt"
                                    value={adsTxt}
                                />
                                <Label htmlFor="site-code-ads-txt-redirect">
                                    {__('Address')}
                                </Label>
                                <Input
                                    id="site-code-ads-txt-redirect"
                                    name="ads_txt_redirect"
                                    type="url"
                                    inputMode="url"
                                    spellCheck={false}
                                    defaultValue={adsTxtRedirect}
                                    data-test="site-code-ads-txt-redirect"
                                    aria-describedby="site-code-ads-txt-redirect-hint site-code-ads-txt-redirect-error"
                                    className="font-mono"
                                />
                                <p
                                    id="site-code-ads-txt-redirect-hint"
                                    className="text-ink-2 text-small"
                                >
                                    {__(
                                        'A full https address on another host. /ads.txt answers with a permanent redirect there.',
                                    )}
                                </p>
                                <InputError
                                    id="site-code-ads-txt-redirect-error"
                                    message={
                                        errors.ads_txt_redirect ??
                                        errors.expected_version
                                    }
                                />
                            </>
                        )}
                        <div>
                            <Button
                                type="submit"
                                variant="neutral"
                                disabled={processing}
                                data-test="site-code-ads-txt-save"
                            >
                                {__('Save ads.txt')}
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </SettingsPanel>
    );
}

/**
 * Settings › Site code (I-863 to I-866): the code on every page, each entry
 * a card with its place, switched on or off, edited in a sheet, deleted
 * after a confirmation; the scripts plugins asked to keep, approved,
 * refused or revoked here; and the changes, one line each. Read-only while
 * the host disallows site code.
 */
export default function SiteCodeSettings({
    locked,
    siteTimezone,
    places,
    changes,
    pluginScripts,
    adsTxt,
    adsTxtMode,
    adsTxtRedirect,
    adsTxtVersion,
}: Props) {
    const [editing, setEditing] = useState<Editing>(null);
    const opener = useRef<HTMLElement | null>(null);
    const decision = useScriptDecision();
    const entries = places.flatMap((place) =>
        place.entries.map((entry) => ({ entry, chip: place.chip })),
    );
    const open = (next: Editing, from: HTMLElement) => {
        opener.current = from;
        setEditing(next);
    };
    const addButton = (
        <Button
            type="button"
            variant="neutral"
            data-test="site-code-add"
            onClick={(event) => open('new', event.currentTarget)}
        >
            {__('Add code to every page')}
        </Button>
    );

    setLayoutProps({
        breadcrumbs: [
            { title: __('Settings'), href: settingsIndex() },
            { title: __('Site code'), href: SiteCodeController.show.url() },
        ],
    });

    return (
        <SettingsPage
            title={__('Site code')}
            description={__(
                'Code added to every public page, such as analytics. To show something from another site in one item, add an Embed card to that item instead.',
            )}
        >
            <Head title={__('Site code settings')} />

            <div className="grid gap-4">
                {locked ? (
                    <Notice
                        tone="danger"
                        role="status"
                        data-test="site-code-notice"
                    >
                        <p>
                            <strong className="font-semibold">
                                {__('The host has switched site code off.')}
                            </strong>{' '}
                            {__(
                                'None of the code below is added to any page, and none of it can be changed here. It’s kept as it was, for when the host allows site code again.',
                            )}
                        </p>
                    </Notice>
                ) : (
                    <Notice tone="warn" data-test="site-code-notice">
                        <p>
                            <strong className="font-semibold">
                                {__(
                                    'Code here runs in every reader’s browser.',
                                )}
                            </strong>{' '}
                            {__(
                                'It runs in yours too when you view the site, and in everyone’s who’s signed in. Add only code you trust. Saving asks for your password, and every change is logged, with what it was before.',
                            )}
                        </p>
                    </Notice>
                )}

                <SettingsPanel
                    as="section"
                    title={__('Code on every page')}
                    description={__(
                        'Each entry says where on the page it goes, and can be switched off without losing it.',
                    )}
                >
                    {entries.length === 0 ? (
                        <EmptyState
                            kind="nothing-yet"
                            title={__('No code added yet')}
                            description={__(
                                'Analytics, chat widgets and ad tags go here. Each entry says where on the page it goes.',
                            )}
                            action={locked ? undefined : addButton}
                        />
                    ) : (
                        <>
                            <div className="grid gap-4">
                                {entries.map(({ entry, chip }) => (
                                    <EntryCard
                                        key={entry.id}
                                        entry={entry}
                                        chip={chip}
                                        locked={locked}
                                        siteTimezone={siteTimezone}
                                        onEdit={(next) =>
                                            open(
                                                next,
                                                document.activeElement as HTMLElement,
                                            )
                                        }
                                    />
                                ))}
                            </div>
                            {!locked && <div>{addButton}</div>}
                        </>
                    )}
                </SettingsPanel>

                <SettingsPanel
                    as="section"
                    title={__('Scripts from plugins')}
                    description={__(
                        'Inline scripts a plugin asked to put on pages. None runs until someone who may change site code approves it.',
                    )}
                >
                    {pluginScripts.length === 0 ? (
                        <p className="text-body text-ink-2">
                            {__(
                                'No scripts from plugins. When a plugin asks to put an inline script on pages, it waits here for approval.',
                            )}
                        </p>
                    ) : (
                        <div className="grid gap-4">
                            {pluginScripts.map((script) => (
                                <ScriptCard
                                    key={script.id}
                                    script={script}
                                    locked={locked}
                                    siteTimezone={siteTimezone}
                                    busy={decision.busy}
                                    onDecide={decision.decide}
                                />
                            ))}
                        </div>
                    )}
                </SettingsPanel>

                <AdsTxtPanel
                    adsTxt={adsTxt}
                    adsTxtMode={adsTxtMode}
                    adsTxtRedirect={adsTxtRedirect}
                    adsTxtVersion={adsTxtVersion}
                />

                <SettingsPanel
                    as="section"
                    title={__('Changes')}
                    description={__(
                        'Newest first. The security log keeps each one for a year.',
                    )}
                >
                    {changes.length === 0 ? (
                        <p className="text-body text-ink-2">
                            {__('No changes yet.')}
                        </p>
                    ) : (
                        <ul className="grid gap-3">
                            {changes.map((change) => (
                                <ChangeLine
                                    key={change.id}
                                    change={change}
                                    siteTimezone={siteTimezone}
                                />
                            ))}
                        </ul>
                    )}
                </SettingsPanel>
            </div>

            <Sheet
                open={editing !== null}
                onOpenChange={(next) => !next && setEditing(null)}
            >
                <ResponsiveSheetContent
                    side="right"
                    style={{ '--sheet-w': '560px' } as CSSProperties}
                    onCloseAutoFocus={(event) => {
                        if (opener.current?.isConnected) {
                            event.preventDefault();
                            opener.current.focus();
                        }
                    }}
                >
                    <SheetHeader>
                        <SheetTitle>
                            {editing === null || editing === 'new'
                                ? __('Add code to every page')
                                : __('Edit “:name”', { name: editing.name })}
                        </SheetTitle>
                        <SheetDescription>
                            {__(
                                'Name it, choose where it goes, and paste the code.',
                            )}
                        </SheetDescription>
                    </SheetHeader>
                    <ScrollArea className="flex-1">
                        {editing !== null && (
                            <CodeForm
                                key={editing === 'new' ? 'new' : editing.id}
                                entry={editing === 'new' ? null : editing}
                                places={places}
                                onClose={() => setEditing(null)}
                            />
                        )}
                    </ScrollArea>
                </ResponsiveSheetContent>
            </Sheet>

            {decision.password}
        </SettingsPage>
    );
}
