import { Link } from '@inertiajs/react';
import { ChevronDown, Plug } from 'lucide-react';
import { useState } from 'react';
import { showToast } from '@/components/flash-toast';
import {
    SidebarGroup,
    SidebarGroupFold,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
    SidebarMenuSubSoon,
    useSidebar,
} from '@/components/ui/sidebar';
import { SoonTag } from '@/components/ui/soon-tag';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { __ } from '@/lib/i18n';
import { navIcon } from '@/lib/nav-icons';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { AdminNavChild, AdminNavEntry } from '@/types';

/** The menu's sections, in order. */
const SECTIONS: AdminNavEntry['section'][] = ['home', 'content', 'site'];

/** A section's heading, looked up while drawing. Home has none. */
function headingOf(section: AdminNavEntry['section']): string | null {
    if (section === 'home') {
        return null;
    }

    return section === 'content' ? __('Content') : __('Site');
}

/** Where a section's fold is remembered, one key per section. */
const foldKey = (group: string): string => `teme.nav.folded.${group}`;

/** Whether a section was left folded; storage may be missing or refused. */
function readFolded(group: string): boolean {
    try {
        return window.localStorage.getItem(foldKey(group)) === '1';
    } catch {
        return false;
    }
}

/** Remembers a section's fold, when storage lets it. */
function writeFolded(group: string, folded: boolean): void {
    try {
        window.localStorage.setItem(foldKey(group), folded ? '1' : '0');
    } catch {
        // Storage refused: the fold lasts until the page is left.
    }
}

/** Where a hub's open list is remembered. */
const hubKey = (key: string): string => `teme.nav.hub.${key}`;

function readHubOpen(key: string): boolean {
    try {
        return window.localStorage.getItem(hubKey(key)) === '1';
    } catch {
        return false;
    }
}

function writeHubOpen(key: string, open: boolean): void {
    try {
        window.localStorage.setItem(hubKey(key), open ? '1' : '0');
    } catch {
        // Storage refused: the open state lasts until the page is left.
    }
}

/** Says that a planned entry opens nothing yet. */
function sayPlanned(label: string): void {
    showToast({
        type: 'info',
        message: __(':label is planned and not built yet.', { label }),
    });
}

/** A planned entry stays clickable, to say so, and stays quiet. */
const SOON =
    'text-ink-3 aria-disabled:pointer-events-auto aria-disabled:opacity-100';

/**
 * What waits for the person at an entry: drawn above 0, "99+" above
 * 99, and said as "Comments, 12 need action"; a dot on the folded rail.
 */
function NavBadge({ count }: { count: number }) {
    if (count <= 0) {
        return null;
    }

    return (
        <>
            <SidebarMenuBadge>
                <span className="sr-only">, </span>
                {count > 99 ? '99+' : count}
                <span className="sr-only">
                    {count === 1 ? __(' needs action') : __(' need action')}
                </span>
            </SidebarMenuBadge>
            <span
                aria-hidden="true"
                className="bg-accent pointer-events-none absolute end-1 top-1 hidden size-2 rounded-full group-data-[collapsible=icon]:block"
            />
        </>
    );
}

/** A group's heading: a button that folds the group; a short line on the rail. */
function GroupHeading({
    label,
    folded,
    onToggle,
}: {
    label: string;
    folded: boolean;
    onToggle: () => void;
}) {
    return (
        <>
            <SidebarGroupFold
                aria-expanded={!folded}
                aria-label={__('Show or hide :group', { group: label })}
                onClick={onToggle}
            >
                <span>{label}</span>
                <ChevronDown
                    aria-hidden="true"
                    className={cn(
                        'transition-transform duration-(--dur-1) ease-(--ease)',
                        folded && '-rotate-90',
                    )}
                />
            </SidebarGroupFold>
            <span
                aria-hidden="true"
                className="bg-line mx-auto mb-2 hidden h-0.5 w-5 rounded-full group-data-[collapsible=icon]:block"
            />
        </>
    );
}

/**
 * A quiet plug mark at the end of a plugin's entry, with the plugin's name
 * for screen readers.
 */
function FromPlugin({ name }: { name: string }) {
    return (
        <>
            <Plug
                aria-hidden="true"
                className="text-ink-3 ms-auto size-3.5 shrink-0"
            />
            <span className="sr-only">{__('From :name', { name })}</span>
        </>
    );
}

/** One entry, 38 tall: its icon, its label and its count, or its Soon tag. */
function EntryButton({
    item,
    isActive,
    hubChevron,
    hubOpen,
    onHubToggle,
}: {
    item: AdminNavChild;
    isActive: boolean;
    /** A Tools or Settings hub: chevron opens its screens from the menu. */
    hubChevron?: boolean;
    hubOpen?: boolean;
    onHubToggle?: () => void;
}) {
    const Icon = navIcon(item.icon);

    if (item.soon) {
        return (
            <SidebarMenuButton
                aria-disabled="true"
                data-test={`nav-${item.key}`}
                tooltip={{ children: item.label }}
                onClick={() => sayPlanned(item.label)}
                className={SOON}
            >
                <Icon />
                <span className="min-w-0 flex-1 truncate">{item.label}</span>
                {item.plugin !== undefined && (
                    <span className="sr-only">
                        {__('From :name', { name: item.plugin })}
                    </span>
                )}
                <SoonTag className="group-data-[collapsible=icon]:hidden" />
            </SidebarMenuButton>
        );
    }

    return (
        <>
            <div className="relative flex min-w-0 flex-1 items-center">
                <SidebarMenuButton
                    asChild
                    isActive={isActive}
                    data-test={`nav-${item.key}`}
                    tooltip={{ children: item.label }}
                    className={hubChevron === true ? 'pe-10' : undefined}
                >
                    <Link
                        href={item.href}
                        prefetch
                        aria-current={isActive ? 'page' : undefined}
                    >
                        <Icon />
                        <span className="min-w-0 flex-1 truncate">
                            {item.label}
                        </span>
                        {item.plugin !== undefined && (
                            <span className="sr-only">
                                {__('From :name', { name: item.plugin })}
                            </span>
                        )}
                    </Link>
                </SidebarMenuButton>
                {hubChevron === true && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-xs"
                        data-test={`nav-hub-${item.key}`}
                        aria-expanded={hubOpen === true}
                        aria-label={__('Show or hide :group', {
                            group: item.label,
                        })}
                        className="text-ink-2 absolute end-1 top-1/2 z-(--z-sticky) -translate-y-1/2 group-data-[collapsible=icon]:hidden"
                        onClick={onHubToggle}
                    >
                        <ChevronDown
                            aria-hidden="true"
                            className={cn(
                                'size-4 transition-transform duration-(--dur-1) ease-(--ease)',
                                hubOpen !== true && '-rotate-90',
                            )}
                        />
                    </Button>
                )}
            </div>
            <NavBadge count={item.badge} />
        </>
    );
}

/** One sub-entry, 32 tall: a child of the current entry, or a hub's screen. */
function SubEntry({
    item,
    isActive,
}: {
    item: Pick<AdminNavChild, 'key' | 'label' | 'href' | 'soon'> & {
        badge?: number;
        /** The plugin that added this screen: a plug, named for screen readers. */
        plugin?: string;
    };
    isActive: boolean;
}) {
    return (
        <SidebarMenuSubItem>
            {item.soon ? (
                <SidebarMenuSubSoon
                    data-test={`nav-${item.key}`}
                    onClick={() => sayPlanned(item.label)}
                >
                    <span>{item.label}</span>
                    <SoonTag />
                </SidebarMenuSubSoon>
            ) : (
                <>
                    <SidebarMenuSubButton asChild isActive={isActive}>
                        <Link
                            href={item.href}
                            prefetch
                            data-test={`nav-${item.key}`}
                            aria-current={isActive ? 'page' : undefined}
                        >
                            <span className="min-w-0 flex-1 truncate">
                                {item.label}
                            </span>
                            {item.plugin !== undefined && (
                                <FromPlugin name={item.plugin} />
                            )}
                        </Link>
                    </SidebarMenuSubButton>
                    <NavBadge count={item.badge ?? 0} />
                </>
            )}
        </SidebarMenuSubItem>
    );
}

/**
 * The main menu, drawn from the server's `nav` in its section groups. The
 * server has already sorted it as they arranged it and removed what they
 * may not open; hidden entries are left out here, and only here.
 */
export function NavMain({ items: all }: { items: AdminNavEntry[] }) {
    const { currentUrl } = useCurrentUrl();
    // A page is within an entry when it is the entry's page or under its address.
    // Home's address is the admin's root, which every page is under: Home is current there only.
    const within = (href: string): boolean => {
        const path = currentUrl.split(/[?#]/)[0].replace(/\/$/, '');

        if (href === '/admin') {
            return path === href;
        }

        return (
            href !== '' &&
            (currentUrl === href || currentUrl.startsWith(`${href}/`))
        );
    };
    const items = all.filter((item) => !item.hidden);
    const sections = SECTIONS.filter((section) =>
        items.some((item) => item.section === section),
    );
    const [folded, setFolded] = useState<Record<string, boolean>>(() =>
        Object.fromEntries(sections.map((group) => [group, readFolded(group)])),
    );
    const hubs = items.filter((item) => item.screens.length > 0);
    const [hubOpen, setHubOpen] = useState<Record<string, boolean>>(() =>
        Object.fromEntries(hubs.map((hub) => [hub.key, readHubOpen(hub.key)])),
    );

    const toggle = (group: string) =>
        setFolded((now) => {
            const next = now[group] !== true;
            writeFolded(group, next);

            return { ...now, [group]: next };
        });

    const toggleHub = (key: string) =>
        setHubOpen((now) => {
            const next = now[key] !== true;
            writeHubOpen(key, next);

            return { ...now, [key]: next };
        });

    // The rail has no headings to unfold, so a folded section still shows its icons there.
    const { isMobile, state, overlayOpen } = useSidebar();
    const onRail = !isMobile && state === 'collapsed' && !overlayOpen;

    const entry = (item: AdminNavEntry) => {
        if (item.soon) {
            return (
                <SidebarMenuItem key={item.key}>
                    <EntryButton item={item} isActive={false} />
                </SidebarMenuItem>
            );
        }

        // A hub's entry is current on any of its pages; a plugin area on every page under its prefix.
        const hubScreens = item.screens.filter((screen) => !screen.external);
        const isHub = hubScreens.length > 0;
        const hubCurrent = hubScreens.some((screen) => within(screen.href));
        const childOpen = item.children.some((child) => within(child.href));
        const entryCurrent = within(item.href) || hubCurrent;
        // Hub screens open from the chevron, or while the person is on a hub page.
        const listOpen = isHub && (hubOpen[item.key] === true || hubCurrent);

        return (
            <SidebarMenuItem key={item.key}>
                <EntryButton
                    item={item}
                    isActive={entryCurrent}
                    hubChevron={isHub}
                    hubOpen={listOpen}
                    onHubToggle={() => toggleHub(item.key)}
                />
                {/* Children under the current entry, so the menu stays short. */}
                {item.children.length > 0 &&
                    (within(item.href) || childOpen) && (
                        <SidebarMenuSub>
                            {item.children.map((child) => (
                                <SubEntry
                                    key={child.key}
                                    item={child}
                                    isActive={within(child.href)}
                                />
                            ))}
                        </SidebarMenuSub>
                    )}
                {isHub && listOpen && (
                    <SidebarMenuSub>
                        {hubScreens.map((screen) => (
                            <SubEntry
                                key={screen.key}
                                item={screen}
                                isActive={within(screen.href)}
                            />
                        ))}
                    </SidebarMenuSub>
                )}
            </SidebarMenuItem>
        );
    };

    return (
        // The menu's own landmark, for those who jump between regions.
        <nav aria-label="Main" className="flex flex-col gap-2">
            {sections.map((section) => {
                const heading = headingOf(section);
                const isFolded = heading !== null && folded[section] === true;

                return (
                    <SidebarGroup key={section} className="px-2 py-0">
                        {heading !== null && (
                            <GroupHeading
                                label={heading}
                                folded={isFolded}
                                onToggle={() => toggle(section)}
                            />
                        )}
                        {(!isFolded || onRail) && (
                            <SidebarMenu>
                                {items
                                    .filter((item) => item.section === section)
                                    .map(entry)}
                            </SidebarMenu>
                        )}
                    </SidebarGroup>
                );
            })}
        </nav>
    );
}
