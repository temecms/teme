/** How an inbox item's work ended, from its kind; null for a plain notice. */
export type InboxResult = 'failed' | 'finished' | 'warning';

/** One item in a person's inbox, as the inbox draws it. */
export type InboxRow = {
    id: string;
    title: string;
    text: string | null;
    /** Where the item leads for the reader now, or null when there's nowhere they can go. */
    href: string | null;
    read: boolean;
    /** ISO 8601, in UTC. */
    at: string;
    owner: string;
    /** The plugin's own name when a plugin sent it; otherwise null. */
    plugin: string | null;
    /** The stored kind key, such as job.finished. */
    kind: string;
    /** Drawn as the kind icon and the Failed pill; null for a plain notice. */
    result: InboxResult | null;
    /** Where the work lived, such as "Tools › Backups"; null with no link. */
    place: string | null;
};

export type InboxPage = {
    rows: InboxRow[];
    newer: string | null;
    older: string | null;
    /** Counted up to 100. */
    unread: number;
    siteTimezone: string;
};

export type JobStateKey =
    | 'waiting'
    | 'running'
    | 'finished'
    | 'failed'
    | 'cancelled';

/** One tracked job, as Tools › Jobs draws it. */
export type JobRow = {
    id: string;
    kind: string;
    label: string;
    owner: string;
    /** The plugin's label when this is a plugin job; otherwise null. */
    plugin: string | null;
    state: JobStateKey;
    stateLabel: string;
    message: string | null;
    total: number | null;
    done: number;
    /** Rounded down; null while the total isn't known. */
    percent: number | null;
    startedAt: string;
    endedAt: string | null;
    /** The account that started it, while it exists; null for the server or a removed account. */
    startedById: number | null;
    startedBy: { kind: 'person' | 'removed' | 'operator'; name: string | null };
    canRetry: boolean;
    canCancel: boolean;
};

/** One job as Tools › Jobs draws it: with what it worked on, and whether the reader started it. */
export type JobsScreenRow = JobRow & {
    /** The line under the job's name, such as "Import", and where it leads. */
    context: { label: string; href: string | null } | null;
    mine: boolean;
};

export type JobsPage = {
    rows: JobsScreenRow[];
    newer: string | null;
    older: string | null;
    whose: 'mine' | 'everyone';
    state: JobStateKey | null;
    /** Each tab's count, whatever the state filter. */
    counts: { mine: number; everyone: number };
    /** How many jobs this tab and filter hold, over every page. */
    shown: number;
    /** Failed jobs whose kind is installed, so Retry all failed can retry them. */
    failed: number;
    siteTimezone: string;
    inboxHref: string;
};

/** The header's counts, shared with every screen (null for anyone without the panels' routes). */
export type HeaderCounts = {
    /** Waiting or running, up to 100. */
    running: number;
    /** Failed and retryable now, up to 100. */
    failed: number;
    /** Whether the person has any job kept; the jobs button shows only then. */
    hasJobs: boolean;
    /** Unread inbox items, up to 100. */
    unread: number;
};

/** `my-jobs.index`: the person's latest jobs and their counts. */
export type MyJobsAnswer = HeaderCounts & {
    rows: JobRow[];
    siteTimezone: string;
    /** Tools › Jobs, for someone who looks after jobs; else null. */
    toolsJobsHref: string | null;
};

/** `inbox.latest`: the person's latest inbox items. */
export type InboxLatestAnswer = {
    rows: InboxRow[];
    unread: number;
    siteTimezone: string;
    inboxHref: string;
};
