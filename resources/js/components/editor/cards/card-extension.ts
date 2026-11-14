import { Node, fromString, mergeAttributes } from '@tiptap/core';
import { ReactNodeViewRenderer } from '@tiptap/react';
import type { CardManifestEntry } from '@/types';
import { cardViews, UnknownCard } from './card-views';

/** The one attribute a card's values travel in through the clipboard. */
const CLIPBOARD = 'data-attrs';

/** A copied card's values, or none when the attribute is missing or unreadable. */
function copiedAttributes(element: HTMLElement): Record<string, unknown> {
    try {
        const decoded: unknown = JSON.parse(
            element.getAttribute(CLIPBOARD) ?? '{}',
        );

        return decoded !== null &&
            typeof decoded === 'object' &&
            !Array.isArray(decoded)
            ? (decoded as Record<string, unknown>)
            : {};
    } catch {
        return {};
    }
}

/**
 * Builds the Tiptap node for one card from the server's manifest. The
 * attributes, their defaults and the card's name all come from PHP; this
 * file decides only how the card behaves inside the editor. Through the
 * clipboard a card's values travel as JSON in one attribute, so a list stays
 * a list and "2024" stays text: read back one HTML attribute each, Tiptap
 * would make the first a string and the second a number, and the server
 * would refuse the save (I-452). A card without that attribute, copied
 * before it existed or written by hand, is still read one HTML attribute
 * each, as Tiptap reads it.
 */
export function cardExtension(card: CardManifestEntry) {
    return Node.create({
        name: card.name,
        group: 'block',
        atom: true,
        draggable: true,
        selectable: true,

        addAttributes: () =>
            Object.fromEntries(
                card.attributes.map((attribute) => [
                    attribute.name,
                    {
                        default: attribute.default ?? null,
                        rendered: false,
                        parseHTML: (element: HTMLElement): unknown =>
                            element.hasAttribute(CLIPBOARD)
                                ? copiedAttributes(element)[attribute.name]
                                : fromString(
                                      element.getAttribute(attribute.name),
                                  ),
                    },
                ]),
            ),

        parseHTML: () => [{ tag: `div[data-card="${card.name}"]` }],
        // A card is a leaf: no content hole, which ProseMirror refuses in one.
        renderHTML: ({ node, HTMLAttributes }) => [
            'div',
            mergeAttributes(HTMLAttributes, {
                'data-card': card.name,
                [CLIPBOARD]: JSON.stringify(node.attrs),
            }),
        ],

        addNodeView: () =>
            ReactNodeViewRenderer(cardViews[card.name] ?? UnknownCard),
    });
}
