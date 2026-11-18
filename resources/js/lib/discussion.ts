import { formatDate } from '@/lib/dates';

/** An item's Discussion (I-274): whether readers see its comments and may add one. */
export type Discussion = 'open' | 'closed' | 'hidden';

/** The three states, as the editor's row, Quick edit and Bulk edit offer them. */
export const DISCUSSION_CHOICES: {
    value: Discussion;
    label: string;
    meaning: string;
}[] = [
    {
        value: 'open',
        label: 'Open',
        meaning: 'Readers see the comments and can add one.',
    },
    {
        value: 'closed',
        label: 'Closed',
        meaning: 'Readers see the comments but can’t add one.',
    },
    {
        value: 'hidden',
        label: 'Hidden',
        meaning: 'Readers see no comments and no comment form.',
    },
];

/** A state's label and what it means to readers. */
export function choiceOf(value: Discussion) {
    return (
        DISCUSSION_CHOICES.find((choice) => choice.value === value) ??
        DISCUSSION_CHOICES[0]
    );
}

function daysWord(days: number): string {
    return days === 1 ? '1 day' : `${days} days`;
}

/**
 * When an open item stops taking new comments, as CommentViews::open()
 * decides it: close_after_days after it was published, if that setting
 * isn't 0. Null for an item that isn't open, or when nothing closes it.
 */
export function closingNote(
    value: Discussion,
    published: boolean,
    publishedAt: string | null,
    closeAfterDays: number,
    timeZone: string,
    now: number = Date.now(),
): string | null {
    if (value !== 'open' || closeAfterDays <= 0) {
        return null;
    }

    const days = daysWord(closeAfterDays);

    if (!published || publishedAt === null) {
        return `New comments stop ${days} after it’s published.`;
    }

    const stops = Date.parse(publishedAt) + closeAfterDays * 86_400_000;
    const date = formatDate(stops, timeZone);

    return stops > now
        ? `New comments stop on ${date}, ${days} after it was published.`
        : `New comments stopped on ${date}, ${days} after it was published.`;
}
