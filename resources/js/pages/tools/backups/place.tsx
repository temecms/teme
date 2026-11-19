import { Form, Head, Link, setLayoutProps } from '@inertiajs/react';
import { useState } from 'react';
import BackupPlacesController from '@/actions/App/Http/Admin/Tools/Backups/BackupPlacesController';
import { PlaceFields } from '@/components/backups/place-fields';
import InputError from '@/components/input-error';
import { FormPage, FormPanel } from '@/components/patterns/form-page';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { __ } from '@/lib/i18n';
import { index } from '@/routes/tools';
import { index as backups } from '@/routes/tools/backups';
import { create, edit } from '@/routes/tools/backups/places';
import type { BackupPlaceKind, PlacePage } from '@/types/backups';

/** Adding a place, or changing where one is (I-2402): the same fields as setup, with the recovery key typed for a new place. */
export default function Place({ mode, place, suggestedFolder }: PlacePage) {
    const adding = mode === 'add';
    const [kind, setKind] = useState<BackupPlaceKind>(place?.kind ?? 'folder');
    const [moved, setMoved] = useState(false);

    // A changed SFTP server or port goes through the fingerprint step first.
    const watchServer = (event: React.FormEvent<HTMLFormElement>) => {
        const target = event.target as HTMLInputElement;

        if (place?.kind !== 'sftp') {
            return;
        }

        if (target.name === 'host') {
            setMoved(target.value.trim() !== place.host);
        } else if (target.name === 'port') {
            setMoved(Number(target.value) !== place.port);
        }
    };

    const title = adding
        ? __('Add a place')
        : __('Change :name', { name: place?.name ?? '' });
    const continues = kind === 'sftp' && (adding || moved);

    setLayoutProps({
        breadcrumbs: [
            { title: __('Tools'), href: index() },
            { title: __('Backups'), href: backups() },
            {
                title: __('Add or change a place'),
                href: adding ? create() : edit({ place: place?.id ?? '' }),
            },
        ],
    });

    return (
        <>
            <Head title={title} />

            <FormPage
                title={title}
                description={
                    adding
                        ? __(
                              'Another place to keep backups, kept in step with the others.',
                          )
                        : __(
                              'Its name, or where it is. Teme checks that the backups at the new address are the same ones.',
                          )
                }
            >
                <Form
                    {...(adding
                        ? BackupPlacesController.store.form()
                        : BackupPlacesController.update.form({
                              place: place?.id ?? '',
                          }))}
                    onInput={watchServer}
                >
                    {({ processing, errors }) => (
                        <>
                            <FormPanel
                                title={adding ? __('The place') : place?.name}
                            >
                                <PlaceFields
                                    kind={kind}
                                    onKindChange={setKind}
                                    suggestedFolder={suggestedFolder}
                                    errors={errors}
                                    values={place}
                                />

                                {adding && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="place-recovery-key">
                                            {__('Your recovery key')}
                                        </Label>
                                        <PasswordInput
                                            id="place-recovery-key"
                                            name="recovery_key"
                                            autoComplete="off"
                                            aria-describedby="place-recovery-key-hint place-recovery-key-error"
                                        />
                                        <p
                                            id="place-recovery-key-hint"
                                            className="text-caption text-ink-3"
                                        >
                                            {__(
                                                'Teme locks the new place with it too, then forgets it. It’s a key you typed back when it was made.',
                                            )}
                                        </p>
                                        <InputError
                                            id="place-recovery-key-error"
                                            message={errors.recovery_key}
                                        />
                                    </div>
                                )}

                                {adding && kind !== 'folder' && (
                                    <p className="text-body">
                                        {__(
                                            'Making the place can take a few minutes on a distant server. Keep this page open.',
                                        )}
                                    </p>
                                )}

                                <div className="grid justify-items-start gap-2">
                                    <InputError
                                        id="place-error"
                                        message={errors.place}
                                    />
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                            aria-describedby="place-error"
                                            data-test="place-submit"
                                        >
                                            {adding
                                                ? processing
                                                    ? kind === 'sftp'
                                                        ? __(
                                                              'Reading the server’s fingerprint…',
                                                          )
                                                        : __('Adding…')
                                                    : continues
                                                      ? __('Continue')
                                                      : __('Add the place')
                                                : processing
                                                  ? __('Saving…')
                                                  : continues
                                                    ? __('Continue')
                                                    : __('Save changes')}
                                        </Button>
                                        <Button variant="ghost" asChild>
                                            <Link
                                                href={backups()}
                                                data-test="place-cancel"
                                            >
                                                {__('Cancel')}
                                            </Link>
                                        </Button>
                                    </div>
                                </div>
                            </FormPanel>
                        </>
                    )}
                </Form>
            </FormPage>
        </>
    );
}
