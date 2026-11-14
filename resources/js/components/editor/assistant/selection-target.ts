import type { Editor } from '@tiptap/core';
import type { Node as ProseMirrorNode } from '@tiptap/pm/model';

/** The blocks a suggestion may target, as the server's TextBlocks::TYPES. */
export const TEXT_BLOCKS = ['paragraph', 'heading'];

/**
 * A selection inside one paragraph or heading (a list item's text is the
 * paragraph inside it), as a suggestion targets it (I-1305).
 */
export type BlockTarget = {
    /** The block's path: child indexes from the document down, joined by dots. */
    path: string;
    /** The selection's offsets inside the block, in the editor's units, which the server measures alike. */
    from: number;
    to: number;
    /** The position just before the block, carried through every change until Use. */
    pos: number;
    /** The selected text, shown as the proposal's original. */
    text: string;
};

/**
 * The path of the block that starts at `pos`, as the server reads it
 * (TextBlocks::at): its index in its parent, and each ancestor's in theirs.
 */
export function blockPath(doc: ProseMirrorNode, pos: number): string {
    const inside = doc.resolve(pos + 1);
    const indexes: number[] = [];

    for (let depth = 0; depth < inside.depth; depth++) {
        indexes.push(inside.index(depth));
    }

    return indexes.join('.');
}

/**
 * Where the selection is, as a target: null when it is empty, runs across
 * blocks, or sits in anything but a paragraph or heading (I-1305).
 */
export function selectionTarget(editor: Editor): BlockTarget | null {
    const { doc, selection } = editor.state;
    const { $from, $to, empty } = selection;

    if (
        empty ||
        !$from.sameParent($to) ||
        !TEXT_BLOCKS.includes($from.parent.type.name)
    ) {
        return null;
    }

    const pos = $from.before();

    return {
        path: blockPath(doc, pos),
        from: $from.parentOffset,
        to: $to.parentOffset,
        pos,
        text: doc.textBetween($from.pos, $to.pos, ' '),
    };
}
