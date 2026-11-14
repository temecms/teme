import { Form, Link } from '@inertiajs/react';
import { CircleCheck, CircleX, Pause } from 'lucide-react';
import { useState } from 'react';
import BackupPlacesController from '@/actions/App/Http/Admin/Tools/Backups/BackupPlacesController';
import { ConfirmAction } from '@/components/confirm-action';
import { post } from '@/components/editor/editor-http';
import { Button } from '@/components/ui/button';
import { sizeWords } from '@/lib/backup-words';
import { create, edit, storage } from '@/routes/tools/backups/places';
import type { BackupPlace } from '@/types/backups';

/** The words for the keys a place doesn't open yet: "Sam’s key doesn’t open…". */
function missing(place: BackupPlace): string {
    const labels = place.missingKeys;
    const list =
        labels.length > 1
            ? `${labels.slice(0, -1).join(', ')} and ${labels[labels.length - 1]}`
            : labels[0];

    return `${list} ${labels.length === 1 ? 'doesn’t' : 'don’t'} open ${place.name} yet.`;
}

/** What a place holds, measured when asked (I-364): its button and its answer. */
function Holds({ place }: { place: BackupPlace }) {
    const [busy, setBusy] = useState(false);
    const [answer, setAnswer] = useState('');

    const measure = async () => {
        setBusy(true);
        setAnswer('');

        try {
            const reply = await post(storage.url({ place: place.id }));
            const body = reply.body as {
                bytes?: number | null;
                sentence?: string | null;
                message?: string;
            };

            setAnswer(
                typeof body.bytes === 'number'
                    ? `${place.name} holds ${sizeWords(body.bytes)} of backups.`
                    : (body.sentence ??
                          body.message ??
                          'The place could not be measured. Try again.'),
            );
        } catch {
            setAnswer('The place could not be measured. Try again.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <>
            <Button
                type="button"
                variant="outline"
                disabled={busy}
                onClick={() => void measure()}
                data-test="backup-place-storage"
            >
                {busy ? 'Measuring…' : 'Measure what it holds'}
                <span className="sr-only"> {place.name}</span>
            </Button>
            <p
                role="status"
                className="text-ink-2 w-full text-sm break-words empty:hidden"
            >
                {answer}
            </p>
        </>
    );
}

/**
 * "Where backups are kept" (I-360): one row a place, its state in words, its
 * newest backup, and what can be done to it.
 */
export function PlacesList({
    places,
    offServerAdvice,
    paused = false,
}: {
    places: BackupPlace[];
    offServerAdvice: boolean;
    /** A restore's places wait for an answer: nothing here can change them. */
    paused?: boolean;
}) {
    return (
        <section aria-labelledby="backup-places" className="space-y-3">
            <h2 id="backup-places" className="text-base font-medium">
                Where backups are kept
            </h2>

            {offServerAdvice && (
                <p className="text-ink-2 text-[13px]">
                    Add a place off this server, such as S3-compatible storage
                    or an SFTP server, to keep a copy if this server is lost.
                </p>
            )}

            <ul className="border-line divide-line divide-y rounded-md border">
                {places.map((place) => (
                    <li
                        key={place.id}
                        data-test="backup-place"
                        data-place={place.id}
                        className="space-y-2 p-4"
                    >
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div className="min-w-0 space-y-1">
                                <p className="font-medium">{place.name}</p>
                                <p className="text-ink-2 text-[13px]">
                                    {place.kindLabel}{' '}
                                    <span className="font-mono break-all">
                                        {place.where}
                                    </span>
                                </p>
                            </div>
                            <p
                                className={
                                    place.state === 'failing'
                                        ? 'text-danger-ink flex items-center gap-1.5 text-sm font-medium'
                                        : 'flex items-center gap-1.5 text-sm font-medium'
                                }
                            >
                                {place.state === 'paused' ? (
                                    <Pause
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                ) : place.state === 'failing' ? (
                                    <CircleX
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                ) : (
                                    <CircleCheck
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                )}
                                {place.stateLabel}
                            </p>
                        </div>

                        <p className="text-ink-2 text-[13px]">
                            {place.newest === null
                                ? 'No backup here yet'
                                : `Newest backup ${place.newest}`}
                            {' · '}
                            {place.lastAnswered === null
                                ? 'Hasn’t answered yet'
                                : `Last answered ${place.lastAnswered}`}
                        </p>

                        {place.error !== null && (
                            <p className="text-danger-ink text-sm break-words">
                                {place.error}
                            </p>
                        )}

                        {place.missingKeys.length > 0 && (
                            <p className="text-sm">{missing(place)}</p>
                        )}

                        <div className="flex flex-wrap gap-2">
                            <Form
                                {...BackupPlacesController.test.form({
                                    place: place.id,
                                })}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                        data-test="backup-place-test"
                                    >
                                        {processing ? 'Testing…' : 'Test'}
                                        <span className="sr-only">
                                            {' '}
                                            {place.name}
                                        </span>
                                    </Button>
                                )}
                            </Form>

                            {!paused && (
                                <Button
                                    asChild
                                    variant="outline"
                                    data-test="backup-place-change"
                                >
                                    <Link href={edit({ place: place.id })}>
                                        Change
                                        <span className="sr-only">
                                            {' '}
                                            {place.name}
                                        </span>
                                    </Link>
                                </Button>
                            )}

                            <Holds place={place} />

                            <Form
                                {...BackupPlacesController.destroy.form({
                                    place: place.id,
                                })}
                                options={{ preserveScroll: true }}
                            >
                                <ConfirmAction
                                    title={`Remove ${place.name}?`}
                                    description={`Teme will stop keeping backups at ${place.name}. The backups already there stay there, and only a recovery key opens them.`}
                                    confirmLabel={`Remove ${place.name}`}
                                >
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        data-test="backup-place-remove"
                                    >
                                        Remove
                                        <span className="sr-only">
                                            {' '}
                                            {place.name}
                                        </span>
                                    </Button>
                                </ConfirmAction>
                            </Form>
                        </div>
                    </li>
                ))}
            </ul>

            {!paused && (
                <Button asChild variant="outline" data-test="backup-place-add">
                    <Link href={create()}>Add a place</Link>
                </Button>
            )}
        </section>
    );
}
