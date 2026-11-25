import type { JSONContent } from '@tiptap/core';
import { __ } from '@/lib/i18n';
import { ulid } from '@/lib/ulid';
import type { CardManifest, CardManifestEntry } from '@/types';

/** One of a region's top-level blocks, as its panel lists it. */
export type RegionRowItem = {
    /** The block's node ID, or its place when it has none yet. */
    key: string;
    index: number;
    type: string;
    /** The icon's Lucide name. */
    icon: string;
    /** What kind of block it is: "Callout", "Text", a plugin's card's name. */
    label: string;
    /** A line of what it holds, or empty. */
    title: string;
    /** Where it comes from: Teme, a plugin or the theme. */
    source: string;
    /** Kept, but not drawn on the site: its plugin isn't running or its theme isn't in use. */
    off: boolean;
};

/** Prose blocks, by node type, as the panel names them. */
const PROSE: Record<string, { label: () => string; icon: string }> = {
    paragraph: { label: () => __('Text'), icon: 'pilcrow' },
    heading: { label: () => __('Heading'), icon: 'heading' },
    bulletList: { label: () => __('List'), icon: 'list' },
    orderedList: { label: () => __('Numbered list'), icon: 'list-ordered' },
    taskList: { label: () => __('Checklist'), icon: 'list-checks' },
    blockquote: { label: () => __('Quote'), icon: 'quote' },
    horizontalRule: { label: () => __('Divider'), icon: 'minus' },
    table: { label: () => __('Table'), icon: 'table' },
};

/** The words in a node, run together, up to a line's worth. */
export function textOf(node: JSONContent): string {
    const words: string[] = [];
    const walk = (part: JSONContent): void => {
        if (typeof part.text === 'string') {
            words.push(part.text);
        }

        part.content?.forEach(walk);
    };

    walk(node);

    return words.join(' ').replace(/\s+/g, ' ').trim().slice(0, 80);
}

/** A card's own line: its title, heading or label attribute, else its words. */
function titleOf(node: JSONContent): string {
    const attrs = (node.attrs ?? {}) as Record<string, unknown>;

    for (const key of ['title', 'heading', 'label', 'text', 'caption']) {
        if (typeof attrs[key] === 'string' && attrs[key].trim() !== '') {
            return attrs[key].trim().slice(0, 80);
        }
    }

    return textOf(node);
}

/** The region's blocks as rows, named from the manifest, without a round trip. */
export function rowsOf(
    document: JSONContent,
    manifest: CardManifest,
): RegionRowItem[] {
    return (document.content ?? []).map((node, index): RegionRowItem => {
        const attrs = (node.attrs ?? {}) as Record<string, unknown>;
        const key =
            typeof attrs.id === 'string' && attrs.id !== ''
                ? attrs.id
                : `at:${index}`;
        const type = node.type ?? 'paragraph';
        const prose = PROSE[type];

        if (prose !== undefined) {
            return {
                key,
                index,
                type,
                icon: prose.icon,
                label: prose.label(),
                title: textOf(node),
                source: __('Teme'),
                off: false,
            };
        }

        if (type === 'plugin-card' || type === 'theme-card') {
            const cardKey = typeof attrs.card === 'string' ? attrs.card : '';
            const plugin = manifest.pluginCards.find(
                (card) => card.key === cardKey,
            );
            const theme = manifest.themeCards.find(
                (card) => card.key === cardKey,
            );
            const found = plugin ?? theme;

            return {
                key,
                index,
                type,
                icon:
                    found?.icon ??
                    (type === 'plugin-card' ? 'plug' : 'palette'),
                label: found?.label ?? cardKey,
                title: '',
                source:
                    plugin?.plugin ??
                    theme?.themeName ??
                    (type === 'plugin-card'
                        ? __('A plugin that isn’t running')
                        : __('A theme not in use')),
                off: found === undefined,
            };
        }

        const card = manifest.cards.find((entry) => entry.name === type);

        return {
            key,
            index,
            type,
            icon: card?.icon ?? 'square',
            label: card?.label ?? type,
            title: titleOf(node),
            source: __('Teme'),
            off: card === undefined,
        };
    });
}

/** The document with the block at `from` moved to `to`. */
export function moved(
    document: JSONContent,
    from: number,
    to: number,
): JSONContent {
    const content = [...(document.content ?? [])];

    if (from === to || to < 0 || to >= content.length) {
        return document;
    }

    const [block] = content.splice(from, 1);
    content.splice(to, 0, block);

    return { ...document, content };
}

/** The document with the block at `index` replaced by these blocks; none removes it. */
export function replaced(
    document: JSONContent,
    index: number,
    blocks: JSONContent[],
): JSONContent {
    const content = [...(document.content ?? [])];
    content.splice(index, 1, ...blocks);

    return { ...document, content };
}

/** The document with this block added at its end. */
export function appended(
    document: JSONContent,
    block: JSONContent,
): JSONContent {
    return { ...document, content: [...(document.content ?? []), block] };
}

/** A new core card, with a fresh ID and the manifest's defaults. */
export function newCard(card: CardManifestEntry): JSONContent {
    const attrs = Object.fromEntries(
        card.attributes.map((attribute) => [
            attribute.name,
            attribute.default ?? null,
        ]),
    );

    return { type: card.name, attrs: { ...attrs, id: ulid() } };
}

/** A new plugin's or theme's card, with a fresh ID and no values yet. */
export function newDeclaredCard(
    type: 'plugin-card' | 'theme-card',
    key: string,
): JSONContent {
    return { type, attrs: { id: ulid(), card: key, values: {} } };
}

/** A new, empty line of text. */
export function newText(): JSONContent {
    return { type: 'paragraph', attrs: { id: ulid() } };
}
