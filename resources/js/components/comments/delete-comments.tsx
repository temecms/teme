import { router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { deleteMethod as deleteComments } from '@/routes/comments';
import type { CommentDetails, CommentRow } from '@/types';

/** What the confirmation needs of a comment: a row on the page, or the open drawer's comment (B-367). */
type Counted = Pick<CommentRow, 'id' | 'parentId' | 'replies' | 'authorName'>;

export const TOMBSTONE =
    'its name, email address, IP address and text are erased, and the thread shows “This comment was removed” where it was.';

/**
 * Which of the selected comments keep a place in their thread because a
 * reply stays (1.8c's D12): one does when it has more replies than selected
 * comments stored under it that are themselves deleted outright. Worked out
 * from the rows on the page, and the drawer's comment when it isn't among
 * them, deepest first as the server deletes them.
 */
function keepingAPlace(rows: Counted[], ids: string[]): Set<string> {
    const chosen = rows.filter((row) => ids.includes(row.id));
    const keeps = new Map<string, boolean>();
    const decide = (row: Counted): boolean => {
        const known = keeps.get(row.id);

        if (known !== undefined) {
            return known;
        }

        const deleted = chosen.filter(
            (child) => child.parentId === row.id && !decide(child),
        ).length;
        const keep = row.replies > deleted;

        keeps.set(row.id, keep);

        return keep;
    };

    chosen.forEach(decide);

    return new Set(
        chosen.filter((row) => keeps.get(row.id)).map((row) => row.id),
    );
}

/**
 * Asks before deleting comments permanently, in words that say which keep a
 * place in their thread (1.8c's D12, v9's confirmDeleteComments).
 */
export function DeleteComments({
    rows: pageRows,
    details = null,
    ids,
    open,
    onOpenChange,
    onDeleted,
}: {
    rows: CommentRow[];
    /** The open drawer's comment: counted when the page's rows don't hold it (B-367). */
    details?: CommentDetails | null;
    ids: string[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onDeleted: () => void;
}) {
    const rows: Counted[] =
        details && !pageRows.some((row) => row.id === details.id)
            ? [
                  ...pageRows,
                  {
                      id: details.id,
                      parentId: null,
                      replies: details.replies,
                      authorName: details.authorName,
                  },
              ]
            : pageRows;
    const kept = keepingAPlace(rows, ids);
    const one =
        ids.length === 1 ? rows.find((row) => row.id === ids[0]) : undefined;

    const title =
        ids.length === 1
            ? `Delete the comment by ${one?.authorName ?? 'someone'} permanently?`
            : `Delete ${ids.length.toLocaleString()} comments permanently?`;

    let description: string;

    if (ids.length === 1) {
        description =
            kept.size === 1 && one
                ? `It has ${one.replies === 1 ? '1 reply' : `${one.replies.toLocaleString()} replies`}, so it stays in the thread: ${TOMBSTONE} This can’t be undone.`
                : 'It’s deleted, with nothing kept. This can’t be undone.';
    } else if (kept.size === 0) {
        description = `All ${ids.length.toLocaleString()} are deleted, with nothing kept. This can’t be undone.`;
    } else if (kept.size === ids.length) {
        // No one is "the rest" when every comment keeps a place (B-368).
        description = `All ${ids.length.toLocaleString()} have replies, so each stays in the thread: ${TOMBSTONE} This can’t be undone.`;
    } else {
        const lead =
            kept.size === 1
                ? `1 of them has replies, so it stays in the thread: ${TOMBSTONE}`
                : `${kept.size.toLocaleString()} of them have replies, so each stays in the thread: ${TOMBSTONE}`;

        description = `${lead} The rest are deleted, with nothing kept. This can’t be undone.`;
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        onClick={() =>
                            router.post(
                                deleteComments().url,
                                { ids },
                                {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        onOpenChange(false);
                                        onDeleted();
                                    },
                                },
                            )
                        }
                    >
                        Delete permanently
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
