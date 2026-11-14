import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { BackupPlaceKind, PlaceFormValues } from '@/types/backups';

const KINDS: { kind: BackupPlaceKind; name: string; line: string }[] = [
    {
        kind: 'folder',
        name: 'A folder on this server',
        line: 'Simplest, but a backup here goes if the server goes. Add a place off it afterwards.',
    },
    {
        kind: 's3',
        name: 'S3-compatible storage',
        line: 'Amazon S3, Backblaze B2, Cloudflare R2, Wasabi and others.',
    },
    {
        kind: 'sftp',
        name: 'An SFTP server',
        line: 'A server you or someone you trust runs, reached over SSH.',
    },
];

const hint = 'text-muted-foreground text-xs';

/** A labelled field with its hint and its error tied to it. */
function Field({
    id,
    label,
    hintText,
    error,
    children,
}: {
    id: string;
    label: React.ReactNode;
    hintText?: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid gap-2">
            {label}
            {children}
            {hintText && (
                <p id={`${id}-hint`} className={hint}>
                    {hintText}
                </p>
            )}
            <InputError id={`${id}-error`} message={error} />
        </div>
    );
}

/** Which ids a field's hint and error go by, for aria-describedby. */
const described = (id: string, hasHint: boolean) =>
    hasHint ? `${id}-hint ${id}-error` : `${id}-error`;

/**
 * A place's kind and the fields that kind needs (I-2401): three radio cards,
 * a folder first, then the name and the kind's own fields. The name offered
 * follows the kind until the person types in it.
 */
export function PlaceFields({
    kind,
    onKindChange,
    suggestedFolder,
    errors,
    values = null,
}: {
    kind: BackupPlaceKind;
    onKindChange: (kind: BackupPlaceKind) => void;
    suggestedFolder: string;
    errors: Record<string, string>;
    /** A place being changed: its kind is fixed and its fields start from today's values. */
    values?: PlaceFormValues | null;
}) {
    const [typedName, setTypedName] = useState<string | null>(null);
    const changing = values !== null;
    const name =
        typedName ??
        values?.name ??
        (kind === 'folder' ? 'This server' : 'Off-site storage');
    const kindName = KINDS.find((option) => option.kind === kind)?.name;

    const copyKey = async () => {
        try {
            await navigator.clipboard.writeText(values?.publicKey ?? '');
            toast.success('Copied the public key.');
        } catch {
            toast.error(
                'The public key could not be copied. Select it and copy it by hand.',
            );
        }
    };

    return (
        <div className="space-y-5">
            {changing ? (
                <p className="text-sm font-medium">{kindName}</p>
            ) : (
                <fieldset className="space-y-3">
                    <legend className="mb-2 text-sm font-medium">
                        Where should backups be kept?
                    </legend>
                    <div className="grid gap-3 sm:grid-cols-3">
                        {KINDS.map((option) => (
                            <label
                                key={option.kind}
                                className="border-border has-checked:border-primary has-focus-visible:ring-ring flex min-h-11 cursor-pointer flex-col gap-1 rounded-md border p-3 has-checked:ring-1 has-focus-visible:ring-2"
                            >
                                <span className="flex items-center gap-2 text-sm font-medium">
                                    <input
                                        type="radio"
                                        name="kind"
                                        value={option.kind}
                                        checked={kind === option.kind}
                                        onChange={() =>
                                            onKindChange(option.kind)
                                        }
                                        data-test={`setup-kind-${option.kind}`}
                                        aria-describedby="setup-kind-error"
                                    />
                                    {option.name}
                                </span>
                                <span className="text-muted-foreground text-xs">
                                    {option.line}
                                </span>
                            </label>
                        ))}
                    </div>
                    <InputError id="setup-kind-error" message={errors.kind} />
                </fieldset>
            )}

            <Field
                id="setup-name"
                label={<Label htmlFor="setup-name">Name this place</Label>}
                hintText="Shown on this screen and in alerts."
                error={errors.name}
            >
                <Input
                    id="setup-name"
                    name="name"
                    value={name}
                    onChange={(event) => setTypedName(event.target.value)}
                    aria-describedby={described('setup-name', true)}
                    autoComplete="off"
                />
            </Field>

            {kind === 'folder' && (
                <Field
                    id="setup-path"
                    label={<Label htmlFor="setup-path">Folder</Label>}
                    hintText={`A full path on this server, outside the site’s own folders, such as ${suggestedFolder}.`}
                    error={errors.path}
                >
                    <Input
                        id="setup-path"
                        name="path"
                        defaultValue={values?.path ?? suggestedFolder}
                        aria-describedby={described('setup-path', true)}
                        className="font-mono"
                        spellCheck={false}
                        autoComplete="off"
                    />
                </Field>
            )}

            {kind === 's3' && (
                <>
                    <Field
                        id="setup-endpoint"
                        label={
                            <Label htmlFor="setup-endpoint">
                                Storage address
                            </Label>
                        }
                        hintText="Starts https://, such as https://s3.eu-central-1.amazonaws.com."
                        error={errors.endpoint}
                    >
                        <Input
                            id="setup-endpoint"
                            name="endpoint"
                            defaultValue={values?.endpoint ?? ''}
                            aria-describedby={described('setup-endpoint', true)}
                            spellCheck={false}
                            autoComplete="off"
                        />
                    </Field>
                    <Field
                        id="setup-bucket"
                        label={<Label htmlFor="setup-bucket">Bucket</Label>}
                        error={errors.bucket}
                    >
                        <Input
                            id="setup-bucket"
                            name="bucket"
                            defaultValue={values?.bucket ?? ''}
                            aria-describedby={described('setup-bucket', false)}
                            spellCheck={false}
                            autoComplete="off"
                        />
                    </Field>
                    <Field
                        id="setup-folder"
                        label={
                            <Label htmlFor="setup-folder">
                                Folder in the bucket
                            </Label>
                        }
                        hintText="Optional."
                        error={errors.path}
                    >
                        <Input
                            id="setup-folder"
                            name="folder"
                            defaultValue={values?.folder ?? ''}
                            aria-describedby={described('setup-folder', true)}
                            spellCheck={false}
                            autoComplete="off"
                        />
                    </Field>
                    <Field
                        id="setup-region"
                        label={<Label htmlFor="setup-region">Region</Label>}
                        hintText="Optional, such as eu-central-1."
                        error={errors.region}
                    >
                        <Input
                            id="setup-region"
                            name="region"
                            defaultValue={values?.region ?? ''}
                            aria-describedby={described('setup-region', true)}
                            spellCheck={false}
                            autoComplete="off"
                        />
                    </Field>
                    <Field
                        id="setup-access-key-id"
                        label={
                            <Label htmlFor="setup-access-key-id">
                                Access key ID
                            </Label>
                        }
                        error={errors.access_key_id}
                    >
                        <Input
                            id="setup-access-key-id"
                            name="access_key_id"
                            aria-describedby={described(
                                'setup-access-key-id',
                                false,
                            )}
                            spellCheck={false}
                            autoComplete="off"
                        />
                    </Field>
                    <Field
                        id="setup-secret-access-key"
                        label={
                            <Label htmlFor="setup-secret-access-key">
                                Secret access key
                            </Label>
                        }
                        hintText={
                            changing
                                ? 'Leave both empty to keep the keys Teme has.'
                                : 'Use a key that can reach this bucket only. Teme keeps it encrypted and never shows it again.'
                        }
                        error={errors.secret_access_key}
                    >
                        <PasswordInput
                            id="setup-secret-access-key"
                            name="secret_access_key"
                            aria-describedby={described(
                                'setup-secret-access-key',
                                true,
                            )}
                            autoComplete="off"
                        />
                    </Field>
                </>
            )}

            {kind === 'sftp' && (
                <>
                    <Field
                        id="setup-host"
                        label={<Label htmlFor="setup-host">Server</Label>}
                        hintText="Its name or IP address, such as backup.example.com."
                        error={errors.host}
                    >
                        <Input
                            id="setup-host"
                            name="host"
                            defaultValue={values?.host ?? ''}
                            aria-describedby={described('setup-host', true)}
                            spellCheck={false}
                            autoComplete="off"
                        />
                    </Field>
                    <Field
                        id="setup-port"
                        label={<Label htmlFor="setup-port">Port</Label>}
                        error={errors.port}
                    >
                        <Input
                            id="setup-port"
                            name="port"
                            type="number"
                            min={1}
                            max={65535}
                            defaultValue={values?.port ?? 22}
                            aria-describedby={described('setup-port', false)}
                        />
                    </Field>
                    <Field
                        id="setup-user"
                        label={<Label htmlFor="setup-user">User</Label>}
                        hintText="The user Teme signs in as."
                        error={errors.user}
                    >
                        <Input
                            id="setup-user"
                            name="user"
                            defaultValue={values?.user ?? ''}
                            aria-describedby={described('setup-user', true)}
                            spellCheck={false}
                            autoComplete="off"
                        />
                    </Field>
                    <Field
                        id="setup-sftp-folder"
                        label={
                            <Label htmlFor="setup-sftp-folder">
                                Folder on the server
                            </Label>
                        }
                        hintText="Such as /srv/backups/teme."
                        error={errors.path}
                    >
                        <Input
                            id="setup-sftp-folder"
                            name="folder"
                            defaultValue={values?.folder ?? ''}
                            aria-describedby={described(
                                'setup-sftp-folder',
                                true,
                            )}
                            className="font-mono"
                            spellCheck={false}
                            autoComplete="off"
                        />
                    </Field>
                    {values?.publicKey && (
                        <Field
                            id="setup-public-key"
                            label={
                                <Label htmlFor="setup-public-key">
                                    Public key
                                </Label>
                            }
                            hintText="This place’s public key. A new server needs it in authorized_keys."
                        >
                            <textarea
                                id="setup-public-key"
                                readOnly
                                rows={4}
                                value={values.publicKey}
                                aria-describedby={described(
                                    'setup-public-key',
                                    true,
                                )}
                                className="border-input bg-background w-full rounded-md border px-3 py-2 font-mono text-xs break-all"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                className="w-fit"
                                onClick={() => void copyKey()}
                            >
                                Copy
                            </Button>
                        </Field>
                    )}
                </>
            )}
        </div>
    );
}
