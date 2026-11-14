/** One row of a dashboard panel: an item, a line in words, where it opens. */
export type DashboardRow = {
    id: string;
    title: string;
    subtitle: string;
    url: string;
    /** ISO 8601: the time the panel's rows are sorted by. */
    at: string;
};

/** One panel the person's Home screen shows; empty panels are never sent. */
export type DashboardPanelData = {
    key: string;
    label: string;
    rows: DashboardRow[];
    /** The list that answers the panel in full, or null. */
    seeAll: string | null;
};

/** One of the site's figures, from the maintained counts. */
export type SiteFigure = {
    key: string;
    label: string;
    /** The heading it sits under: a content type's plural, or none. */
    group: string | null;
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
    figures: SiteFigure[];
    /** The type Home writes and links to; posts today. */
    mainType: { singular: string; segment: string; canCreate: boolean };
    /** For someone who may manage the site, until it is done or hidden. */
    gettingStarted: { steps: GettingStartedStep[] } | null;
    canQuickDraft: boolean;
    /** While search engines are asked to stay away, for whoever may change Search and social (I-873). */
    searchEngines: {
        message: string;
        detail: string;
        actionLabel: string;
        actionUrl: string;
    } | null;
};
