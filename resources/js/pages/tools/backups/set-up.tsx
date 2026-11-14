import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import BackupSetupController from '@/actions/App/Http/Admin/Tools/Backups/BackupSetupController';
import { PlaceFields } from '@/components/backups/place-fields';
import { SetupSteps } from '@/components/backups/setup-steps';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/list/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/tools';
import { index as backups, setup } from '@/routes/tools/backups';
import type { BackupPlaceKind, SetUpPage } from '@/types/backups';

/** Setting backups up (I-2401): the first place and the recovery key's name; the key itself comes next. */
export default function SetUp({ suggestedFolder }: SetUpPage) {
    const [kind, setKind] = useState<BackupPlaceKind>('folder');

    return (
        <>
            <Head title="Set up backups" />

            <div className="max-w-3xl space-y-6 p-6">
                <PageHeader
                    title="Set up backups"
                    description="Choose where backups are kept. Teme then shows you a recovery key, once."
                />

                <SetupSteps current={1} />

                <Form
                    {...BackupSetupController.store.form()}
                    className="max-w-[640px] space-y-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <PlaceFields
                                kind={kind}
                                onKindChange={setKind}
                                suggestedFolder={suggestedFolder}
                                errors={errors}
                            />

                            <div className="grid gap-2">
                                <Label htmlFor="setup-label">
                                    Name the recovery key
                                </Label>
                                <Input
                                    id="setup-label"
                                    name="label"
                                    defaultValue="Recovery key"
                                    aria-describedby="setup-label-hint setup-label-error"
                                    autoComplete="off"
                                />
                                <p
                                    id="setup-label-hint"
                                    className="text-ink-2 text-xs"
                                >
                                    Name it after who keeps it, such as Ada’s
                                    key. You can add more keys later.
                                </p>
                                <InputError
                                    id="setup-label-error"
                                    message={errors.label}
                                />
                            </div>

                            <p className="text-sm">
                                Have a pen and paper or a printer ready.
                            </p>
                            {kind !== 'folder' && (
                                <p className="text-sm">
                                    Making the place can take a few minutes on a
                                    distant server. Keep this page open.
                                </p>
                            )}

                            <InputError
                                id="setup-place-error"
                                message={errors.place}
                            />

                            <Button
                                type="submit"
                                disabled={processing}
                                aria-describedby="setup-place-error"
                                data-test="setup-submit"
                            >
                                {processing
                                    ? kind === 'sftp'
                                        ? 'Reading the server’s fingerprint…'
                                        : 'Setting up…'
                                    : kind === 'sftp'
                                      ? 'Continue'
                                      : 'Set up backups'}
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

SetUp.layout = {
    breadcrumbs: [
        { title: 'Tools', href: index() },
        { title: 'Backups', href: backups() },
        { title: 'Set up backups', href: setup() },
    ],
};
