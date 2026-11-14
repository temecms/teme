import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';

/**
 * The bar that appears while rows are selected, saying how many. A muted
 * background and a hairline, not an accent panel: the accent marks what is
 * current, not what is busy.
 */
export function BulkBar({
    count,
    noun,
    selectedLabel,
    onClear,
    children,
}: {
    count: number;
    /** The type's names in lower case, as in "3 posts selected"; without them, "3 selected". */
    noun?: { one: string; many: string };
    /** Replaces the count's words, as in "All matching posts selected" when every match has no count to give. */
    selectedLabel?: string;
    onClear: () => void;
    children: ReactNode;
}) {
    const selectedWords =
        selectedLabel ??
        (noun
            ? `${count.toLocaleString()} ${count === 1 ? noun.one : noun.many} selected`
            : `${count.toLocaleString()} selected`);

    return (
        <div
            role="region"
            aria-label="Bulk actions"
            className="bg-surface-2 border-edge flex flex-wrap items-center gap-2 rounded border px-3 py-2"
        >
            <span className="mr-2 text-[13px] font-semibold">
                {selectedWords}
            </span>
            {children}
            <span className="flex-1" />
            <Button variant="ghost" size="sm" type="button" onClick={onClear}>
                Clear selection
            </Button>
        </div>
    );
}
