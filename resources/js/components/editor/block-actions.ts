import type { Editor } from '@tiptap/core';
import type { Node as PmNode, Schema } from '@tiptap/pm/model';
import { NodeSelection, TextSelection } from '@tiptap/pm/state';
import { __ } from '@/lib/i18n';
import { ulid } from '@/lib/ulid';
import type { CardManifestEntry } from '@/types';

/** A block at the top of the article: the node, where it starts, and its place among its siblings. */
export type TopBlock = { node: PmNode; pos: number; index: number };

/** What "Turn into" offers, for text blocks, quotes and lists. */
export type TurnInto =
    | 'paragraph'
    | 'heading'
    | 'subheading'
    | 'quote'
    | 'bulletList'
    | 'orderedList';

/** The top-level block holding the selection, or null in an empty document. */
export function currentBlock(editor: Editor): TopBlock | null {
    const { selection, doc } = editor.state;

    if (doc.childCount === 0) {
        return null;
    }

    const from =
        selection instanceof NodeSelection && selection.$from.depth === 0
            ? selection.from
            : selection.$from.depth === 0
              ? Math.min(selection.from, doc.content.size - 1)
              : selection.$from.before(1);

    return blockAt(editor, from);
}

/** The top-level block that starts at pos, or null. */
export function blockAt(editor: Editor, pos: number): TopBlock | null {
    const { doc } = editor.state;
    const $pos = doc.resolve(Math.max(0, Math.min(pos, doc.content.size)));
    const index = $pos.index(0);
    const node = doc.maybeChild(index);

    if (node === null) {
        return null;
    }

    let start = 0;

    for (let i = 0; i < index; i++) {
        start += doc.child(i).nodeSize;
    }

    return { node, pos: start, index };
}

/** Whether a node type carries a block id, the attribute the UniqueID rule writes (I-3362). */
function carriesId(schema: Schema, type: string): boolean {
    const attrs = schema.nodes[type]?.spec.attrs;

    return attrs !== undefined && 'id' in attrs;
}

/** A copy of the node with every id inside it new, so the copy is a new block (I-3362). */
function freshCopy(node: PmNode): PmNode {
    const schema = node.type.schema;
    const renew = (json: Record<string, unknown>): Record<string, unknown> => {
        const type = json.type as string;
        const attrs = json.attrs as Record<string, unknown> | undefined;
        const content = json.content as Record<string, unknown>[] | undefined;
        const withId =
            attrs !== undefined && carriesId(schema, type)
                ? { ...json, attrs: { ...attrs, id: ulid() } }
                : json;

        return content === undefined
            ? withId
            : { ...withId, content: content.map(renew) };
    };

    return schema.nodeFromJSON(renew(node.toJSON() as Record<string, unknown>));
}

/** Puts the caret at the start of the block at pos, or selects it when it holds no text. */
function caretInto(editor: Editor, pos: number): void {
    const { state } = editor;
    const node = state.doc.nodeAt(pos);

    if (node === null) {
        return;
    }

    const selection = node.isAtom
        ? NodeSelection.create(state.doc, pos)
        : TextSelection.near(state.doc.resolve(pos + 1));

    editor.view.dispatch(state.tr.setSelection(selection).scrollIntoView());
}

/**
 * Moves the block one place up (-1) or down (1). The node itself moves, so
 * it keeps its id; the caret stays in it. False when it cannot move.
 */
export function moveBlock(
    editor: Editor,
    block: TopBlock,
    by: -1 | 1,
): boolean {
    const { doc } = editor.state;
    const neighbour = doc.maybeChild(block.index + by);

    if (neighbour === null) {
        return false;
    }

    const offset = editor.state.selection.from - block.pos;
    const tr = editor.state.tr.delete(
        block.pos,
        block.pos + block.node.nodeSize,
    );
    const target =
        by === 1
            ? block.pos + neighbour.nodeSize
            : block.pos - neighbour.nodeSize;

    tr.insert(target, block.node);

    const inside = Math.min(
        target + Math.max(offset, 1),
        target + block.node.nodeSize - 1,
    );

    tr.setSelection(
        block.node.isAtom || block.node.isLeaf
            ? NodeSelection.create(tr.doc, target)
            : TextSelection.near(tr.doc.resolve(inside)),
    );
    editor.view.dispatch(tr.scrollIntoView());
    editor.view.focus();

    return true;
}

/** Puts a copy of the block after it, every id in the copy new, and the caret in the copy. */
export function duplicateBlock(editor: Editor, block: TopBlock): boolean {
    const after = block.pos + block.node.nodeSize;

    editor.view.dispatch(editor.state.tr.insert(after, freshCopy(block.node)));
    caretInto(editor, after);
    editor.view.focus();

    return true;
}

/** Removes the block and puts the caret in the one that took its place, or the one before. */
export function deleteBlock(editor: Editor, block: TopBlock): boolean {
    const { doc, schema } = editor.state;
    const tr = editor.state.tr.delete(
        block.pos,
        block.pos + block.node.nodeSize,
    );

    // An article keeps one line to type on.
    if (tr.doc.childCount === 0) {
        tr.insert(0, schema.nodes.paragraph.create());
    }

    editor.view.dispatch(tr);

    const next = block.index < doc.childCount - 1 ? block.pos : null;
    const at =
        next ??
        (block.index > 0 ? block.pos - doc.child(block.index - 1).nodeSize : 0);

    caretInto(editor, at);
    editor.view.focus();

    return true;
}

/** Whether "Turn into" applies: a paragraph, a heading, a quote or a list. */
export function canTurn(block: TopBlock): boolean {
    return [
        'paragraph',
        'heading',
        'blockquote',
        'bulletList',
        'orderedList',
    ].includes(block.node.type.name);
}

/**
 * Turns the block into another kind. Text blocks change in place and keep
 * their id; a quote or a list wraps the text, which keeps its own.
 */
export function turnInto(
    editor: Editor,
    block: TopBlock,
    kind: TurnInto,
): boolean {
    caretInto(editor, block.pos);

    const chain = editor.chain().focus();
    const name = block.node.type.name;

    // Out of a quote or a list first, so the text is a plain line again.
    if (name === 'blockquote') {
        chain.lift('blockquote');
    } else if (name === 'bulletList' || name === 'orderedList') {
        chain.liftListItem('listItem');
    }

    switch (kind) {
        case 'paragraph':
            return chain.setParagraph().run();
        case 'heading':
            return chain.setHeading({ level: 2 }).run();
        case 'subheading':
            return chain.setHeading({ level: 3 }).run();
        case 'quote':
            return chain.setParagraph().wrapIn('blockquote').run();
        case 'bulletList':
            return chain.setParagraph().toggleBulletList().run();
        case 'orderedList':
            return chain.setParagraph().toggleOrderedList().run();
    }
}

/** The block's kind as a person names it, for the handle's label and the picker's head. */
export function blockName(node: PmNode, cards: CardManifestEntry[]): string {
    switch (node.type.name) {
        case 'paragraph':
            return __('Paragraph');
        case 'heading':
            return node.attrs.level === 2 ? __('Heading') : __('Subheading');
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

    return (
        cards.find((card) => card.name === node.type.name)?.label ??
        node.type.name
    );
}
