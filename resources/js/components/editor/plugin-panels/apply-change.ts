import type { Editor } from '@tiptap/core';
import type { JSONContent } from '@tiptap/core';
import type { EditorDocument } from '@/types';

/**
 * Wraps the `occurrence`th match of `phrase`, in text outside existing
 * links and code, with an internal link to the item (rule 9: its ID, never
 * an address). False when the phrase isn't there any more.
 */
export function applyLink(
    editor: Editor,
    phrase: string,
    itemId: string,
    occurrence: number,
): boolean {
    const wanted = phrase.toLocaleLowerCase();
    let seen = 0;
    let found: { from: number; to: number } | null = null;

    if (wanted === '') {
        return false;
    }

    editor.state.doc.descendants((node, pos) => {
        if (
            found !== null ||
            !node.isText ||
            node.marks.some((mark) => ['link', 'code'].includes(mark.type.name))
        ) {
            return found === null;
        }

        const text = (node.text ?? '').toLocaleLowerCase();

        for (
            let at = text.indexOf(wanted);
            at !== -1;
            at = text.indexOf(wanted, at + wanted.length)
        ) {
            if (++seen === occurrence) {
                found = { from: pos + at, to: pos + at + phrase.length };

                return false;
            }
        }

        return true;
    });

    if (found === null) {
        return false;
    }

    return editor
        .chain()
        .focus()
        .setTextSelection(found)
        .setLink({ href: `teme:content/${itemId}` })
        .run();
}

/** Replaces the whole document as one change the person can undo. */
export function applyBody(editor: Editor, document: EditorDocument): boolean {
    return editor
        .chain()
        .focus()
        .setContent(document as JSONContent, { emitUpdate: true })
        .run();
}

/** A document's words, a blank line between blocks, for the word diff. */
export function plainText(document: EditorDocument): string {
    const blocks: string[] = [];

    const walk = (node: JSONContent): string =>
        node.type === 'text'
            ? (node.text ?? '')
            : (node.content ?? []).map(walk).join('');

    for (const block of (document as JSONContent).content ?? []) {
        const text = walk(block).trim();

        if (text !== '') {
            blocks.push(text);
        }
    }

    return blocks.join('\n\n');
}
