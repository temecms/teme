import { router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Notice } from '@/components/ui/notice';
import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';
import { Progress } from '@/components/ui/progress';
import { __ } from '@/lib/i18n';
import { uploadInPieces } from '@/lib/upload-in-pieces';
import type { ImportUploadCopy } from '@/types/import';

type Phase = 'choose' | 'uploading' | 'refused';

/** The size of a copy, in megabytes, to one place. */
function megabytes(bytes: number): string {
    return (bytes / (1024 * 1024)).toLocaleString(undefined, {
        maximumFractionDigits: 1,
    });
}

/**
 * The uploads copy of a site on Tools › Import: what the import has brought from
 * it so far, and, on the plan, the upload of a zip of the old site's
 * wp-content/uploads folder, in pieces.
 */
export function UploadsCopyPanel({
    siteId,
    copy,
    maxUploadsMb,
    planning,
}: {
    siteId: string;
    copy: ImportUploadCopy | null;
    maxUploadsMb: number;
    /** Whether the plan is shown, so the upload is offered. */
    planning: boolean;
}) {
    const inputRef = useRef<HTMLInputElement>(null);
    const abortRef = useRef<AbortController | null>(null);
    const uploadIdRef = useRef<string | null>(null);
    const [phase, setPhase] = useState<Phase>('choose');
    const [fileName, setFileName] = useState('');
    const [doneBytes, setDoneBytes] = useState(0);
    const [totalBytes, setTotalBytes] = useState(0);
    const [refused, setRefused] = useState('');

    const maxGb = Math.max(1, Math.floor(maxUploadsMb / 1024));

    async function begin(file: File): Promise<void> {
        abortRef.current?.abort();
        const controller = new AbortController();
        abortRef.current = controller;

        setFileName(file.name);
        setTotalBytes(file.size);
        setDoneBytes(0);
        setRefused('');
        uploadIdRef.current = null;
        setPhase('uploading');

        const result = await uploadInPieces({
            file,
            signal: controller.signal,
            urls: {
                start: `/admin/tools/import/${siteId}/uploads/start`,
                piece: (id) => `/admin/tools/import/uploads/${id}/pieces`,
                finish: (id) => `/admin/tools/import/uploads/${id}/finish`,
                cancel: (id) => `/admin/tools/import/uploads/${id}/cancel`,
            },
            onStarted: (id) => {
                uploadIdRef.current = id;
            },
            onProgress: (done, total) => {
                setDoneBytes(done);
                setTotalBytes(total);
            },
        });

        if (controller.signal.aborted) {
            return;
        }

        if (!result.ok) {
            setPhase('refused');
            setRefused(result.message);

            return;
        }

        setPhase('choose');
        toast.success(
            __('The uploads copy is attached. Later imports use it.'),
        );
        router.reload({ only: ['uploadsCopy'] });
    }

    async function cancel(): Promise<void> {
        const controller = abortRef.current;
        abortRef.current = null;
        controller?.abort();
        const id = uploadIdRef.current;
        uploadIdRef.current = null;

        if (id) {
            try {
                const match = document.cookie.match(
                    /(?:^|; )XSRF-TOKEN=([^;]*)/,
                );
                const token = match ? decodeURIComponent(match[1]) : '';
                await fetch(`/admin/tools/import/uploads/${id}/cancel`, {
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
                // The upload may already be gone; the toast still tells the person.
            }
        }

        setPhase('choose');
        setFileName('');
        toast.message(__('Upload cancelled. Nothing was kept.'));
    }

    const summary =
        copy === null ? null : (
            <p
                className="text-body text-ink-2"
                data-test="uploads-copy-summary"
            >
                {__(
                    'Pictures from the copy “:name” (:mb MB): :copied from the copy, :site from the old site.',
                    {
                        name: copy.name,
                        mb: megabytes(copy.sizeBytes),
                        copied: String(copy.copiedPictures),
                        site: String(copy.sitePictures),
                    },
                )}
            </p>
        );

    if (!planning) {
        return summary === null ? null : (
            <Panel aria-labelledby="uploads-copy-heading">
                <PanelHeader>
                    <h2
                        id="uploads-copy-heading"
                        className="text-lead font-semibold"
                    >
                        {__('Copy of the uploads folder')}
                    </h2>
                </PanelHeader>
                <PanelBody>{summary}</PanelBody>
            </Panel>
        );
    }

    return (
        <Panel aria-labelledby="uploads-copy-heading" data-test="uploads-copy">
            <PanelHeader>
                <h2
                    id="uploads-copy-heading"
                    className="text-lead font-semibold"
                >
                    {__('Copy of the uploads folder')}
                </h2>
            </PanelHeader>
            <PanelBody>
                <div className="grid max-w-(--form-max) gap-5">
                    <p className="text-body text-ink-2">
                        {__(
                            'Zip the old site’s wp-content/uploads folder and upload the zip here. Teme takes each picture from the copy when it has it, and asks the old site only for the rest. Make the copy once, off-peak, with tar or rsync.',
                        )}
                    </p>

                    {summary}

                    {phase === 'refused' && (
                        <Notice tone="danger" data-test="uploads-copy-refused">
                            {refused}
                        </Notice>
                    )}

                    {phase === 'choose' && (
                        <div className="border-edge grid gap-2 rounded-md border border-dashed p-6 text-center">
                            <p className="text-small text-ink-3">
                                {__(
                                    'A .zip of the uploads folder, up to :n GB.',
                                    { n: String(maxGb) },
                                )}
                            </p>
                            <div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => inputRef.current?.click()}
                                    data-test="uploads-copy-choose"
                                >
                                    {__('Choose the zip')}
                                </Button>
                                <input
                                    ref={inputRef}
                                    type="file"
                                    accept=".zip,application/zip"
                                    className="sr-only"
                                    data-test="uploads-copy-input"
                                    onChange={(event) => {
                                        const file = event.target.files?.[0];

                                        if (file) {
                                            void begin(file);
                                        }
                                    }}
                                />
                            </div>
                        </div>
                    )}

                    {phase === 'uploading' && (
                        <div
                            className="grid gap-4"
                            data-test="uploads-copy-progress"
                        >
                            <div className="grid gap-1">
                                <p className="text-body font-medium">
                                    {fileName}
                                </p>
                                <p className="text-small text-ink-2">
                                    {__(':done of :total MB', {
                                        done: megabytes(doneBytes),
                                        total: megabytes(totalBytes),
                                    })}
                                </p>
                            </div>
                            <Progress
                                value={doneBytes}
                                max={Math.max(totalBytes, 1)}
                                label={__('Upload progress')}
                            />
                            <div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => void cancel()}
                                >
                                    {__('Cancel the upload')}
                                </Button>
                            </div>
                        </div>
                    )}
                </div>
            </PanelBody>
        </Panel>
    );
}
