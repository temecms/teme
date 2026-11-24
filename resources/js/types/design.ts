import type { ExtensionOrigin, ExtensionsData } from './extensions';
import type { RegionsIndexPage } from './regions';
import type { FieldType } from './settings';

/** Settings › Design's four sections of theme settings. */
export type DesignFieldset = 'site' | 'latest' | 'content' | 'archive';

export type DesignField = {
    key: string;
    type: FieldType;
    label: string;
    description: string;
    options: Record<string, string>;
    /** Shown only while every other setting named here has this value, or one of these. */
    visibleWhen: Record<string, unknown>;
};

/** A theme's state on Site › Themes, as the server names it. */
export type ThemeState =
    | 'active'
    | 'previewing'
    | 'available'
    | 'incompatible'
    | 'broken';

/** The theme the design's draft would make live, while one is previewed. */
export type ThemePreview = {
    code: string;
    name: string;
    version: string;
    /** Whether its own settings or menu places hold unpublished changes. */
    changed: boolean;
};

export type DesignTheme = {
    code: string;
    name: string;
    version: string;
    description: string;
    author: string;
    authorUrl: string;
    homepage: string;
    usable: boolean;
    errors: string[];
    /** The theme the public site is drawn with now. */
    live: boolean;
    state: ThemeState;
    screenshot: string | null;
    /** Part of the site's build, or installed here from the admin (I-211). */
    origin: ExtensionOrigin;
    /** The source kind it was installed from, when installed here. */
    source: string | null;
    /** Its Composer package, when installed here. */
    package: string | null;
};

export type DesignSlot = {
    slot: string;
    key: string;
    label: string;
    navigationId: string;
};

/** The accent on one colour scheme, as the page draws it. */
export type AccentScheme = {
    accent: string;
    onAccent: string;
    ratio: number;
};

/** The site accent on each scheme the theme shows (I-457): lightened for the dark one. */
export type AccentSchemes = {
    light: AccentScheme;
    dark: AccentScheme | null;
};

export type DesignDraft = {
    by: string | null;
    at: string;
};

export type DesignPage = {
    themes: DesignTheme[];
    /** The theme chosen: the draft's, else the live one. */
    theme: string;
    /** The theme being previewed, or null. */
    preview: ThemePreview | null;
    themeGroup: string | null;
    fieldsets: Record<DesignFieldset, DesignField[]>;
    values: {
        design: Record<string, unknown>;
        theme: Record<string, unknown>;
    };
    slots: DesignSlot[];
    menus: { id: string; name: string }[];
    draft: DesignDraft | null;
    /** The active theme's regions and the rows it leaves unplaced, as Content › Regions reads them. */
    regions: RegionsIndexPage;
    /** The previewed theme's regions and what it would leave unplaced; null with no preview. */
    previewRegions: RegionsIndexPage | null;
    /** Whether the viewer may edit regions, so each links to where it is edited. */
    canEditRegions: boolean;
    /** The active theme's own cards its regions hold, which a switch away hides. */
    hiddenCards: HiddenThemeCard[];
    /** Installing from the admin; null for anyone who may not install. */
    extensions: ExtensionsData | null;
};

/** A theme's own settings page: what Themes sends, without the regions. */
export type DesignSettingsPage = Omit<
    DesignPage,
    | 'regions'
    | 'previewRegions'
    | 'canEditRegions'
    | 'hiddenCards'
    | 'extensions'
>;

/** One of the active theme's own cards that a switch hides (ThemeSwitchNotes::hiddenByLeaving). */
export type HiddenThemeCard = {
    /** "{theme}:{id}". */
    card: string;
    label: string;
    /** How many of the theme's regions hold it. */
    regions: number;
};
