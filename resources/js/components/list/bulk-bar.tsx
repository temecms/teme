import { ChevronDown } from 'lucide-react';
import { useEffect } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { __, __n } from '@/lib/i18n';
import type { RowAction } from './row-actions';
import { useCards } from './use-cards';

/**
 * The bar that appears while rows are selected (docs/architecture/
 * design-system.md, section 4.26): a labelled region on the accent's soft
 * ground saying how many, announced as it changes, then the caller's
 * actions, More for the rest, and Clear selection at the end. On a phone it
 * is pinned to the foot of the screen, and the page makes room under it.
 */
export function BulkBar({
    count,
    noun,
    selectedLabel,
    onClear,
    more,
    children,
}: {
    count: number;
    /** The type's names in lower case, as in "3 posts selected"; without them, "3 selected". */
    noun?: { one: string; many: string };
    /** Replaces the count's words, as in "All matching posts selected" when every match has no count to give. */
    selectedLabel?: string;
    onClear?: () => void;
    /** Actions past the first few, under More. */
    more?: RowAction[];
    children: ReactNode;
}) {
    const pinned = useCards();
    const selectedWords =
        selectedLabel ??
        (noun
            ? __n(':count :one selected|:count :many selected', count, {
                  count: count.toLocaleString(),
                  one: noun.one,
                  many: noun.many,
              })
            : __(':count selected', { count: count.toLocaleString() }));

    // Room under the last row for the pinned bar, while it shows.
    useEffect(() => {
        if (!pinned) {
            return;
        }

        const before = document.body.style.paddingBottom;
        document.body.style.paddingBottom = '6rem';

        return () => {
            document.body.style.paddingBottom = before;
        };
    }, [pinned]);

    return (
        <div
            role="region"
            aria-label={__('Bulk actions')}
            data-slot="bulk-bar"
            style={{ bottom: 'calc(1rem + env(safe-area-inset-bottom))' }}
            className="bg-accent-soft text-on-accent-soft max-sm:shadow-float flex min-h-13 flex-wrap items-center gap-2 rounded-lg py-2 pr-2 pl-5 max-sm:fixed max-sm:inset-x-4 max-sm:z-(--z-sticky)"
        >
            <span aria-live="polite" className="text-body me-2 font-semibold">
                {selectedWords}
            </span>
            {children}
            {more !== undefined && more.length > 0 && (
                <DropdownMenu modal={false}>
                    <DropdownMenuTrigger asChild>
                        <Button
                            type="button"
                            variant="neutral"
                            size="sm"
                            className="bg-surface"
                        >
                            {__('More')}
                            <ChevronDown aria-hidden="true" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start">
                        {more.map((item) =>
                            'onSelect' in item ? (
                                <DropdownMenuItem
                                    key={item.id}
                                    variant={
                                        item.destructive
                                            ? 'destructive'
                                            : 'default'
                                    }
                                    onSelect={item.onSelect}
                                >
                                    {item.label}
                                </DropdownMenuItem>
                            ) : 'href' in item ? (
                                <DropdownMenuItem key={item.id} asChild>
                                    <a href={item.href}>{item.label}</a>
                                </DropdownMenuItem>
                            ) : null,
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            )}
            <span className="flex-1" />
            {onClear !== undefined && (
                <Button
                    variant="ghost"
                    size="sm"
                    type="button"
                    onClick={onClear}
                >
                    {__('Clear selection')}
                </Button>
            )}
        </div>
    );
}
