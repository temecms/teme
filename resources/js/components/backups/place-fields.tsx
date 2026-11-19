import { useState } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { RadioGroup, RadioGroupChoice } from '@/components/ui/radio-group';
import { Textarea } from '@/components/ui/textarea';
import { __ } from '@/lib/i18n';
import type { BackupPlaceKind, PlaceFormValues } from '@/types/backups';

/** The three kinds of place, their words looked up as they are drawn. */
const kinds = (): { kind: BackupPlaceKind; name: string; line: string }[] => [
    {
        kind: 'folder',
        name: __('A folder on this server'),
        line: __(
            'Simplest, but a backup here goes if the server goes. Add a place off it afterwards.',
        ),
    },
    {
        kind: 's3',
        name: __('S3-compatible storage'),
        line: __('Amazon S3, Backblaze B2, Cloudflare R2, Wasabi and others.'),
    },
    {
        kind: 'sftp',
        name: __('An SFTP server'),
        line: __('A server you or someone you trust runs, reached over SSH.'),
    },
];

/**
 * A place's kind and the fields that kind needs (I-2401): three choices, a
 * folder first, then the name and the kind's own fields. The name offered
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
        (kind === 'folder' ? __('This server') : __('Off-site storage'));
    const kindName = kinds().find((option) => option.kind === kind)?.name;

    const copyKey = async () => {
        try {
            await navigator.clipboard.writeText(values?.publicKey ?? '');
            toast.success(__('Copied the public key.'));
        } catch {
            toast.error(
                __(
                    'The public key could not be copied. Select it and copy it by hand.',
                ),
            );
        }
    };

    return (
        <div className="grid gap-5">
            {changing ? (
                <p className="text-body font-medium">{kindName}</p>
            ) : (
                <fieldset className="grid gap-3">
                    <legend className="text-small text-ink mb-2 font-medium">
                        {__('Where should backups be kept?')}
                    </legend>
                    <RadioGroup
                        name="kind"
                        value={kind}
                        onValueChange={(next) =>
                            onKindChange(next as BackupPlaceKind)
                        }
                        aria-describedby="setup-kind-error"
                        className="gap-3"
                    >
                        {kinds().map((option) => (
                            <RadioGroupChoice
                                key={option.kind}
                                id={`setup-kind-${option.kind}`}
                                value={option.kind}
                                label={option.name}
                                description={option.line}
                                data-test={`setup-kind-${option.kind}`}
                                className={
                                    option.kind === kind
                                        ? 'bg-accent-soft border-accent rounded-lg border px-4 py-3'
                                        : 'bg-surface-2 rounded-lg border border-transparent px-4 py-3'
                                }
                            />
                        ))}
                    </RadioGroup>
                    <InputError id="setup-kind-error" message={errors.kind} />
                </fieldset>
            )}

            <Field
                htmlFor="setup-name"
                label={__('Name this place')}
                help={__('Shown on this screen and in alerts.')}
                error={errors.name}
            >
                <Input
                    id="setup-name"
                    name="name"
                    value={name}
                    onChange={(event) => setTypedName(event.target.value)}
                    autoComplete="off"
                />
            </Field>

            {kind === 'folder' && (
                <Field
                    htmlFor="setup-path"
                    label={__('Folder')}
                    help={__(
                        'A full path on this server, outside the site’s own folders, such as :folder.',
                        { folder: suggestedFolder },
                    )}
                    error={errors.path}
                >
                    <Input
                        id="setup-path"
                        name="path"
                        defaultValue={values?.path ?? suggestedFolder}
                        className="font-mono"
                        spellCheck={false}
                        autoComplete="off"
                    />
                </Field>
            )}

            {kind === 's3' && (
                <>
                    <Field
                        htmlFor="setup-endpoint"
                        label={__('Storage address')}
                        help={__(
                            'Starts https://, such as https://s3.eu-central-1.amazonaws.com.',
                        )}
                        error={errors.endpoint}
                    >
                        <Input
                            id="setup-endpoint"
                            name="endpoint"
                            defaultValue={values?.endpoint ?? ''}
                            spellCheck={false}
                            autoComplete="off"
                        />
                    </Field>
                    <Field
                        htmlFor="setup-bucket"
                        label={__('Bucket')}
                        error={errors.bucket}
                    >
                        <Input
                            id="setup-bucket"
                            name="bucket"
                            defaultValue={values?.bucket ?? ''}
                            spellCheck={false}
                            autoComplete="off"
                        />
                    </Field>
                    <Field
                        htmlFor="setup-folder"
                        label={__('Folder in the bucket')}
                        help={__('Optional.')}
                        error={errors.path}
                    >
                        <Input
                            id="setup-folder"
                            name="folder"
                            defaultValue={values?.folder ?? ''}
                            spellCheck={false}
                            autoComplete="off"
                        />
                    </Field>
                    <Field
                        htmlFor="setup-region"
                        label={__('Region')}
                        help={__('Optional, such as eu-central-1.')}
                        error={errors.region}
                    >
                        <Input
                            id="setup-region"
                            name="region"
                            defaultValue={values?.region ?? ''}
                            spellCheck={false}
                            autoComplete="off"
                        />
                    </Field>
                    <Field
                        htmlFor="setup-access-key-id"
                        label={__('Access key ID')}
                        error={errors.access_key_id}
                    >
                        <Input
                            id="setup-access-key-id"
                            name="access_key_id"
                            spellCheck={false}
                            autoComplete="off"
                        />
                    </Field>
                    <Field
                        htmlFor="setup-secret-access-key"
                        label={__('Secret access key')}
                        help={
                            changing
                                ? __(
                                      'Leave both empty to keep the keys Teme has.',
                                  )
                                : __(
                                      'Use a key that can reach this bucket only. Teme keeps it encrypted and never shows it again.',
                                  )
                        }
                        error={errors.secret_access_key}
                    >
                        <PasswordInput
                            id="setup-secret-access-key"
                            name="secret_access_key"
                            autoComplete="off"
                        />
                    </Field>
                </>
            )}

            {kind === 'sftp' && (
                <>
                    <Field
                        htmlFor="setup-host"
                        label={__('Server')}
                        help={__(
                            'Its name or IP address, such as backup.example.com.',
                        )}
                        error={errors.host}
                    >
                        <Input
                            id="setup-host"
                            name="host"
                            defaultValue={values?.host ?? ''}
                            spellCheck={false}
                            autoComplete="off"
                        />
                    </Field>
                    <Field
                        htmlFor="setup-port"
                        label={__('Port')}
                        error={errors.port}
                    >
                        <Input
                            id="setup-port"
                            name="port"
                            type="number"
                            min={1}
                            max={65535}
                            defaultValue={values?.port ?? 22}
                        />
                    </Field>
                    <Field
                        htmlFor="setup-user"
                        label={__('User')}
                        help={__('The user Teme signs in as.')}
                        error={errors.user}
                    >
                        <Input
                            id="setup-user"
                            name="user"
                            defaultValue={values?.user ?? ''}
                            spellCheck={false}
                            autoComplete="off"
                        />
                    </Field>
                    <Field
                        htmlFor="setup-sftp-folder"
                        label={__('Folder on the server')}
                        help={__('Such as /srv/backups/teme.')}
                        error={errors.path}
                    >
                        <Input
                            id="setup-sftp-folder"
                            name="folder"
                            defaultValue={values?.folder ?? ''}
                            className="font-mono"
                            spellCheck={false}
                            autoComplete="off"
                        />
                    </Field>
                    {values?.publicKey && (
                        <div className="grid justify-items-start gap-2">
                            <Field
                                htmlFor="setup-public-key"
                                label={__('Public key')}
                                help={__(
                                    'This place’s public key. A new server needs it in authorized_keys.',
                                )}
                                className="w-full"
                            >
                                <Textarea
                                    id="setup-public-key"
                                    readOnly
                                    rows={4}
                                    value={values.publicKey}
                                    className="text-small font-mono break-all"
                                />
                            </Field>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => void copyKey()}
                            >
                                {__('Copy')}
                            </Button>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}
