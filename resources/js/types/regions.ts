import type { CardManifest, EditorSession } from './editor';
/** "empty" with no content yet, "draft" never published, "changed" published with newer work. */
export type RegionState = 'empty' | 'draft' | 'published' | 'changed';

/** One of the active theme's regions, on Content › Regions. */
export type RegionRow = {
    key: string;
    label: string;
    /** The heading its theme lists it under; null for none. */
    group: string | null;
    /** Whether it takes every card rather than the ones it names. */
    every: boolean;
    /** The cards it allows, by name. */
    cards: string[];
    /** The same cards by their labels, as people read them. */
    cardLabels: string[];
    max: number;
    /** The most blocks it holds, in words: "At most 5 blocks". */
    limit: string;
    /** How many top-level blocks it holds now. */
    used: number;
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

/** A region on Content › Regions' one screen: a row, and whether it has changes not yet published. */
export type WorkspaceRegion = RegionRow & { dirty: boolean };

/** The region chosen on Content › Regions, with what its panel edits. */
export type ChosenRegion = WorkspaceRegion & {
    /** What it takes, in words; null before it has content. */
    takes: string | null;
    size: string | null;
    /** Its document as this person's editor would open it; null before it has content. */
    session: EditorSession | null;
};

/** Content › Regions as one screen. */
export type RegionsWorkspacePage = RegionsIndexPage & {
    regions: WorkspaceRegion[];
    groups: { group: string; regions: WorkspaceRegion[] }[];
    chosen: ChosenRegion | null;
    manifest: CardManifest;
    canManageSiteCode: boolean;
    siteCodeLocked: boolean;
    siteUrl: string;
    editor: {
        autosaveIdleMs: number;
        autosaveMaxMs: number;
        checkpointAfterMinutes: number;
    };
};
