// Later (I-200): extensions contribute entries to this list in phase 5.
// Core draws them, so they look like the rest of the editor; the region
// shows a fixed number and puts the rest under "More".
import type { Editor } from '@tiptap/core';
import { useEditorState } from '@tiptap/react';
import { useEffect, useMemo, useState } from 'react';
import type { CardManifestEntry } from '@/types';
import { cardEntries } from './insert-entries';
import type { EditorContext, RegionEntry } from './region-entry';
import { matches, visibleEntries } from './region-entry';

/** The block types the slash menu offers beside the cards. */
const BLOCK_ENTRIES: RegionEntry[] = [
    {
        id: 'paragraph',
        owner: 'core',
        label: 'Paragraph',
        group: 'Blocks',
        order: 10,
        keywords: ['text'],
        run: ({ editor }) => editor.chain().focus().setParagraph().run(),
    },
    {
        id: 'heading-2',
        owner: 'core',
        label: 'Heading 2',
        group: 'Blocks',
        order: 20,
        keywords: ['h2', 'title', 'section'],
        run: ({ editor }) =>
            editor.chain().focus().setHeading({ level: 2 }).run(),
    },
    {
        id: 'heading-3',
        owner: 'core',
        label: 'Heading 3',
        group: 'Blocks',
        order: 30,
        keywords: ['h3', 'subheading'],
        run: ({ editor }) =>
            editor.chain().focus().setHeading({ level: 3 }).run(),
    },
    {
        id: 'heading-4',
        owner: 'core',
        label: 'Heading 4',
        group: 'Blocks',
        order: 40,
        keywords: ['h4'],
        run: ({ editor }) =>
            editor.chain().focus().setHeading({ level: 4 }).run(),
    },
    {
        id: 'bulletList',
        owner: 'core',
        label: 'Bulleted list',
        group: 'Blocks',
        order: 50,
        keywords: ['list', 'bullets', 'ul'],
        run: ({ editor }) => editor.chain().focus().toggleBulletList().run(),
    },
    {
        id: 'orderedList',
        owner: 'core',
        label: 'Numbered list',
        group: 'Blocks',
        order: 60,
        keywords: ['list', 'numbers', 'ol'],
        run: ({ editor }) => editor.chain().focus().toggleOrderedList().run(),
    },
    {
        id: 'taskList',
        owner: 'core',
        label: 'Task list',
        group: 'Blocks',
        order: 70,
        keywords: ['todo', 'checklist', 'tasks'],
        run: ({ editor }) => editor.chain().focus().toggleTaskList().run(),
    },
    {
        id: 'blockquote',
        owner: 'core',
        label: 'Quote',
        group: 'Blocks',
        order: 80,
        keywords: ['blockquote', 'quotation'],
        run: ({ editor }) => editor.chain().focus().toggleBlockquote().run(),
    },
    {
        id: 'codeBlock',
        owner: 'core',
        label: 'Code block',
        group: 'Blocks',
        order: 90,
        keywords: ['code', 'pre'],
        run: ({ editor }) => editor.chain().focus().setCodeBlock().run(),
    },
    {
        id: 'table',
        owner: 'core',
        label: 'Table',
        group: 'Blocks',
        order: 95,
        keywords: ['grid', 'rows', 'columns'],
        run: ({ editor }) =>
            editor
                .chain()
                .focus()
                .insertTable({ rows: 3, cols: 3, withHeaderRow: true })
                .run(),
    },
    {
        id: 'horizontalRule',
        owner: 'core',
        label: 'Horizontal rule',
        group: 'Blocks',
        order: 900,
        keywords: ['rule', 'divider', 'line', 'hr', 'separator'],
        run: ({ editor }) =>
            editor
                .chain()
                .focus()
                .insertContent({ type: 'horizontalRule' })
                .run(),
    },
];

/** The slash typed on an empty line, and what follows it, or null. */
function slashAt(
    editor: Editor,
): { query: string; from: number; to: number } | null {
    const { selection } = editor.state;

    if (!selection.empty) {
        return null;
    }

    const { $from } = selection;

    if ($from.parent.type.name !== 'paragraph') {
        return null;
    }

    const text = $from.parent.textContent;
    const match = /^\/([\w-]*)$/.exec(text);

    if (match === null || $from.parentOffset !== text.length) {
        return null;
    }

    return { query: match[1], from: $from.start(), to: $from.end() };
}

/**
 * The slash menu: / on an empty line offers the block types and every card,
 * filtered as you type. It is an accelerator, not the only route: everything
 * here is also on the toolbar or in + Insert (I-185).
 */
export function SlashMenu({
    editor,
    cards,
    context,
}: {
    editor: Editor;
    cards: CardManifestEntry[];
    context: EditorContext;
}) {
    const slash = useEditorState({
        editor,
        selector: ({ editor: current }) => slashAt(current),
    });
    const [active, setActive] = useState(0);
    const [dismissed, setDismissed] = useState<number | null>(null);

    const entries = useMemo(
        () =>
            slash === null
                ? []
                : visibleEntries(
                      [...BLOCK_ENTRIES, ...cardEntries(cards)],
                      context,
                  ).filter((entry) => matches(entry, slash.query)),
        [slash, cards, context],
    );

    const open =
        slash !== null && dismissed !== slash.from && entries.length > 0;

    useEffect(() => setActive(0), [slash?.query]);

    useEffect(() => {
        if (slash === null) {
            setDismissed(null);
        }
    }, [slash]);

    useEffect(() => {
        if (!open) {
            return;
        }

        const choose = (entry: RegionEntry) => {
            editor
                .chain()
                .focus()
                .deleteRange({ from: slash.from, to: slash.to })
                .run();
            entry.run(context);
        };

        const keys = (event: KeyboardEvent) => {
            if (event.key === 'ArrowDown') {
                setActive((index) => (index + 1) % entries.length);
            } else if (event.key === 'ArrowUp') {
                setActive(
                    (index) => (index - 1 + entries.length) % entries.length,
                );
            } else if (event.key === 'Enter') {
                choose(entries[Math.min(active, entries.length - 1)]);
            } else if (event.key === 'Escape') {
                setDismissed(slash.from);
            } else {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
        };

        const dom = editor.view.dom;
        dom.addEventListener('keydown', keys, true);

        return () => dom.removeEventListener('keydown', keys, true);
    }, [open, slash, entries, active, editor, context]);

    if (!open) {
        return null;
    }

    const coords = editor.view.coordsAtPos(slash.from);

    return (
        <div
            role="listbox"
            aria-label="Insert"
            className="bg-surface text-ink border-line shadow-float fixed z-50 max-h-72 w-72 overflow-y-auto rounded-lg border p-1"
            style={{ top: coords.bottom + 6, left: coords.left }}
        >
            {entries.map((entry, index) => (
                <button
                    key={entry.id}
                    type="button"
                    role="option"
                    aria-selected={index === active}
                    onMouseDown={(event) => {
                        event.preventDefault();
                        editor
                            .chain()
                            .focus()
                            .deleteRange({ from: slash.from, to: slash.to })
                            .run();
                        entry.run(context);
                    }}
                    className="aria-selected:bg-surface-3 grid w-full rounded-sm px-2 py-1.5 text-left text-[13px]"
                >
                    <span className="font-medium">{entry.label}</span>
                    {entry.description && (
                        <span className="text-ink-2 text-xs">
                            {entry.description}
                        </span>
                    )}
                </button>
            ))}
        </div>
    );
}
