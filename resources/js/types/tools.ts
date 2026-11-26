/** A tool the hub lists: one the person may open (I-467). */
export type ToolPage = {
    key: string;
    label: string;
    icon: string;
    description: string;
    href: string;
    /** A plugin's link to another site, opened in a new tab (I-3353). */
    external: boolean;
    /** The plugin that added this screen (I-3353); absent for Teme's own. */
    plugin?: string;
};

export type ToolsIndexPage = {
    tools: ToolPage[];
};

export type PageCachePage = {
    on: boolean;
    keptIn: string;
    lives: { any: string; search: string; notFound: string };
    cdn: string | null;
    cleared: { at: string; by: string } | null;
    siteTimezone: string;
};

/** Where a redirect sends readers, as the list shows it (I-694, I-860). */
export type RedirectTo = {
    kind: 'address' | 'outside' | 'content' | 'media' | null;
    address: string | null;
    title: string | null;
    contentId: string | null;
    mediaId: string | null;
};

/** One redirect as the list shows it, with what it does now (I-857, I-858). */
export type RedirectRow = {
    id: string;
    from: string;
    pattern: boolean;
    status: number;
    statusLabel: string;
    to: RedirectTo;
    note: string;
    origin: 'manual' | 'import';
    hits: number;
    lastHitAt: string | null;
    on: boolean;
    state: string;
    stateLabel: string;
};

export type RedirectCounts = { redirects: number; notFound: number };

/** What an import left out, line by line, shown once. */
export type ImportReport = {
    added: number;
    refused: { line: number; reason: string }[];
};

export type RedirectsPage = {
    rows: RedirectRow[];
    newer: string | null;
    older: string | null;
    search: string;
    /** The filters in force, each null when none is chosen. */
    filters: {
        on: 'on' | 'off' | null;
        status: number | null;
        origin: 'manual' | 'import' | null;
    };
    /** The statuses a redirect can answer with, for the filter. */
    statuses: { value: number; label: string }[];
    /** How many redirects the search and filters find. */
    shown: number;
    counts: RedirectCounts;
    siteTimezone: string;
    canManageAddresses: boolean;
    prefill: { from: string } | null;
    imported: ImportReport | null;
};

/** One address of the 404 log, and the redirect that answers it now, if any (I-871). */
export type NotFoundRow = {
    path: string;
    address: string;
    count: number;
    firstSeenAt: string;
    lastSeenAt: string;
    referrerHost: string | null;
    redirectId: string | null;
};

export type NotFoundPage = {
    rows: NotFoundRow[];
    next: { count: number; path: string } | null;
    paged: boolean;
    keep: number;
    counts: RedirectCounts;
    siteTimezone: string;
    /** Settings › Search and social's 404 log section for whoever holds settings.seo.manage, else null. */
    settingsUrl: string | null;
};

/** One AI request as Tools › AI activity draws it (I-1307). */
export type AiActivityRow = {
    id: string;
    at: string;
    person: { id: number | null; name: string | null };
    task: { key: string; label: string };
    variant: { key: string; label: string } | null;
    /** The plugin that asked, by code and name; null for Teme's own requests. */
    plugin: { code: string; label: string } | null;
    subject: {
        kind: 'content' | 'media' | 'none';
        id: string;
        title: string | null;
        kindLabel: string;
        state: 'present' | 'trashed' | 'gone' | 'none';
    };
    outcome: {
        key:
            | 'suggested'
            | 'applied'
            | 'discarded'
            | 'failed'
            | 'refused'
            | 'stale'
            | 'answered';
        label: string;
    };
    why: string | null;
    provider: string | null;
    model: string | null;
    prompt: { key: string; version: number };
    tokens: {
        input: number;
        output: number;
        cacheRead: number;
        cacheWrite: number;
    };
    costMicrounits: number | null;
    latencyMs: number;
    /** In the drawer's details only: the alt text from before and after a used alt-text suggestion (I-1312), or null. */
    altText?: { before: string | null; after: string } | null;
};

/** The figures above Tools › AI activity's list: one month's spend, and the budget for this month. */
export type AiMonthSummary = {
    month: string;
    label: string;
    current: boolean;
    costMicrounits: number;
    requests: number;
    unpricedRequests: number;
    budgetMicrounits: number | null;
    reached: boolean;
};

/** One row of Tools › AI activity's By plugin table: Teme's own requests (`teme`) or one plugin's, in a month. */
export type AiPluginMonth = {
    code: string;
    label: string;
    costMicrounits: number;
    requests: number;
    unpricedRequests: number;
    /** This month only: the limit, what it counts (for Teme, the site's whole spend) and whether it is reached; null for none. */
    budget: {
        limitMicrounits: number;
        countedMicrounits: number;
        reached: boolean;
    } | null;
};

export type AiActivityPage = {
    rows: AiActivityRow[];
    newer: string | null;
    older: string | null;
    /** Where this page's rows sit in the filtered list; null when the list is empty. */
    from: number | null;
    to: number | null;
    /** Always null: a full filtered count was ruled out for speed. */
    total: number | null;
    filters: {
        person: number | null;
        task: string | null;
        month: string | null;
        plugin: string | null;
    };
    people: { value: number; label: string }[];
    tasks: { value: string; label: string }[];
    months: { value: string; label: string }[];
    /** The filter's choices after "Teme and plugins": `teme` (Teme only), then each plugin with requests. */
    plugins: { value: string; label: string }[];
    summary: AiMonthSummary;
    /** The By plugin table: Teme, then each plugin that asked; empty when no plugin asked that month. */
    pluginMonths: AiPluginMonth[];
    details: AiActivityRow | null;
    missing: boolean;
    siteTimezone: string;
    aiOn: boolean;
    canChangeSettings: boolean;
};

/** One section of a release's notes, as plain text (never HTML). */
export type ReleaseNoteSection = {
    heading: string | null;
    paragraphs: string[];
    items: string[];
};

export type UpdateRelease = {
    version: string;
    prerelease: boolean;
    security: boolean;
    maintenance: boolean;
    publishedAt: string;
    url: string | null;
    notes: ReleaseNoteSection[];
};

/** Tools › Updates (I-3329). */
export type UpdatesPage = {
    running: {
        version: string;
        tagged: boolean;
        revision: string | null;
        channel: 'stable' | 'prerelease';
        channelSet: boolean;
    };
    newer: UpdateRelease[];
    newest: UpdateRelease | null;
    check: {
        enabled: boolean;
        checkedAt: string | null;
        succeededAt: string | null;
        error: string | null;
        tokenSet: boolean;
    };
    deploys: {
        version: string;
        outcome: string;
        startedAt: string;
        finishedAt: string;
        migrations: number;
        backupRun: string | null;
        sentence: string;
    }[];
    update: TemeUpdate | null;
    rollback: { version: string; refusal: string | null } | null;
    updaterSeenAt: string | null;
    canUpdate: string | null;
    siteTimezone: string;
};

/** One step of an update, with the five keys the updater's record shares with the page. */
export type TemeUpdateStep = {
    step: string;
    state: string;
    startedAt: string | null;
    finishedAt: string | null;
    detail: string | null;
};

/** The update request Tools › Updates shows: open, or ended within seven days. */
export type TemeUpdate = {
    id: string;
    kind: 'update' | 'rollback';
    from: string;
    to: string;
    status:
        | 'requested'
        | 'running'
        | 'done'
        | 'switched_back'
        | 'failed'
        | 'cancelled';
    step: string | null;
    steps: TemeUpdateStep[];
    error: string | null;
    backupRun: string | null;
    requestedBy: string | null;
    requestedAt: string;
    startedAt: string | null;
    finishedAt: string | null;
    canCancel: boolean;
};
