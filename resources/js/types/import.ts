import type { JobRow } from '@/types/jobs';

/** A site Teme imports from, as Tools › Import lists it. */
export type ImportSiteRow = {
    id: string;
    address: string;
    host: string;
    state: 'none' | 'running' | 'stopped' | 'finished';
    stateLabel: string;
    /** The Teme Companion version the site last reported. */
    pluginVersion: string;
    forgotten: boolean;
    /** ISO 8601, in UTC; null before its first import. */
    lastImportAt: string | null;
    href: string;
    /** Live companion site, or one brought from an export file. */
    kind: 'companion' | 'file';
    /** ISO 8601, in UTC; set when kind is file. */
    uploadedAt: string | null;
};

export type ImportIndexPage = {
    sites: ImportSiteRow[];
    companion: { version: string; releases: string | null };
    siteTimezone: string;
    /** Largest export upload, in megabytes. */
    maxFileMb: number;
};

/** The export file a site reads from now. */
export type ImportSiteFile = {
    id: string;
    name: string;
    sizeBytes: number;
    /** ISO 8601, in UTC: when WordPress made the file; null when it doesn't say. */
    madeAt: string | null;
    /** ISO 8601, in UTC. */
    uploadedAt: string;
    /** ISO 8601, in UTC: when Teme deletes it, 30 days after the last import from it; null before one. */
    deletesOn: string | null;
};

export type ImportSite = {
    id: string;
    address: string;
    host: string;
    userName: string | null;
    pluginVersion: string | null;
    /** ISO 8601, in UTC; null for a site never connected live. */
    connectedAt: string | null;
    forgotten: boolean;
    /** Whether the site has been connected live through Teme Companion. */
    connected: boolean;
    /** The export file the site reads from now; null when it reads from its companion. */
    file: ImportSiteFile | null;
    /** Other old hosts kept on the source, bare. */
    otherHosts: string[];
    /** Subdomain hosts suggested from the companion’s analysis. */
    suggestedHosts: { host: string; links: number }[];
};

export type ImportStep = 'connect' | 'plan' | 'import' | 'report';

export type ImportCounts = {
    created: number;
    updated: number;
    skipped: number;
    failed: number;
    warnings: number;
};

export type ImportRunView = {
    id: string;
    kind: 'full' | 'catch-up';
    kindLabel: string;
    state: 'running' | 'stopped' | 'finished';
    stateLabel: string;
    startedAt: string;
    endedAt: string | null;
    stopMessage: string | null;
    counts: ImportCounts;
    steps: { key: string; label: string; state: 'done' | 'now' | 'next' }[];
    /** The export file the import read; null for an import from the companion. */
    file: {
        id: string;
        name: string;
        sizeBytes: number;
        uploadedAt: string;
        deletesOn: string | null;
        deleted: boolean;
    } | null;
};

export type SiteAnalysisView = {
    reported: boolean;
    reportedAt: string | null;
    /** A null count: the site report doesn't count that kind. */
    comes: { key: string; label: string; count: number | null }[];
    stays: {
        key: string;
        wordpressKey: string | null;
        label: string;
        count: number;
        why: string;
    }[];
    warning: { custom: number; total: number } | null;
    seo: string | null;
};

export type ChoicesForm = {
    menuLocations: { key: string; value: string }[];
    slots: { value: string; label: string }[];
    templates: { key: string; count: number; value: string }[];
    themeTemplates: { value: string; label: string }[];
    roles: { key: string; value: string }[];
    roleOptions: { value: string; label: string }[];
    owner: string;
    people: { value: string; label: string }[];
    outsideHosts: { host: string; count: number }[];
    pause: number;
    pauses: { value: number; label: string }[];
};

/** The choices of a site read from its export file, with their defaults. */
export type FileChoicesForm = {
    menuLocations: { key: string; label: string; value: string }[];
    slots: { value: string; label: string }[];
    templates: { key: string; count: number; value: string }[];
    themeTemplates: { value: string; label: string }[];
    owner: string;
    people: { value: string; label: string }[];
    pause: number;
    authors: {
        login: string;
        name: string;
        email: string;
        items: number;
        /** The person here with the same email or login, by ID. */
        matched: string | null;
        /** match:{id}, to:{id} or new:{role}. */
        value: string;
    }[];
    fileRoles: string[];
    types: {
        key: string;
        label: string;
        count: number;
        /** The Teme type that brings them; null when this site has none. */
        type: string | null;
        typeLabel: string | null;
        value: string;
    }[];
    media: string;
    categoryBase: string;
    tagBase: string;
    /** A real category's and tag's slug from the file, for the examples; empty when it has none. */
    categoryExample: string;
    tagExample: string;
    drafts: boolean;
    comments: boolean;
    postCount: number;
    pageCount: number;
    mediaCount: number;
};

/** The choices kept across the password's confirmation. */
export type RememberedChoices = {
    menu_locations: Record<string, string | null>;
    templates: Record<string, string | null>;
    roles: Record<string, string>;
    owner: string;
    outside_images: string[];
    pause: number;
};

export type ImportPlan = {
    analysis: SiteAnalysisView | null;
    problem: string | null;
    form: ChoicesForm | null;
    /** An export file's choices, in place of form. */
    fileForm: FileChoicesForm | null;
    /** For a site read from its file: whether the old site answers, so its media can be downloaded. */
    oldSiteAnswers: boolean | null;
    remembered: RememberedChoices | null;
    rememberedHosts: string[] | null;
    suggestedHosts: { host: string; links: number }[];
};

/** One of a site's imports, as the list under its report draws it. */
export type ImportRunRow = {
    id: string;
    kind: 'full' | 'catch-up';
    kindLabel: string;
    state: 'running' | 'stopped' | 'finished';
    stateLabel: string;
    startedAt: string;
    endedAt: string | null;
    counts: { created: number; failed: number };
    href: string;
};

export type RunReport = {
    types: {
        type: string;
        label: string;
        created: number;
        updated: number;
        skipped: number;
        failed: number;
        /** Objects of this kind with a warning; null for a plugin's records, which share one kind. */
        warnings: number | null;
    }[];
    messages: {
        code: string;
        level: 'error' | 'warning';
        levelLabel: string;
        words: string;
        count: number;
    }[];
    more: boolean;
    /** Every error and warning of the run, not only the codes listed. */
    levels: { error: number; warning: number };
    problems: number;
    ready: boolean | null;
};

export type ImportShowPage = {
    site: ImportSite;
    step: ImportStep;
    run: ImportRunView | null;
    job: JobRow | null;
    /** Deferred, and only at the plan step. */
    plan?: ImportPlan | null;
    /** Whether the run shown is the site's latest. */
    latest: boolean;
    runs: ImportRunRow[];
    report: RunReport | null;
    downloads: { everything: string; attention: string } | null;
    /** Hosts kept across the password confirmation; null when none. */
    rememberedHosts: string[] | null;
    siteTimezone: string;
    /** Largest export upload, in megabytes. */
    maxFileMb: number;
};
