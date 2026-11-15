import { Form, Head, setLayoutProps } from '@inertiajs/react';
import { toast } from 'sonner';
import SftpStepController from '@/actions/App/Http/Admin/Tools/Backups/SftpStepController';
import { SetupSteps } from '@/components/backups/setup-steps';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { FormPage, FormPanel } from '@/components/patterns/form-page';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldInline } from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import { __ } from '@/lib/i18n';
import { index } from '@/routes/tools';
import { index as backups, sftp } from '@/routes/tools/backups';
import type { SftpPage } from '@/types/backups';

/** The submit button's words, by what the step is for and whether it is sending. */
function submitWords(purpose: SftpPage['purpose'], processing: boolean) {
    if (purpose === 'add') {
        return processing ? __('Adding…') : __('Add the place');
    }

    if (purpose === 'change') {
        return processing ? __('Saving…') : __('Save changes');
    }

    return processing ? __('Setting up…') : __('Set up backups');
}

/** An SFTP place's second step (I-2403): the public key to add, and the fingerprint to check. */
export default function Sftp({
    purpose,
    user,
    host,
    publicKey,
    fingerprint,
}: SftpPage) {
    setLayoutProps({
        breadcrumbs: [
            { title: __('Tools'), href: index() },
            { title: __('Backups'), href: backups() },
            { title: __('Check the server'), href: sftp() },
        ],
    });

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(publicKey);
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
        <>
            <Head title={__('Check the server')} />

            <FormPage
                title={
                    purpose === 'change'
                        ? __('Check the new server')
                        : __('Check the server')
                }
                description={__(
                    'Teme made this key for the server. Add it there, then check the fingerprint.',
                )}
            >
                <div className="grid gap-4">
                    {purpose === 'set-up' && <SetupSteps current={1} />}

                    <Form {...SftpStepController.store.form()}>
                        {({ processing, errors }) => (
                            <>
                                <FormPanel
                                    title={__('Add this key to the server')}
                                    description={
                                        purpose === 'change'
                                            ? __(
                                                  'Add this place’s public key to :user’s authorized_keys on :host, as you did on the old server.',
                                                  { user, host },
                                              )
                                            : __(
                                                  'Add this public key to :user’s authorized_keys on :host. It lets Teme sign in to that server only.',
                                                  { user, host },
                                              )
                                    }
                                >
                                    <div className="grid justify-items-start gap-2">
                                        <Field
                                            htmlFor="sftp-public-key"
                                            label={__('Public key')}
                                            className="w-full"
                                        >
                                            <Textarea
                                                id="sftp-public-key"
                                                readOnly
                                                rows={4}
                                                value={publicKey}
                                                className="text-small font-mono break-all"
                                            />
                                        </Field>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => void copy()}
                                        >
                                            {__('Copy')}
                                        </Button>
                                    </div>
                                </FormPanel>

                                <FormPanel
                                    title={__('Check the server’s fingerprint')}
                                    description={__(
                                        ':host answered with the fingerprint below. Ask whoever runs it to check it’s theirs before you go on.',
                                        { host },
                                    )}
                                >
                                    <p className="text-body font-mono break-all">
                                        {fingerprint}
                                    </p>
                                    <FieldInline
                                        htmlFor="sftp-confirmed"
                                        label={__(
                                            'The key is added, and the fingerprint is the server’s',
                                        )}
                                        error={errors.confirmed}
                                    >
                                        <Checkbox
                                            id="sftp-confirmed"
                                            name="confirmed"
                                            value="1"
                                            data-test="sftp-confirm"
                                        />
                                    </FieldInline>

                                    {purpose === 'add' && (
                                        <Field
                                            htmlFor="sftp-recovery-key"
                                            label={__('Your recovery key')}
                                            help={__(
                                                'Teme locks the new place with it too, then forgets it. It’s a key you typed back when it was made.',
                                            )}
                                            error={errors.recovery_key}
                                        >
                                            <PasswordInput
                                                id="sftp-recovery-key"
                                                name="recovery_key"
                                                autoComplete="off"
                                            />
                                        </Field>
                                    )}

                                    <div className="grid justify-items-start gap-2">
                                        <InputError
                                            id="sftp-place-error"
                                            message={errors.place}
                                        />
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                            aria-describedby="sftp-place-error"
                                            data-test="sftp-submit"
                                        >
                                            {submitWords(purpose, processing)}
                                        </Button>
                                    </div>
                                </FormPanel>
                            </>
                        )}
                    </Form>

                    <Form {...SftpStepController.destroy.form()}>
                        <Button
                            type="submit"
                            variant="outline"
                            data-test="sftp-start-again"
                        >
                            {__('Start again')}
                        </Button>
                    </Form>
                </div>
            </FormPage>
        </>
    );
}
