import { Form, Head, router, setLayoutProps } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import ManagementApiController from '@/actions/App/Http/Admin/Settings/ManagementApiController';
import { ConfirmAction } from '@/components/confirm-action';
import { post } from '@/components/editor/editor-http';
import { SettingsField } from '@/components/fields/settings-field';
import { SettingsForm } from '@/components/fields/settings-form';
import { SettingsPanel } from '@/components/fields/settings-panel';
import InputError from '@/components/input-error';
import { FreshPasswordInline } from '@/components/patterns/fresh-password';
import { ResponsiveDialogContent } from '@/components/patterns/responsive-dialog';
import { SettingsPage } from '@/components/patterns/settings-page';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Notice } from '@/components/ui/notice';
import { SelectField } from '@/components/ui/select-field';
import { StatusPill } from '@/components/ui/status-pill';
import { Switch } from '@/components/ui/switch';
import { __ } from '@/lib/i18n';
import type { FieldDefinition } from '@/types';

/** One key as the list shows it: never its secret, only its start. */
type ApiKeyRow = {
    id: string;
    name: string;
    start: string;
    person: string;
    abilities: string[];
    createdAt: string | null;
    lastUsedAt: string | null;
    expiresAt: string | null;
    revokedAt: string | null;
    live: boolean;
};

type Option = { value: string; label: string };

type ManagementApiPageProps = {
    description: string;
    switch: FieldDefinition;
    limit: FieldDefinition;
    values: Record<string, unknown>;
    version: number;
    endpoint: string;
    keys: ApiKeyRow[];
    people: Option[];
    abilities: Option[];
};

/** A date as the reader's browser writes one. */
function shortDate(at: string): string {
    return new Date(at).toLocaleDateString();
}

/** The first message of a refused field in a 422 answer. */
function firstError(
    body: Record<string, unknown>,
    key: string,
): string | undefined {
    const errors = body.errors as Record<string, string[]> | undefined;

    return errors?.[key]?.[0];
}

/** A key's state in a word: live, revoked or expired. */
function KeyState({ row }: { row: ApiKeyRow }) {
    if (row.revokedAt !== null) {
        return <StatusPill tone="neutral">{__('Revoked')}</StatusPill>;
    }

    if (!row.live) {
        return <StatusPill tone="warn">{__('Expired')}</StatusPill>;
    }

    return <StatusPill tone="ok">{__('Active')}</StatusPill>;
}

/** One key in the list, with Revoke while it still signs in. */
function KeyRow({ row, abilities }: { row: ApiKeyRow; abilities: Option[] }) {
    const named = row.abilities.map(
        (value) =>
            abilities.find((option) => option.value === value)?.label ?? value,
    );

    return (
        <li
            data-test="api-key"
            className="border-line grid gap-2 border-b py-3 last:border-b-0"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-body flex flex-wrap items-center gap-2">
                    <strong className="font-medium">{row.name}</strong>
                    <code className="text-small text-ink-2">{row.start}…</code>
                    <KeyState row={row} />
                </p>
                {row.live && (
                    <Form
                        {...ManagementApiController.destroy.form({
                            key: row.id,
                        })}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <ConfirmAction
                                title={__('Revoke “:name”?', {
                                    name: row.name,
                                })}
                                description={__(
                                    'Tools using this key are refused from now on. This can’t be undone; make a new key instead.',
                                )}
                                confirmLabel={__('Revoke key')}
                            >
                                <Button
                                    type="submit"
                                    variant="ghost"
                                    size="sm"
                                    disabled={processing}
                                    data-test="api-key-revoke"
                                    className="text-danger-ink"
                                >
                                    {__('Revoke')}
                                    <span className="sr-only"> {row.name}</span>
                                </Button>
                            </ConfirmAction>
                        )}
                    </Form>
                )}
            </div>
            <dl className="text-small text-ink-2 grid gap-x-4 gap-y-1 sm:grid-cols-2">
                <div>
                    <dt className="inline">{__('Acts as')}: </dt>
                    <dd className="inline">{row.person}</dd>
                </div>
                <div>
                    <dt className="inline">{__('Can')}: </dt>
                    <dd className="inline">{named.join(', ')}</dd>
                </div>
                <div>
                    <dt className="inline">{__('Created')}: </dt>
                    <dd className="inline">
                        {row.createdAt === null
                            ? '—'
                            : shortDate(row.createdAt)}
                    </dd>
                </div>
                <div>
                    <dt className="inline">{__('Last used')}: </dt>
                    <dd className="inline">
                        {row.lastUsedAt === null
                            ? __('Never')
                            : shortDate(row.lastUsedAt)}
                    </dd>
                </div>
                <div>
                    <dt className="inline">{__('Expires')}: </dt>
                    <dd className="inline">
                        {row.expiresAt === null
                            ? __('Never')
                            : shortDate(row.expiresAt)}
                    </dd>
                </div>
                {row.revokedAt !== null && (
                    <div>
                        <dt className="inline">{__('Revoked')}: </dt>
                        <dd className="inline">{shortDate(row.revokedAt)}</dd>
                    </div>
                )}
            </dl>
        </li>
    );
}

/**
 * Create a key: name, person, abilities and an optional expiry, then the
 * secret shown once with Copy. The secret comes back as JSON and is never
 * kept in the page's props or the session; a lapsed password is asked for
 * in the dialog, so what was typed stays.
 */
function CreateKeyDialog({
    open,
    onOpenChange,
    people,
    abilities,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    people: Option[];
    abilities: Option[];
}) {
    const [name, setName] = useState('');
    const [person, setPerson] = useState('');
    const [chosen, setChosen] = useState<string[]>([]);
    const [expires, setExpires] = useState('');
    const [busy, setBusy] = useState(false);
    const [asking, setAsking] = useState(false);
    const [errors, setErrors] = useState<Record<string, string | undefined>>(
        {},
    );
    const [secret, setSecret] = useState<string | null>(null);
    const [copied, setCopied] = useState(false);

    const reset = () => {
        setName('');
        setPerson('');
        setChosen([]);
        setExpires('');
        setErrors({});
        setAsking(false);
        setSecret(null);
        setCopied(false);
    };

    const close = (next: boolean) => {
        if (!next) {
            const made = secret !== null;
            reset();

            if (made) {
                router.reload({ only: ['keys'] });
            }
        }

        onOpenChange(next);
    };

    const send = async () => {
        setBusy(true);
        setErrors({});

        try {
            const reply = await post(ManagementApiController.store.url(), {
                name,
                user_id: person,
                abilities: chosen,
                expires_at: expires,
            });

            if (reply.status === 423) {
                setAsking(true);
            } else if (reply.status === 422) {
                setErrors({
                    name: firstError(reply.body, 'name'),
                    user: firstError(reply.body, 'user'),
                    abilities:
                        firstError(reply.body, 'abilities') ??
                        firstError(reply.body, 'abilities.0'),
                    expires_at: firstError(reply.body, 'expires_at'),
                });
            } else if (reply.status === 201) {
                setAsking(false);
                setSecret(
                    typeof reply.body.secret === 'string'
                        ? reply.body.secret
                        : '',
                );
            } else {
                setErrors({
                    name: __('The key could not be made. Try again.'),
                });
            }
        } finally {
            setBusy(false);
        }
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        void send();
    };

    return (
        <Dialog open={open} onOpenChange={close}>
            <ResponsiveDialogContent size="sm" data-test="api-key-dialog">
                <DialogHeader>
                    <DialogTitle>
                        {secret === null
                            ? __('Create a key')
                            : __('Your new key')}
                    </DialogTitle>
                    <DialogDescription>
                        {secret === null
                            ? __(
                                  'A key acts as one person, with only the abilities you tick. What that person may do still decides what the key may do.',
                              )
                            : __(
                                  'Copy it now and keep it somewhere safe. You won’t see this again.',
                              )}
                    </DialogDescription>
                </DialogHeader>

                {secret !== null ? (
                    <div className="grid gap-3">
                        <div className="flex items-center gap-2">
                            <Input
                                readOnly
                                value={secret}
                                aria-label={__('The new key')}
                                data-test="api-key-secret"
                                onFocus={(event) => event.target.select()}
                            />
                            <Button
                                type="button"
                                variant="outline"
                                data-test="api-key-copy"
                                onClick={() =>
                                    void navigator.clipboard
                                        .writeText(secret)
                                        .then(() => setCopied(true))
                                }
                            >
                                {copied ? __('Copied') : __('Copy')}
                            </Button>
                        </div>
                        <p className="text-caption text-ink-3">
                            {__('You won’t see this again.')}
                        </p>
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" data-test="api-key-done">
                                    {__('Done')}
                                </Button>
                            </DialogClose>
                        </DialogFooter>
                    </div>
                ) : (
                    <form className="grid gap-4" onSubmit={submit}>
                        <Field
                            htmlFor="api-key-name"
                            label={__('Name')}
                            help={__(
                                'Say which tool uses it, such as “Newsroom importer”.',
                            )}
                            error={errors.name}
                        >
                            <Input
                                id="api-key-name"
                                value={name}
                                onChange={(event) =>
                                    setName(event.target.value)
                                }
                                autoComplete="off"
                                data-test="api-key-name"
                            />
                        </Field>
                        <Field
                            htmlFor="api-key-person"
                            label={__('Acts as')}
                            error={errors.user}
                        >
                            <SelectField
                                id="api-key-person"
                                value={person}
                                onValueChange={setPerson}
                                emptyLabel={__('Choose a person')}
                                options={people}
                            />
                        </Field>
                        <fieldset className="grid gap-2">
                            <legend className="text-small text-ink-2 mb-1 font-medium">
                                {__('Abilities')}
                            </legend>
                            {abilities.map((ability) => (
                                <div
                                    key={ability.value}
                                    className="flex items-center gap-2"
                                >
                                    <Checkbox
                                        id={`api-ability-${ability.value}`}
                                        checked={chosen.includes(ability.value)}
                                        onCheckedChange={(checked) =>
                                            setChosen((now) =>
                                                checked === true
                                                    ? [...now, ability.value]
                                                    : now.filter(
                                                          (value) =>
                                                              value !==
                                                              ability.value,
                                                      ),
                                            )
                                        }
                                    />
                                    <Label
                                        htmlFor={`api-ability-${ability.value}`}
                                        className="text-body text-ink"
                                    >
                                        {ability.label}
                                    </Label>
                                </div>
                            ))}
                            <InputError message={errors.abilities} />
                        </fieldset>
                        <Field
                            htmlFor="api-key-expires"
                            label={__('Expires on')}
                            help={__(
                                'Leave empty for a key that never expires.',
                            )}
                            error={errors.expires_at}
                        >
                            <Input
                                id="api-key-expires"
                                type="date"
                                value={expires}
                                onChange={(event) =>
                                    setExpires(event.target.value)
                                }
                            />
                        </Field>
                        <FreshPasswordInline
                            open={asking}
                            onOpenChange={setAsking}
                            title={__('Confirm your password')}
                            text={__(
                                'Type your password to carry on. What you typed stays here.',
                            )}
                            onConfirmed={() => void send()}
                        />
                        {!asking && (
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button type="button" variant="neutral">
                                        {__('Cancel')}
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    loading={busy}
                                    data-test="api-key-create"
                                >
                                    {__('Create key')}
                                </Button>
                            </DialogFooter>
                        )}
                    </form>
                )}
            </ResponsiveDialogContent>
        </Dialog>
    );
}

/**
 * Settings › Management API (docs/proposals/management-api.md): the switch
 * at the top, acting at once (I-485); the limit per key, saved by the save
 * bar; then the keys, each made in a dialog that shows its secret once, and
 * revoked after asking.
 */
export default function ManagementApiSettings(props: ManagementApiPageProps) {
    const [switching, setSwitching] = useState(false);
    const [switchError, setSwitchError] = useState<string | undefined>();
    const [creating, setCreating] = useState(false);
    const on = props.values[props.switch.key] === true;

    setLayoutProps({
        breadcrumbs: [
            {
                title: __('Management API'),
                href: ManagementApiController.show.url(),
            },
        ],
    });

    const flip = (next: boolean) => {
        router.put(
            ManagementApiController.update.url(),
            {
                values: { [props.switch.key]: next },
                expected_version: props.version,
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
            <Head title={__('Management API settings')} />

            <SettingsPage
                title={__('Management API')}
                description={props.description}
            >
                <SettingsPanel
                    as="section"
                    id="management-api-switch"
                    title={__('Management API on this site')}
                    description={__(
                        'Tools sign in at :address with a key from the list below.',
                        { address: props.endpoint },
                    )}
                    className="mb-4"
                >
                    <div className="grid gap-1">
                        <div className="flex items-center gap-2">
                            <Switch
                                id="field-management-api"
                                checked={on}
                                disabled={switching}
                                onCheckedChange={flip}
                                aria-describedby="field-management-api-hint field-management-api-error"
                                data-test="management-api-switch"
                            />
                            <Label
                                htmlFor="field-management-api"
                                className="text-body text-ink"
                            >
                                {props.switch.label}
                            </Label>
                        </div>
                        <p
                            id="field-management-api-hint"
                            className="text-caption text-ink-3"
                        >
                            {props.switch.description}
                        </p>
                        <InputError
                            id="field-management-api-error"
                            className="text-small"
                            message={switchError}
                        />
                    </div>
                </SettingsPanel>

                {!on && (
                    <Notice tone="info" className="mb-4">
                        {__(
                            'The management API is off, so every key is refused. Keys you make now work once it’s on.',
                        )}
                    </Notice>
                )}

                <div className="grid gap-4">
                    <SettingsPanel
                        as="section"
                        id="management-api-keys"
                        title={__('Keys')}
                        description={__(
                            'Each key is shown once, when it’s made. Revoke a key that’s lost or no longer used.',
                        )}
                    >
                        <div>
                            <Button
                                type="button"
                                data-test="api-key-add"
                                onClick={() => setCreating(true)}
                            >
                                <Plus aria-hidden="true" />
                                {__('Create a key')}
                            </Button>
                        </div>
                        {props.keys.length === 0 ? (
                            <p className="text-body text-ink-2">
                                {__('No keys yet.')}
                            </p>
                        ) : (
                            <ul data-test="api-keys">
                                {props.keys.map((row) => (
                                    <KeyRow
                                        key={row.id}
                                        row={row}
                                        abilities={props.abilities}
                                    />
                                ))}
                            </ul>
                        )}
                    </SettingsPanel>

                    <SettingsForm
                        action={ManagementApiController.update.form()}
                        saveLabel={__('Save the limit')}
                        saveTestId="management-api-save"
                        what={__(':page settings', {
                            page: __('Management API'),
                        })}
                    >
                        {({ errors }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="expected_version"
                                    value={props.version}
                                />
                                <InputError
                                    id="expected-version-error"
                                    message={errors.expected_version}
                                />
                                <SettingsPanel
                                    title={__('Limits')}
                                    description={__(
                                        'An address that fails to sign in 30 times in a minute waits until the minute ends.',
                                    )}
                                >
                                    <SettingsField
                                        field={props.limit}
                                        value={props.values[props.limit.key]}
                                        error={
                                            errors[`values.${props.limit.key}`]
                                        }
                                    />
                                </SettingsPanel>
                            </>
                        )}
                    </SettingsForm>
                </div>

                <CreateKeyDialog
                    open={creating}
                    onOpenChange={setCreating}
                    people={props.people}
                    abilities={props.abilities}
                />
            </SettingsPage>
        </>
    );
}
