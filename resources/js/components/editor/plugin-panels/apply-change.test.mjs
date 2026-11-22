/**
 * Tests for applyLink (issues #536–#539). Run with:
 *   node --import ./tests/Fixtures/editor-hooks.mjs resources/js/components/editor/plugin-panels/apply-change.test.mjs
 */
import { Editor } from '@tiptap/core';
import { Link } from '@tiptap/extension-link';
import { StarterKit } from '@tiptap/starter-kit';
import { applyLink } from './apply-change.ts';

const itemId = '01kitemaaaaaaaaaaaaaaaaaaa';

function editorWith(content) {
    return new Editor({
        extensions: [
            StarterKit.configure({ link: false }),
            Link.configure({
                openOnClick: false,
                // Teme stores internal links as teme:content/{id} (rule 9).
                isAllowedUri: () => true,
                protocols: ['http', 'https', 'mailto', 'teme'],
            }),
        ],
        content: { type: 'doc', content },
    });
}

function linkedText(editor) {
    const parts = [];

    editor.state.doc.descendants((node) => {
        if (node.isText && node.marks.some((m) => m.type.name === 'link')) {
            parts.push(node.text ?? '');
        }

        return true;
    });

    return parts.join('');
}

let failed = 0;

function check(name, ok) {
    if (ok) {
        console.log(`ok - ${name}`);
    } else {
        failed += 1;
        console.error(`not ok - ${name}`);
    }
}

// #536: skip matches inside a code block
{
    const skip = editorWith([
        {
            type: 'paragraph',
            content: [{ type: 'text', text: 'See roses outside.' }],
        },
        {
            type: 'codeBlock',
            content: [{ type: 'text', text: 'const roses = true;' }],
        },
    ]);
    check(
        '#536 skips the match inside a code block',
        applyLink(skip, 'roses', itemId, 2) === false,
    );
    skip.destroy();

    const prose = editorWith([
        {
            type: 'paragraph',
            content: [{ type: 'text', text: 'See roses outside.' }],
        },
        {
            type: 'codeBlock',
            content: [{ type: 'text', text: 'const roses = true;' }],
        },
    ]);
    check(
        '#536 still links the prose match',
        applyLink(prose, 'roses', itemId, 1) === true &&
            linkedText(prose) === 'roses',
    );
    prose.destroy();
}

// #537: whole words only — "cat" inside "category" must not count
{
    const editor = editorWith([
        {
            type: 'paragraph',
            content: [
                { type: 'text', text: 'A category of cat food for the cat.' },
            ],
        },
    ]);
    const linked = applyLink(editor, 'cat', itemId, 1);
    check(
        '#537 links the first whole word cat',
        linked === true && linkedText(editor) === 'cat',
    );
    // After linking the first "cat" (in "cat food"), the document still has "category" intact.
    check('#537 left category unlinked', editor.getText().includes('category'));
    // Occurrence 2 is the second whole-word "cat", not a third from inside category.
    const second = editorWith([
        {
            type: 'paragraph',
            content: [
                { type: 'text', text: 'A category of cat food for the cat.' },
            ],
        },
    ]);
    check(
        '#537 second occurrence is the last cat',
        applyLink(second, 'cat', itemId, 2) === true &&
            second
                .getJSON()
                .content[0].content.filter((n) =>
                    n.marks?.some((m) => m.type === 'link'),
                ).length === 1,
    );
    // A third whole-word match does not exist (category does not count).
    check(
        '#537 no third whole-word match',
        applyLink(second, 'cat', itemId, 3) === false,
    );
    editor.destroy();
    second.destroy();
}

// #538: phrase split by formatting — documented limit (PHP LinkSuggester matches one run at a time)
{
    const editor = editorWith([
        {
            type: 'paragraph',
            content: [
                { type: 'text', text: 'natural ' },
                { type: 'text', text: 'antacids', marks: [{ type: 'bold' }] },
            ],
        },
    ]);
    check(
        '#538 cannot match a phrase split by formatting (documented limit)',
        applyLink(editor, 'natural antacids', itemId, 1) === false,
    );
    editor.destroy();
}

// #539: Turkish İ — fold must map positions back to the original characters
{
    const editor = editorWith([
        {
            type: 'paragraph',
            content: [{ type: 'text', text: 'İstanbul and more' }],
        },
    ]);
    check(
        '#539 links İstanbul without shifting characters',
        applyLink(editor, 'İstanbul', itemId, 1) === true &&
            linkedText(editor) === 'İstanbul',
    );
    editor.destroy();
}

if (failed > 0) {
    console.error(`${failed} failed`);
    process.exit(1);
}

console.log('All apply-change tests passed.');
