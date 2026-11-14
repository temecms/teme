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
import { empty } from '@/routes/comments';
import { TOMBSTONE } from './delete-comments';

/**
 * The confirmation's words for an Empty press (C4, D6): v9's, with I-577's
 * "replies" and B-368's "all" case; every count with its singular.
 */
export function emptyingWords({
    view,
    facts,
    itemTitle,
    search,
    own,
}: {
    view: 'spam' | 'trash';
    facts: { count: number; tombstones: number; replies: number };
    itemTitle: string | null;
    search: string | null;
    own: boolean;
}): { title: string; body: string; action: string } {
    const spam = view === 'spam';
    const n = facts.count;
    const t = facts.tombstones;
    const many = n.toLocaleString();

    const title = spam
        ? n === 1
            ? 'Delete 1 spam comment permanently?'
            : `Delete ${many} spam comments permanently?`
        : n === 1
          ? 'Delete 1 comment in the trash permanently?'
          : `Delete ${many} comments in the trash permanently?`;

    const where = `${spam ? 'Spam' : 'the trash'}${itemTitle ? ` on “${itemTitle}”` : own ? ' on your posts and pages' : ''}`;
    const matching = search ? ` that matches “${search}” and was` : ' that was';
    const since = spam
        ? 'Spam that arrived since stays, for you to check first.'
        : 'Anything moved to the trash since stays.';

    let tombstones = '';

    if (t > 0 && n === 1) {
        tombstones = `It has ${facts.replies === 1 ? '1 reply' : `${facts.replies.toLocaleString()} replies`}, so it stays in the thread: ${TOMBSTONE} `;
    } else if (t > 0 && t === n) {
        tombstones = `All ${many} have replies, so each stays in the thread: ${TOMBSTONE} `;
    } else if (t === 1) {
        tombstones = `1 of them has replies, so it stays in the thread: ${TOMBSTONE} `;
    } else if (t > 1) {
        tombstones = `${t.toLocaleString()} of them have replies, so each stays in the thread: ${TOMBSTONE} `;
    }

    return {
        title,
        body: `Every comment in ${where}${matching} there when you opened this list is deleted. ${since} ${tombstones}This can’t be undone.`,
        action: spam ? 'Empty spam' : 'Empty trash',
    };
}

/**
 * Asks before Empty spam or Empty trash, in words that say what goes, what
 * stays and which comments keep a place in their thread (C4; 1.8c′'s D6;
 * v9's confirmEmptyComments).
 */
export function EmptyComments({
    open,
    onOpenChange,
    view,
    facts,
    item,
    search,
    own,
    openedAt,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    view: 'spam' | 'trash';
    facts: { count: number; tombstones: number; replies: number };
    item: { id: string; title: string } | null;
    search: string | null;
    own: boolean;
    openedAt: string;
}) {
    const words = emptyingWords({
        view,
        facts,
        itemTitle: item?.title ?? null,
        search,
        own,
    });

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{words.title}</DialogTitle>
                    <DialogDescription>{words.body}</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        onClick={() =>
                            router.post(
                                empty().url,
                                {
                                    view,
                                    ...(item ? { item: item.id } : {}),
                                    ...(search ? { search } : {}),
                                    openedAt,
                                },
                                {
                                    preserveScroll: true,
                                    onSuccess: () => onOpenChange(false),
                                },
                            )
                        }
                    >
                        {words.action}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
