import { usePage } from '@inertiajs/react';
import { Content as MenuContent } from '@radix-ui/react-dropdown-menu';
import type { Editor } from '@tiptap/core';
import { ChevronDown, Sparkles } from 'lucide-react';
import { useState } from 'react';
import { useAssistant } from '@/components/editor/assistant/assistant-context';
import { selectionTarget } from '@/components/editor/assistant/selection-target';
import type { BlockTarget } from '@/components/editor/assistant/selection-target';
import {
    DropdownMenu,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { EditorAi } from '@/types';
import { REWRITE_PRESETS, REWRITE_TASK } from './rewrite-presets';

/** The menu's own sentences; the first is the scope, shown before anything runs. */
const REWRITE_WORDS = {
    scope: 'Rewrite the selected text',
    blocks: 'Select text within one paragraph',
    lineBreak: 'Select text without a line break',
};

/** Why a selection can't be rewritten. */
type Refusal = 'blocks' | 'line-break';

/**
 * The selection as a rewrite's target, with the spaces at either end left
 * out so they stay where they are; or why it can't be one: it runs across
 * blocks, sits outside a paragraph or heading, holds no words, or holds a
 * line break, which a one-line answer would lose (I-1305).
 */
function rewriteTarget(editor: Editor): BlockTarget | Refusal {
    const target = selectionTarget(editor);

    if (target === null) {
        return 'blocks';
    }

    const { from, to } = editor.state.selection;

    // Inside one block, only a hard break reads as a line feed here.
    if (editor.state.doc.textBetween(from, to, '\n', '\n').includes('\n')) {
        return 'line-break';
    }

    const text = target.text.trim();

    if (text === '') {
        return 'blocks';
    }

    // Plain text only from here, so each character is one unit of the offsets.
    const lead = target.text.length - target.text.trimStart().length;
    const trail = target.text.length - target.text.trimEnd().length;

    return { ...target, from: target.from + lead, to: target.to - trail, text };
}

/** The menu's first line: the scope, or why nothing can be chosen. */
function firstLine(refusal: Refusal | null): string {
    if (refusal === 'line-break') {
        return REWRITE_WORDS.lineBreak;
    }

    return refusal === 'blocks' ? REWRITE_WORDS.blocks : REWRITE_WORDS.scope;
}

/**
 * The selection menu's Rewrite button (I-1309): seven presets, each asking
 * the assistant to rewrite the selected text that way, its proposal in the
 * assistant pane. Drawn, with its divider, only when the editor's ai prop
 * offers the task: AI on, a model chosen, and the person may ask.
 */
export function RewriteMenu({ editor }: { editor: Editor }) {
    const ai = usePage<{ ai?: EditorAi | null }>().props.ai ?? null;

    if (ai === null || !ai.tasks.some((task) => task.key === REWRITE_TASK)) {
        return null;
    }

    return (
        <>
            <span aria-hidden="true" className="bg-line mx-0.5 h-5 w-px" />
            <RewriteDropdown editor={editor} />
        </>
    );
}

function RewriteDropdown({ editor }: { editor: Editor }) {
    const { ask } = useAssistant();
    const [target, setTarget] = useState<BlockTarget | Refusal>('blocks');
    const refusal = typeof target === 'string' ? target : null;

    const choose = (variant: string, label: string) => {
        if (typeof target === 'string') {
            return;
        }

        void ask({
            task: REWRITE_TASK,
            scope: `Rewrite · ${label}: the selected text`,
            before: target.text,
            target,
            options: { variant },
        });
    };

    return (
        <DropdownMenu
            onOpenChange={(open) => {
                // Read once as it opens: the selection can't change while the menu has focus.
                if (open) {
                    setTarget(rewriteTarget(editor));
                }
            }}
        >
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    data-test="rewrite-menu"
                    className="hover:bg-surface-3 focus-visible:ring-ring inline-flex h-[30px] items-center gap-1 rounded-md px-2 text-[13px] outline-none focus-visible:ring-2"
                >
                    <Sparkles aria-hidden="true" className="size-4" />
                    Rewrite
                    <ChevronDown aria-hidden="true" className="size-3" />
                </button>
            </DropdownMenuTrigger>
            {/* In place, not portalled: focus stays inside the selection menu, which hides when it leaves. */}
            <MenuContent
                align="start"
                sideOffset={4}
                className="bg-surface text-ink shadow-float z-50 min-w-[8rem] rounded-md border p-1"
            >
                <DropdownMenuLabel
                    data-test="rewrite-scope"
                    className="text-ink-2 text-xs font-normal"
                >
                    {firstLine(refusal)}
                </DropdownMenuLabel>
                {REWRITE_PRESETS.map((preset) => (
                    <DropdownMenuItem
                        key={preset.value}
                        data-test={`rewrite-${preset.value}`}
                        disabled={refusal !== null}
                        onSelect={() => choose(preset.value, preset.label)}
                    >
                        {preset.label}
                    </DropdownMenuItem>
                ))}
            </MenuContent>
        </DropdownMenu>
    );
}
