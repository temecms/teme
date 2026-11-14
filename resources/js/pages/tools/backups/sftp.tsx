import { Form, Head } from '@inertiajs/react';
import { toast } from 'sonner';
import SftpStepController from '@/actions/App/Http/Admin/Tools/Backups/SftpStepController';
import { SetupSteps } from '@/components/backups/setup-steps';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/list/page-header';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/tools';
import { index as backups, sftp } from '@/routes/tools/backups';
import type { SftpPage } from '@/types/backups';

/** An SFTP place's second step (I-2403): the public key to add, and the fingerprint to check. */
export default function Sftp({
    purpose,
    user,
    host,
    publicKey,
    fingerprint,
}: SftpPage) {
    const copy = async () => {
        try {
            await navigator.clipboard.writeText(publicKey);
            toast.success('Copied the public key.');
        } catch {
            toast.error(
                'The public key could not be copied. Select it and copy it by hand.',
            );
        }
    };

    return (
        <>
            <Head title="Check the server" />

            <div className="max-w-3xl space-y-6 p-6">
                <PageHeader
                    title={
                        purpose === 'change'
                            ? 'Check the new server'
                            : 'Check the server'
                    }
                    description="Teme made this key for the server. Add it there, then check the fingerprint."
                />

                {purpose === 'set-up' && <SetupSteps current={1} />}

                <Form
                    {...SftpStepController.store.form()}
                    className="max-w-[640px] space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <section
                                aria-labelledby="sftp-add-key"
                                className="space-y-2"
                            >
                                <h2
                                    id="sftp-add-key"
                                    className="text-base font-medium"
                                >
                                    Add this key to the server
                                </h2>
                                <p className="text-sm">
                                    {purpose === 'change'
                                        ? `Add this place’s public key to ${user}’s authorized_keys on ${host}, as you did on the old server.`
                                        : `Add this public key to ${user}’s authorized_keys on ${host}. It lets Teme sign in to that server only.`}
                                </p>
                                <Label htmlFor="sftp-public-key">
                                    Public key
                                </Label>
                                <textarea
                                    id="sftp-public-key"
                                    readOnly
                                    rows={4}
                                    value={publicKey}
                                    className="border-input bg-background w-full rounded-md border px-3 py-2 font-mono text-xs break-all"
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => void copy()}
                                >
                                    Copy
                                </Button>
                            </section>

                            <section
                                aria-labelledby="sftp-fingerprint"
                                className="space-y-2"
                            >
                                <h2
                                    id="sftp-fingerprint"
                                    className="text-base font-medium"
                                >
                                    Check the server’s fingerprint
                                </h2>
                                <p className="text-sm">
                                    {host} answered with the fingerprint below.
                                    Ask whoever runs it to check it’s theirs
                                    before you go on.
                                </p>
                                <p className="font-mono text-sm break-all">
                                    {fingerprint}
                                </p>
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="sftp-confirmed"
                                        name="confirmed"
                                        value="1"
                                        className="size-6"
                                        aria-describedby="sftp-confirmed-error"
                                        data-test="sftp-confirm"
                                    />
                                    <Label htmlFor="sftp-confirmed">
                                        The key is added, and the fingerprint is
                                        the server’s
                                    </Label>
                                </div>
                                <InputError
                                    id="sftp-confirmed-error"
                                    message={errors.confirmed}
                                />
                            </section>

                            {purpose === 'add' && (
                                <div className="grid gap-2">
                                    <Label htmlFor="sftp-recovery-key">
                                        Your recovery key
                                    </Label>
                                    <PasswordInput
                                        id="sftp-recovery-key"
                                        name="recovery_key"
                                        autoComplete="off"
                                        aria-describedby="sftp-recovery-key-hint sftp-recovery-key-error"
                                    />
                                    <p
                                        id="sftp-recovery-key-hint"
                                        className="text-muted-foreground text-xs"
                                    >
                                        Teme locks the new place with it too,
                                        then forgets it. It’s a key you typed
                                        back when it was made.
                                    </p>
                                    <InputError
                                        id="sftp-recovery-key-error"
                                        message={errors.recovery_key}
                                    />
                                </div>
                            )}

                            <InputError
                                id="sftp-place-error"
                                message={errors.place}
                            />

                            <div className="flex flex-wrap gap-2">
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    aria-describedby="sftp-place-error"
                                    data-test="sftp-submit"
                                >
                                    {purpose === 'add'
                                        ? processing
                                            ? 'Adding…'
                                            : 'Add the place'
                                        : purpose === 'change'
                                          ? processing
                                              ? 'Saving…'
                                              : 'Save changes'
                                          : processing
                                            ? 'Setting up…'
                                            : 'Set up backups'}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                <Form {...SftpStepController.destroy.form()}>
                    <Button
                        type="submit"
                        variant="outline"
                        data-test="sftp-start-again"
                    >
                        Start again
                    </Button>
                </Form>
            </div>
        </>
    );
}

Sftp.layout = {
    breadcrumbs: [
        { title: 'Tools', href: index() },
        { title: 'Backups', href: backups() },
        { title: 'Check the server', href: sftp() },
    ],
};
