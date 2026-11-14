import type { AnyExtension } from '@tiptap/core';
import { Highlight } from '@tiptap/extension-highlight';
import { Link } from '@tiptap/extension-link';
import { TaskItem, TaskList } from '@tiptap/extension-list';
import { Subscript } from '@tiptap/extension-subscript';
import { Superscript } from '@tiptap/extension-superscript';
import {
    Table,
    TableCell,
    TableHeader,
    TableRow,
} from '@tiptap/extension-table';
import { TextAlign } from '@tiptap/extension-text-align';
import { Typography } from '@tiptap/extension-typography';
import { Placeholder } from '@tiptap/extensions';
import { StarterKit } from '@tiptap/starter-kit';
import type { CardManifestEntry } from '@/types';
import { __ } from '@/lib/i18n';
import { blockIds } from './block-ids';
import { cardExtension } from './cards/card-extension';
import { linkTarget, normaliseHref } from './link-address';

// The browser's schema must match the server's allow-list in
// app/Domain/Cards/DocumentSchema.php. Anything the browser can produce that
// the server refuses is a save the writer cannot explain.
export const MARKS = [
    'bold',
    'italic',
    'underline',
    'strike',
    'code',
    'highlight',
    'subscript',
    'superscript',
    'link',
] as const;

/**
 * Whether an address is a link by ID: to an article, a term, a person or a
 * library file, each ID in its table's case, a place at most (I-1702).
 */
export function isInternalLink(href: string): boolean {
    return linkTarget(href) !== null;
}

/**
 * Tiptap's link, as Teme stores it (I-452). An address goes through the
 * server's own rules (link-address.ts), so the editor never makes a link
 * the server would remove. From pasted HTML only the address, a new-tab
 * target and a title are read: rel and class belong to the site's
 * renderer, never the document, and a title stays text even when it looks
 * like a number.
 */
export const TemeLink = Link.extend({
    addAttributes() {
        return {
            ...this.parent?.(),
            target: {
                default: null,
                parseHTML: (element: HTMLElement) =>
                    element.getAttribute('target') === '_blank'
                        ? '_blank'
                        : null,
            },
            rel: { default: null, parseHTML: () => null },
            class: { default: null, parseHTML: () => null },
            title: {
                default: null,
                parseHTML: (element: HTMLElement) =>
                    element.getAttribute('title')?.slice(0, 255) || null,
            },
        };
    },
}).configure({
    openOnClick: false,
    autolink: true,
    HTMLAttributes: { target: null, rel: null, class: null },
    isAllowedUri: (url) => normaliseHref(url) !== null,
});

/**
 * Every extension the editor registers: prose, marks, and one node per card.
 * With smart quotes off (the person's editor.smart_quotes), Typography stays,
 * so dashes and ellipses still convert, and only its four quote rules go.
 */
export function temeExtensions(
    cards: CardManifestEntry[],
    { smartQuotes = true }: { smartQuotes?: boolean } = {},
): AnyExtension[] {
    return [
        // Heading levels 2 to 4: the article's h1 is its title, a separate field.
        // StarterKit 3 already includes Underline.
        StarterKit.configure({
            heading: { levels: [2, 3, 4] },
            link: false,
            // Where a dragged block lands: a 3px line in the accent.
            dropcursor: { width: 3, color: 'var(--accent)' },
        }),
        Highlight,
        Subscript,
        Superscript,
        TextAlign.configure({ types: ['heading', 'paragraph'] }),
        Table.configure({ resizable: true }),
        TableRow,
        TableHeader,
        TableCell,
        TaskList,
        TaskItem.configure({ nested: true }),
        // I-3362: every block that carries an ID gets a ULID the server accepts.
        blockIds(cards.map((card) => card.name)),
        // Decision 7A: smart quotes as typed.
        smartQuotes
            ? Typography
            : Typography.configure({
                  openDoubleQuote: false,
                  closeDoubleQuote: false,
                  openSingleQuote: false,
                  closeSingleQuote: false,
              }),
        TemeLink,
        Placeholder.configure({
            placeholder: () => __('Write, or type / for a block'),
        }),
        ...cards.map(cardExtension),
    ];
}
