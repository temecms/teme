import { Form, Head, router, setLayoutProps } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import FediverseSettingsController from '@/actions/App/Http/Admin/Settings/FediverseSettingsController';
import { SettingsField } from '@/components/fields/settings-field';
import { SettingsForm } from '@/components/fields/settings-form';
import { SettingsPanel } from '@/components/fields/settings-panel';
import InputError from '@/components/input-error';
import { SettingsPage } from '@/components/patterns/settings-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Notice } from '@/components/ui/notice';
import { Switch } from '@/components/ui/switch';
import { __, __n } from '@/lib/i18n';
import { index as settingsIndex } from '@/routes/settings';
import type { FieldDefinition } from '@/types';

type Account = {
    kind: 'blog' | 'person';
    personId: string | null;
    name: string;
    handle: string;
    followers: number;
    enabled: boolean;
};

type FediverseSettingsPage = {
    description: string;
    fediverse: {
        field: FieldDefinition;
        value: 'on' | 'off';
        version: number;
        canSwitch: boolean;
    };
    fields: FieldDefinition[];
    values: Record<string, unknown>;
    version: number;
    accounts: Account[];
    unreachable: { host: string; accounts: number; failed: number }[];
    blocked: { host: string; reason: string | null; since: string }[];
    imported: { accounts: number; followers: number };
};

/** Each column's width, the same on every table, so they line up. */
const FIRST = 'min-w-0 flex-1 pr-2';
const SECOND = 'w-24 shrink-0 px-2 sm:w-32';
const THIRD = 'w-28 shrink-0 pl-2 sm:w-36';
const ROW = 'border-line flex border-t';

/** Every table on the page shares its columns' widths, so they line up. */
function Table({
    id,
    caption,
    heads,
    empty,
    children,
}: {
    id: string;
    caption: string;
    heads: [string, string];
    empty: string | null;
    children: ReactNode;
}) {
    if (empty !== null) {
        return (
            <p className="text-body text-ink-3" data-test={`${id}-empty`}>
                {empty}
            </p>
        );
    }

    // An ARIA table of rows, its three columns the same width on every
    // table of the page, so they line up (no table component exists yet).
    return (
        <div
            role="table"
            aria-label={caption}
            data-test={id}
            className="text-small w-full"
        >
            <div role="rowgroup" className="text-ink-3">
                <div role="row" className="flex">
                    <span
                        role="columnheader"
                        className={`${FIRST} py-1 font-medium`}
                    >
                        {heads[0]}
                    </span>
                    <span
                        role="columnheader"
                        className={`${SECOND} py-1 font-medium`}
                    >
                        {heads[1]}
                    </span>
                    <span
                        role="columnheader"
                        className={`${THIRD} py-1 text-right font-medium`}
                    >
                        <span className="sr-only">{__('Action')}</span>
                    </span>
                </div>
            </div>
            <div role="rowgroup">{children}</div>
        </div>
    );
}

/** A row's first cell: its name, then a line under it. */
function Named({ name, line }: { name: string; line?: string | null }) {
    return (
        <div role="rowheader" className={`${FIRST} py-2`}>
            <span className="text-body text-ink block font-medium break-words">
                {name}
            </span>
            {line && (
                <span className="text-caption text-ink-3 block break-all">
                    {line}
                </span>
            )}
        </div>
    );
}

const CELL = `${SECOND} py-2`;
const ACTION = `${THIRD} flex items-start justify-end py-2`;

/**
 * Settings › Fediverse, as the maintainers approved its drawing: the switch
 * at the top, acting at once, with who can be followed, saved by the save
 * bar; then the accounts, the servers not answering and the blocked
 * servers, one row each with one action; and what the import brought.
 */
export default function FediverseSettings({
    description,
    fediverse,
    fields,
    values,
    version,
    accounts,
    unreachable,
    blocked,
    imported,
}: FediverseSettingsPage) {
    const [switching, setSwitching] = useState(false);
    const [unsaved, setUnsaved] = useState(false);
    const [switchError, setSwitchError] = useState<string | undefined>();
    const on = fediverse.value === 'on';

    setLayoutProps({
        breadcrumbs: [
            { title: __('Settings'), href: settingsIndex() },
            {
                title: __('Fediverse'),
                href: FediverseSettingsController.show.url(),
            },
        ],
    });

    const switchFediverse = (next: boolean) => {
        router.put(
            FediverseSettingsController.switchFediverse.url(),
            {
                values: { fediverse: next ? 'on' : 'off' },
                expected_version: fediverse.version,
            },
            {
                preserveScroll: true,
                onStart: () => setSwitching(true),
                onFinish: () => setSwitching(false),
                onError: (errors) => setSwitchError(Object.values(errors)[0]),
                onSuccess: () => setSwitchError(undefined),
            },
        );
    };

    return (
        <>
            <Head title={__('Fediverse settings')} />

            <SettingsPage title={__('Fediverse')} description={description}>
                <SettingsForm
                    action={FediverseSettingsController.update.form()}
                    what={__(':page settings', { page: __('Fediverse') })}
                    onDirtyChange={setUnsaved}
                >
                    {({ errors }) => (
                        <SettingsPanel
                            as="section"
                            id="fediverse-switch"
                            title={__('The fediverse on this site')}
                            description={__(
                                'People on Mastodon and other fediverse servers can follow the site and see its new posts there.',
                            )}
                        >
                            <div className="grid gap-1">
                                <div className="flex items-center gap-2">
                                    <Switch
                                        id="field-fediverse"
                                        checked={on}
                                        disabled={
                                            !fediverse.canSwitch ||
                                            switching ||
                                            unsaved
                                        }
                                        onCheckedChange={switchFediverse}
                                        aria-describedby="field-fediverse-hint field-fediverse-error"
                                    />
                                    <Label
                                        htmlFor="field-fediverse"
                                        className="text-body text-ink"
                                    >
                                        {__('Fediverse')}
                                    </Label>
                                </div>
                                <p
                                    id="field-fediverse-hint"
                                    className="text-caption text-ink-3 flex items-center gap-2"
                                >
                                    {!fediverse.canSwitch ? (
                                        <>
                                            <Lock
                                                aria-hidden="true"
                                                className="size-4"
                                            />
                                            {__(
                                                'Only administrators can switch the fediverse on or off.',
                                            )}
                                        </>
                                    ) : unsaved ? (
                                        __(
                                            'Save or discard the changes below first.',
                                        )
                                    ) : (
                                        __(
                                            'Off, the fediverse is paused: nothing is sent or received, and followers and keys are kept.',
                                        )
                                    )}
                                </p>
                                <InputError
                                    id="field-fediverse-error"
                                    className="text-small"
                                    message={switchError}
                                />
                            </div>

                            <input
                                type="hidden"
                                name="expected_version"
                                value={version}
                            />
                            <InputError
                                id="expected-version-error"
                                message={errors.expected_version}
                            />

                            {fields.map((field) => (
                                <SettingsField
                                    key={field.key}
                                    field={field}
                                    value={values[field.key]}
                                    error={errors[`values.${field.key}`]}
                                />
                            ))}
                        </SettingsPanel>
                    )}
                </SettingsForm>

                {!on && (
                    <Notice tone="info" className="mt-4">
                        {__(
                            'The fediverse is paused. Other servers can still find the accounts below, but nothing is sent or received until it’s switched on.',
                        )}
                    </Notice>
                )}

                <SettingsPanel
                    as="section"
                    id="fediverse-accounts"
                    title={__('Accounts')}
                    description={__(
                        'The site’s accounts, as people on other servers follow them.',
                    )}
                    className="mt-4"
                >
                    <Table
                        id="fediverse-accounts-table"
                        caption={__('Accounts')}
                        heads={[__('Account'), __('Followers')]}
                        empty={
                            accounts.length === 0
                                ? __(
                                      'No accounts yet. Switching the fediverse on makes the blog’s.',
                                  )
                                : null
                        }
                    >
                        {accounts.map((account) => (
                            <div
                                role="row"
                                className={ROW}
                                key={account.personId ?? account.handle}
                            >
                                <Named
                                    name={account.name}
                                    line={
                                        account.enabled
                                            ? account.handle
                                            : __(':handle, not followed', {
                                                  handle: account.handle,
                                              })
                                    }
                                />
                                <div role="cell" className={CELL}>
                                    {__n(
                                        ':count follower|:count followers',
                                        account.followers,
                                    )}
                                </div>
                                <div role="cell" className={ACTION}>
                                    <AccountAction account={account} />
                                </div>
                            </div>
                        ))}
                    </Table>
                </SettingsPanel>

                <SettingsPanel
                    as="section"
                    id="fediverse-unreachable"
                    title={__('Servers not answering')}
                    description={__(
                        'Servers whose accounts couldn’t be reached, or that didn’t take what the site sent.',
                    )}
                    className="mt-4"
                >
                    <Table
                        id="fediverse-unreachable-table"
                        caption={__('Servers not answering')}
                        heads={[__('Server'), __('Failing')]}
                        empty={
                            unreachable.length === 0
                                ? __('Every server is answering.')
                                : null
                        }
                    >
                        {unreachable.map((server) => (
                            <div role="row" className={ROW} key={server.host}>
                                <Named name={server.host} />
                                <div role="cell" className={CELL}>
                                    {server.failed > 0
                                        ? __n(
                                              ':count delivery|:count deliveries',
                                              server.failed,
                                          )
                                        : __n(
                                              ':count account|:count accounts',
                                              server.accounts,
                                          )}
                                </div>
                                <div role="cell" className={ACTION}>
                                    <BlockButton host={server.host} />
                                </div>
                            </div>
                        ))}
                    </Table>
                </SettingsPanel>

                <SettingsPanel
                    as="section"
                    id="fediverse-blocked"
                    title={__('Blocked servers')}
                    description={__(
                        'Nothing from a blocked server, or from any server under its name, is accepted, and nothing is sent to it.',
                    )}
                    className="mt-4"
                >
                    <Table
                        id="fediverse-blocked-table"
                        caption={__('Blocked servers')}
                        heads={[__('Server'), __('Blocked')]}
                        empty={
                            blocked.length === 0
                                ? __('No server is blocked.')
                                : null
                        }
                    >
                        {blocked.map((server) => (
                            <div role="row" className={ROW} key={server.host}>
                                <Named
                                    name={server.host}
                                    line={server.reason}
                                />
                                <div role="cell" className={CELL}>
                                    {new Date(server.since).toLocaleDateString(
                                        undefined,
                                        { dateStyle: 'medium' },
                                    )}
                                </div>
                                <div role="cell" className={ACTION}>
                                    <Form
                                        {...FediverseSettingsController.unblock.form(
                                            { host: server.host },
                                        )}
                                        options={{ preserveScroll: true }}
                                    >
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                size="sm"
                                                variant="outline"
                                                disabled={processing}
                                                data-test="fediverse-unblock"
                                            >
                                                {__('Unblock')}
                                                <span className="sr-only">
                                                    {' '}
                                                    {server.host}
                                                </span>
                                            </Button>
                                        )}
                                    </Form>
                                </div>
                            </div>
                        ))}
                    </Table>

                    <BlockForm />
                </SettingsPanel>

                <SettingsPanel
                    as="section"
                    id="fediverse-import"
                    title={__('Import from WordPress')}
                    description={__(
                        'Accounts and followers brought over from the WordPress ActivityPub plugin keep their old addresses.',
                    )}
                    className="mt-4"
                >
                    <p className="text-body" data-test="fediverse-import">
                        {imported.accounts === 0
                            ? __('Nothing imported yet.')
                            : __(':accounts with :followers imported.', {
                                  accounts: __n(
                                      ':count account|:count accounts',
                                      imported.accounts,
                                  ),
                                  followers: __n(
                                      ':count follower|:count followers',
                                      imported.followers,
                                  ),
                              })}
                    </p>
                </SettingsPanel>
            </SettingsPage>
        </>
    );
}

/** An account's one action: an author is turned on or off; the blog follows "Who can be followed". */
function AccountAction({ account }: { account: Account }) {
    if (account.personId === null) {
        return (
            <span className="text-caption text-ink-3">
                {account.enabled ? __('Can be followed') : __('Not followed')}
            </span>
        );
    }

    const action = account.enabled
        ? FediverseSettingsController.disableAuthor.form({
              person: account.personId,
          })
        : FediverseSettingsController.enableAuthor.form({
              person: account.personId,
          });

    return (
        <Form {...action} options={{ preserveScroll: true }}>
            {({ processing }) => (
                <Button
                    type="submit"
                    size="sm"
                    variant="outline"
                    disabled={processing}
                    data-test={
                        account.enabled
                            ? 'fediverse-author-off'
                            : 'fediverse-author-on'
                    }
                >
                    {account.enabled ? __('Turn off') : __('Turn on')}
                    <span className="sr-only"> {account.name}</span>
                </Button>
            )}
        </Form>
    );
}

/** A server not answering, blocked from its row. */
function BlockButton({ host }: { host: string }) {
    return (
        <Form
            {...FediverseSettingsController.block.form()}
            options={{ preserveScroll: true }}
        >
            {({ processing }) => (
                <>
                    <input type="hidden" name="host" value={host} />
                    <Button
                        type="submit"
                        size="sm"
                        variant="outline"
                        disabled={processing}
                        data-test="fediverse-block-row"
                    >
                        {__('Block')}
                        <span className="sr-only"> {host}</span>
                    </Button>
                </>
            )}
        </Form>
    );
}

/** Blocks a server by name, with an optional reason. */
function BlockForm() {
    return (
        <Form
            {...FediverseSettingsController.block.form()}
            options={{ preserveScroll: true }}
            resetOnSuccess
            className="grid gap-3"
            data-test="fediverse-block-form"
        >
            {({ errors, processing }) => (
                <>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-1">
                            <Label htmlFor="fediverse-block-host">
                                {__('Server')}
                            </Label>
                            <Input
                                id="fediverse-block-host"
                                name="host"
                                placeholder={__('spam.example')}
                                autoComplete="off"
                                spellCheck={false}
                                aria-invalid={errors.host ? true : undefined}
                                aria-describedby="fediverse-block-host-error"
                            />
                            <InputError
                                id="fediverse-block-host-error"
                                message={errors.host}
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="fediverse-block-reason">
                                {__('Reason (optional)')}
                            </Label>
                            <Input
                                id="fediverse-block-reason"
                                name="reason"
                                maxLength={255}
                                aria-describedby="fediverse-block-reason-error"
                            />
                            <InputError
                                id="fediverse-block-reason-error"
                                message={errors.reason}
                            />
                        </div>
                    </div>
                    <div>
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                            data-test="fediverse-block"
                        >
                            {__('Block server')}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
