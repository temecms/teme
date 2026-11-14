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
import { __ } from '@/lib/i18n';
import type { ContentIndexPage } from '@/types/content';

/** What Select all matching can do to every match. */
export type MatchingAction = 'trash' | 'restore' | 'delete';

/**
 * The words after "Select all": the active tab's own exact count when nothing
 * narrows the list, and no count under a search or a filter, which has none to
 * give (I-140).
 */
export function matchingScope(page: ContentIndexPage): string {
    const plural = page.labels.plural.toLowerCase();
    const singular = page.labels.singular.toLowerCase();
    const key = page.filters.tab ?? 'all';
    const inTrash = key === 'trash';

    if (page.filtered) {
        return inTrash
            ? __('matching :plural in the trash', { plural })
            : __('matching :plural', { plural });
    }

    const tab = page.tabs.find((candidate) => candidate.key === key);
    const count = tab?.count ?? page.tabs[0]?.count ?? 0;
    const numbered = count.toLocaleString();
    const noun = count === 1 ? singular : plural;

    if (key === 'all') {
        return __(':count :noun', { count: numbered, noun });
    }

    if (key === 'mine') {
        return __(':count of your :noun', { count: numbered, noun });
    }

    if (inTrash) {
        return __(':count :noun in the trash', { count: numbered, noun });
    }

    return __(':count :noun in the :tab tab', {
        count: numbered,
        noun,
        tab: tab?.label ?? key,
    });
}

/**
 * The link in the bulk bar that moves the selection from the page's rows to
 * every match: offered only once every row the person may change is selected
 * and the list has another page.
 */
export function SelectAllMatching({
    page,
    editableCount,
    selectedCount,
    onChoose,
}: {
    page: ContentIndexPage;
    editableCount: number;
    selectedCount: number;
    onChoose: () => void;
}) {
    const morePages =
        page.paging.last > 1 || page.paging.more || page.paging.number === null;

    if (editableCount === 0 || selectedCount !== editableCount || !morePages) {
        return null;
    }

    return (
        <Button
            type="button"
            variant="link"
            size="sm"
            data-test="select-all-matching"
            onClick={onChoose}
        >
            {__('Select all :scope', { scope: matchingScope(page) })}
        </Button>
    );
}

/**
 * Asks before a run over every match starts. It acts on items the person
 * can't see, so every action asks, even Move to trash (I-143).
 */
export function ConfirmMatching({
    action,
    page,
    processing,
    onOpenChange,
    onConfirm,
}: {
    action: MatchingAction | null;
    page: ContentIndexPage;
    processing: boolean;
    onOpenChange: (open: boolean) => void;
    onConfirm: () => void;
}) {
    const scope = matchingScope(page);
    const singular = page.labels.singular.toLowerCase();
    const background = __(
        'This runs in the background, one :singular at a time, and you can keep working.',
        { singular },
    );
    const skipped = __('Any you may not change are skipped.');
    const words = {
        trash: {
            title: __('Move all :scope to the trash?', { scope }),
            description: [
                background,
                skipped,
                __('You can restore them from the trash.'),
            ].join(' '),
            confirm: __('Move to trash'),
        },
        restore: {
            title: __('Restore all :scope?', { scope }),
            description: [background, skipped].join(' '),
            confirm: __('Restore'),
        },
        delete: {
            title: __('Delete all :scope permanently?', { scope }),
            description: __(
                'All their revisions go with them. This can’t be undone. It runs in the background, one :singular at a time, and any you may not change are skipped.',
                { singular },
            ),
            confirm: __('Delete permanently'),
        },
    };
    const shown = words[action ?? 'trash'];

    return (
        <Dialog open={action !== null} onOpenChange={onOpenChange}>
            <DialogContent data-test="confirm-matching">
                <DialogHeader>
                    <DialogTitle>{shown.title}</DialogTitle>
                    <DialogDescription>{shown.description}</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button type="button" variant="outline">
                            {__('Cancel')}
                        </Button>
                    </DialogClose>
                    <Button
                        type="button"
                        variant={
                            action === 'restore' ? 'default' : 'destructive'
                        }
                        disabled={processing}
                        data-test="confirm-matching-go"
                        onClick={onConfirm}
                    >
                        {shown.confirm}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
