// `then` names what saving does next; a SaveFailure is plain data, never awaited.
/* eslint-disable unicorn/no-thenable */
import { __ } from '@/lib/i18n';

/**
 * What the editor does when an autosave isn't kept: the header's few words
 * (`phrase`), the notice that stays until a save succeeds (`title` and
 * `description`, none for a lost connection, which the header says), and
 * what saving does next: tries again by itself with the backoff, stops, or
 * waits for the person's next change.
 */
export type SaveFailure = {
    phrase: string;
    title: string | null;
    description: string | null;
    then: 'retry' | 'stop' | 'next-change';
};

/**
 * The failure for an autosave's answer: its HTTP status, or null when no
 * answer came at all, and the reason the answer gave, which a refusal shows.
 */
export function saveFailure(
    status: number | null,
    reason: string,
): SaveFailure {
    if (status === null) {
        return {
            phrase: __('Not saved: offline'),
            title: null,
            description: null,
            then: 'retry',
        };
    }

    // The session ended. Once the person signs in again in another tab, a
    // retry succeeds without a reload: the client reads the XSRF cookie on
    // every request.
    if (status === 401 || status === 419) {
        return {
            phrase: __('Not saved: signed out'),
            title: __('You’ve been signed out'),
            description: __(
                'Sign in again in another tab, then come back: your text is still here and saves by itself.',
            ),
            then: 'retry',
        };
    }

    if (status === 403) {
        return {
            phrase: __('Not saved: no permission'),
            title: __('You can no longer edit this item'),
            description: __(
                'Your text is still here in the editor. Copy it somewhere safe before you leave.',
            ),
            then: 'stop',
        };
    }

    if (status === 404) {
        return {
            phrase: __('Not saved: item gone'),
            title: __('This item is no longer here'),
            description: __(
                'Someone moved it to the trash or deleted it. Your text is still here in the editor. Copy it somewhere safe before you leave.',
            ),
            then: 'stop',
        };
    }

    if (status === 413) {
        return {
            phrase: __('Not saved: too large'),
            title: __('This is too large to save'),
            description: __(
                'Your text is still here in the editor. Shorten it, or split it into two articles, and it saves again.',
            ),
            then: 'next-change',
        };
    }

    // Too many saves too fast is the server's to answer, as an error is.
    if (status === 429 || (status >= 500 && status <= 599)) {
        return {
            phrase: __('Not saved: retrying'),
            title: __('The server couldn’t save your changes'),
            description: __(
                'Your text is still here in the editor, and it saves as soon as the server answers.',
            ),
            then: 'retry',
        };
    }

    // A 422, or any other refusal: the server said why.
    return {
        phrase: __('Not saved'),
        title: __('This change couldn’t be saved'),
        description: `${reason} ${__('Your text is still here in the editor, and saving tries again after your next change.')}`,
        then: 'next-change',
    };
}

/**
 * A version save's refusal (a checkpoint's): its reason, shown as before
 * with no notice, since the autosave still holds the latest text.
 */
export function versionRefused(reason: string): SaveFailure {
    return {
        phrase: __('Not saved'),
        title: null,
        description: reason,
        then: 'next-change',
    };
}

/**
 * How long to wait before trying a save again: the backoff, or the
 * Retry-After the server sent, in seconds or as a date, if that is longer.
 */
export function retryDelay(
    backoffMs: number,
    retryAfter: string | null,
    now: number = Date.now(),
): number {
    if (retryAfter === null || retryAfter.trim() === '') {
        return backoffMs;
    }

    const seconds = Number(retryAfter);
    const asked = Number.isFinite(seconds)
        ? seconds * 1000
        : Date.parse(retryAfter) - now;

    return Number.isFinite(asked) ? Math.max(backoffMs, asked) : backoffMs;
}
