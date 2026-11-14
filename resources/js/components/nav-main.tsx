import { Link } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { navIcon } from '@/lib/nav-icons';
import type { AdminNavChild, AdminNavEntry } from '@/types';

/** The headings of the menu's sections. Home has none. */
const headings: Record<AdminNavEntry['section'], string | null> = {
    home: null,
    content: 'Content',
    site: 'Site',
};

/** The class every top-level menu button shares. */
const buttonClass =
    'text-sidebar-foreground data-[active=true]:bg-sidebar-accent data-[active=true]:text-sidebar-foreground h-[34px] text-[13px] data-[active=true]:font-semibold data-[active=true]:shadow-[inset_2px_0_0_var(--sidebar-primary)]';

/**
 * What waits for the person at an entry (I-220, v9's badgeHtml): drawn above
 * 0, "99+" above 99, and said as "Comments, 12 need action".
 */
function NavBadge({ count }: { count: number }) {
    if (count <= 0) {
        return null;
    }

    return (
        <SidebarMenuBadge>
            <span className="sr-only">, </span>
            {count > 99 ? '99+' : count}
            <span className="sr-only">
                {count === 1 ? ' needs action' : ' need action'}
            </span>
        </SidebarMenuBadge>
    );
}

/**
 * The main menu, drawn from the server's `nav` in its section groups, after
 * a Pinned group when the person pinned entries. The server has already
 * sorted it as they arranged it and removed what they may not open; hidden
 * entries are left out here, and only here.
 */
export function NavMain({
    items: all,
    pinned = [],
}: {
    items: AdminNavEntry[];
    /** The pinned entries, in order; they also stay in their own sections. */
    pinned?: AdminNavChild[];
}) {
    const { currentUrl, isCurrentUrl } = useCurrentUrl();
    // A page is within an entry when it is the entry's page or under its address.
    const within = (href: string): boolean =>
        currentUrl === href || currentUrl.startsWith(`${href}/`);
    const items = all.filter((item) => !item.hidden);
    const sections = (
        Object.keys(headings) as AdminNavEntry['section'][]
    ).filter((section) => items.some((item) => item.section === section));

    return (
        // The menu's own landmark (v9's nav), for those who jump between regions; the
        // groups keep the gap the sidebar's content gave them.
        <nav aria-label="Main" className="flex flex-col gap-2">
            {pinned.length > 0 && (
                <SidebarGroup className="px-2 py-0">
                    <SidebarGroupLabel className="text-sidebar-muted text-[11px] font-medium">
                        Pinned
                    </SidebarGroupLabel>
                    <SidebarMenu>
                        {pinned.map((item) => {
                            const Icon = navIcon(item.icon);

                            return (
                                <SidebarMenuItem key={item.key}>
                                    <SidebarMenuButton
                                        asChild
                                        className={buttonClass}
                                        isActive={isCurrentUrl(item.href)}
                                        tooltip={{ children: item.label }}
                                    >
                                        <Link href={item.href} prefetch>
                                            <Icon />
                                            <span>{item.label}</span>
                                        </Link>
                                    </SidebarMenuButton>
                                    <NavBadge count={item.badge} />
                                </SidebarMenuItem>
                            );
                        })}
                    </SidebarMenu>
                </SidebarGroup>
            )}
            {sections.map((section) => (
                <SidebarGroup key={section} className="px-2 py-0">
                    {headings[section] && (
                        <SidebarGroupLabel className="text-sidebar-muted text-[11px] font-medium">
                            {headings[section]}
                        </SidebarGroupLabel>
                    )}
                    <SidebarMenu>
                        {items
                            .filter((item) => item.section === section)
                            .map((item) => {
                                const Icon = navIcon(item.icon);

                                return (
                                    <SidebarMenuItem key={item.key}>
                                        <SidebarMenuButton
                                            asChild
                                            // The current item: a muted fill, the
                                            // foreground, and a 2px accent bar on
                                            // its leading edge, never accent text.
                                            className={buttonClass}
                                            // A hub's entry is current on any of its pages, as Settings is on Settings › Media.
                                            isActive={
                                                isCurrentUrl(item.href) ||
                                                item.screens.some((screen) =>
                                                    within(screen.href),
                                                )
                                            }
                                            tooltip={{ children: item.label }}
                                        >
                                            <Link href={item.href} prefetch>
                                                <Icon />
                                                <span>{item.label}</span>
                                            </Link>
                                        </SidebarMenuButton>
                                        <NavBadge count={item.badge} />
                                        {/* Children only inside their parent's area, so the menu stays short (I-220). */}
                                        {item.children.length > 0 &&
                                            (within(item.href) ||
                                                item.children.some((child) =>
                                                    within(child.href),
                                                )) && (
                                                <SidebarMenuSub>
                                                    {item.children.map(
                                                        (child) => (
                                                            <SidebarMenuSubItem
                                                                key={child.key}
                                                            >
                                                                <SidebarMenuSubButton
                                                                    asChild
                                                                    isActive={within(
                                                                        child.href,
                                                                    )}
                                                                >
                                                                    <Link
                                                                        href={
                                                                            child.href
                                                                        }
                                                                        prefetch
                                                                    >
                                                                        {
                                                                            child.label
                                                                        }
                                                                    </Link>
                                                                </SidebarMenuSubButton>
                                                                <NavBadge
                                                                    count={
                                                                        child.badge
                                                                    }
                                                                />
                                                            </SidebarMenuSubItem>
                                                        ),
                                                    )}
                                                </SidebarMenuSub>
                                            )}
                                    </SidebarMenuItem>
                                );
                            })}
                    </SidebarMenu>
                </SidebarGroup>
            ))}
        </nav>
    );
}
