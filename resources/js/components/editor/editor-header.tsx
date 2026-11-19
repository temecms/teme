import { Link } from '@inertiajs/react';
import {
    ArrowLeft,
    Check,
    ChevronsUpDown,
    FileText,
    History,
    List,
    ListTree,
    Minimize2,
    PanelRight,
    ScanEye,
    Share2,
    Sparkles,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { SiteStatus } from '@/components/header/site-status';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { __ } from '@/lib/i18n';
import { edit } from '@/routes/content';
import { useLinkTargets } from './use-link-targets';
import { offeredTools, PINNABLE_TOOLS, usePins } from './tools/editor-tools';
import type { EditorTool } from './tools/editor-tools';
import { ToolsLauncher } from './tools/tools-launcher';

/** What each of Teme's own tools draws in its pane. */
export type ToolPanes = {
    overview: () => ReactNode;
    history: () => ReactNode;
    assistant: () => ReactNode;
    share: () => ReactNode;
};

/**
 * Teme's own tools. Their ids stay as the panes have always been named;
 * `assistant` and `canShare` say whether this item offers those two.
 */
export function coreTools(
    panes: ToolPanes,
    { assistant, canShare }: { assistant: boolean; canShare: boolean },
): EditorTool[] {
    return [
        {
            id: 'overview',
            owner: 'core',
            label: __('Outline'),
            icon: ListTree,
            size: 'pane',
            fixed: true,
            available: true,
            render: panes.overview,
        },
        {
            id: 'history',
            owner: 'core',
            label: __('History'),
            icon: History,
            size: 'pane',
            openLarger: true,
            fixed: true,
            available: true,
            render: panes.history,
        },
        // Shown only when the editor's ai prop offers a task (I-321).
        {
            id: 'assistant',
            owner: 'core',
            label: __('Assistant'),
            icon: Sparkles,
            size: 'pane',
            available: assistant,
            render: panes.assistant,
        },
        // Preview links, for anyone who may edit the item; never a theme region's (I-1316).
        {
            id: 'share',
            owner: 'core',
            label: __('Share'),
            icon: Share2,
            size: 'pane',
            available: canShare,
            render: panes.share,
        },
    ];
}

/** A crumb's look in the editor's bar, as the admin's crumbs draw them (4.31). */
const CRUMB =
    'text-body text-ink-2 hover:bg-surface-3 hover:text-ink focus-visible:outline-ring inline-flex h-8 min-w-0 items-center gap-1 rounded-sm px-2 font-medium focus-visible:outline-2 focus-visible:-outline-offset-2';

/** Where the editor is: the list's switch, or a plain link back to it. */
export type EditorPlace = {
    /** The list's name: "Posts", "Pages", "Regions". */
    label: string;
    href: string;
    /** The item's type, whose recent items the switch lists; null for a theme region, which has none. */
    type: string | null;
    /** The item being edited, marked in the switch. */
    current: string;
};

/**
 * The list's crumb, "Posts ⌃": a menu of the type's items changed most
 * recently, the one being edited ticked, then the whole list. The items are
 * asked for as the menu opens, from the link search's most recent ones.
 */
function PlaceSwitch({ place }: { place: EditorPlace }) {
    const [open, setOpen] = useState(false);
    const recent = useLinkTargets('', open && place.type !== null).filter(
        (target) => target.type === place.type,
    );

    if (place.type === null) {
        return (
            <Link href={place.href} className={CRUMB}>
                {place.label}
            </Link>
        );
    }

    return (
        <DropdownMenu open={open} onOpenChange={setOpen}>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    data-test="editor-place"
                    className={CRUMB}
                    aria-label={__('Switch :list', { list: place.label })}
                >
                    {place.label}
                    <ChevronsUpDown aria-hidden className="text-ink-3" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-80">
                <DropdownMenuLabel>
                    {__('Recent :list', { list: place.label.toLowerCase() })}
                </DropdownMenuLabel>
                {recent.slice(0, 7).map((target) => (
                    <DropdownMenuItem key={target.id} asChild>
                        <Link href={edit(target.id).url}>
                            <FileText aria-hidden />
                            <span className="min-w-0 flex-1 truncate">
                                {target.title.trim() || __('Untitled')}
                            </span>
                            {target.id === place.current && (
                                <Check
                                    aria-hidden
                                    className="text-accent-ink"
                                />
                            )}
                        </Link>
                    </DropdownMenuItem>
                ))}
                <DropdownMenuSeparator />
                <DropdownMenuItem asChild>
                    <Link href={place.href}>
                        <List aria-hidden />
                        {__('All :list', { list: place.label.toLowerCase() })}
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * The editor's own top bar, in place of the admin's
 * (docs/architecture/design-system.md, 4.39): on the left the crumbs, the
 * list's switch and the item's title, its status and the save state; on the
 * right the site status's count, Outline and History, the pinned tools,
 * Tools with every tool, then Focus and the panel's toggle. A theme region's
 * bar keeps only what a region has: no Tools and no Focus. Each tool opens
 * its pane in the rail, one at a time. Below 768px the crumbs give way to a
 * way back, and below 1024px the tools wait in Tools.
 */
export function EditorHeader({
    place,
    title,
    statusChip,
    saveState,
    presence,
    activePane,
    onTogglePane,
    railOpen,
    railLabel,
    onToggleRail,
    focus,
    onToggleFocus,
    canShare,
    assistant = false,
    region = false,
    panes,
    pluginTools = [],
    live,
}: {
    place: EditorPlace;
    /** The item's title as typed, shown after the crumbs. */
    title: string;
    /** The item's state, as a pill after the crumbs (I-1316). */
    statusChip?: ReactNode;
    saveState?: ReactNode;
    presence?: ReactNode;
    activePane: string | null;
    onTogglePane: (id: string) => void;
    railOpen: boolean;
    /** The rail's name: "Post settings". */
    railLabel: string;
    onToggleRail: () => void;
    focus: boolean;
    onToggleFocus: () => void;
    /** Whether this person may share the article: anyone who may edit it. */
    canShare: boolean;
    /** Whether the editor's ai prop offers a task, so the assistant shows (I-321). */
    assistant?: boolean;
    /** A theme region: no Tools and no Focus. */
    region?: boolean;
    /** What each tool draws in its pane. */
    panes: ToolPanes;
    /** The plugins' panels this person sees on this item, each pinnable. */
    pluginTools?: EditorTool[];
    /** Live preview's toggle, where the item has a page to show. */
    live?: ReactNode;
}) {
    const pinnable = [...PINNABLE_TOOLS, ...pluginTools.map((tool) => tool.id)];
    const { pins, saving, refused, toggle } = usePins(pinnable);
    const tools = offeredTools([
        ...coreTools(panes, { assistant, canShare }),
        ...pluginTools,
    ]);
    const icon = (tool: EditorTool) => (
        <Button
            key={tool.id}
            variant="ghost"
            size="icon"
            aria-label={tool.label}
            aria-pressed={activePane === tool.id}
            title={tool.label}
            onClick={() => onTogglePane(tool.id)}
            className="text-ink-2 aria-pressed:bg-surface-3"
        >
            <tool.icon />
        </Button>
    );
    const fixed = tools.filter((tool) => tool.fixed === true);
    const pinned = pins
        .map((id) => tools.find((tool) => tool.id === id))
        .filter((tool): tool is EditorTool => tool !== undefined);
    const shownTitle = title.trim() === '' ? __('Untitled') : title;

    return (
        <header
            data-slot="editor-bar"
            className="bg-page flex h-(--top-h) min-w-0 shrink-0 items-center gap-3 px-6 max-md:gap-1 max-md:px-2"
        >
            <Button
                variant="ghost"
                size="icon"
                asChild
                className="text-ink-2 md:hidden"
            >
                <Link
                    href={place.href}
                    aria-label={__('Back to :list', { list: place.label })}
                >
                    <ArrowLeft aria-hidden />
                </Link>
            </Button>
            <Breadcrumb
                aria-label={__('Where you are')}
                data-test="crumbs"
                className="min-w-0 max-md:hidden"
            >
                <BreadcrumbList className="flex-nowrap gap-1">
                    <BreadcrumbItem className="shrink-0 gap-0">
                        <PlaceSwitch place={place} />
                    </BreadcrumbItem>
                    <BreadcrumbSeparator />
                    <BreadcrumbItem className="min-w-0 gap-0">
                        <BreadcrumbPage
                            data-test="editor-crumb-title"
                            title={shownTitle}
                            className="text-body text-ink block max-w-105 min-w-40 truncate px-1 font-semibold max-xl:min-w-0"
                        >
                            {shownTitle}
                        </BreadcrumbPage>
                    </BreadcrumbItem>
                </BreadcrumbList>
            </Breadcrumb>
            <span className="contents max-md:hidden">{statusChip}</span>
            {saveState}
            {presence}
            <div
                data-slot="editor-tools"
                className="ms-auto flex shrink-0 items-center"
            >
                <span className="max-lg:hidden">
                    <SiteStatus />
                </span>
                <div
                    role="group"
                    aria-label={__('Teme')}
                    className="flex items-center gap-0.5 px-1 max-lg:hidden"
                >
                    {fixed.map(icon)}
                </div>
                {!region && (
                    <div
                        role="group"
                        aria-label={__('Tools')}
                        className="border-line flex items-center gap-0.5 border-s px-1 max-lg:border-s-0"
                    >
                        <span className="flex items-center gap-0.5 max-xl:hidden">
                            {pinned.map(icon)}
                        </span>
                        <ToolsLauncher
                            tools={tools}
                            pins={pins}
                            pinnable={pinnable}
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
                )}
                <div
                    role="group"
                    aria-label={__('View')}
                    className="border-line flex items-center gap-0.5 border-s ps-1"
                >
                    {live}
                    {!region && (
                        <Button
                            variant="ghost"
                            size="icon"
                            data-test="editor-focus"
                            aria-label={__('Focus mode')}
                            aria-pressed={focus}
                            title={__('Focus mode (Esc leaves)')}
                            onClick={onToggleFocus}
                            className="text-ink-2 aria-pressed:bg-surface-3"
                        >
                            {focus ? <Minimize2 /> : <ScanEye />}
                        </Button>
                    )}
                    <Button
                        variant="ghost"
                        size="icon"
                        data-test="rail-toggle"
                        aria-label={__(':name panel', { name: railLabel })}
                        aria-pressed={railOpen}
                        title={
                            railOpen
                                ? __('Hide :name', {
                                      name: railLabel.toLowerCase(),
                                  })
                                : __('Show :name', {
                                      name: railLabel.toLowerCase(),
                                  })
                        }
                        onClick={onToggleRail}
                        className="text-ink-2 aria-pressed:bg-accent-soft aria-pressed:text-accent-ink"
                    >
                        <PanelRight />
                    </Button>
                </div>
            </div>
        </header>
    );
}
