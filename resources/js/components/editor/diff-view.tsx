import { CircleCheck } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { EmptyState } from '@/components/list/empty-state';
import { Panel } from '@/components/ui/panel';
import { TextButton } from '@/components/ui/text-button';
import { __, __n } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { DiffBlock } from '@/types';

/** What a block is called, as the editor's block menu names it; a card by its own label. */
function typeOf(block: DiffBlock): string {
    switch (block.node) {
        case 'paragraph':
            return __('Paragraph');
        case 'heading':
            return __('Heading');
        case 'blockquote':
            return __('Quote');
        case 'bulletList':
            return __('Bulleted list');
        case 'orderedList':
            return __('Numbered list');
        case 'taskList':
            return __('Task list');
        case 'codeBlock':
            return __('Code block');
        case 'table':
            return __('Table');
        case 'horizontalRule':
            return __('Horizontal rule');
    }

    return block.label ?? block.node;
}

/** What happened to the block as a whole, after its type: "Paragraph · added". */
function changeOf(kind: DiffBlock['kind']): string | null {
    switch (kind) {
        case 'added':
            return __('added');
        case 'removed':
            return __('removed');
        case 'moved':
            return __('moved');
        default:
            return null;
    }
}

/**
 * Colors for marked words: added text on the success token, underlined,
 * removed on the destructive one, struck through, always the muted
 * variants, so a long diff reads as a document rather than an alarm, and a
 * change never shows by color alone.
 */
const MARKS =
    '[&_ins]:bg-ok-soft [&_ins]:text-ok-ink [&_ins]:underline [&_ins]:underline-offset-3 [&_del]:bg-danger-soft [&_del]:text-danger-ink [&_del]:line-through';

/** The texts the server escaped (DocumentDiff), drawn as HTML to show the marked words. */
function Marked({ html }: { html: string | null }) {
    if (html === null) {
        return <span className="text-ink-3">—</span>;
    }

    return (
        <p
            className="leading-relaxed break-words"
            dangerouslySetInnerHTML={{ __html: html }}
        />
    );
}

/** One side's text: the earlier with its removed words struck, the later with its added words marked. */
function sideOf(block: DiffBlock, side: 'before' | 'after'): string | null {
    if (side === 'before') {
        if (block.kind === 'added') {
            return null;
        }

        if (block.kind === 'removed') {
            return `<del>${block.before ?? ''}</del>`;
        }

        return block.markedBefore ?? block.before;
    }

    if (block.kind === 'removed') {
        return null;
    }

    if (block.kind === 'added') {
        return `<ins>${block.after ?? ''}</ins>`;
    }

    return block.markedAfter ?? block.after;
}

/** Both sides in one text, for a narrow or touch screen: the struck words beside the words that replaced them. */
function inlineOf(block: DiffBlock): string {
    if (block.kind === 'added') {
        return `<ins>${block.after ?? ''}</ins>`;
    }

    if (block.kind === 'removed') {
        return `<del>${block.before ?? ''}</del>`;
    }

    return block.after ?? block.before ?? '';
}

/**
 * One block as a pair: Before beside After, each named by the block's
 * type; on a narrow or touch screen, one text with both marked.
 */
function BlockPair({ block }: { block: DiffBlock }) {
    const type = typeOf(block);
    const change = changeOf(block.kind);

    return (
        <li
            data-kind={block.kind}
            className="text-body grid gap-6 pt-4 md:grid-cols-2"
        >
            {(['before', 'after'] as const).map((side) => {
                const shown =
                    side === 'before'
                        ? block.kind !== 'added'
                        : block.kind !== 'removed';
                const named =
                    change !== null &&
                    (block.kind === 'moved' ||
                        (side === 'before') === (block.kind === 'removed'))
                        ? `${type} · ${change}`
                        : type;

                return (
                    <div
                        key={side}
                        data-side={side}
                        className="grid min-w-0 content-start gap-1 max-md:hidden pointer-coarse:hidden"
                    >
                        <span className="text-caption text-ink-3">
                            {side === 'before' ? __('Before') : __('After')}
                        </span>
                        {shown && (
                            <span className="text-caption text-ink-3 font-medium uppercase">
                                {named}
                            </span>
                        )}
                        <Marked html={sideOf(block, side)} />
                    </div>
                );
            })}
            <div
                data-inline
                className="hidden min-w-0 gap-1 max-md:grid pointer-coarse:grid"
            >
                <span className="text-caption text-ink-3 font-medium">
                    {__(':block: before and after in one', {
                        block: change === null ? type : `${type} · ${change}`,
                    })}
                </span>
                <Marked html={inlineOf(block)} />
            </div>
        </li>
    );
}

/**
 * A comparison of two revisions in one panel: how many blocks changed and
 * how many words came and went, with the key; the field changes (`meta`);
 * each changed block as a Before and After pair; and the blocks that did
 * not change folded away under a button.
 */
export function DiffView({
    blocks,
    meta = null,
}: {
    blocks: DiffBlock[];
    /** The title, excerpt and terms that changed; null when none did. */
    meta?: ReactNode;
}) {
    const [showSame, setShowSame] = useState(false);
    const changed = blocks.filter((block) => block.kind !== 'same');
    const same = blocks.filter((block) => block.kind === 'same');

    if (changed.length === 0 && meta === null) {
        return (
            <Panel>
                {blocks.length === 0 ? (
                    <EmptyState
                        kind="nothing-yet"
                        icon={CircleCheck}
                        title={__('Both versions are empty.')}
                    />
                ) : (
                    <EmptyState
                        kind="nothing-yet"
                        icon={CircleCheck}
                        title={__('These two versions are the same')}
                        description={__(
                            'Nothing differs in the text, the title, the excerpt or the categories and tags. Choose two different versions.',
                        )}
                    />
                )}
            </Panel>
        );
    }

    const added = blocks.reduce((sum, block) => sum + block.added, 0);
    const removed = blocks.reduce((sum, block) => sum + block.removed, 0);

    return (
        <Panel aria-label={__('Changes')} className={cn('pt-2 pb-4', MARKS)}>
            <div className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                <p className="text-body">
                    <strong className="font-semibold">
                        {__n(
                            ':count block changed|:count blocks changed',
                            changed.length,
                        )}
                    </strong>
                    <span className="text-ink-2">
                        {` · ${__n(':count word added|:count words added', added)}, ${__n(':count word removed|:count words removed', removed)}`}
                    </span>
                </p>
                <div
                    role="group"
                    aria-label={__('Key')}
                    className="text-small flex gap-3"
                >
                    <ins>{__('Added')}</ins>
                    <del>{__('Removed')}</del>
                </div>
            </div>
            {meta !== null && <div className="px-5 pb-3">{meta}</div>}
            {changed.length > 0 && (
                <ol className="grid gap-4 px-5">
                    {changed.map((block, index) => (
                        <BlockPair key={index} block={block} />
                    ))}
                </ol>
            )}
            {same.length > 0 && (
                <div className="px-5 pt-4">
                    <TextButton
                        aria-expanded={showSame}
                        onClick={() => setShowSame((shown) => !shown)}
                    >
                        {showSame
                            ? __n(
                                  'Hide :count block that did not change|Hide :count blocks that did not change',
                                  same.length,
                              )
                            : __n(
                                  'Show :count block that did not change|Show :count blocks that did not change',
                                  same.length,
                              )}
                    </TextButton>
                    {showSame && (
                        <ol className="text-ink-2 mt-3 grid gap-4">
                            {same.map((block, index) => (
                                <BlockPair key={index} block={block} />
                            ))}
                        </ol>
                    )}
                </div>
            )}
        </Panel>
    );
}
