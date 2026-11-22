/**
 * Tests for uploadInPieces (D2). Run with:
 *   node resources/js/lib/upload-in-pieces.test.mjs
 */
import { uploadInPieces, GATEWAY_STATUSES, INITIAL_PIECE_BYTES, MIN_PIECE_BYTES } from './upload-in-pieces.ts';

let failed = 0;

function check(name, ok) {
    if (ok) {
        console.log(`ok - ${name}`);
    } else {
        failed += 1;
        console.error(`not ok - ${name}`);
    }
}

function fileOf(bytes, name = 'export.xml') {
    return new File([bytes], name, { type: 'application/xml' });
}

function waiters() {
    const sleeps = [];
    return {
        sleeps,
        sleep: async (ms) => {
            sleeps.push(ms);
        },
    };
}

// Happy path: two pieces, progress by bytes, finish once
{
    const body = 'a'.repeat(3 * 1024 * 1024);
    const calls = [];
    const progress = [];
    const { sleep, sleeps } = waiters();

    let received = 0;
    const fetchImpl = async (url, init = {}) => {
        calls.push({ url, method: init.method ?? 'GET', body: init.body });
        if (String(url).endsWith('/start')) {
            return new Response(
                JSON.stringify({
                    fileId: 'file1',
                    receivedBytes: 0,
                    sizeBytes: body.length,
                    carriedOn: false,
                }),
                { status: 200, headers: { 'Content-Type': 'application/json' } },
            );
        }
        if (String(url).includes('/pieces')) {
            const offset = Number(new Headers(init.headers).get('X-Upload-Offset') ?? '0');
            const chunk = init.body;
            const size = chunk instanceof Blob ? chunk.size : String(chunk).length;
            received = offset + size;
            return new Response(
                JSON.stringify({ receivedBytes: received, sizeBytes: body.length }),
                { status: 200, headers: { 'Content-Type': 'application/json' } },
            );
        }
        if (String(url).endsWith('/finish')) {
            return new Response(JSON.stringify({ fileId: 'file1', refused: false }), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            });
        }
        return new Response('missing', { status: 404 });
    };

    const result = await uploadInPieces({
        file: fileOf(body),
        urls: {
            start: '/admin/tools/import/files/start',
            piece: (id) => `/admin/tools/import/files/${id}/pieces`,
            finish: (id) => `/admin/tools/import/files/${id}/finish`,
        },
        fetchImpl,
        sleep,
        onProgress: (done, total) => progress.push([done, total]),
    });

    check(
        'uploads every byte then finishes',
        result.ok === true &&
            result.fileId === 'file1' &&
            received === body.length &&
            calls.some((c) => String(c.url).endsWith('/finish')),
    );
    check(
        'reports progress by bytes',
        progress.length > 0 &&
            progress[progress.length - 1][0] === body.length &&
            progress.every(([done, total]) => total === body.length && done <= total),
    );
    check('starts at 2 MB pieces', calls.filter((c) => String(c.url).includes('/pieces')).length >= 2);
    check('no backoff when every piece lands', sleeps.length === 0);
}

// Gateway HTML is a failure to retry, not JSON
{
    const body = 'b'.repeat(512 * 1024);
    const { sleep, sleeps } = waiters();
    let pieceTries = 0;
    let pieceBytesSeen = [];

    const fetchImpl = async (url, init = {}) => {
        if (String(url).endsWith('/start')) {
            return new Response(
                JSON.stringify({
                    fileId: 'g1',
                    receivedBytes: 0,
                    sizeBytes: body.length,
                    carriedOn: false,
                }),
                { status: 200, headers: { 'Content-Type': 'application/json' } },
            );
        }
        if (String(url).includes('/pieces')) {
            pieceTries += 1;
            const chunk = init.body;
            pieceBytesSeen.push(chunk instanceof Blob ? chunk.size : String(chunk).length);
            if (pieceTries <= 2) {
                return new Response('<html>Bad Gateway</html>', {
                    status: 502,
                    headers: { 'Content-Type': 'text/html' },
                });
            }
            return new Response(
                JSON.stringify({ receivedBytes: body.length, sizeBytes: body.length }),
                { status: 200, headers: { 'Content-Type': 'application/json' } },
            );
        }
        if (String(url).endsWith('/finish')) {
            return new Response(JSON.stringify({ fileId: 'g1', refused: false }), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            });
        }
        return new Response('missing', { status: 404 });
    };

    const retrying = [];
    const result = await uploadInPieces({
        file: fileOf(body),
        urls: {
            start: '/start',
            piece: () => '/pieces',
            finish: () => '/finish',
        },
        fetchImpl,
        sleep,
        onRetrying: (size) => retrying.push(size),
    });

    check('retries after 502 HTML without treating it as JSON', result.ok === true);
    check('backs off between retries', sleeps.length >= 2 && sleeps[0] < sleeps[1]);
    check(
        'halves the piece after a gateway failure',
        pieceBytesSeen[0] === Math.min(INITIAL_PIECE_BYTES, body.length) &&
            pieceBytesSeen.some((n) => n < pieceBytesSeen[0]) &&
            Math.min(...pieceBytesSeen) >= Math.min(MIN_PIECE_BYTES, body.length),
    );
    check('tells the UI it is retrying in smaller pieces', retrying.length > 0);
    check('knows the gateway statuses', GATEWAY_STATUSES.has(502) && GATEWAY_STATUSES.has(429));
}

// Dropped connection (network error) then success
{
    const body = 'c'.repeat(300 * 1024);
    const { sleep } = waiters();
    let tries = 0;

    const fetchImpl = async (url) => {
        if (String(url).endsWith('/start')) {
            return new Response(
                JSON.stringify({
                    fileId: 'd1',
                    receivedBytes: 0,
                    sizeBytes: body.length,
                    carriedOn: false,
                }),
                { status: 200, headers: { 'Content-Type': 'application/json' } },
            );
        }
        if (String(url).includes('/pieces')) {
            tries += 1;
            if (tries === 1) {
                throw new TypeError('Failed to fetch');
            }
            return new Response(
                JSON.stringify({ receivedBytes: body.length, sizeBytes: body.length }),
                { status: 200, headers: { 'Content-Type': 'application/json' } },
            );
        }
        return new Response(JSON.stringify({ fileId: 'd1', refused: false }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
        });
    };

    const result = await uploadInPieces({
        file: fileOf(body),
        urls: { start: '/start', piece: () => '/pieces', finish: () => '/finish' },
        fetchImpl,
        sleep,
    });

    check('retries when the connection drops', result.ok === true && tries >= 2);
}

// Timeout is a retryable failure
{
    const body = 'd'.repeat(300 * 1024);
    const { sleep } = waiters();
    let tries = 0;

    const fetchImpl = async (url) => {
        if (String(url).endsWith('/start')) {
            return new Response(
                JSON.stringify({
                    fileId: 't1',
                    receivedBytes: 0,
                    sizeBytes: body.length,
                    carriedOn: false,
                }),
                { status: 200, headers: { 'Content-Type': 'application/json' } },
            );
        }
        if (String(url).includes('/pieces')) {
            tries += 1;
            if (tries === 1) {
                const err = new Error('The operation was aborted');
                err.name = 'TimeoutError';
                throw err;
            }
            return new Response(
                JSON.stringify({ receivedBytes: body.length, sizeBytes: body.length }),
                { status: 200, headers: { 'Content-Type': 'application/json' } },
            );
        }
        return new Response(JSON.stringify({ fileId: 't1', refused: false }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
        });
    };

    const result = await uploadInPieces({
        file: fileOf(body),
        urls: { start: '/start', piece: () => '/pieces', finish: () => '/finish' },
        fetchImpl,
        sleep,
        pieceTimeoutMs: 50,
    });

    check('retries after a timeout', result.ok === true && tries >= 2);
}

// Carry-on from receivedBytes
{
    const body = 'e'.repeat(1000);
    const { sleep } = waiters();
    const offsets = [];

    const fetchImpl = async (url, init = {}) => {
        if (String(url).endsWith('/start')) {
            return new Response(
                JSON.stringify({
                    fileId: 'c1',
                    receivedBytes: 400,
                    sizeBytes: body.length,
                    carriedOn: true,
                }),
                { status: 200, headers: { 'Content-Type': 'application/json' } },
            );
        }
        if (String(url).includes('/pieces')) {
            offsets.push(Number(new Headers(init.headers).get('X-Upload-Offset') ?? '0'));
            return new Response(
                JSON.stringify({ receivedBytes: body.length, sizeBytes: body.length }),
                { status: 200, headers: { 'Content-Type': 'application/json' } },
            );
        }
        return new Response(JSON.stringify({ fileId: 'c1', refused: false }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
        });
    };

    const result = await uploadInPieces({
        file: fileOf(body),
        urls: { start: '/start', piece: () => '/pieces', finish: () => '/finish' },
        fetchImpl,
        sleep,
    });

    check('carries on from receivedBytes', result.ok === true && offsets[0] === 400);
}

if (failed > 0) {
    console.error(`${failed} failed`);
    process.exit(1);
}

console.log('All upload-in-pieces tests passed.');
