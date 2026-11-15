import type { AnyExtension, JSONContent } from '@tiptap/core';
import { UniqueID } from '@tiptap/extension-unique-id';
import { Fragment, Slice } from '@tiptap/pm/model';
import { Plugin } from '@tiptap/pm/state';
import type { EditorView } from '@tiptap/pm/view';
import { ulid } from '@/lib/ulid';

/**
 * The prose blocks that carry a permanent ID (I-3362): exactly those
 * DocumentSchema lets carry one. Cards carry theirs too, made when inserted.
 */
export const BLOCK_ID_TYPES = [
    'heading',
    'paragraph',
    'blockquote',
    'codeBlock',
    'listItem',
    'taskItem',
    'table',
] as const;

/** A node ID's shape, as the server checks it (DocumentSchema::NODE_ID). */
const NODE_ID = /^[0-9A-HJKMNP-TV-Z]{26}$/;

/** The node types that carry an ID: the prose blocks and these cards. */
function carriers(cards: readonly string[]): Set<string> {
    return new Set<string>([...BLOCK_ID_TYPES, ...cards]);
}

/** The node's ID when it carries one of a node ID's shape, else null. */
function idOf(node: JSONContent): string | null {
    const id: unknown = node.attrs?.id;

    return typeof id === 'string' && NODE_ID.test(id) ? id : null;
}

/**
 * The nodes, in document order, with each node that carries an ID kept
 * when its ID has a node ID's shape and isn't taken yet, and given a new
 * one otherwise; every ID kept or given is then taken.
 */
function renewed(
    nodes: JSONContent[],
    types: Set<string>,
    taken: Set<string>,
    newId: () => string,
): JSONContent[] {
    return nodes.map((node) => {
        let next = node;

        if (node.type !== undefined && types.has(node.type)) {
            let id = idOf(node);

            if (id === null || taken.has(id)) {
                id = newId();
                next = { ...node, attrs: { ...node.attrs, id } };
            }

            taken.add(id);
        }

        return next.content === undefined
            ? next
            : { ...next, content: renewed(next.content, types, taken, newId) };
    });
}

/**
 * The stored document as the editor opens it: every block and card with no
 * ID, an ID of another shape, or one an earlier node already has, given a
 * new one; the first of a repeated ID keeps it, as the server keeps it. Run
 * before the editor exists, so it is no change: an article opened and left
 * is never saved (I-3362).
 */
export function withBlockIds(
    document: JSONContent,
    cards: readonly string[],
    newId: () => string = ulid,
): JSONContent {
    const [opened] = renewed([document], carriers(cards), new Set(), newId);

    return opened;
}

/** The cards that carry an ID in this editor, as blockIds() was given them. */
export function idCarryingCards(storage: unknown): readonly string[] {
    const cards = (storage as { uniqueID?: { cards?: unknown } }).uniqueID
        ?.cards;

    return Array.isArray(cards) ? (cards as string[]) : [];
}

/** Every ID of a node ID's shape the document's blocks and cards carry, in document order. */
export function blockIdsIn(
    document: JSONContent,
    cards: readonly string[],
): string[] {
    const types = carriers(cards);
    const ids: string[] = [];
    const visit = (node: JSONContent): void => {
        const id =
            node.type !== undefined && types.has(node.type) ? idOf(node) : null;

        if (id !== null) {
            ids.push(id);
        }

        node.content?.forEach(visit);
    };

    visit(document);

    return ids;
}

/**
 * Pasted or dropped nodes with each ID the article already holds, one the
 * paste used earlier, one of another shape, or none, made new; an ID the
 * article doesn't hold is kept, so a block cut and pasted, or copied from
 * another article, stays the same block (I-3362).
 */
export function freshPastedIds(
    pasted: JSONContent[],
    present: readonly string[],
    cards: readonly string[],
    newId: () => string = ulid,
): JSONContent[] {
    return renewed(pasted, carriers(cards), new Set(present), newId);
}

/**
 * Tiptap's UniqueID on every block in BLOCK_ID_TYPES, with ULIDs, and
 * Teme's paste and drop rule in place of its own, which makes every pasted
 * or dropped block new, one dragged within the article included (I-3362).
 */
export function blockIds(cards: readonly string[]): AnyExtension {
    return UniqueID.extend({
        // The cards that carry an ID, for a block copied in the editor (block-actions.ts).
        addStorage() {
            return { ...this.parent?.(), cards };
        },

        addProseMirrorPlugins() {
            // Whether the drop under way moves blocks dragged within this article.
            let moving = false;

            return (this.parent?.() ?? []).map(
                (plugin) =>
                    new Plugin({
                        ...plugin.spec,
                        view: undefined,
                        props: {
                            ...plugin.spec.props,
                            handleDOMEvents: {
                                // ProseMirror copies instead of moving when Ctrl, or Option on a Mac, is held at the drop; either key counts as a copy here.
                                drop: (
                                    view: EditorView,
                                    event: DragEvent,
                                ): boolean => {
                                    moving =
                                        view.dragging?.move === true &&
                                        !event.ctrlKey &&
                                        !event.altKey;

                                    return false;
                                },
                            },
                            transformPasted: (
                                slice: Slice,
                                view: EditorView,
                            ): Slice => {
                                const moved =
                                    moving && view.dragging?.move === true;
                                moving = false;

                                // Dragged within the article: the blocks move, so they keep their IDs.
                                if (moved) {
                                    return slice;
                                }

                                const pasted = freshPastedIds(
                                    (slice.content.toJSON() as
                                        | JSONContent[]
                                        | null) ?? [],
                                    blockIdsIn(
                                        view.state.doc.toJSON() as JSONContent,
                                        cards,
                                    ),
                                    cards,
                                );

                                return new Slice(
                                    Fragment.fromJSON(
                                        view.state.schema,
                                        pasted,
                                    ),
                                    slice.openStart,
                                    slice.openEnd,
                                );
                            },
                        },
                    }),
            );
        },
    }).configure({ types: [...BLOCK_ID_TYPES], generateID: () => ulid() });
}
