import { __ } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { DiffBlock } from '@/types';

/** What each kind of block is called, for the badge beside it. */
function labelOf(kind: DiffBlock['kind']): string | null {
    switch (kind) {
        case 'added':
            return __('Added');
        case 'removed':
            return __('Removed');
        case 'changed':
            return __('Changed');
        case 'moved':
            return __('Moved');
        default:
            return null;
    }
}

/**
 * Colours for marked words and whole blocks: added text on the success token,
 * underlined, removed on the destructive one, struck through, always the
 * muted variants, so a long diff reads as a document rather than an alarm,
 * and a change never shows by color alone.
 */
const MARKS =
    '[&_ins]:bg-ok-soft [&_ins]:underline [&_del]:bg-danger-soft [&_del]:line-through';

/**
 * A comparison of two revisions, block by block: side by side above a wide
 * breakpoint, stacked below it. The texts are escaped by the server
 * (DocumentDiff), so they are drawn as HTML to show the marked words.
 */
export function DiffView({ blocks }: { blocks: DiffBlock[] }) {
    if (blocks.length === 0) {
        return (
            <p className="text-ink-2 text-small">
                {__('Both versions are empty.')}
            </p>
        );
    }

    return (
        <ol className="grid gap-2">
            {blocks.map((block, index) => {
                const label = labelOf(block.kind);

                return (
                    <li
                        key={index}
                        data-kind={block.kind}
                        className="border-line grid gap-2 border-b pb-2 last:border-b-0 lg:grid-cols-2 lg:gap-6"
                    >
                        <div
                            className={cn(
                                'text-body min-w-0 rounded-sm px-2 py-1 leading-relaxed',
                                block.kind === 'removed' && 'bg-danger-soft',
                                MARKS,
                            )}
                        >
                            <span className="text-ink-2 text-caption mb-1 block lg:hidden">
                                {__('Before')}
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
                                'text-body min-w-0 rounded-sm px-2 py-1 leading-relaxed',
                                block.kind === 'added' && 'bg-ok-soft',
                                MARKS,
                            )}
                        >
                            <span className="text-ink-2 text-caption mb-1 flex items-center gap-2">
                                <span className="lg:hidden">{__('After')}</span>
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
