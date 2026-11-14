import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import BackupPlacesController from '@/actions/App/Http/Admin/Tools/Backups/BackupPlacesController';
import { PlaceFields } from '@/components/backups/place-fields';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/list/page-header';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
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

    const title = adding ? 'Add a place' : `Change ${place?.name ?? 'place'}`;
    const continues = kind === 'sftp' && (adding || moved);

    return (
        <>
            <Head title={title} />

            <div className="max-w-3xl space-y-6 p-6">
                <PageHeader
                    title={title}
                    description={
                        adding
                            ? 'Another place to keep backups, kept in step with the others.'
                            : 'Its name, or where it is. Teme checks that the backups at the new address are the same ones.'
                    }
                />

                <Form
                    {...(adding
                        ? BackupPlacesController.store.form()
                        : BackupPlacesController.update.form({
                              place: place?.id ?? '',
                          }))}
                    onInput={watchServer}
                    className="max-w-[640px] space-y-5"
                >
                    {({ processing, errors }) => (
                        <>
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
                                        className="text-muted-foreground text-xs"
                                    >
                                        Teme locks the new place with it too,
                                        then forgets it. It’s a key you typed
                                        back when it was made.
                                    </p>
                                    <InputError
                                        id="place-recovery-key-error"
                                        message={errors.recovery_key}
                                    />
                                </div>
                            )}

                            {adding && kind !== 'folder' && (
                                <p className="text-sm">
                                    Making the place can take a few minutes on a
                                    distant server. Keep this page open.
                                </p>
                            )}

                            <InputError
                                id="place-error"
                                message={errors.place}
                            />

                            <div className="flex flex-wrap items-center gap-3">
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    aria-describedby="place-error"
                                    data-test="place-submit"
                                >
                                    {adding
                                        ? processing
                                            ? kind === 'sftp'
                                                ? 'Reading the server’s fingerprint…'
                                                : 'Adding…'
                                            : continues
                                              ? 'Continue'
                                              : 'Add the place'
                                        : processing
                                          ? 'Saving…'
                                          : continues
                                            ? 'Continue'
                                            : 'Save changes'}
                                </Button>
                                {!adding && (
                                    <Link
                                        href={backups()}
                                        className="text-sm underline"
                                    >
                                        Cancel
                                    </Link>
                                )}
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

Place.layout = {
    breadcrumbs: [
        { title: 'Tools', href: index() },
        { title: 'Backups', href: backups() },
    ],
};
