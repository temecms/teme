import type { JSONContent } from '@tiptap/core';
import { ulid } from '@/lib/ulid';

/** A run of text in an Imported content card's tree, as MarkupTree holds it. */
export type MarkupText = { text: string };

/** An element in the tree, from MarkupTree's list. */
export type MarkupElement = {
    element: string;
    attrs?: Record<string, string | number>;
    children?: MarkupPart[];
};

/** One part of the tree: an element or a run of text. */
export type MarkupPart = MarkupText | MarkupElement;

/** What the tree mostly is, as MarkupTree::holds() says. */
export type Holds = 'table' | 'definition-list' | 'list' | 'text';

/** The headline's words for what the card holds. */
const WHAT: Record<Holds, string> = {
    table: 'a table',
    'definition-list': 'a definition list',
    list: 'a list',
    text: 'formatted text',
};

function isText(part: MarkupPart): part is MarkupText {
    return typeof (part as Partial<MarkupText>).text === 'string';
}

/** The card's tree, whatever its attribute holds: a pasted card may hold words instead. */
export function partsOf(value: unknown): MarkupPart[] {
    return Array.isArray(value)
        ? value.filter(
              (part): part is MarkupPart =>
                  typeof part === 'object' && part !== null,
          )
        : [];
}

/** What the tree mostly is: a table first, then a definition list, then a list (MarkupTree::holds()). */
export function holdsOf(parts: MarkupPart[]): Holds {
    const found = new Set<string>();
    const walk = (list: MarkupPart[]): void => {
        for (const part of list) {
            if (!isText(part)) {
                found.add(part.element);
                walk(part.children ?? []);
            }
        }
    };

    walk(parts);

    if (found.has('table')) {
        return 'table';
    }

    if (found.has('dl')) {
        return 'definition-list';
    }

    return found.has('ul') || found.has('ol') ? 'list' : 'text';
}

/** The box's headline. */
export function headline(parts: MarkupPart[]): string {
    return `Imported from WordPress: ${WHAT[holdsOf(parts)]} Teme couldn’t convert`;
}

/** The box's line saying where the card came from, or null when it doesn't say. */
export function sourceLine(kind: unknown, name: unknown): string | null {
    const named = typeof name === 'string' && name !== '' ? name : null;

    switch (kind) {
        case 'html-block':
            return 'It came from an HTML block.';
        case 'block':
            return named === null
                ? null
                : `It came from the ${named} block, which Teme has no match for.`;
        case 'shortcode':
            return named === null
                ? null
                : `It came from the [${named}] shortcode, as its plugin drew it on the old site.`;
        case 'classic':
            return 'It came from the classic editor’s HTML.';
        default:
            return null;
    }
}

/** The marks a run of text carries while Convert walks the tree. */
type Marks = {
    bold: boolean;
    italic: boolean;
    code: boolean;
    subscript: boolean;
    superscript: boolean;
    href: string | null;
};

const PLAIN: Marks = {
    bold: false,
    italic: false,
    code: false,
    subscript: false,
    superscript: false,
    href: null,
};

/** Tiptap's marks for a run: code only alone, since Tiptap's code mark excludes every other. */
function marksFor(marks: Marks): JSONContent['marks'] {
    const list: NonNullable<JSONContent['marks']> = [];

    if (marks.bold) {
        list.push({ type: 'bold' });
    }

    if (marks.italic) {
        list.push({ type: 'italic' });
    }

    if (marks.subscript) {
        list.push({ type: 'subscript' });
    }

    if (marks.superscript) {
        list.push({ type: 'superscript' });
    }

    if (marks.href !== null) {
        list.push({ type: 'link', attrs: { href: marks.href } });
    }

    if (marks.code && list.length === 0) {
        list.push({ type: 'code' });
    }

    return list.length > 0 ? list : undefined;
}

/**
 * The tree as Convert leaves it (I-329): each block a paragraph, each table
 * row one paragraph of its cells joined by " | " with header cells bold,
 * each list item a paragraph starting "• " or its number, a definition's
 * term bold; line breaks, bold, italic, code, superscript, subscript and
 * links kept; each picture an Image card where it stood, or after its row.
 */
export function toParagraphs(parts: MarkupPart[]): JSONContent[] {
    const blocks: JSONContent[] = [];
    const afterRow: JSONContent[] = [];
    let line: JSONContent[] = [];
    let prefix: string | null = null;
    let inRow = false;

    const push = (node: JSONContent): void => {
        if (prefix !== null) {
            line.push({ type: 'text', text: prefix });
            prefix = null;
        }

        line.push(node);
    };

    const flush = (): void => {
        while (line.length > 0 && line[0].type === 'hardBreak') {
            line.shift();
        }

        while (line.length > 0 && line[line.length - 1].type === 'hardBreak') {
            line.pop();
        }

        const first = line[0];
        const last = line[line.length - 1];

        if (first?.type === 'text') {
            first.text = (first.text ?? '').trimStart();
        }

        if (last?.type === 'text') {
            last.text = (last.text ?? '').trimEnd();
        }

        const content = line.filter(
            (node) => node.type !== 'text' || (node.text ?? '') !== '',
        );

        if (content.some((node) => node.type === 'text')) {
            blocks.push({ type: 'paragraph', content });
        }

        blocks.push(...afterRow.splice(0));
        line = [];
    };

    /** A block's edge: a new paragraph, or inside a table row one space between its parts. */
    const edge = (): void => {
        if (!inRow) {
            flush();
        } else if (
            line.length > 0 &&
            !/\s$/.test(line[line.length - 1].text ?? '')
        ) {
            push({ type: 'text', text: ' ' });
        }
    };

    const text = (value: string, marks: Marks, pre: boolean): void => {
        (pre ? value.split('\n') : [value]).forEach((piece, index) => {
            if (index > 0) {
                push({ type: 'hardBreak' });
            }

            if (piece !== '') {
                const node: JSONContent = { type: 'text', text: piece };
                const list = marksFor(marks);

                if (list !== undefined) {
                    node.marks = list;
                }

                push(node);
            }
        });
    };

    const walk = (list: MarkupPart[], marks: Marks, pre: boolean): void => {
        for (const part of list) {
            if (isText(part)) {
                text(part.text, marks, pre);

                continue;
            }

            const children = part.children ?? [];
            const attrs = part.attrs ?? {};

            switch (part.element) {
                case 'br':
                    push({ type: 'hardBreak' });
                    break;
                case 'img': {
                    const card: JSONContent = {
                        type: 'image',
                        attrs: {
                            id: ulid(),
                            mediaId: String(attrs.mediaId ?? ''),
                            alt:
                                typeof attrs.alt === 'string' &&
                                attrs.alt !== ''
                                    ? attrs.alt
                                    : null,
                        },
                    };

                    if (inRow) {
                        afterRow.push(card);
                    } else {
                        flush();
                        blocks.push(card);
                    }

                    break;
                }
                case 'strong':
                    walk(children, { ...marks, bold: true }, pre);
                    break;
                case 'em':
                    walk(children, { ...marks, italic: true }, pre);
                    break;
                case 'code':
                    walk(children, { ...marks, code: true }, pre);
                    break;
                case 'sup':
                    walk(
                        children,
                        { ...marks, superscript: true, subscript: false },
                        pre,
                    );
                    break;
                case 'sub':
                    walk(
                        children,
                        { ...marks, subscript: true, superscript: false },
                        pre,
                    );
                    break;
                case 'a':
                    walk(
                        children,
                        {
                            ...marks,
                            href:
                                typeof attrs.href === 'string'
                                    ? attrs.href
                                    : null,
                        },
                        pre,
                    );
                    break;
                case 'ul':
                case 'ol': {
                    let number =
                        part.element === 'ol' && typeof attrs.start === 'number'
                            ? attrs.start
                            : 1;

                    for (const item of children) {
                        edge();
                        prefix = part.element === 'ul' ? '• ' : `${number}. `;
                        number += 1;
                        walk(
                            isText(item) ? [item] : (item.children ?? []),
                            marks,
                            pre,
                        );
                        edge();
                    }

                    prefix = null;
                    break;
                }
                case 'tr': {
                    const outer = inRow;

                    edge();
                    inRow = true;
                    children.forEach((cell, index) => {
                        if (index > 0) {
                            push({ type: 'text', text: ' | ' });
                        }

                        const header = !isText(cell) && cell.element === 'th';

                        walk(
                            isText(cell) ? [cell] : (cell.children ?? []),
                            header ? { ...marks, bold: true } : marks,
                            pre,
                        );
                    });
                    inRow = outer;
                    edge();
                    break;
                }
                case 'dt':
                    edge();
                    walk(children, { ...marks, bold: true }, pre);
                    edge();
                    break;
                case 'pre':
                    edge();
                    walk(children, marks, true);
                    edge();
                    break;
                default:
                    edge();
                    walk(children, marks, pre);
                    edge();
            }
        }
    };

    walk(parts, PLAIN, false);
    flush();

    return blocks;
}
