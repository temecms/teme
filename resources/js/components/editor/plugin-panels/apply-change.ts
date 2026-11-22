import type { Editor } from '@tiptap/core';
import type { JSONContent } from '@tiptap/core';
import type { Node as ProseNode } from '@tiptap/pm/model';
import type { EditorDocument } from '@/types';

/**
 * Lowercases for matching without following the browser's locale, so two
 * editors count the same way (issue #539).
 */
function fold(text: string): string {
    return text.toLocaleLowerCase('en-US');
}

/** A letter or digit, for whole-word edges (issue #537). */
function isWordChar(char: string): boolean {
    return char !== '' && /[\p{L}\p{N}]/u.test(char);
}

/**
 * Whether the match at `at`..`at+length` in `folded` sits on word edges.
 */
function wholeWords(folded: string, at: number, length: number): boolean {
    const before = at === 0 ? '' : (folded[at - 1] ?? '');
    const after = folded[at + length] ?? '';

    return !isWordChar(before) && !isWordChar(after);
}

/**
 * Maps a character index in the folded (lowercased) string back to the
 * original, so a link range never shifts when lowercasing changes length
 * (issue #539).
 */
function originalIndex(original: string, foldedTarget: number): number {
    let foldedAt = 0;

    for (let i = 0; i <= original.length; i++) {
        if (foldedAt === foldedTarget) {
            return i;
        }

        if (i < original.length) {
            foldedAt += fold(original[i] ?? '').length;
        }
    }

    return original.length;
}

/**
 * Whether this text node sits inside a code block (issue #536). Inline code
 * marks are already skipped; code blocks carry no mark on their text.
 */
function inCodeBlock(editor: Editor, pos: number): boolean {
    const $pos = editor.state.doc.resolve(pos);

    for (let depth = $pos.depth; depth > 0; depth--) {
        if ($pos.node(depth).type.name === 'codeBlock') {
            return true;
        }
    }

    return false;
}

/**
 * Wraps the `occurrence`th match of `phrase`, in text outside existing
 * links and code (marks and code blocks), with an internal link to the
 * item (rule 9: its ID, never an address). Matches are whole words only,
 * counted with a locale-independent fold. Phrases split across formatting
 * marks are not matched (same limit as Teme SEO's own counter). False when
 * the phrase isn't there any more.
 */
export function applyLink(
    editor: Editor,
    phrase: string,
    itemId: string,
    occurrence: number,
): boolean {
    const wanted = fold(phrase);
    let seen = 0;
    let found: { from: number; to: number } | null = null;

    if (wanted === '' || occurrence < 1) {
        return false;
    }

    editor.state.doc.descendants((node: ProseNode, pos: number) => {
        if (
            found !== null ||
            !node.isText ||
            node.marks.some((mark) =>
                ['link', 'code'].includes(mark.type.name),
            ) ||
            inCodeBlock(editor, pos)
        ) {
            return found === null;
        }

        const original = node.text ?? '';
        const folded = fold(original);

        for (
            let at = folded.indexOf(wanted);
            at !== -1;
            at = folded.indexOf(wanted, at + wanted.length)
        ) {
            if (!wholeWords(folded, at, wanted.length)) {
                continue;
            }

            if (++seen === occurrence) {
                const from = pos + originalIndex(original, at);
                const to = pos + originalIndex(original, at + wanted.length);
                found = { from, to };

                return false;
            }
        }

        return true;
    });

    if (found === null) {
        return false;
    }

    // No focus(): the panel already has the editor; focus() fails headless in tests.
    return editor
        .chain()
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
