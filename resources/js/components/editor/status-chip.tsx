import { cn } from '@/lib/utils';
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

/** Each status's tag: an outline for a draft, a tint for the rest. */
const TONES: Record<string, string> = {
    draft: 'border-line text-ink-2 border',
    review: 'bg-warn-soft text-warn-ink',
    scheduled: 'bg-surface-2 text-ink',
    published: 'bg-ok-soft text-ok-ink',
    private: 'bg-surface-2 text-ink',
};

/**
 * The item's state as a small tag (I-1316): beside the way back in the
 * header, where screen readers hear "Status:" first, and in the Publish
 * panel's Status row, whose label names it there.
 */
export function StatusChip({
    named = true,
    ...state
}: ItemState & { named?: boolean }) {
    const words = statusWords(state);

    return (
        <span
            data-test="status-chip"
            title={words}
            className={cn(
                'inline-block max-w-full min-w-0 truncate rounded-sm px-2 py-0.5 align-middle text-[11px] leading-4 font-medium',
                TONES[
                    state.status === 'published' &&
                    state.visibility === 'private'
                        ? 'private'
                        : state.status
                ] ?? TONES.draft,
            )}
        >
            {named && <span className="sr-only">Status: </span>}
            {words}
        </span>
    );
}
