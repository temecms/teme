import type { JobRow } from '@/types/jobs';

/** A site Teme imports from, as Tools › Import lists it. */
export type ImportSiteRow = {
    id: string;
    address: string;
    host: string;
    state: 'none' | 'running' | 'stopped' | 'finished';
    stateLabel: string;
    forgotten: boolean;
    /** ISO 8601, in UTC; null before its first import. */
    lastImportAt: string | null;
    href: string;
};

export type ImportIndexPage = {
    sites: ImportSiteRow[];
    companion: { version: string; releases: string | null };
    siteTimezone: string;
};

export type ImportSite = {
    id: string;
    address: string;
    host: string;
    userName: string | null;
    pluginVersion: string;
    /** ISO 8601, in UTC. */
    connectedAt: string;
    forgotten: boolean;
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
};

export type SiteAnalysisView = {
    reported: boolean;
    reportedAt: string | null;
    comes: { key: string; label: string; count: number }[];
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
    remembered: RememberedChoices | null;
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
    }[];
    messages: {
        code: string;
        level: 'error' | 'warning';
        levelLabel: string;
        words: string;
        count: number;
    }[];
    more: boolean;
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
    siteTimezone: string;
};
