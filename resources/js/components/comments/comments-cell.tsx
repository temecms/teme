import { Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { index as commentsIndex } from '@/routes/comments';
import type { ContentRow } from '@/types';

/**
 * A row's comments in the Posts and Pages lists (1.8c's D21, v9's
 * commentsCell): the approved count, opening them for whoever looks after
 * the item, then how many wait.
 */
export function CommentsCell({
    itemId,
    counts,
}: {
    itemId: string;
    counts: ContentRow['comments'];
}) {
    if (counts === null) {
        return null;
    }

    const approved = counts.approved.toLocaleString();
    const label =
        counts.approved === 1
            ? '1 approved comment'
            : `${approved} approved comments`;

    return (
        <span className="inline-flex items-center justify-end gap-2">
            {counts.approved === 0 ? (
                <span className="text-muted-foreground">0</span>
            ) : counts.mine ? (
                // Targets of at least 24 pixels (WCAG 2.2, 2.5.8).
                <Link
                    href={commentsIndex({
                        query: { view: 'approved', item: itemId },
                    })}
                    aria-label={label}
                    className="inline-flex min-h-6 min-w-6 items-center justify-center rounded-sm hover:underline"
                >
                    {approved}
                </Link>
            ) : (
                <span>{approved}</span>
            )}
            {counts.waiting !== null && counts.waiting > 0 && (
                <Link
                    href={commentsIndex({
                        query: { view: 'pending', item: itemId },
                    })}
                    title="Waiting for approval"
                    className="inline-flex min-h-6 items-center"
                >
                    <Badge variant="secondary">
                        {counts.waiting.toLocaleString()}
                        <span className="sr-only"> waiting for approval</span>
                    </Badge>
                </Link>
            )}
        </span>
    );
}
