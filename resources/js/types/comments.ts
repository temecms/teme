import type { ListPages } from './list-pages';

export type CommentView = 'all' | 'pending' | 'approved' | 'spam' | 'trash';

/** One comment in the Comments screen's list (1.8c): what the screen draws, decided on the server (D14). */
export type CommentRow = {
    id: string;
    kind: 'comment' | 'pingback' | 'trackback';
    /** A status a moderator may set: never 'removed'. */
    status: string;
    /** The comment it's stored under: the confirmation's count of what keeps a place (D12). */
    parentId: string | null;
    authorName: string;
    /** Null for a ping. */
    authorEmail: string | null;
    authorUrl: string | null;
    /** The writer's account, when they were signed in. */
    signedIn: boolean;
    /** Null unless the person holds comments.moderate (I-270 B). */
    ip: string | null;
    /** The words readers see: paragraphs by a blank line, line breaks kept. */
    text: string;
    /** Null when it isn't a reply. */
    parent: { authorName: string | null; removed: boolean } | null;
    item: { id: string; title: string; editable: boolean };
    /** ISO 8601, UTC. */
    createdAt: string;
    /** Every reply, any status (I-577). */
    replies: number;
    /** Its text made into a document again gives the stored one: Quick edit's hint is right wherever it opens (D10). */
    plain: boolean;
};

/** One comment's drawer: its row's facts, the moderator's judgement, and what only editors see (D13, D16). */
export type CommentDetails = {
    id: string;
    kind: 'comment' | 'pingback' | 'trackback';
    status: string;
    statusLabel: string;
    authorName: string;
    authorEmail: string | null;
    authorUrl: string | null;
    signedIn: boolean;
    item: { id: string; title: string; editable: boolean };
    parent: { authorName: string | null; removed: boolean } | null;
    /** Every reply, any status (I-577): what a permanent deletion would leave a tombstone for (B-367). */
    replies: number;
    createdAt: string;
    /** Null unless the person holds comments.moderate (I-270 B). */
    ip: string | null;
    userAgent: string | null;
    /** A reply written in the admin, which keeps no IP address or browser (C6). */
    writtenInAdmin: boolean;
    /** When keep_ip_days after arrival the address and browser are erased, ISO 8601, UTC (1.8d's D14). */
    erasesAt: string;
    /** keep_ip_days (1.8d's D14). */
    keptDays: number;
    legacyId: number | null;
    text: string;
    /** As the row's: nothing in it but what a reader could type (D10). */
    plain: boolean;
    judgement: {
        byline: string | null;
        verdict: string;
        checks: {
            label: string;
            state: 'ok' | 'warn' | 'bad';
            detail: string;
        }[];
        hint: string | null;
    };
};

export type CommentsIndexPage = {
    view: CommentView;
    /** All, Pending, Approved, Spam, Trash, each with its count. */
    views: { key: CommentView; label: string; count: number }[];
    rows: CommentRow[];
    /** Where this page sits among the list's pages (I-3421). */
    paging: ListPages;
    filters: { search?: string; item?: string };
    /** The item the list is narrowed to. */
    item: { id: string; title: string } | null;
    /** True for someone who looks after only their own items: the header's words. */
    own: boolean;
    /** comments.moderate (I-270 B). */
    seesAddresses: boolean;
    siteTimezone: string;
    /** Set when the address has ?comment=. */
    details: CommentDetails | null;
    /** When the page was drawn, ISO 8601, UTC, to the microsecond: Empty's cut-off (D7). */
    openedAt: string;
    /** What Empty spam or Empty trash would delete, for its confirmation (D6): the Spam and Trash views only. */
    emptying: { count: number; tombstones: number; replies: number } | null;
    /** content.trash_days: the Trash view's empty words (D18). */
    trashDays: number;
};

export type CommentsRefusedPage = {
    message: string;
    back: { label: string; href: string };
};
