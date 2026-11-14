/**
 * Sends one file as multipart form data and reports its progress. Uses
 * XMLHttpRequest because only it reports upload progress; Laravel accepts
 * the XSRF-TOKEN cookie's value as the X-XSRF-TOKEN header.
 */
export type UploadAnswer = { status: number; body: Record<string, unknown> };

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export function uploadFile(
    url: string,
    fields: Record<string, string | Blob>,
    onProgress: (fraction: number) => void,
): Promise<UploadAnswer> {
    return new Promise((resolve, reject) => {
        const request = new XMLHttpRequest();
        const form = new FormData();

        Object.entries(fields).forEach(([key, value]) =>
            form.append(key, value),
        );

        request.open('POST', url);
        request.setRequestHeader('Accept', 'application/json');
        request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        request.setRequestHeader('X-XSRF-TOKEN', xsrfToken());
        request.upload.addEventListener('progress', (event) => {
            if (event.lengthComputable) {
                onProgress(event.loaded / event.total);
            }
        });
        request.addEventListener('load', () => {
            let body: Record<string, unknown> = {};

            try {
                body = JSON.parse(request.responseText || '{}');
            } catch {
                body = {};
            }

            resolve({ status: request.status, body });
        });
        request.addEventListener('error', () =>
            reject(new Error('The connection failed.')),
        );
        request.send(form);
    });
}
