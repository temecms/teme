import type { JSONContent } from '@tiptap/core';
import { Placeholder } from '@tiptap/extensions';
import { StarterKit } from '@tiptap/starter-kit';
import { EditorContent, useEditor, useEditorState } from '@tiptap/react';
import { Bold, Italic, List, ListOrdered } from 'lucide-react';
import { useEffect, useRef } from 'react';
import { Button } from '@/components/ui/button';
import { __ } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { TemeLink } from '../schema';

/** A card's rich text as stored (app/Domain/Cards/CardProse.php): a small document. */
export type CardProseValue = JSONContent;

/** An empty piece, as the server's CardProse::blank() writes it. */
export function blankProse(): CardProseValue {
    return { type: 'doc', content: [{ type: 'paragraph' }] };
}

/** Its words, for a card's warnings and its preview when collapsed. */
export function proseText(value: unknown): string {
    const walk = (node: unknown): string => {
        if (node === null || typeof node !== 'object') {
            return '';
        }

        const { text, content } = node as { text?: unknown; content?: unknown };

        return (
            (typeof text === 'string' ? text : '') +
            (Array.isArray(content) ? content.map(walk).join(' ') : '')
        );
    };

    return walk(value).replace(/\s+/g, ' ').trim();
}

/**
 * Short rich text written in place inside a card, such as an FAQ's answer
 * or a step: paragraphs, lists, bold, italic and links, nothing more, as
 * the server's CardProse keeps them. Typing here never reaches the
 * article's own editor; each change is written to the card's attributes.
 */
export function CardProse({
    value,
    onChange,
    label,
    placeholder,
}: {
    value: unknown;
    onChange: (value: CardProseValue) => void;
    label: string;
    placeholder?: string;
}) {
    const written = useRef<string>(JSON.stringify(value ?? null));
    const editor = useEditor({
        extensions: [
            StarterKit.configure({
                heading: false,
                blockquote: false,
                codeBlock: false,
                code: false,
                horizontalRule: false,
                strike: false,
                underline: false,
                link: false,
                dropcursor: false,
                gapcursor: false,
            }),
            TemeLink,
            Placeholder.configure({ placeholder: placeholder ?? '' }),
        ],
        content:
            value !== null && typeof value === 'object'
                ? (value as CardProseValue)
                : blankProse(),
        editorProps: {
            attributes: {
                class: 'teme-prose min-h-10 px-3 py-2 outline-none focus-visible:ring-0',
                'aria-label': label,
                'aria-multiline': 'true',
                role: 'textbox',
            },
        },
        onUpdate: ({ editor: changed }) => {
            const next = changed.getJSON();

            written.current = JSON.stringify(next);
            onChange(next);
        },
    });

    // A change from outside, such as undo in the article, is shown here too.
    useEffect(() => {
        const incoming = JSON.stringify(value ?? null);

        if (editor !== null && incoming !== written.current) {
            written.current = incoming;
            editor.commands.setContent(
                value !== null && typeof value === 'object'
                    ? (value as CardProseValue)
                    : blankProse(),
                { emitUpdate: false },
            );
        }
    }, [editor, value]);

    const marks = useEditorState({
        editor,
        selector: ({ editor: current }) => ({
            bold: current?.isActive('bold') ?? false,
            italic: current?.isActive('italic') ?? false,
            bulletList: current?.isActive('bulletList') ?? false,
            orderedList: current?.isActive('orderedList') ?? false,
        }),
    });

    const tools = [
        {
            key: 'bold',
            label: __('Bold'),
            icon: Bold,
            run: () => editor?.chain().focus().toggleBold().run(),
        },
        {
            key: 'italic',
            label: __('Italic'),
            icon: Italic,
            run: () => editor?.chain().focus().toggleItalic().run(),
        },
        {
            key: 'bulletList',
            label: __('Bulleted list'),
            icon: List,
            run: () => editor?.chain().focus().toggleBulletList().run(),
        },
        {
            key: 'orderedList',
            label: __('Numbered list'),
            icon: ListOrdered,
            run: () => editor?.chain().focus().toggleOrderedList().run(),
        },
    ] as const;

    return (
        <div className="bg-surface-2 focus-within:ring-ring rounded-sm focus-within:ring-2">
            <div
                role="toolbar"
                aria-label={__(':label formatting', { label })}
                className="flex gap-0.5 px-1 pt-1"
            >
                {tools.map((tool) => (
                    <Button
                        key={tool.key}
                        type="button"
                        variant="ghost"
                        size="icon-xs"
                        aria-label={tool.label}
                        aria-pressed={marks?.[tool.key] ?? false}
                        className={cn(
                            marks?.[tool.key] && 'bg-surface text-ink',
                        )}
                        onMouseDown={(event) => event.preventDefault()}
                        onClick={tool.run}
                    >
                        <tool.icon />
                    </Button>
                ))}
            </div>
            <EditorContent editor={editor} />
        </div>
    );
}
