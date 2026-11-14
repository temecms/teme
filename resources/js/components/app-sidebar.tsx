import { usePage } from '@inertiajs/react';
import {
    ExternalLink,
    PanelLeftClose,
    PanelLeftOpen,
    Search,
    SlidersHorizontal,
} from 'lucide-react';
import { useState } from 'react';
import { SidebarBrand } from '@/components/brand';
import { NavMain } from '@/components/nav-main';
import { PersonaliseMenuDialog } from '@/components/personalise-menu-dialog';
import { Kbd } from '@/components/ui/kbd';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { __ } from '@/lib/i18n';
import { toUrl } from '@/lib/utils';
import { home } from '@/routes';
import type { AdminNavChild } from '@/types';

/** The foot's rows: quiet until hovered. */
const footRow = 'text-ink-3 hover:text-ink-2';

/** Whether the keyboard's command key is ⌘, for the shortcut's hint. */
function isMac(): boolean {
    return (
        typeof navigator !== 'undefined' &&
        /Mac|iPhone|iPad/.test(navigator.userAgent)
    );
}

/** Folds the full menu to the rail and back on wide screens: the same toggle as the keyboard shortcut. */
function FoldMenu({ className }: { className: string }) {
    const { state, toggleSidebar } = useSidebar();
    const folded = state === 'collapsed';

    return (
        <SidebarMenuButton
            type="button"
            className={className}
            tooltip={{ children: __('Unfold the menu') }}
            data-test="collapse-menu"
            aria-pressed={folded}
            onClick={toggleSidebar}
        >
            {folded ? <PanelLeftOpen /> : <PanelLeftClose />}
            <span>{__('Fold the menu')}</span>
        </SidebarMenuButton>
    );
}

/** Slides the full menu over the page from the rail, between 768 and 1023 pixels. */
function OpenMenu({ className }: { className: string }) {
    const { overlayOpen, setOverlayOpen } = useSidebar();

    return (
        <SidebarMenuButton
            type="button"
            className={className}
            tooltip={{ children: __('Open the menu') }}
            data-test="open-menu"
            aria-expanded={overlayOpen}
            onClick={() => setOverlayOpen(!overlayOpen)}
        >
            <PanelLeftOpen />
            <span>{__('Open the menu')}</span>
        </SidebarMenuButton>
    );
}

/** Search or jump to…: opens the command palette, not a second search. */
function SearchButton() {
    return (
        <SidebarMenu className="px-3 pb-2 group-data-[collapsible=icon]:items-center group-data-[collapsible=icon]:px-0">
            <SidebarMenuItem>
                <SidebarMenuButton
                    type="button"
                    data-test="palette-button"
                    aria-keyshortcuts="Control+K Meta+K"
                    tooltip={{ children: __('Search or jump to…') }}
                    onClick={() =>
                        window.dispatchEvent(new Event('teme:palette'))
                    }
                    className="bg-surface-3 text-ink-3 hover:bg-surface-4 font-normal"
                >
                    <Search />
                    <span className="flex-1">{__('Search or jump to…')}</span>
                    <Kbd className="group-data-[collapsible=icon]:hidden">
                        {isMac() ? __('⌘K') : __('Ctrl K')}
                    </Kbd>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}

export function AppSidebar() {
    // The menu comes from the server's registry, already filtered to what
    // this person may open and sorted (NavigationServiceProvider).
    const { nav, navPinned, preferences } = usePage().props;
    const { width } = useSidebar();
    const [personalising, setPersonalising] = useState(false);
    // A pin may name a child entry, so both levels are looked up.
    const entries = new Map<string, AdminNavChild>(
        nav.flatMap((entry) => [
            [entry.key, entry] as const,
            ...entry.children.map((child) => [child.key, child] as const),
        ]),
    );
    const pinned = navPinned.flatMap((key) => {
        const entry = entries.get(key);

        return entry === undefined ? [] : [entry];
    });

    return (
        <Sidebar collapsible="icon">
            <SidebarHeader className="gap-0 p-0">
                <SidebarBrand />
                <SearchButton />
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={nav} pinned={pinned} />
            </SidebarContent>

            <SidebarFooter className="px-3 py-2">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            asChild
                            className={footRow}
                            tooltip={{ children: 'View site' }}
                        >
                            <a
                                href={toUrl(home())}
                                target="_blank"
                                rel="noopener noreferrer"
                                data-test="view-site"
                            >
                                <ExternalLink />
                                <span>
                                    View site
                                    <span className="sr-only">
                                        , opens in a new tab
                                    </span>
                                </span>
                            </a>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            type="button"
                            className={footRow}
                            tooltip={{ children: __('Customise menu') }}
                            data-test="personalise-menu"
                            onClick={() => setPersonalising(true)}
                        >
                            <SlidersHorizontal />
                            <span>{__('Customise menu')}</span>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                    {width === 'wide' && (
                        <SidebarMenuItem>
                            <FoldMenu className={footRow} />
                        </SidebarMenuItem>
                    )}
                    {width === 'compact' && (
                        <SidebarMenuItem>
                            <OpenMenu className={footRow} />
                        </SidebarMenuItem>
                    )}
                </SidebarMenu>
                {personalising && (
                    <PersonaliseMenuDialog
                        nav={nav}
                        pinned={navPinned}
                        storedOrder={preferences['nav.order'] ?? []}
                        open
                        onOpenChange={setPersonalising}
                    />
                )}
            </SidebarFooter>
        </Sidebar>
    );
}
