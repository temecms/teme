import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
};

/** A child of a main-menu entry, as the server shares it. */
export type AdminNavChild = {
    key: string;
    label: string;
    icon: string;
    href: string;
    /** What waits for this person here (I-220); a parent's includes its children's. 0 draws none. */
    badge: number;
    /** Planned and not built (I-3408): drawn with a Soon tag, opening nothing; its href is empty. */
    soon?: true;
    /** The plugin that added this entry; absent for Teme's own. */
    plugin?: string;
};

/** A screen of a hub, such as Settings › General, for the palette's Go to. */
export type AdminNavScreen = {
    key: string;
    label: string;
    icon: string;
    href: string;
    /** A plugin's link to another site (I-3353); absent for Teme's own screens. */
    external?: boolean;
    /** The plugin that added this screen (I-3353); absent for Teme's own. */
    plugin?: string;
};

/** One entry of the admin's main menu, as the server shares it in `nav`. */
export type AdminNavEntry = AdminNavChild & {
    section: 'home' | 'content' | 'site';
    /** Hidden by this person (nav.hidden): out of the sidebar, still in the palette. */
    hidden: boolean;
    children: AdminNavChild[];
    /** A hub's screens (Settings, Tools) this person may open; empty for other entries. */
    screens: AdminNavScreen[];
};
