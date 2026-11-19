import { StatusPill } from '@/components/ui/status-pill';
import { __ } from '@/lib/i18n';
import type { EditorReview, EditorSchedule } from '@/types';

/** What the chip is told: the item's status, and what may wait beside a live one. */
export type ItemState = {
    status: string;
    review: EditorReview | null;
    schedule: EditorSchedule | null;
    /** Whether a live item has changes readers don't see yet. */
    unpublishedChanges: boolean;
    /** A published private item reads Private in place of Published (I-1700). */
    visibility?: 'public' | 'private' | 'protected';
};

const NOT_LIVE: Record<string, string> = {
    draft: 'Draft',
    review: 'In review',
    scheduled: 'Scheduled',
};

/**
 * An item's state in words, as the editor's header and its Publish panel
 * say it. A live item says what waits beside it, as the Regions screen
 * does: an update in review, then a scheduled one, then any saved change.
 */
export function statusWords(state: ItemState): string {
    if (state.status !== 'published') {
        return NOT_LIVE[state.status] ?? 'Draft';
    }

    const hidden = state.visibility === 'private';

    if (state.review?.state === 'pending') {
        return hidden
            ? 'Private, with an update in review'
            : 'Published, with an update in review';
    }

    if (
        state.schedule !== null &&
        state.schedule.failedReason === null &&
        state.schedule.heldReason === null
    ) {
        return hidden
            ? 'Private, with an update scheduled'
            : 'Published, with an update scheduled';
    }

    if (hidden) {
        return state.unpublishedChanges
            ? 'Private, with unpublished changes'
            : 'Private';
    }

    return state.unpublishedChanges
        ? 'Published, with unpublished changes'
        : 'Published';
}

/** Each status's pill tone (4.13). */
const TONES: Record<string, 'ok' | 'neutral' | 'info' | 'warn'> = {
    draft: 'neutral',
    review: 'warn',
    scheduled: 'info',
    published: 'ok',
    private: 'neutral',
};

/**
 * The item's state as a status pill (I-1316): after the crumbs in the
 * editor's bar, where screen readers hear "Status:" first, and wherever
 * else the item's state is said.
 */
export function StatusChip({
    named = true,
    ...state
}: ItemState & { named?: boolean }) {
    const words = statusWords(state);

    return (
        <StatusPill
            data-test="status-chip"
            title={words}
            tone={
                TONES[
                    state.status === 'published' &&
                    state.visibility === 'private'
                        ? 'private'
                        : state.status
                ] ?? 'neutral'
            }
        >
            {named && <span className="sr-only">{__('Status:')} </span>}
            {words}
        </StatusPill>
    );
}
