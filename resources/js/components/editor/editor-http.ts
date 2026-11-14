import { http } from '@inertiajs/react';

/** A JSON answer: its status, its body parsed, and any Retry-After it sent. */
export type Answer = {
    status: number;
    body: Record<string, unknown>;
    retryAfter?: string | null;
};

/** A route as Wayfinder gives it: where, and with which method. */
export type Route = {
    url: string;
    method: NonNullable<
        Parameters<ReturnType<typeof http.getClient>['request']>[0]['method']
    >;
};

/** A JSON POST; a 4xx or 5xx answer is returned, and only a lost connection throws. */
export function post(url: string, data?: object): Promise<Answer> {
    return send({ url, method: 'post' }, data);
}

/**
 * A JSON request with the route's own method, so Cancel can send DELETE; a
 * 4xx or 5xx answer is returned, and only a lost connection throws. A
 * success whose body isn't JSON is an ended session's redirect, followed to
 * a page, so it is returned as a 401.
 */
export async function send(
    route: Route,
    data?: object,
    headers: Record<string, string> = {},
): Promise<Answer> {
    try {
        const response = await http.getClient().request({
            method: route.method,
            url: route.url,
            data: data ?? {},
            headers: { Accept: 'application/json', ...headers },
        });

        try {
            return {
                status: response.status,
                body: JSON.parse(response.data || '{}') as Record<
                    string,
                    unknown
                >,
            };
        } catch {
            return { status: 401, body: {} };
        }
    } catch (error) {
        if (
            error instanceof Error &&
            'response' in error &&
            typeof (error as { response?: unknown }).response === 'object'
        ) {
            const {
                status,
                data: raw,
                headers = {},
            } = (
                error as unknown as {
                    response: {
                        status: number;
                        data: string;
                        headers?: Record<string, string>;
                    };
                }
            ).response;
            let body: Record<string, unknown> = {};

            try {
                body = JSON.parse(raw || '{}') as Record<string, unknown>;
            } catch {
                body = {};
            }

            return { status, body, retryAfter: headers['retry-after'] ?? null };
        }

        throw error;
    }
}

/** The first validation message in a 422 answer, for the save state to show. */
export function reasonOf(body: Record<string, unknown>): string {
    const errors = body.errors as Record<string, string[]> | undefined;
    const first = errors ? Object.values(errors)[0]?.[0] : undefined;

    return (
        first ??
        (body.message as string | undefined) ??
        'Teme couldn’t save this.'
    );
}
