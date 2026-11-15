import { Form, Link } from '@inertiajs/react';
import { useState } from 'react';
import RecoveryKeyController from '@/actions/App/Http/Admin/Tools/Backups/RecoveryKeyController';
import { ConfirmAction } from '@/components/confirm-action';
import { post } from '@/components/editor/editor-http';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { FreshPasswordInline } from '@/components/patterns/fresh-password';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Field } from '@/components/ui/field';
import { __, __n } from '@/lib/i18n';
import { names } from '@/lib/backup-words';
import { check, create } from '@/routes/tools/backups/keys';
import type { RecoveryKeyRow } from '@/types/backups';

type Checked = {
    label: string | null;
    opens: string[];
    doesNotOpen: string[];
};

/** The first message in a 422 answer under one key. */
function firstError(body: Record<string, unknown>, key: string): string {
    const errors = body.errors as Record<string, string[]> | undefined;

    return errors?.[key]?.[0] ?? (body.message as string | undefined) ?? '';
}

/**
 * Check my recovery key (I-359): a dialog that tries a typed key on every
 * place and says which it opens, keeping nothing of it. A lapsed password is
 * asked for inside the dialog, so the typed key isn't lost.
 */
function CheckKey() {
    const [open, setOpen] = useState(false);
    const [typed, setTyped] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [answer, setAnswer] = useState<Checked | null>(null);
    const [askPassword, setAskPassword] = useState(false);

    const close = (next: boolean) => {
        setOpen(next);

        if (!next) {
            // Nothing typed is kept once the dialog is gone.
            setTyped('');
            setAnswer(null);
            setError('');
            setAskPassword(false);
        }
    };

    const send = async () => {
        setBusy(true);
        setError('');

        try {
            const reply = await post(check.url(), { recovery_key: typed });

            if (reply.status === 423) {
                setAskPassword(true);
            } else if (reply.status === 422) {
                setError(firstError(reply.body, 'recovery_key'));
            } else if (reply.status >= 200 && reply.status < 300) {
                setAnswer(reply.body as unknown as Checked);
            } else {
                setError(__('The key could not be checked. Try again.'));
            }
        } finally {
            setBusy(false);
        }
    };

    return (
        <>
            <Button
                type="button"
                variant="outline"
                onClick={() => setOpen(true)}
                data-test="backup-key-check"
            >
                {__('Check my recovery key')}
            </Button>
            <Dialog open={open} onOpenChange={close}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{__('Check my recovery key')}</DialogTitle>
                        <DialogDescription>
                            {__(
                                'Teme tries it on every place and forgets it straight away.',
                            )}
                        </DialogDescription>
                    </DialogHeader>

                    <Field
                        htmlFor="check-recovery-key"
                        label={__('Recovery key')}
                        error={error || undefined}
                    >
                        <PasswordInput
                            id="check-recovery-key"
                            value={typed}
                            onChange={(event) => setTyped(event.target.value)}
                            autoComplete="off"
                        />
                    </Field>

                    <FreshPasswordInline
                        open={askPassword}
                        onOpenChange={setAskPassword}
                        title={__('Confirm your password')}
                        text={__(
                            'Type your password to carry on. What you typed stays here.',
                        )}
                        onConfirmed={() => void send()}
                    />

                    <div role="status" className="text-body grid gap-1">
                        {answer !== null && (
                            <>
                                <p className="font-medium">
                                    {answer.label === null
                                        ? __(
                                              'This key isn’t one of this site’s recovery keys.',
                                          )
                                        : __('This is :label.', {
                                              label: answer.label,
                                          })}
                                </p>
                                <ul className="grid gap-1">
                                    {answer.opens.map((place) => (
                                        <li key={`opens-${place}`}>
                                            {__(
                                                'Opens the backups at :place.',
                                                {
                                                    place,
                                                },
                                            )}
                                        </li>
                                    ))}
                                    {answer.doesNotOpen.map((place) => (
                                        <li key={`closed-${place}`}>
                                            {__(
                                                'Doesn’t open the backups at :place.',
                                                { place },
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            </>
                        )}
                    </div>

                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                {__('Close')}
                            </Button>
                        </DialogClose>
                        <Button
                            type="button"
                            disabled={busy || typed === ''}
                            onClick={() => void send()}
                            data-test="check-key-submit"
                        >
                            {busy ? __('Checking…') : __('Check the key')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

/**
 * Add my key to new places (I-2402): the typed key is given to places added
 * after it was made, then forgotten.
 */
function ExtendKey({ places }: { places: string[] }) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <Button
                type="button"
                variant="outline"
                onClick={() => setOpen(true)}
                data-test="backup-key-extend"
            >
                {__('Add my key to new places')}
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {__('Add your key to new places')}
                        </DialogTitle>
                        <DialogDescription>
                            {__n(
                                ':places was added after some keys were made. Type your recovery key, and Teme adds it there too, then forgets it.|:places were added after some keys were made. Type your recovery key, and Teme adds it there too, then forgets it.',
                                places.length,
                                { places: names(places) },
                            )}
                        </DialogDescription>
                    </DialogHeader>
                    <Form {...RecoveryKeyController.extend.form()}>
                        {({ processing, errors }) => (
                            <div className="grid gap-4">
                                <Field
                                    htmlFor="extend-recovery-key"
                                    label={__('Recovery key')}
                                    error={errors.recovery_key}
                                >
                                    <PasswordInput
                                        id="extend-recovery-key"
                                        name="recovery_key"
                                        autoComplete="off"
                                    />
                                </Field>
                                <InputError
                                    id="extend-place-error"
                                    message={errors.place}
                                />
                                <DialogFooter>
                                    <DialogClose asChild>
                                        <Button type="button" variant="outline">
                                            {__('Cancel')}
                                        </Button>
                                    </DialogClose>
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        aria-describedby="extend-place-error"
                                    >
                                        {__('Add my key')}
                                    </Button>
                                </DialogFooter>
                            </div>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        </>
    );
}

/** Check, Add another and Add my key to new places, above the list of keys. */
export function KeyActions({ keys }: { keys: RecoveryKeyRow[] }) {
    const lacking = Array.from(new Set(keys.flatMap((key) => key.missingAt)));

    return (
        <div className="flex flex-wrap gap-2">
            <CheckKey />
            <Button asChild variant="outline" data-test="backup-key-add">
                <Link href={create()}>{__('Add another key')}</Link>
            </Button>
            {lacking.length > 0 && <ExtendKey places={lacking} />}
        </div>
    );
}

/** Replace beside a key's label: a new key is made and shown once; the old one keeps working until two groups are typed back. */
export function ReplaceKey({ label }: { label: string }) {
    return (
        <Form {...RecoveryKeyController.store.form()}>
            <input type="hidden" name="label" value={label} />
            <input type="hidden" name="replace" value="1" />
            <ConfirmAction
                title={__('Replace :label?', { label })}
                description={__(
                    'Teme makes a new key for :label and shows it once. The old one keeps working until you’ve typed part of the new one back, then it stops opening the backups.',
                    { label },
                )}
                confirmLabel={__('Replace :label', { label })}
                confirmVariant="default"
            >
                <Button
                    type="submit"
                    variant="outline"
                    data-test="backup-key-replace"
                >
                    {__('Replace')}
                    <span className="sr-only"> {label}</span>
                </Button>
            </ConfirmAction>
        </Form>
    );
}
