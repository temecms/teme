import { Form, Link } from '@inertiajs/react';
import { useState } from 'react';
import BackupPlacesController from '@/actions/App/Http/Admin/Tools/Backups/BackupPlacesController';
import { ConfirmAction } from '@/components/confirm-action';
import { post } from '@/components/editor/editor-http';
import { Button } from '@/components/ui/button';
import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';
import { StatusPill } from '@/components/ui/status-pill';
import { __, __n } from '@/lib/i18n';
import { names, sizeWords } from '@/lib/backup-words';
import { create, edit, storage } from '@/routes/tools/backups/places';
import type { BackupPlace } from '@/types/backups';

/** The words for the keys a place doesn't open yet: "Sam’s key doesn’t open…". */
function missing(place: BackupPlace): string {
    return __n(
        ':keys doesn’t open :place yet.|:keys don’t open :place yet.',
        place.missingKeys.length,
        { keys: names(place.missingKeys), place: place.name },
    );
}

/** A place's state as a pill: failing is a problem, paused needs a look. */
function stateTone(state: BackupPlace['state']) {
    return state === 'failing' ? 'danger' : state === 'paused' ? 'warn' : 'ok';
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
                    ? __(':place holds :size of backups.', {
                          place: place.name,
                          size: sizeWords(body.bytes),
                      })
                    : (body.sentence ??
                          body.message ??
                          __('The place could not be measured. Try again.')),
            );
        } catch {
            setAnswer(__('The place could not be measured. Try again.'));
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
                {busy ? __('Measuring…') : __('Measure what it holds')}
                <span className="sr-only"> {place.name}</span>
            </Button>
            <p
                role="status"
                className="text-ink-2 w-full break-words empty:hidden"
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
        <Panel aria-labelledby="backup-places">
            <PanelHeader>
                <h2 id="backup-places" className="text-lead font-semibold">
                    {__('Where backups are kept')}
                </h2>
            </PanelHeader>
            <PanelBody className="text-body justify-items-start">
                {offServerAdvice && (
                    <p className="text-small text-ink-2">
                        {__(
                            'Add a place off this server, such as S3-compatible storage or an SFTP server, to keep a copy if this server is lost.',
                        )}
                    </p>
                )}

                <ul className="divide-line grid w-full divide-y">
                    {places.map((place) => (
                        <li
                            key={place.id}
                            data-test="backup-place"
                            data-place={place.id}
                            className="grid gap-2 py-3"
                        >
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div className="grid min-w-0 gap-1">
                                    <p className="font-medium">{place.name}</p>
                                    <p className="text-small text-ink-2">
                                        {place.kindLabel}{' '}
                                        <span className="font-mono break-all">
                                            {place.where}
                                        </span>
                                    </p>
                                </div>
                                <StatusPill tone={stateTone(place.state)}>
                                    {place.stateLabel}
                                </StatusPill>
                            </div>

                            <p className="text-small text-ink-2">
                                {place.newest === null
                                    ? __('No backup here yet')
                                    : __('Newest backup :when', {
                                          when: place.newest,
                                      })}
                                {' · '}
                                {place.lastAnswered === null
                                    ? __('Hasn’t answered yet')
                                    : __('Last answered :when', {
                                          when: place.lastAnswered,
                                      })}
                            </p>

                            {place.error !== null && (
                                <p className="text-danger-ink break-words">
                                    {place.error}
                                </p>
                            )}

                            {place.missingKeys.length > 0 && (
                                <p>{missing(place)}</p>
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
                                            {processing
                                                ? __('Testing…')
                                                : __('Test')}
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
                                            {__('Change')}
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
                                        title={__('Remove :name?', {
                                            name: place.name,
                                        })}
                                        description={__(
                                            'Teme will stop keeping backups at :name. The backups already there stay there, and only a recovery key opens them.',
                                            { name: place.name },
                                        )}
                                        confirmLabel={__('Remove :name', {
                                            name: place.name,
                                        })}
                                    >
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            data-test="backup-place-remove"
                                        >
                                            {__('Remove')}
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
                    <Button
                        asChild
                        variant="outline"
                        data-test="backup-place-add"
                    >
                        <Link href={create()}>{__('Add a place')}</Link>
                    </Button>
                )}
            </PanelBody>
        </Panel>
    );
}
