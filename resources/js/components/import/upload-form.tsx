import { useRef, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Notice } from '@/components/ui/notice';
import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';
import { Progress } from '@/components/ui/progress';
import { Spinner } from '@/components/ui/spinner';
import { __ } from '@/lib/i18n';
import { uploadInPieces } from '@/lib/upload-in-pieces';
import { router } from '@inertiajs/react';

type Phase = 'choose' | 'uploading' | 'retrying' | 'reading' | 'refused';

/**
 * Uploading a WordPress export file in pieces on Tools › Import.
 */
export function UploadForm({ maxFileMb }: { maxFileMb: number }) {
    const inputRef = useRef<HTMLInputElement>(null);
    const abortRef = useRef<AbortController | null>(null);
    const fileIdRef = useRef<string | null>(null);
    const [phase, setPhase] = useState<Phase>('choose');
    const [fileName, setFileName] = useState('');
    const [doneBytes, setDoneBytes] = useState(0);
    const [totalBytes, setTotalBytes] = useState(0);
    const [pieceLabel, setPieceLabel] = useState('');
    const [refused, setRefused] = useState('');
    const [dragging, setDragging] = useState(false);

    const maxGb = Math.max(1, Math.floor(maxFileMb / 1024));

    async function begin(file: File): Promise<void> {
        abortRef.current?.abort();
        const controller = new AbortController();
        abortRef.current = controller;

        setFileName(file.name);
        setTotalBytes(file.size);
        setDoneBytes(0);
        setRefused('');
        fileIdRef.current = null;
        setPhase('uploading');

        const result = await uploadInPieces({
            file,
            signal: controller.signal,
            urls: {
                start: '/admin/tools/import/files/start',
                piece: (id) => `/admin/tools/import/files/${id}/pieces`,
                finish: (id) => `/admin/tools/import/files/${id}/finish`,
                cancel: (id) => `/admin/tools/import/files/${id}/cancel`,
            },
            onStarted: (id) => {
                fileIdRef.current = id;
            },
            onProgress: (done, total) => {
                setDoneBytes(done);
                setTotalBytes(total);
                if (done > 0) {
                    setPhase((current) =>
                        current === 'retrying' ? 'uploading' : current,
                    );
                }
            },
            onRetrying: (pieceBytes) => {
                setPhase('retrying');
                setPieceLabel(formatPiece(pieceBytes));
            },
        });

        if (controller.signal.aborted) {
            return;
        }

        if (!result.ok && result.refused) {
            setPhase('refused');
            setRefused(result.message);
            return;
        }

        if (!result.ok) {
            if (result.message.includes('cancelled')) {
                setPhase('choose');
                setFileName('');
                return;
            }
            setPhase('refused');
            setRefused(result.message);
            return;
        }

        setPhase('reading');
        setDoneBytes(file.size);
        void watchReading(result.fileId);
    }

    async function watchReading(fileId: string): Promise<void> {
        for (let n = 0; n < 60; n += 1) {
            await new Promise((resolve) => setTimeout(resolve, 2000));
            const response = await fetch(
                `/admin/tools/import/files/${fileId}`,
                {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                },
            );
            if (!response.ok) {
                continue;
            }
            const status = (await response.json()) as {
                state: string;
                refused: string | null;
                sourceId: string | null;
            };
            if (status.state === 'refused') {
                setPhase('refused');
                setRefused(
                    status.refused ??
                        __(
                            'That isn’t a WordPress export file. Teme reads the .xml file from WordPress’s Tools › Export. Choose that file and try again.',
                        ),
                );
                return;
            }
            if (status.state === 'ready' && status.sourceId) {
                router.visit(`/admin/tools/import/${status.sourceId}`);
                return;
            }
        }
    }

    async function cancel(): Promise<void> {
        const controller = abortRef.current;
        abortRef.current = null;
        controller?.abort();
        const id = fileIdRef.current;
        fileIdRef.current = null;
        if (id) {
            try {
                const match = document.cookie.match(
                    /(?:^|; )XSRF-TOKEN=([^;]*)/,
                );
                const token = match ? decodeURIComponent(match[1]) : '';
                await fetch(`/admin/tools/import/files/${id}/cancel`, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'Content-Type': 'application/json',
                        'X-XSRF-TOKEN': token,
                    },
                    body: '{}',
                });
            } catch {
                // The row may already be gone; the toast still tells the person.
            }
        }
        setPhase('choose');
        setFileName('');
        toast.message(__('Upload cancelled. Nothing was kept.'));
    }

    function onChosen(list: FileList | null): void {
        const file = list?.[0];
        if (file) {
            void begin(file);
        }
    }

    const doneMb = (doneBytes / (1024 * 1024)).toFixed(1);
    const totalMb = (totalBytes / (1024 * 1024)).toFixed(1);

    return (
        <Panel
            aria-labelledby="import-upload-heading"
            data-test="import-upload"
        >
            <PanelHeader>
                <h2
                    id="import-upload-heading"
                    className="text-lead font-semibold"
                >
                    {__('Upload an export file')}
                </h2>
            </PanelHeader>
            <PanelBody>
                <div className="grid max-w-(--form-max) gap-5">
                    <p className="text-body text-ink-2">
                        {__(
                            'In WordPress, open Tools › Export, choose All content and download the file. Nothing needs installing on the old site.',
                        )}
                    </p>

                    {phase === 'refused' && (
                        <Notice tone="danger" data-test="import-upload-refused">
                            {refused ||
                                __(
                                    'That isn’t a WordPress export file. Teme reads the .xml file from WordPress’s Tools › Export. Choose that file and try again.',
                                )}
                        </Notice>
                    )}

                    {(phase === 'choose' || phase === 'refused') && (
                        <div
                            data-test="import-upload-drop"
                            onDragEnter={(event) => {
                                event.preventDefault();
                                setDragging(true);
                            }}
                            onDragOver={(event) => event.preventDefault()}
                            onDragLeave={() => setDragging(false)}
                            onDrop={(event) => {
                                event.preventDefault();
                                setDragging(false);
                                onChosen(event.dataTransfer.files);
                            }}
                            className={
                                dragging
                                    ? 'border-accent bg-accent-soft grid gap-2 rounded-md border border-dashed p-8 text-center'
                                    : 'border-edge grid gap-2 rounded-md border border-dashed p-8 text-center'
                            }
                        >
                            <p className="text-body font-medium">
                                {__('Drop the export file here, or choose it')}
                            </p>
                            <p className="text-small text-ink-3">
                                {__(
                                    'The .xml file from Tools › Export in WordPress, up to :n GB.',
                                    { n: String(maxGb) },
                                )}
                            </p>
                            <div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => inputRef.current?.click()}
                                    data-test="import-upload-choose"
                                >
                                    {__('Choose a file')}
                                </Button>
                                <input
                                    ref={inputRef}
                                    type="file"
                                    accept=".xml,text/xml,application/xml"
                                    className="sr-only"
                                    data-test="import-upload-input"
                                    onChange={(event) =>
                                        onChosen(event.target.files)
                                    }
                                />
                            </div>
                        </div>
                    )}

                    {(phase === 'uploading' || phase === 'retrying') && (
                        <div
                            className="grid gap-4"
                            data-test="import-upload-progress"
                        >
                            <div className="grid gap-1">
                                <p className="text-body font-medium">
                                    {fileName}
                                </p>
                                <p className="text-small text-ink-2">
                                    {__(':done of :total MB', {
                                        done: doneMb,
                                        total: totalMb,
                                    })}
                                </p>
                            </div>
                            <Progress
                                value={doneBytes}
                                max={Math.max(totalBytes, 1)}
                                label={__('Upload progress')}
                            />
                            {phase === 'retrying' ? (
                                <p
                                    className="text-body text-ink-2"
                                    data-test="import-upload-retrying"
                                >
                                    {__(
                                        'The connection dropped. Trying again in smaller pieces (:size each). Nothing sent so far is lost.',
                                        { size: pieceLabel },
                                    )}
                                </p>
                            ) : (
                                <p className="text-body text-ink-2">
                                    {__(
                                        'The file goes up in pieces, so a dropped connection carries on where it was. If you close this page, open Import again and choose the same file: the upload picks up where it stopped.',
                                    )}
                                </p>
                            )}
                            <div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => void cancel()}
                                    data-test="import-upload-cancel"
                                >
                                    {__('Cancel the upload')}
                                </Button>
                            </div>
                        </div>
                    )}

                    {phase === 'reading' && (
                        <div
                            className="grid gap-3"
                            data-test="import-upload-reading"
                        >
                            <div className="flex items-center gap-3">
                                <Spinner className="text-accent size-5" />
                                <div className="grid gap-1">
                                    <p className="text-body font-medium">
                                        {fileName}
                                    </p>
                                    <p className="text-small text-ink-2">
                                        {__(':size MB · uploaded', {
                                            size: totalMb,
                                        })}
                                    </p>
                                </div>
                            </div>
                            <p className="text-body text-ink-2">
                                {__(
                                    'Reading the file: counting its posts, pages, media and authors…',
                                )}
                            </p>
                        </div>
                    )}
                </div>
            </PanelBody>
        </Panel>
    );
}

function formatPiece(bytes: number): string {
    if (bytes >= 1024 * 1024) {
        return `${(bytes / (1024 * 1024)).toFixed(bytes % (1024 * 1024) === 0 ? 0 : 1)} MB`;
    }

    return `${Math.round(bytes / 1024)} KB`;
}
