import { Link } from '@inertiajs/react';
import {
    ArrowLeft,
    EllipsisVertical,
    FileText,
    History,
    Maximize2,
    Minimize2,
    PanelRight,
    Share2,
    Sparkles,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/** A panel the top bar can open in the rail (I-234). */
export type EditorPanel = {
    id: string;
    owner: string;
    label: string;
    icon: typeof FileText;
    /** False while the part that fills it is not built: nothing is drawn for it. */
    available: boolean;
};

const PINNED_KEY = 'teme.editor.unpinnedPanels';

// Later (I-234): plugin panels pinned per person, from their manifests.
export const CORE_PANELS: EditorPanel[] = [
    {
        id: 'overview',
        owner: 'core',
        label: 'Document overview',
        icon: FileText,
        available: true,
    },
    {
        id: 'history',
        owner: 'core',
        label: 'History',
        icon: History,
        available: true,
    },
    // Shown only when the editor's ai prop offers a task (I-321).
    {
        id: 'assistant',
        owner: 'core',
        label: 'Assistant',
        icon: Sparkles,
        available: true,
    },
    // Preview links, for anyone who may edit the item; never a theme region's (I-1316).
    {
        id: 'share',
        owner: 'core',
        label: 'Share',
        icon: Share2,
        available: true,
    },
];

function storedUnpinned(): string[] {
    try {
        return JSON.parse(
            window.localStorage.getItem(PINNED_KEY) ?? '[]',
        ) as string[];
    } catch {
        return [];
    }
}

/**
 * The editor's 48px top bar: the way back, the save state and who else is
 * here on the left; on the right the panel icons, a menu listing every panel
 * with Pin and Unpin, the rail's toggle and Focus. Each icon opens its pane
 * in the rail, one at a time.
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
    const [unpinned, setUnpinned] = useState<string[]>([]);

    useEffect(() => setUnpinned(storedUnpinned()), []);

    const panels = CORE_PANELS.filter((panel) =>
        panel.id === 'assistant'
            ? assistant
            : panel.id === 'share'
              ? canShare
              : panel.available,
    );

    const togglePin = (id: string) => {
        const next = unpinned.includes(id)
            ? unpinned.filter((panel) => panel !== id)
            : [...unpinned, id];
        setUnpinned(next);

        try {
            window.localStorage.setItem(PINNED_KEY, JSON.stringify(next));
        } catch {
            // A private window: the choice lasts for this page only.
        }
    };

    return (
        // Below 640px the header keeps to its essentials so nothing is pushed off
        // the screen: pinned panels stay in the ⋮ menu, and Focus is its icon.
        <header className="border-line bg-page flex h-12 min-w-0 shrink-0 items-center gap-1.5 border-b px-3 sm:gap-3 sm:px-4">
            <Link
                href={backHref}
                className="text-ink-2 hover:text-ink inline-flex items-center gap-1.5 text-[13px]"
            >
                <ArrowLeft className="size-4" />
                {backLabel}
            </Link>
            {statusChip}
            {saveState}
            {presence}
            <span className="flex-1" />
            {panels
                .filter((panel) => !unpinned.includes(panel.id))
                .map((panel) => (
                    <Button
                        key={panel.id}
                        variant="ghost"
                        size="icon"
                        aria-label={panel.label}
                        aria-pressed={activePane === panel.id}
                        title={panel.label}
                        onClick={() => onTogglePane(panel.id)}
                        className="aria-pressed:bg-surface-2 max-sm:hidden"
                    >
                        <panel.icon />
                    </Button>
                ))}
            {panels.length > 0 && (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label="All panels"
                        >
                            <EllipsisVertical />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuLabel>Panels</DropdownMenuLabel>
                        {panels.map((panel) => (
                            <DropdownMenuItem
                                key={panel.id}
                                onSelect={() => onTogglePane(panel.id)}
                            >
                                {panel.label}
                            </DropdownMenuItem>
                        ))}
                        <DropdownMenuSeparator />
                        {panels.map((panel) => (
                            <DropdownMenuItem
                                key={`pin-${panel.id}`}
                                onSelect={() => togglePin(panel.id)}
                            >
                                {unpinned.includes(panel.id) ? 'Pin' : 'Unpin'}{' '}
                                {panel.label}
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>
            )}
            <Button
                variant="ghost"
                size="icon"
                aria-label={railOpen ? 'Hide settings' : 'Show settings'}
                aria-pressed={railOpen}
                title={railOpen ? 'Hide settings' : 'Show settings'}
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
                    {focus ? 'Leave focus' : 'Focus'}
                </span>
            </Button>
        </header>
    );
}
