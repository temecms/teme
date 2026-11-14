import { usePage } from '@inertiajs/react';
import {
    ExternalLink,
    PanelLeftClose,
    PanelLeftOpen,
    SlidersHorizontal,
} from 'lucide-react';
import { useState } from 'react';
import { SidebarBrand } from '@/components/brand';
import { NavMain } from '@/components/nav-main';
import { PersonaliseMenuDialog } from '@/components/personalise-menu-dialog';
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
import { toUrl } from '@/lib/utils';
import { home } from '@/routes';
import type { AdminNavChild } from '@/types';

/** The foot's rows: muted until hovered, 34px tall. */
const footRow =
    'text-sidebar-muted hover:text-sidebar-foreground h-[34px] text-[13px]';

/** Collapses the sidebar to icons and back: the same toggle as the keyboard shortcut. */
function CollapseMenu({ className }: { className: string }) {
    const { state, toggleSidebar } = useSidebar();
    const collapsed = state === 'collapsed';

    return (
        <SidebarMenuButton
            type="button"
            className={className}
            tooltip={{ children: 'Expand the menu' }}
            data-test="collapse-menu"
            aria-pressed={collapsed}
            onClick={toggleSidebar}
        >
            {collapsed ? <PanelLeftOpen /> : <PanelLeftClose />}
            <span>Collapse menu</span>
        </SidebarMenuButton>
    );
}

export function AppSidebar() {
    // The menu comes from the server's registry, already filtered to what
    // this person may open and sorted (NavigationServiceProvider).
    const { nav, navPinned, preferences } = usePage().props;
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
            <SidebarHeader className="border-sidebar-border h-[52px] border-b p-0">
                <SidebarBrand />
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={nav} pinned={pinned} />
            </SidebarContent>

            <SidebarFooter className="border-sidebar-border border-t p-2">
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
                            tooltip={{ children: 'Personalise menu' }}
                            data-test="personalise-menu"
                            onClick={() => setPersonalising(true)}
                        >
                            <SlidersHorizontal />
                            <span>Personalise menu</span>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                    <SidebarMenuItem className="max-md:hidden">
                        <CollapseMenu className={footRow} />
                    </SidebarMenuItem>
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
