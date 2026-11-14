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
    state: JobStateKey;
    stateLabel: string;
    message: string | null;
    total: number | null;
    done: number;
    /** Rounded down; null while the total isn't known. */
    percent: number | null;
    startedAt: string;
    endedAt: string | null;
    startedBy: { kind: 'person' | 'removed' | 'operator'; name: string | null };
    canRetry: boolean;
    canCancel: boolean;
};

export type JobsPage = {
    rows: JobRow[];
    newer: string | null;
    older: string | null;
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
