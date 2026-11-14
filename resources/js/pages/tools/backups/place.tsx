import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import BackupPlacesController from '@/actions/App/Http/Admin/Tools/Backups/BackupPlacesController';
import { PlaceFields } from '@/components/backups/place-fields';
import InputError from '@/components/input-error';
import { FormPage, FormPanel } from '@/components/patterns/form-page';
import { SaveBar } from '@/components/patterns/save-bar';
import PasswordInput from '@/components/password-input';
import { Label } from '@/components/ui/label';
import { TextButton } from '@/components/ui/text-button';
import { __ } from '@/lib/i18n';
import { index } from '@/routes/tools';
import { index as backups } from '@/routes/tools/backups';
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

    // How many times Discard has drawn the fields afresh.
    const [drawn, setDrawn] = useState(0);
    const title = adding ? 'Add a place' : `Change ${place?.name ?? 'place'}`;
    const continues = kind === 'sftp' && (adding || moved);

    return (
        <>
            <Head title={title} />

            <FormPage
                title={title}
                description={
                    adding
                        ? 'Another place to keep backups, kept in step with the others.'
                        : 'Its name, or where it is. Teme checks that the backups at the new address are the same ones.'
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
                    {({ processing, errors, isDirty, reset }) => (
                        <>
                            <FormPanel>
                                <PlaceFields
                                    // Discard draws the fields afresh, since they keep what is typed in their own state.
                                    key={drawn}
                                    kind={kind}
                                    onKindChange={setKind}
                                    suggestedFolder={suggestedFolder}
                                    errors={errors}
                                    values={place}
                                />

                                {adding && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="place-recovery-key">
                                            Your recovery key
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
                                            Teme locks the new place with it
                                            too, then forgets it. It’s a key you
                                            typed back when it was made.
                                        </p>
                                        <InputError
                                            id="place-recovery-key-error"
                                            message={errors.recovery_key}
                                        />
                                    </div>
                                )}

                                {adding && kind !== 'folder' && (
                                    <p className="text-body">
                                        Making the place can take a few minutes
                                        on a distant server. Keep this page
                                        open.
                                    </p>
                                )}

                                <InputError
                                    id="place-error"
                                    message={errors.place}
                                />
                            </FormPanel>

                            <p className="mt-4">
                                <TextButton asChild>
                                    <Link
                                        href={backups()}
                                        data-test="place-cancel"
                                    >
                                        Cancel
                                    </Link>
                                </TextButton>
                            </p>

                            <SaveBar
                                dirty={isDirty}
                                processing={processing}
                                onDiscard={() => {
                                    reset();
                                    setKind(place?.kind ?? 'folder');
                                    setMoved(false);
                                    setDrawn((count) => count + 1);
                                }}
                                what={place?.name ?? __('this place')}
                                testId="place-submit"
                                aria-describedby="place-error"
                                saveLabel={
                                    adding
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
                                            : __('Save changes')
                                }
                            />
                        </>
                    )}
                </Form>
            </FormPage>
        </>
    );
}

Place.layout = {
    breadcrumbs: [
        { title: 'Tools', href: index() },
        { title: 'Backups', href: backups() },
    ],
};
