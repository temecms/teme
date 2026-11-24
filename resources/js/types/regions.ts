/** "empty" with no content yet, "draft" never published, "changed" published with newer work. */
export type RegionState = 'empty' | 'draft' | 'published' | 'changed';

/** One of the active theme's regions, on Content › Regions. */
export type RegionRow = {
    key: string;
    label: string;
    /** The cards it allows, by name. */
    cards: string[];
    /** The same cards by their labels, as people read them. */
    cardLabels: string[];
    max: number;
    /** The most blocks it holds, in words: "At most 5 blocks". */
    limit: string;
    state: RegionState;
    /** Its content's ID once it has some, for the editor. */
    contentId: string | null;
    editedAt: string | null;
};

/** A region kept from a theme that isn't active, shown read-only. */
export type OtherRegionRow = {
    slug: string;
    label: string;
    /** Its theme's name, or null when that theme is no longer installed. */
    themeName: string | null;
    state: RegionState;
    editedAt: string | null;
    /** How many of its theme's own cards it keeps, which come back with that theme. */
    themeCards: number;
};

export type RegionsIndexPage = {
    theme: { code: string; name: string } | null;
    regions: RegionRow[];
    others: OtherRegionRow[];
};
