import type {
    AreaBlock,
    AreaChartData,
    AreaFiguresData,
    AreaRowsData,
} from '@/types/plugin-area';

/** One row of a dashboard panel: an item, a line in words, where it opens. */
export type DashboardRow = {
    id: string;
    title: string;
    subtitle: string;
    url: string;
    /** ISO 8601: the time the panel's rows are sorted by. */
    at: string;
};

/** How wide a panel is drawn. */
export type PanelSize = 'small' | 'medium' | 'large' | 'full';

/** What a panel draws: rows from its query, the quick draft form, or site status. */
export type PanelKind = 'rows' | 'quick-draft' | 'site-status' | 'plugin';

/** One panel the person's Home screen shows; empty panels are never sent. */
export type DashboardPanelData = {
    key: string;
    label: string;
    rows: DashboardRow[];
    /** The list that answers the panel in full, or null. */
    seeAll: string | null;
    /** True when this panel's query failed; the rest of Home still shows. */
    failed: boolean;
    /** A plugin's panel: its blocks, each read for the person; empty for other panels. */
    blocks?: DashboardPluginBlock[];
};

/** One block of a plugin's Home panel: its area frame, with its data read for the person (null for a notice). */
export type DashboardPluginBlock = AreaBlock & {
    data: AreaFiguresData | AreaChartData | AreaRowsData | null;
    /** True when the block couldn't be read at all. */
    failed?: boolean;
};

/** One panel the person may see, empty or hidden, for Customize. */
export type DashboardCatalogueEntry = {
    key: string;
    label: string;
    size: PanelSize;
    kind: PanelKind;
    owner: string;
    /** The plugin's name when owner is not core; null for core. */
    ownerName: string | null;
};

/** The person's arrangement of Home panels. */
export type DashboardLayout = {
    order: string[];
    hidden: string[];
    sizes: Record<string, PanelSize>;
};

/** One of the site's figures, from the maintained counts. */
export type SiteFigure = {
    key: string;
    label: string;
    /** Its status dot: the colour token's name, such as `ok` or `warn`. */
    dot: string;
    value: number;
    /** Its screen, or null when the person may not open it. */
    url: string | null;
};

/** One step of Getting started, ticked off from the site's real state. */
export type GettingStartedStep = {
    key: string;
    label: string;
    done: boolean;
    url: string;
};

/** The props of the Home screen. */
export type DashboardPage = {
    panels: DashboardPanelData[];
    catalogue: DashboardCatalogueEntry[];
    layout: DashboardLayout;
    figures: SiteFigure[];
    /** The type Home writes and links to; posts today. */
    mainType: { singular: string; segment: string; canCreate: boolean };
    /** Post and Page create actions for the header (+ Post, + Page). */
    create: { singular: string; segment: string }[];
    /** For someone who may manage the site, until it is done or hidden. */
    gettingStarted: { steps: GettingStartedStep[] } | null;
    canQuickDraft: boolean;
    canUpload: boolean;
    /** While search engines are asked to stay away, for whoever may change Search and social (I-873). */
    searchEngines: {
        message: string;
        detail: string;
        actionLabel: string;
        actionUrl: string;
    } | null;
};
