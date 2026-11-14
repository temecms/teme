import { cn } from '@/lib/utils';
import type { DiffBlock } from '@/types';

/** What each kind of block is called, for the badge beside it. */
const LABELS: Record<DiffBlock['kind'], string | null> = {
    same: null,
    added: 'Added',
    removed: 'Removed',
    changed: 'Changed',
    moved: 'Moved',
};

/**
 * Colours for marked words and whole blocks: added text on the success token,
 * removed on the destructive one, always the muted variants, so a long diff
 * reads as a document rather than an alarm.
 */
const MARKS =
    '[&_ins]:bg-ok-soft [&_ins]:no-underline [&_del]:bg-danger-soft [&_del]:line-through';

/**
 * A comparison of two revisions, block by block: side by side above a wide
 * breakpoint, stacked below it. The texts are escaped by the server
 * (DocumentDiff), so they are drawn as HTML to show the marked words.
 */
export function DiffView({ blocks }: { blocks: DiffBlock[] }) {
    if (blocks.length === 0) {
        return (
            <p className="text-ink-2 text-[13px]">Both versions are empty.</p>
        );
    }

    return (
        <ol className="grid gap-2">
            {blocks.map((block, index) => {
                const label = LABELS[block.kind];

                return (
                    <li
                        key={index}
                        className="border-line grid gap-2 border-b pb-2 last:border-b-0 lg:grid-cols-2 lg:gap-6"
                    >
                        <div
                            className={cn(
                                'min-w-0 rounded-sm px-2 py-1 text-[14px] leading-relaxed',
                                block.kind === 'removed' && 'bg-danger-soft',
                                MARKS,
                            )}
                        >
                            <span className="text-ink-2 mb-0.5 block text-[11px] lg:hidden">
                                Before
                            </span>
                            {block.before === null ? (
                                <span className="text-ink-2">—</span>
                            ) : (
                                <span
                                    dangerouslySetInnerHTML={{
                                        __html: block.before,
                                    }}
                                />
                            )}
                        </div>
                        <div
                            className={cn(
                                'min-w-0 rounded-sm px-2 py-1 text-[14px] leading-relaxed',
                                block.kind === 'added' && 'bg-ok-soft',
                                MARKS,
                            )}
                        >
                            <span className="text-ink-2 mb-0.5 flex items-center gap-2 text-[11px]">
                                <span className="lg:hidden">After</span>
                                {label && (
                                    <span className="font-medium">{label}</span>
                                )}
                            </span>
                            {block.after === null ? (
                                <span className="text-ink-2">—</span>
                            ) : (
                                <span
                                    dangerouslySetInnerHTML={{
                                        __html: block.after,
                                    }}
                                />
                            )}
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}
