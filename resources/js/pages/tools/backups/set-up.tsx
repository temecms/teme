import { Form, Head, Link, setLayoutProps } from '@inertiajs/react';
import { useState } from 'react';
import BackupSetupController from '@/actions/App/Http/Admin/Tools/Backups/BackupSetupController';
import { PlaceFields } from '@/components/backups/place-fields';
import { SetupSteps } from '@/components/backups/setup-steps';
import InputError from '@/components/input-error';
import { FormPage, FormPanel } from '@/components/patterns/form-page';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { __ } from '@/lib/i18n';
import { index } from '@/routes/tools';
import { index as backups, setup } from '@/routes/tools/backups';
import type { BackupPlaceKind, SetUpPage } from '@/types/backups';

/** Setting backups up (I-2401): the first place and the recovery key's name; the key itself comes next. */
export default function SetUp({ suggestedFolder }: SetUpPage) {
    const [kind, setKind] = useState<BackupPlaceKind>('folder');

    setLayoutProps({
        breadcrumbs: [
            { title: __('Tools'), href: index() },
            { title: __('Backups'), href: backups() },
            { title: __('Set up backups'), href: setup() },
        ],
    });

    return (
        <>
            <Head title={__('Set up backups')} />

            <FormPage
                title={__('Set up backups')}
                description={__(
                    'Choose where backups are kept. Teme then shows you a recovery key, once.',
                )}
            >
                <div className="grid gap-4">
                    <SetupSteps current={1} />

                    <Form {...BackupSetupController.store.form()}>
                        {({ processing, errors }) => (
                            <>
                                <FormPanel title={__('The first place')}>
                                    <PlaceFields
                                        kind={kind}
                                        onKindChange={setKind}
                                        suggestedFolder={suggestedFolder}
                                        errors={errors}
                                    />
                                </FormPanel>
                                <FormPanel title={__('The recovery key')}>
                                    <Field
                                        htmlFor="setup-label"
                                        label={__('Name the recovery key')}
                                        help={__(
                                            'Name it after who keeps it, such as Ada’s key. You can add more keys later.',
                                        )}
                                        error={errors.label}
                                    >
                                        <Input
                                            id="setup-label"
                                            name="label"
                                            defaultValue={__('Recovery key')}
                                            autoComplete="off"
                                        />
                                    </Field>

                                    <p className="text-body">
                                        {__(
                                            'Have a pen and paper or a printer ready.',
                                        )}
                                    </p>
                                    {kind !== 'folder' && (
                                        <p className="text-body">
                                            {__(
                                                'Making the place can take a few minutes on a distant server. Keep this page open.',
                                            )}
                                        </p>
                                    )}

                                    <div className="grid justify-items-start gap-2">
                                        <InputError
                                            id="setup-place-error"
                                            message={errors.place}
                                        />

                                        <div className="flex flex-wrap gap-2">
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                                aria-describedby="setup-place-error"
                                                data-test="setup-submit"
                                            >
                                                {processing
                                                    ? kind === 'sftp'
                                                        ? __(
                                                              'Reading the server’s fingerprint…',
                                                          )
                                                        : __('Setting up…')
                                                    : kind === 'sftp'
                                                      ? __('Continue')
                                                      : __('Set up backups')}
                                            </Button>
                                            <Button variant="ghost" asChild>
                                                <Link href={backups()}>
                                                    {__('Cancel')}
                                                </Link>
                                            </Button>
                                        </div>
                                    </div>
                                </FormPanel>
                            </>
                        )}
                    </Form>
                </div>
            </FormPage>
        </>
    );
}
