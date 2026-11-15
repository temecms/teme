import { Link } from '@inertiajs/react';
import {
    ArrowLeft,
    History,
    ListTree,
    Maximize2,
    Minimize2,
    PanelRight,
    Share2,
    Sparkles,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { __ } from '@/lib/i18n';
import { FIXED_TOOLS, PINNABLE_TOOLS, usePins } from './tools/editor-tools';
import type { EditorTool } from './tools/editor-tools';
import { ToolsLauncher } from './tools/tools-launcher';

/** Teme's own tools, before the ones this item does not offer are left out. */
function coreTools(): EditorTool[] {
    return [
        {
            id: 'outline',
            owner: 'core',
            label: __('Outline'),
            icon: ListTree,
            size: 'pane',
            available: true,
        },
        {
            id: 'history',
            owner: 'core',
            label: __('History'),
            icon: History,
            size: 'pane',
            available: true,
        },
        // Shown only when the editor's ai prop offers a task (I-321).
        {
            id: 'assistant',
            owner: 'core',
            label: __('Assistant'),
            icon: Sparkles,
            size: 'pane',
            available: true,
        },
        // Preview links, for anyone who may edit the item; never a theme region's (I-1316).
        {
            id: 'share',
            owner: 'core',
            label: __('Share'),
            icon: Share2,
            size: 'pane',
            available: true,
        },
    ];
}

/**
 * The editor's top bar: the way back, the save state and who else is here
 * on the left; on the right Outline and History, the tools this person
 * pinned, Tools with every tool (docs/architecture/design-system.md, 4.39),
 * the rail's toggle and Focus. Each tool opens its pane in the rail, one at
 * a time.
 */
export function EditorHeader({
    backHref,
    backLabel,
    statusChip,
    saveState,
    presence,
    activePane,
    onTogglePane,
    railOpen,
    onToggleRail,
    focus,
    onToggleFocus,
    canShare,
    assistant = false,
}: {
    backHref: string;
    backLabel: string;
    /** The item's state, as a chip beside the way back (I-1316). */
    statusChip?: ReactNode;
    saveState?: ReactNode;
    presence?: ReactNode;
    activePane: string | null;
    onTogglePane: (id: string) => void;
    railOpen: boolean;
    onToggleRail: () => void;
    focus: boolean;
    onToggleFocus: () => void;
    /** Whether this person may share the article: anyone who may edit it. */
    canShare: boolean;
    /** Whether the editor's ai prop offers a task, so the assistant shows (I-321). */
    assistant?: boolean;
}) {
    const { pins, saving, refused, toggle } = usePins();
    const tools = coreTools().filter((tool) =>
        tool.id === 'assistant'
            ? assistant
            : tool.id === 'share'
              ? canShare
              : true,
    );
    const icon = (tool: EditorTool) => (
        <Button
            key={tool.id}
            variant="ghost"
            size="icon"
            aria-label={tool.label}
            aria-pressed={activePane === tool.id}
            title={tool.label}
            onClick={() => onTogglePane(tool.id)}
            className="aria-pressed:bg-surface-2"
        >
            <tool.icon />
        </Button>
    );
    const fixed = tools.filter((tool) => FIXED_TOOLS.includes(tool.id));
    const pinned = pins
        .map((id) => tools.find((tool) => tool.id === id))
        .filter((tool): tool is EditorTool => tool !== undefined);

    return (
        // Below 640px the header keeps to its essentials so nothing is pushed off
        // the screen: the tools stay in Tools, and Focus is its icon.
        <header className="border-line bg-chrome flex h-(--top-h) min-w-0 shrink-0 items-center gap-2 border-b px-3 sm:gap-3 sm:px-4">
            <Link
                href={backHref}
                className="text-ink-2 hover:text-ink text-small inline-flex items-center gap-2"
            >
                <ArrowLeft className="size-4" />
                {backLabel}
            </Link>
            {statusChip}
            {saveState}
            {presence}
            <span className="flex-1" />
            {/* Below 640px only Tools stays: every tool is in it. */}
            <div data-slot="editor-tools" className="flex items-center gap-1">
                <div className="flex items-center gap-1 max-sm:hidden">
                    {fixed.map(icon)}
                    {pinned.length > 0 && (
                        <Separator
                            orientation="vertical"
                            className="mx-1 h-5"
                        />
                    )}
                    {pinned.map(icon)}
                </div>
                <ToolsLauncher
                    tools={tools}
                    pins={pins}
                    pinnable={PINNABLE_TOOLS}
                    saving={saving}
                    refused={refused}
                    onOpen={(id) => {
                        if (activePane !== id) {
                            onTogglePane(id);
                        }
                    }}
                    onTogglePin={toggle}
                />
            </div>
            <Button
                variant="ghost"
                size="icon"
                aria-label={
                    railOpen ? __('Hide settings') : __('Show settings')
                }
                aria-pressed={railOpen}
                title={railOpen ? __('Hide settings') : __('Show settings')}
                onClick={onToggleRail}
                className="aria-pressed:bg-surface-2"
            >
                <PanelRight />
            </Button>
            <Button
                variant="ghost"
                size="sm"
                aria-pressed={focus}
                onClick={onToggleFocus}
            >
                {focus ? <Minimize2 /> : <Maximize2 />}
                <span className="max-sm:sr-only">
                    {focus ? __('Leave focus') : __('Focus')}
                </span>
            </Button>
        </header>
    );
}
