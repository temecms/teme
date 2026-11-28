import type { Discussion } from '@/lib/discussion';
import type { FeaturedImage } from './media';
import type { ListPages } from './list-pages';
import type { PluginCellValue, PluginColumn } from './plugin-columns';

export type TermChip = { id: string; name: string };

export type ContentRow = {
    id: string;
    title: string;
    slug: string;
    status: 'draft' | 'review' | 'scheduled' | 'published';
    /** Who may read it once published (I-1700). */
    visibility: 'public' | 'private' | 'protected';
    trashed: boolean;
    authorId: number | null;
    authorName: string | null;
    /** Taxonomy key => the item's terms in it, for each taxonomy the type uses. */
    terms: Record<string, TermChip[]>;
    featuredImage: FeaturedImage | null;
    /** A live item's address on the site, opened by View; null for anything else (I-3130). */
    viewUrl: string | null;
    updatedAt: string;
    publishedAt: string | null;
    workingRevisionId: string | null;
    /** When a scheduled item will publish, ISO 8601, or null. */
    scheduledFor: string | null;
    /** The item's pending review, whatever its status, or null. */
    review: {
        id: string;
        submittedBy: string | null;
        submittedAt: string;
        canDecide: boolean;
    } | null;
    /** A scheduled item's schedule that cannot run as things stand. */
    scheduleIssue: 'failed' | 'held' | null;
    /** Whether this person may edit the item; other people's work is listed read-only (I-409). */
    canEdit: boolean;
    /** The Comments column (1.8c's D21): null when the type takes none or comments are off; `waiting` is null for someone who doesn't look after the item. */
    comments: {
        approved: number;
        waiting: number | null;
        mine: boolean;
    } | null;
    /** Quick and Bulk edit's Discussion (I-688): null when the type takes none or comments are off; `canSet` when this person may publish the item. */
    discussion: { value: Discussion; canSet: boolean } | null;
    /** Its group's versions by language tag, for the language marks; null on a site with one language. */
    versions?: Record<string, 'there' | 'outOfDate'> | null;
    /** The item it sits under, for the Parent column; null at the top. */
    parent?: { id: string; title: string } | null;
    /** The name of the template it chose, null for the theme's default. */
    template?: string | null;
    /** Its values for the type's list fields, by field key, as a cell shows them; an empty one is left out. */
    fields?: Record<string, string>;
};

export type ContentTab = { key: string; label: string; count: number };

export type BulkReport = {
    done: number;
    skipped: { id: string; title: string; reason: string }[];
};

/** A listed content type, as the server shares it for the sidebar. */
export type ContentTypeInfo = {
    key: string;
    singular: string;
    plural: string;
    segment: string;
    icon: string;
    /** Whether this person may create one: the palette offers New {singular} only then. */
    canCreate: boolean;
    /** Stored in the admin: Types is current on its list; no menu entry of its own. */
    stored: boolean;
};

/** A taxonomy the list's type uses: its pickers, columns and filters. */
export type TypeTaxonomy = {
    key: string;
    singular: string;
    plural: string;
    segment: string;
    hierarchical: boolean;
    /** Whether the server lets this person create its terms. */
    canCreate: boolean;
};

/** A plugin's action on the content lists (contributes.listActions). */
export type ContentListAction = {
    code: string;
    id: string;
    label: string;
    row: boolean;
    bulk: boolean;
    confirm: string | null;
};

export type ContentIndexPage = {
    /** The URL segment, such as posts. */
    type: string;
    /** The plugins' list actions, offered on rows and in bulk where their scope says. */
    listActions: ContentListAction[];
    rows: ContentRow[];
    /** Its place among the pages (I-3421). */
    paging: ListPages;
    tabs: ContentTab[];
    filtered: boolean;
    /** The sort and direction in force, after any fallback the tab makes. */
    sort: string;
    direction: 'asc' | 'desc';
    filters: Record<string, string | undefined>;
    /** The Date control's months (I-3131): what they are counted by, newest first, each count null while another filter narrows the list. */
    dates: {
        by: 'updated' | 'published';
        months: { month: string; count: number | null }[];
    };
    authors: { value: number; label: string }[];
    can: { changeAuthor: boolean; publish: boolean };
    bulk: BulkReport | null;
    /** Whether the list has a Comments column: the type takes comments and they're on. */
    takesComments: boolean;
    typeTaxonomies: TypeTaxonomy[];
    /** The type's own fields a list may show as columns, in the editor's order. */
    listFields: { key: string; label: string; kind: string }[];
    /** Whether the type nests, so its list offers the Parent column. */
    hasParent: boolean;
    /** Whether its items choose a template, so its list offers the Template column. */
    hasTemplate: boolean;
    labels: { singular: string; plural: string };
    /** The site's languages, in order, for the marks and the Language filter; none when the type has no versions. */
    languages?: { tag: string; name: string }[];
    /** Whether Add new is offered: false once a type with one item only has it (I-415). */
    canAdd: boolean;
    /** On the Scheduled tab, one line per reason held schedules cannot run; empty elsewhere. */
    scheduleWarning: string[];
    /** The site's zone, as an IANA name: scheduled times are on its clock. */
    siteTimezone: string;
    /**
     * Every nesting taxonomy's terms, by key, with every list: the Category
     * filter and Bulk Edit read them.
     */
    termTrees: Record<
        string,
        { id: string; name: string; parentId: string | null }[]
    >;
    /**
     * By a flat taxonomy's key, the name of the term the list is filtered
     * by: no select shows it, so a chip names it. A term that is gone, or
     * isn't of that taxonomy, is left out.
     */
    chosenTerms: Record<string, string>;
    /** Every plugin column offered for this type. */
    pluginColumns: PluginColumn[];
    /** The plugins' list filters for this type: a select of their declared options (issue 802). */
    listFilters: {
        key: string;
        label: string;
        options: { value: string; label: string }[];
    }[];
    /** Values for shown plugin columns only: key → row id → cell. */
    pluginCells: Record<string, Record<string, PluginCellValue>>;
    /** Plugin labels whose columns couldn't be shown this time. */
    pluginColumnProblems: string[];
};
