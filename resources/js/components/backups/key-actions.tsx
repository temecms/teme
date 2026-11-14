import { Form, Link } from '@inertiajs/react';
import { useState } from 'react';
import RecoveryKeyController from '@/actions/App/Http/Admin/Tools/Backups/RecoveryKeyController';
import { ConfirmAction } from '@/components/confirm-action';
import { post } from '@/components/editor/editor-http';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
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
import { Label } from '@/components/ui/label';
import { names } from '@/lib/backup-words';
import { store as confirmPassword } from '@/routes/password/confirm';
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
 * asked for in place, so the typed key isn't lost.
 */
function CheckKey() {
    const [open, setOpen] = useState(false);
    const [typed, setTyped] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [answer, setAnswer] = useState<Checked | null>(null);
    const [askPassword, setAskPassword] = useState(false);
    const [password, setPassword] = useState('');
    const [passwordError, setPasswordError] = useState('');

    const close = (next: boolean) => {
        setOpen(next);

        if (!next) {
            // Nothing typed is kept once the dialog is gone.
            setTyped('');
            setAnswer(null);
            setError('');
            setAskPassword(false);
            setPassword('');
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
                setError('The key could not be checked. Try again.');
            }
        } finally {
            setBusy(false);
        }
    };

    const confirmThenSend = async () => {
        setBusy(true);
        setPasswordError('');

        try {
            const reply = await post(confirmPassword.url(), { password });

            if (reply.status === 422) {
                setPasswordError(firstError(reply.body, 'password'));

                return;
            }

            setAskPassword(false);
            setPassword('');
        } finally {
            setBusy(false);
        }

        await send();
    };

    return (
        <>
            <Button
                type="button"
                variant="outline"
                onClick={() => setOpen(true)}
                data-test="backup-key-check"
            >
                Check my recovery key
            </Button>
            <Dialog open={open} onOpenChange={close}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Check my recovery key</DialogTitle>
                        <DialogDescription>
                            Teme tries it on every place and forgets it straight
                            away.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="check-recovery-key">Recovery key</Label>
                        <PasswordInput
                            id="check-recovery-key"
                            value={typed}
                            onChange={(event) => setTyped(event.target.value)}
                            autoComplete="off"
                            aria-describedby="check-recovery-key-error"
                        />
                        <InputError
                            id="check-recovery-key-error"
                            message={error}
                        />
                    </div>

                    {askPassword && (
                        <div className="border-line space-y-3 rounded-md border p-4">
                            <p className="text-sm">
                                Type your password to carry on. What you typed
                                stays here.
                            </p>
                            <div className="grid gap-2">
                                <Label htmlFor="key-password">Password</Label>
                                <PasswordInput
                                    id="key-password"
                                    value={password}
                                    onChange={(event) =>
                                        setPassword(event.target.value)
                                    }
                                    autoComplete="current-password"
                                    aria-describedby="key-password-error"
                                />
                                <InputError
                                    id="key-password-error"
                                    message={passwordError}
                                />
                            </div>
                            <Button
                                type="button"
                                disabled={busy}
                                onClick={() => void confirmThenSend()}
                                data-test="key-password-confirm"
                            >
                                Confirm password
                            </Button>
                        </div>
                    )}

                    <div role="status" className="space-y-1 text-sm">
                        {answer !== null && (
                            <>
                                <p className="font-medium">
                                    {answer.label === null
                                        ? 'This key isn’t one of this site’s recovery keys.'
                                        : `This is ${answer.label}.`}
                                </p>
                                <ul className="space-y-1">
                                    {answer.opens.map((place) => (
                                        <li key={`opens-${place}`}>
                                            Opens the backups at {place}.
                                        </li>
                                    ))}
                                    {answer.doesNotOpen.map((place) => (
                                        <li key={`closed-${place}`}>
                                            Doesn’t open the backups at {place}.
                                        </li>
                                    ))}
                                </ul>
                            </>
                        )}
                    </div>

                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Close
                            </Button>
                        </DialogClose>
                        <Button
                            type="button"
                            disabled={busy || typed === ''}
                            onClick={() => void send()}
                            data-test="check-key-submit"
                        >
                            {busy ? 'Checking…' : 'Check the key'}
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
                Add my key to new places
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Add your key to new places</DialogTitle>
                        <DialogDescription>
                            {names(places)}{' '}
                            {places.length === 1 ? 'was' : 'were'} added after
                            some keys were made. Type your recovery key, and
                            Teme adds it there too, then forgets it.
                        </DialogDescription>
                    </DialogHeader>
                    <Form {...RecoveryKeyController.extend.form()}>
                        {({ processing, errors }) => (
                            <div className="space-y-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="extend-recovery-key">
                                        Recovery key
                                    </Label>
                                    <PasswordInput
                                        id="extend-recovery-key"
                                        name="recovery_key"
                                        autoComplete="off"
                                        aria-describedby="extend-recovery-key-error"
                                    />
                                    <InputError
                                        id="extend-recovery-key-error"
                                        message={errors.recovery_key}
                                    />
                                </div>
                                <InputError
                                    id="extend-place-error"
                                    message={errors.place}
                                />
                                <DialogFooter>
                                    <DialogClose asChild>
                                        <Button type="button" variant="outline">
                                            Cancel
                                        </Button>
                                    </DialogClose>
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        aria-describedby="extend-place-error"
                                    >
                                        Add my key
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
                <Link href={create()}>Add another key</Link>
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
                title={`Replace ${label}?`}
                description={`Teme makes a new key for ${label} and shows it once. The old one keeps working until you’ve typed part of the new one back, then it stops opening the backups.`}
                confirmLabel={`Replace ${label}`}
                confirmVariant="default"
            >
                <Button
                    type="submit"
                    variant="outline"
                    data-test="backup-key-replace"
                >
                    Replace
                    <span className="sr-only"> {label}</span>
                </Button>
            </ConfirmAction>
        </Form>
    );
}
