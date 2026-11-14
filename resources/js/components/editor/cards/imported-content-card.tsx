import { NodeViewWrapper } from '@tiptap/react';
import type { ReactNodeViewProps } from '@tiptap/react';
import { Fragment, createElement, useId } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { largest, variant } from '@/lib/media-urls';
import { cn } from '@/lib/utils';
import { useCardEnvironment } from './card-environment';
import { headline, partsOf, sourceLine, toParagraphs } from './imported-markup';
import type { MarkupPart } from './imported-markup';

/** The elements the preview draws as themselves; links, pictures and line breaks are drawn apart. */
const ELEMENTS = new Set([
    'p',
    'h2',
    'h3',
    'h4',
    'ul',
    'ol',
    'li',
    'dl',
    'dt',
    'dd',
    'blockquote',
    'pre',
    'table',
    'caption',
    'thead',
    'tbody',
    'tfoot',
    'tr',
    'th',
    'td',
    'strong',
    'em',
    'code',
    'sup',
    'sub',
]);

/** The attributes the preview passes on, by their React names. */
const PROPS: Record<string, string> = {
    colspan: 'colSpan',
    rowspan: 'rowSpan',
    scope: 'scope',
    start: 'start',
    type: 'type',
};

/** The editor's prose look for the preview's elements. */
const PREVIEW =
    'border-line max-h-96 overflow-auto rounded-sm border p-3 text-[14px] leading-relaxed [&>*+*]:mt-2 [&_blockquote]:border-l-2 [&_blockquote]:ps-3 [&_caption]:text-ink-2 [&_caption]:text-start [&_dd]:ms-5 [&_dt]:font-semibold [&_h2]:text-lg [&_h2]:font-semibold [&_h3]:font-semibold [&_h4]:font-semibold [&_ol]:list-decimal [&_ol]:ps-5 [&_pre]:whitespace-pre-wrap [&_table]:w-full [&_table]:border-collapse [&_td]:border [&_td]:p-1.5 [&_th]:border [&_th]:p-1.5 [&_th]:text-start [&_ul]:list-disc [&_ul]:ps-5';

/** The tree as readers see it, for the box's preview; a link is drawn as words, so a click never leaves the editor. */
function Preview({ parts }: { parts: MarkupPart[] }) {
    const { media } = useCardEnvironment();

    const draw = (list: MarkupPart[]): ReactNode[] =>
        list.map((part, index) => {
            if ('text' in part) {
                return part.text;
            }

            const attrs = part.attrs ?? {};
            const children = draw(part.children ?? []);

            if (part.element === 'br') {
                return <br key={index} />;
            }

            if (part.element === 'img') {
                const item =
                    typeof attrs.mediaId === 'string'
                        ? media[attrs.mediaId]
                        : undefined;

                return item ? (
                    <img
                        key={index}
                        src={
                            variant(item.variants, 'w640') ??
                            largest(item.variants) ??
                            undefined
                        }
                        alt={typeof attrs.alt === 'string' ? attrs.alt : ''}
                        className="inline-block h-auto max-w-full"
                    />
                ) : (
                    <span key={index} className="text-ink-2 italic">
                        A picture that is no longer in the library.
                    </span>
                );
            }

            if (part.element === 'a') {
                return (
                    <span
                        key={index}
                        className="text-accent underline"
                        title={String(attrs.href ?? '')}
                    >
                        {children}
                    </span>
                );
            }

            if (!ELEMENTS.has(part.element)) {
                return <Fragment key={index}>{children}</Fragment>;
            }

            const props: Record<string, string | number> = {};

            for (const [name, value] of Object.entries(attrs)) {
                if (name in PROPS) {
                    props[PROPS[name]] = value;
                }
            }

            return createElement(
                part.element,
                { key: index, ...props },
                ...children,
            );
        });

    return <>{draw(parts)}</>;
}

/**
 * An Imported content card in the editor: a locked box saying what it is
 * and where it came from, a preview of what readers see, and Convert to
 * paragraphs, Keep as it is and Delete (I-329). Nothing in it is edited
 * in place, and no menu adds one.
 */
export function ImportedContentCard({
    node,
    selected,
    editor,
    getPos,
    updateAttributes,
    deleteNode,
}: ReactNodeViewProps) {
    const parts = partsOf(node.attrs.markup);
    const kept = node.attrs.kept === true;
    const source = sourceLine(node.attrs.sourceKind, node.attrs.sourceName);
    const hint = useId();

    // One step, so Undo brings the card back whole.
    const convert = (): void => {
        const position = getPos();

        if (typeof position !== 'number') {
            return;
        }

        const content = toParagraphs(parts);

        if (content.length === 0) {
            deleteNode();

            return;
        }

        editor
            .chain()
            .focus()
            .insertContentAt(
                { from: position, to: position + node.nodeSize },
                content,
            )
            .run();
    };

    return (
        <NodeViewWrapper
            role="group"
            aria-label="Imported content card"
            className={cn(
                'border-line bg-surface relative my-4 rounded-md border',
                selected && 'ring-ring ring-2',
            )}
            contentEditable={false}
        >
            <div className="flex flex-wrap items-center gap-1 px-2 pt-1.5">
                <span className="text-ink-2 text-[11px] font-medium tracking-wide uppercase">
                    Imported content
                </span>
                <span
                    className={cn(
                        'ml-auto rounded-md px-2 py-0.5 text-[12px] font-medium',
                        kept ? 'text-ink-2' : 'bg-warn-soft text-warn-ink',
                    )}
                >
                    {kept ? 'Kept' : 'Needs a decision'}
                </span>
            </div>
            <div className="grid gap-2 px-3 pt-1 pb-3">
                <p className="text-[13px] font-medium">{headline(parts)}</p>
                {source !== null && (
                    <p className="text-ink-2 text-[13px]">{source}</p>
                )}
                <div
                    role="region"
                    aria-label="Preview of the imported content"
                    tabIndex={0}
                    className={PREVIEW}
                >
                    <Preview parts={parts} />
                </div>
                <div
                    role="group"
                    aria-label="What to do with this imported content"
                    aria-describedby={hint}
                    className="flex flex-wrap items-center gap-2"
                >
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={convert}
                    >
                        Convert to paragraphs
                    </Button>
                    {!kept && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => updateAttributes({ kept: true })}
                        >
                            Keep as it is
                        </Button>
                    )}
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="text-danger-ink"
                        aria-label="Delete imported content"
                        onClick={deleteNode}
                    >
                        Delete
                    </Button>
                </div>
                {kept && (
                    <p className="text-ink-2 text-[13px]">
                        Kept as readers see it. The import’s report no longer
                        lists it.
                    </p>
                )}
                <p id={hint} className="text-ink-2 text-xs">
                    Convert to paragraphs gives you text to edit and loses the
                    layout. Keep as it is leaves it as readers see it. Delete
                    takes it out of the text. Each can be undone.
                </p>
            </div>
        </NodeViewWrapper>
    );
}

/** The rail's inspector for the card: nothing to set, only a decision to make on the box. */
export function ImportedContentSettings() {
    return (
        <p className="text-ink-2 text-[13px]">
            It holds what readers saw on the old site, cleaned, and can’t be
            edited here. Convert it to paragraphs to edit its words, keep it as
            it is, or delete it.
        </p>
    );
}
