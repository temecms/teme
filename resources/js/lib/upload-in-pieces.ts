/**
 * Uploads a WordPress export file in pieces: starts at 2 MB, halves after a
 * gateway or timeout failure down to 256 KB, retries each piece three times
 * with a growing wait, and reports progress in bytes. Every answer is read
 * as text first so gateway HTML is a retry, not a JSON parse.
 */

import { __ } from '@/lib/i18n';

export const INITIAL_PIECE_BYTES = 2 * 1024 * 1024;
export const MIN_PIECE_BYTES = 256 * 1024;
export const MAX_PIECE_BYTES = 8 * 1024 * 1024;

export const GATEWAY_STATUSES = new Set([
    429, 502, 503, 504, 520, 521, 522, 523, 524, 525, 526, 527, 528, 529,
]);

export type PieceUploadUrls = {
    start: string;
    piece: (fileId: string) => string;
    finish: (fileId: string) => string;
    cancel?: (fileId: string) => string;
};

export type PieceUploadResult =
    | { ok: true; fileId: string; refused: false }
    | { ok: false; fileId?: string; refused: boolean; message: string };

export type PieceUploadOptions = {
    file: File;
    /** The key of the import reader that reads the file; absent for WordPress's export. */
    reader?: string;
    urls: PieceUploadUrls;
    onProgress?: (done: number, total: number) => void;
    onRetrying?: (pieceBytes: number) => void;
    /** Called once the server has accepted the start (or a carry-on). */
    onStarted?: (fileId: string) => void;
    signal?: AbortSignal;
    fetchImpl?: typeof fetch;
    sleep?: (ms: number) => Promise<void>;
    pieceTimeoutMs?: number;
    headers?: Record<string, string>;
};

type StartAnswer = {
    fileId: string;
    receivedBytes: number;
    sizeBytes: number;
    carriedOn?: boolean;
};

function xsrfToken(): string {
    if (typeof document === 'undefined') {
        return '';
    }

    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function defaultSleep(ms: number): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function isTimeout(error: unknown): boolean {
    if (!(error instanceof Error)) {
        return false;
    }

    return (
        error.name === 'TimeoutError' ||
        error.name === 'AbortError' ||
        /timeout|aborted/i.test(error.message)
    );
}

function isDropped(error: unknown): boolean {
    if (!(error instanceof Error)) {
        return false;
    }

    return (
        error.name === 'TypeError' ||
        /failed to fetch|network|connection/i.test(error.message) ||
        isTimeout(error)
    );
}

async function readAnswer(response: Response): Promise<{
    status: number;
    json: Record<string, unknown> | null;
    text: string;
}> {
    const text = await response.text();
    let json: Record<string, unknown> | null = null;

    try {
        json = JSON.parse(text) as Record<string, unknown>;
    } catch {
        json = null;
    }

    return { status: response.status, json, text };
}

function waitMs(attempt: number): number {
    return 500 * 2 ** (attempt - 1);
}

/**
 * Uploads `file` through start → pieces → finish. Returns when the server has
 * every byte and has finished the peek, or when the upload is refused / fails.
 */
export async function uploadInPieces(
    options: PieceUploadOptions,
): Promise<PieceUploadResult> {
    const fetchImpl = options.fetchImpl ?? fetch;
    const sleep = options.sleep ?? defaultSleep;
    const total = options.file.size;
    const baseHeaders: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': xsrfToken(),
        ...options.headers,
    };

    const startResponse = await fetchImpl(options.urls.start, {
        method: 'POST',
        headers: {
            ...baseHeaders,
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            name: options.file.name,
            size: total,
            reader: options.reader ?? null,
        }),
        signal: options.signal,
    });
    const startAnswer = await readAnswer(startResponse);

    if (
        startAnswer.json === null ||
        startAnswer.status >= 400 ||
        typeof startAnswer.json.fileId !== 'string'
    ) {
        const errors = startAnswer.json?.errors as
            | { size?: string[] }
            | undefined;
        const message =
            typeof startAnswer.json?.message === 'string'
                ? startAnswer.json.message
                : typeof errors?.size?.[0] === 'string'
                  ? errors.size[0]
                  : __('Teme couldn’t start the upload. Try again.');

        return { ok: false, refused: false, message };
    }

    const started = startAnswer.json as unknown as StartAnswer;
    options.onStarted?.(started.fileId);
    let offset = Math.max(0, Number(started.receivedBytes) || 0);
    let pieceBytes = Math.min(INITIAL_PIECE_BYTES, MAX_PIECE_BYTES, total);
    options.onProgress?.(offset, total);

    while (offset < total) {
        let attempt = 0;
        let landed = false;

        while (attempt < 3 && !landed) {
            attempt += 1;
            const size = Math.min(pieceBytes, total - offset);
            const blob = options.file.slice(offset, offset + size);

            try {
                const local =
                    options.pieceTimeoutMs !== undefined
                        ? new AbortController()
                        : null;
                const timer =
                    local && options.pieceTimeoutMs !== undefined
                        ? setTimeout(() => {
                              const err = new Error(
                                  'The operation was aborted',
                              );
                              err.name = 'TimeoutError';
                              local.abort(err);
                          }, options.pieceTimeoutMs)
                        : null;

                const signals = [options.signal, local?.signal].filter(
                    (s): s is AbortSignal => s !== undefined && s !== null,
                );
                const signal =
                    signals.length === 0
                        ? undefined
                        : signals.length === 1
                          ? signals[0]
                          : AbortSignal.any(signals);

                const response = await fetchImpl(
                    options.urls.piece(started.fileId),
                    {
                        method: 'POST',
                        headers: {
                            ...baseHeaders,
                            'Content-Type': 'application/octet-stream',
                            'X-Upload-Offset': String(offset),
                        },
                        body: blob,
                        signal,
                    },
                );

                if (timer) {
                    clearTimeout(timer);
                }

                const answer = await readAnswer(response);

                if (answer.json !== null && answer.status < 400) {
                    const next = Number(answer.json.receivedBytes);
                    offset = Number.isFinite(next) ? next : offset + size;
                    options.onProgress?.(offset, total);
                    landed = true;
                    break;
                }

                if (
                    answer.json !== null &&
                    answer.status === 422 &&
                    typeof (
                        answer.json.errors as { offset?: string[] } | undefined
                    )?.offset?.[0] === 'string'
                ) {
                    const wanted = Number(
                        String(
                            (answer.json.errors as { offset: string[] })
                                .offset[0],
                        ).match(/\d+/)?.[0] ?? NaN,
                    );

                    if (Number.isFinite(wanted) && wanted <= offset) {
                        offset = wanted;
                        landed = true;
                        break;
                    }
                }

                if (
                    !GATEWAY_STATUSES.has(answer.status) &&
                    answer.json !== null
                ) {
                    const message =
                        typeof answer.json.message === 'string'
                            ? answer.json.message
                            : __('Teme couldn’t take that piece. Try again.');

                    return {
                        ok: false,
                        fileId: started.fileId,
                        refused: false,
                        message,
                    };
                }

                // Gateway HTML or status: shrink and retry.
                pieceBytes = Math.max(
                    MIN_PIECE_BYTES,
                    Math.floor(pieceBytes / 2),
                );
                options.onRetrying?.(pieceBytes);

                if (attempt < 3) {
                    await sleep(waitMs(attempt));
                }
            } catch (error) {
                if (options.signal?.aborted) {
                    return {
                        ok: false,
                        fileId: started.fileId,
                        refused: false,
                        message: __('Upload cancelled. Nothing was kept.'),
                    };
                }

                if (!isDropped(error) || attempt >= 3) {
                    return {
                        ok: false,
                        fileId: started.fileId,
                        refused: false,
                        message: __('The connection dropped. Try again.'),
                    };
                }

                pieceBytes = Math.max(
                    MIN_PIECE_BYTES,
                    Math.floor(pieceBytes / 2),
                );
                options.onRetrying?.(pieceBytes);
                await sleep(waitMs(attempt));
            }
        }

        if (!landed) {
            return {
                ok: false,
                fileId: started.fileId,
                refused: false,
                message: __('The connection dropped. Try again.'),
            };
        }
    }

    const finishResponse = await fetchImpl(
        options.urls.finish(started.fileId),
        {
            method: 'POST',
            headers: {
                ...baseHeaders,
                'Content-Type': 'application/json',
            },
            body: '{}',
            signal: options.signal,
        },
    );
    const finishAnswer = await readAnswer(finishResponse);

    if (finishAnswer.json === null || finishAnswer.status >= 500) {
        return {
            ok: false,
            fileId: started.fileId,
            refused: false,
            message: __('Teme couldn’t finish the upload. Try again.'),
        };
    }

    if (finishAnswer.json.refused === true) {
        return {
            ok: false,
            fileId: started.fileId,
            refused: true,
            message:
                typeof finishAnswer.json.message === 'string'
                    ? finishAnswer.json.message
                    : __(
                          'That isn’t a WordPress export file. Teme reads the .xml file from WordPress’s Tools › Export. Choose that file and try again.',
                      ),
        };
    }

    return { ok: true, fileId: started.fileId, refused: false };
}
