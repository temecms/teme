/** A menu on Content › Navigation's list. */
export type MenuRow = {
    id: string;
    name: string;
    handle: string;
    itemCount: number;
    /** The labels of the theme's places that show it, such as "Header". */
    shownIn: string[];
};

export type NavigationIndexPage = {
    navigations: MenuRow[];
};

export type MenuItemKind =
    | 'content'
    | 'term'
    | 'home'
    | 'url'
    | 'heading'
    | 'languages';

/** One item of a menu, as its editor holds it. */
export type MenuItem = {
    kind: MenuItemKind;
    targetId: string | null;
    url: string | null;
    /** Null shows the target's own title. */
    label: string | null;
    opensNewTab: boolean;
    /** What it points at, in words, such as "Page: About". */
    target: string;
    /** Why it isn't shown on the site, or null when it is. */
    warning: string | null;
    children: MenuItem[];
};

/** A place the active theme draws a menu in, and the menu it shows. */
export type MenuSlot = {
    slot: string;
    label: string;
    navigationId: string | null;
};

/** A public content type or taxonomy an item may point at. */
export type MenuTargetKind = {
    key: string;
    singular: string;
    plural: string;
};

export type NavigationEditPage = {
    navigation: { id: string; name: string; handle: string };
    items: MenuItem[];
    /** The active theme's code, or null when it has no places for menus. */
    themeCode: string | null;
    /** The active theme's name, for "Show in"; null while no theme is usable. */
    themeName: string | null;
    slots: MenuSlot[];
    menus: { id: string; name: string }[];
    types: MenuTargetKind[];
    taxonomies: MenuTargetKind[];
    /** "For readers of" (MenuLanguages): the site's languages, the menu's own, and each place's menu for each other language's readers. */
    readers?: {
        languages: { tag: string; name: string }[];
        language: string | null;
        bindings: Record<string, Record<string, string | null>>;
    };
};
