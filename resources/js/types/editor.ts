import type { ReactNode } from 'react';
import type { Discussion } from '@/lib/discussion';

/** One card as the server declares it (CardManifest::forBrowser). */
export type CardManifestEntry = {
    name: string;
    label: string;
    description: string;
    menuLabel: string;
    group: string;
    keywords: string[];
    icon: string;
    /** Whether + Insert and the slash menu offer it (CardMenuEntry::$inMenus). */
    inMenus: boolean;
    /** Whether it belongs in theme regions only, so the menus offer it only there (CardMenuEntry::$regionOnly, I-3127). */
    regionOnly: boolean;
    /** Each attribute's default, and its allowed values when its rule lists them. */
    attributes: { name: string; default: unknown; options: string[] | null }[];
};

/** A site code entry the Custom HTML card may place: its name and whether it is on, never its code (I-3127). */
export type PlacedCode = { id: string; name: string; on: boolean };

/** A provider an embed card may frame, from config/embeds.php. */
export type EmbedProvider = {
    name: string;
    match: string;
    frame: string;
    /** What a pasted address of this provider's becomes: video or audio. */
    kind: string;
};

/** Everything the browser knows about cards: nothing it restates itself. */
export type CardManifest = {
    cards: CardManifestEntry[];
    embedProviders: EmbedProvider[];
    /** The active theme's image contexts, each with its `sizes` value. */
    imageSizes: Record<string, string>;
};

/** A media item as a card needs it (CardMedia::forIds). */
export type CardMedia = {
    variants: Record<
        string,
        {
            path: string;
            url: string;
            width: number;
            height: number;
            bytes: number;
        }
    >;
    width: number;
    height: number;
    alt: string | null;
    title: string;
    /** The library's credit, in the editor's own map (MediaCredits); absent wherever the renderer's shape is sent. */
    credit?: string | null;
    /** The library's licence, beside the credit (I-236). */
    licence?: string | null;
    /** The focal point, in percent from the left and the top. */
    focalX: number;
    focalY: number;
};

/** Someone else with unsaved work in the article. */
export type EditorPerson = { id: number; name: string };

/** A Tiptap document as stored. */
export type EditorDocument = { type: 'doc'; content?: unknown[] } & Record<
    string,
    unknown
>;

/** What the editor opens on (EditorSession). */
export type EditorSession = {
    id: string;
    document: EditorDocument;
    fromAutosave: boolean;
    savedAt: string | null;
    workingRevisionId: string | null;
    otherEditors: EditorPerson[];
    title: string;
    type: string;
    status: string;
    publishedAt: string | null;
    /** Whether a live item has changes readers don't see yet: a revision saved after the published one, or this person's autosave. */
    unpublishedChanges: boolean;
    fields: Record<string, unknown>;
    terms: { id: string; name: string; taxonomy: string }[];
    taxonomies: {
        key: string;
        singular: string;
        plural: string;
        segment: string;
        hierarchical: boolean;
        /** Whether the server lets this person create its terms. */
        canCreate: boolean;
        /** A hierarchical taxonomy's every term (TermPicker::tree); null for a flat one. */
        terms: { id: string; name: string; parentId: string | null }[] | null;
    }[];
    can: {
        edit: boolean;
        publish: boolean;
        delete: boolean;
        manageTerms: boolean;
        /** Whether this person may make a version in another language. */
        translate?: boolean;
    };
    media: Record<string, CardMedia>;
    slug: string;
    /** Where the type's items live, with `{slug}` for this one's part. */
    addressPattern: string;
    /** The item's live address, for View on site; null while it has none. */
    url: string | null;
    /** The active theme's page templates for the item's type, in its order (I-443). */
    templates: { slug: string; label: string }[];
    author: EditorPerson | null;
    revisionCount: number;
    /** The pending schedule, held or not, or else a failed one; null otherwise. */
    schedule: EditorSchedule | null;
    /** The latest review while it is pending, or returned and not resubmitted. */
    review: EditorReview | null;
    /** The states of the articles the opening document links to. */
    linked: Record<string, LinkedContent>;
    /** Who may read it once published (I-1700). */
    visibility: 'public' | 'private' | 'protected';
    /** The password readers give, for whoever may set it; null otherwise. */
    readerPassword: string | null;
    /** Whether a protected item's password can't be read on this server. */
    passwordUnreadable: boolean;
    /** The items the fields point at by ID, for their controls' first paint (EditorSession::entries). */
    entries: Record<string, EditorEntry>;
    /** The item's language, by its tag (I-3360). */
    language?: string;
    /** The site's languages, the default first; none on a site with one, or for a type that isn't translated. */
    languages?: EditorLanguage[];
    /** Its group's live versions, itself included. */
    translations?: EditorTranslation[];
};

/** One of the site's languages, as the editor knows it. */
export type EditorLanguage = { tag: string; name: string; shown: boolean };

/** A version of the item in one language (EditorSession::translations); one the person may not open has no id, title or address. */
export type EditorTranslation = {
    language: string;
    status: string;
    visibility: string;
    outOfDate: boolean;
    sourceLanguage: string | null;
    sourceChangedAt: string | null;
    current: boolean;
    id: string | null;
    title: string | null;
    editUrl: string | null;
    workingRevisionId: string | null;
};

/** An item a field points at: its title, its type's key and its status. */
export type EditorEntry = {
    title: string;
    type: string;
    status: string;
};

/** Whether an article a link points at can be reached (LinkedContent::states). */
export type LinkedContent = {
    title: string | null;
    state: 'published' | 'unpublished' | 'trashed' | 'missing';
};

/** What the editor's Search and social section reads beside the item's own fields (EditorSearchAndSocial). */
export type EditorSeo = {
    /** Which row of Search and social titles the item's head: its type's (`item`), the home page's (`home`: the site's address shows it), or its type's as the posts page, which lists the latest posts (`latest`, I-903). */
    kind: 'item' | 'home' | 'latest';
    /** That row's template and the separator, as Search and social has them now (I-290). */
    template: string;
    separator: string;
    /** The tokens the screen can't know, as the page fills them: the site's name and tagline always; for the home page `title` too; for an item its type's singular, first author, and the date and year it would show if published now. */
    tokens: Record<string, string>;
    /** Whether the site discourages search engines (I-291). */
    discouraged: boolean;
    /** Whether Search and social hides every item of this type (I-853). */
    typeHidden: boolean;
    /** The host CanonicalChoice compares an outside address with: config('app.url')'s. */
    siteHost: string;
    /** The site's default social image (I-293), or null. */
    defaultImage: { id: string; media: CardMedia } | null;
    /** The canonical's chosen item as the editor opens (I-292), or null for its own address or an outside one. */
    canonicalItem: {
        id: string;
        title: string | null;
        state: LinkedContent['state'];
    } | null;
    /** What the type says its items are, and the article types they may choose (I-709). */
    schema: {
        pageType: string;
        mainEntity: string | null;
        mainEntities: string[];
    };
    /** Whether the type's address pattern uses {category} (I-869). */
    addressFollowsCategory: boolean;
    /** Whether the active theme draws a breadcrumb trail (I-870). */
    trail: boolean;
};

/** An article's schedule, as the rail shows it. `runAt` is ISO 8601 in UTC. */
export type EditorSchedule = {
    runAt: string;
    heldReason: 'scheduler_removed' | 'scheduler_cannot_publish' | null;
    failedReason: string | null;
};

/** An article's review, as the rail shows it. `submittedBy` is a name. */
export type EditorReview = {
    id: string;
    state: 'pending' | 'returned';
    submittedBy: string | null;
    note: string | null;
    revisionId: string;
};

/** A field of the article's type, as the editor screen is given it. */
export type EditorField = {
    key: string;
    handle: string;
    label: string;
    description: string;
    type: string;
    required: boolean;
    maxLength: number | null;
    options: Record<string, string> | string[];
    /** Where the editor draws it: core's own panels, or the Details section. */
    place: 'details' | 'core';
    /** The control that draws it, or null when only core's panels do. */
    component: string | null;
    /** What that control needs. */
    settings: Record<string, unknown>;
};

/** What an autosave answered (SaveAutosaveResult). */
export type SaveAutosaveResult = {
    savedAt: string | null;
    stale: boolean;
    workingRevisionId: string | null;
    otherEditors: EditorPerson[];
    links: { from: string; to: string | null }[];
};

/** What the editor's assistant pane offers this person (EditorAssistant, I-321). */
export type EditorAi = {
    /** The tasks about an item they may ask for now, in their order. */
    tasks: { key: string; label: string }[];
    /** The providers those tasks' text may be sent to, by their names (I-1304). */
    sentTo: string[];
    /** Whether they may change Settings › AI. */
    canManage: boolean;
    /** Requests an hour, per person, for the limit's sentence. */
    hourlyLimit: number;
};

/** Someone an item may be credited to, as the Author select offers them (AuthorOptions). */
export type EditorAuthorChoice = { value: number; label: string };

/** The props of the editor screen. */
export type ContentEditPage = {
    session: EditorSession;
    manifest: CardManifest;
    fields: EditorField[];
    can: EditorSession['can'];
    /** The people an item may be credited to, for someone who may change who wrote it; null for anyone else. */
    authors: EditorAuthorChoice[] | null;
    /** 'region' for a theme's region, which opens in the editor's theme-region mode (1.6e). */
    kind: 'content' | 'region';
    /** A theme region's cards: the only ones its menus offer. Null for any other item. */
    allowedCards: string[] | null;
    /** The theme region this item fills, its theme, and what it takes, in words. Null for any other item. */
    themeRegion: {
        label: string;
        themeName: string;
        /** Whether its theme is the active one. */
        inUse: boolean;
        /** The names of the cards it takes, besides text. */
        cardLabels: string[];
        /** The most blocks it holds. */
        max: number;
        takes: string;
    } | null;
    /** The code the Custom HTML card may place, by name; null for any item that isn't a theme region. */
    placedCode: PlacedCode[] | null;
    /** Whether the host has switched site code off; null for any item that isn't a theme region. */
    siteCodeLocked: boolean | null;
    siteUrl: string;
    /** The site's zone, as an IANA name: every time the editor shows is on its clock. */
    siteTimezone: string;
    /** The Publish panel's Discussion row (I-687): the item's state and the days after publishing that new comments stop (0, never); null while comments are off or for a type that takes none. Whoever may publish sets it (`session.can.publish`). */
    discussion: { value: Discussion; closeAfterDays: number } | null;
    /** The Search and social section's rules beside the fields (EditorSearchAndSocial). */
    seo: EditorSeo;
    /** The assistant pane's offer; null shows no assistant. */
    ai: EditorAi | null;
    /** The plugins' panels this person sees on this item, as tools in the bar. */
    panels: EditorPluginPanel[];
    /** The plugins' tabs in Search and social for this type (PluginSearchFields::tabsFor). */
    searchTabs: PluginSearchTab[];
    /** Only on a presence poll: who else has unsaved work in the article. */
    otherEditors?: EditorPerson[];
    editor: {
        presenceWindow: number;
        autosaveIdleMs: number;
        autosaveMaxMs: number;
        checkpointAfterMinutes: number;
    };
};

/** One occupant of the rail's single pane: a card's settings, or later the assistant. */
export type RailPane = {
    id: string;
    owner: string;
    title: string;
    render: () => ReactNode;
    /** Offers "Open larger": the same pane, its state kept, in a large dialog. */
    openLarger?: boolean;
};

/** What wrote a revision the assistant made: the option's words, else the task's (null once the request was pruned), and the request's row for those who may open it (I-1307). */
export type RevisionAssistant = { label: string | null; row: string | null };

/** One revision in an article's history (RevisionHistory). */
export type RevisionRow = {
    id: string;
    kind: string;
    /** Who made the revision: a person, the assistant or an import. */
    origin: string;
    /** What wrote it, when the assistant did; else null. */
    assistant: RevisionAssistant | null;
    wordCount: number;
    /** Words added (positive) or removed since the revision before; null for the first. */
    wordDelta: number | null;
    author: string | null;
    /** "through Writer" when a plugin wrote it; else null. */
    via: string | null;
    createdAt: string;
    isPublished: boolean;
    isWorking: boolean;
};

/** A page of history, newest first, and the cursor for older revisions. */
export type RevisionPage = { rows: RevisionRow[]; older: string | null };

/** One block of a comparison (DocumentDiff); `before` and `after` are escaped HTML. */
export type DiffBlock = {
    kind: 'same' | 'added' | 'removed' | 'changed' | 'moved';
    before: string | null;
    /** For changed prose, the later text with both the added and the removed words marked. */
    after: string | null;
    node: string;
    /** For changed prose, the earlier text with its removed words marked; else null. */
    markedBefore: string | null;
    /** For changed prose, the later text with its added words marked; else null. */
    markedAfter: string | null;
    /** Words added and removed in this block. */
    added: number;
    removed: number;
    /** What a card is called; null for prose, which the screen names. */
    label: string | null;
};

/** A revision as a comparison names it. */
export type RevisionSummary = {
    id: string;
    kind: string;
    author: string | null;
    createdAt: string;
};

/** A revision in the comparison's list of versions: the History pane's row, without what wrote the assistant's. */
export type CompareVersion = Omit<RevisionRow, 'assistant'>;

/** The props of the comparison screen. */
export type ComparePageProps = {
    contentId: string;
    title: string;
    /** The article's type for the crumbs: the segment of its list, null when it has none. */
    type: { plural: string; segment: string | null };
    /** The History pane's first page of revisions, newest first, to choose from. */
    versions: { rows: CompareVersion[]; older: string | null };
    /** Restore is offered for either side that is not this one. */
    workingRevisionId: string | null;
    from: RevisionSummary;
    to: RevisionSummary;
    blocks: DiffBlock[];
    fields: FieldChange[];
    terms: TermChange[];
};

/** A field whose stored value differs between two revisions, as short text. */
export type FieldChange = {
    key: string;
    label: string;
    before: string | null;
    after: string | null;
};

/** The terms added to and removed from one taxonomy between two revisions. */
export type TermChange = {
    taxonomy: string;
    label: string;
    added: string[];
    removed: string[];
};

/** A preview link made for an article, as the Share panel lists it (PreviewLinkList). */
export type PreviewLinkRow = {
    id: string;
    /** The ID of the person who made it. */
    createdBy: string | null;
    /** The name of the person who made it; null once their account is gone. */
    createdByName: string | null;
    createdAt: string;
    expiresAt: string;
    revokedAt: string | null;
    lastViewedAt: string | null;
    viewCount: number;
    live: boolean;
};

/** A new preview link: its address, shown this once and never again. */
export type CreatedPreviewLink = { url: string };

/** One field of a plugin's tab in Search and social, as its manifest declares it. */
export type PluginSearchField = {
    key: string;
    type: 'text' | 'textarea' | 'number' | 'boolean' | 'select' | 'url';
    label: string;
    description: string;
    maxLength: number | null;
    options: { value: string; label: string }[];
    min: number | null;
    max: number | null;
    /** Whether its value shows in the tab's one-line summary. */
    summary: boolean;
};

/** A plugin's tab in Search and social: its values are one object under the plugin's key in the item's fields. */
export type PluginSearchTab = {
    /** The plugin's key in the item's fields, plugin_{code}; also the tab's id. */
    key: string;
    code: string;
    /** The plugin's name, which marks the tab. */
    plugin: string;
    label: string;
    description: string;
    position: 'after-search' | 'after-social' | 'last';
    fields: PluginSearchField[];
};

/** One result of a plugin's editor check (PluginChecks), drawn as plain text. */
export type PluginCheckResult = {
    id: string;
    label: string;
    state: 'ok' | 'improve' | 'problem';
    message: string;
    /** 'title', or a field's handle, to put the cursor on. */
    field: string | null;
    /** Or one of the plugin's own Search and social fields. */
    ownField: string | null;
};

/** One plugin's checks on the item, under its name; failed when it couldn't answer this time. */
export type PluginCheckGroup = {
    code: string;
    plugin: string;
    checks: PluginCheckResult[];
    failed: string | null;
};

/** One field of a plugin panel action's form, as its manifest declares it. */
export type PanelField = {
    key: string;
    type: 'text' | 'textarea' | 'select' | 'boolean' | 'number';
    label: string;
    required: boolean;
    default: string | number | boolean | null;
    maxLength: number | null;
    options: { value: string; label: string }[];
    min: number | null;
    max: number | null;
};

/** One of a plugin panel's actions: its button, its confirmation and its form. */
export type PanelAction = {
    id: string;
    label: string;
    confirm: string | null;
    fields: PanelField[];
};

/** A plugin's panel in the editor (EditorPanels::toolsOn): a tool in the bar, owned by the plugin. */
export type EditorPluginPanel = {
    /** The tool's id, plugin.{code}.{panel}. */
    tool: string;
    code: string;
    id: string;
    /** The plugin's name, which marks the pane. */
    plugin: string;
    label: string;
    description: string;
    icon: string;
    actions: PanelAction[];
};

/** What one suggestion would change, as core checked it against the item. */
export type PanelChange =
    | { kind: 'title'; title: string }
    | {
          kind: 'field';
          key: string;
          handle: string;
          label: string;
          value: string | number | boolean | null;
      }
    | {
          kind: 'terms';
          taxonomy: string;
          taxonomyLabel: string;
          terms: { id: string; name: string; taxonomy: string }[];
          create: string[];
      }
    | {
          kind: 'link';
          phrase: string;
          itemId: string;
          occurrence: number;
          target: string;
      }
    | { kind: 'body'; document: EditorDocument };

/** One of a panel's suggestions. */
export type PanelSuggestion = {
    id: string;
    label: string;
    detail: string;
    change: PanelChange;
};

/** One of a panel's jobs on this item. */
export type PanelJob = {
    id: string;
    label: string;
    state: 'waiting' | 'running' | 'finished' | 'failed' | 'cancelled';
    done: number;
    total: number | null;
    message: string | null;
};

/** What a panel shows (PanelReads): its status, its suggestions, its jobs, and whether to ask again. */
export type PanelAnswer = {
    status: string | null;
    tone: 'ok' | 'neutral' | 'info' | 'warn' | 'danger';
    suggestions: PanelSuggestion[];
    failed: string | null;
    jobs: PanelJob[];
    busy: boolean;
    /** The plugin's own AI is switched off, or its budget for the month is spent. */
    ai: 'off' | 'budget' | null;
};
