import type { Editor } from '@tiptap/core';
import { ulid } from '@/lib/ulid';
import type { CardManifestEntry } from '@/types';
import type { RegionEntry } from './region-entry';

/** Inserts a new card of this kind, with a fresh ID and the manifest's defaults. */
export function insertCard(editor: Editor, card: CardManifestEntry): void {
    const attrs = Object.fromEntries(
        card.attributes.map((attribute) => [
            attribute.name,
            attribute.default ?? null,
        ]),
    );

    editor
        .chain()
        .focus()
        .insertContent({ type: card.name, attrs: { ...attrs, id: ulid() } })
        .run();
}

/**
 * Every card from the server's manifest that may be inserted, as + Insert
 * and the slash menu offer them; a card only the importer adds is left out
 * (I-329). Both menus are searched, so neither has a budget.
 */
export function cardEntries(cards: CardManifestEntry[]): RegionEntry[] {
    return cards
        .filter((card) => card.inMenus)
        .map((card, index): RegionEntry => ({
            id: `card:${card.name}`,
            owner: 'core',
            label: card.menuLabel,
            description: card.description,
            icon: card.icon,
            group: card.group,
            order: 100 + index,
            keywords: card.keywords,
            run: ({ editor }) => insertCard(editor, card),
        }));
}
