import type { AccentSchemes } from './design';
import type { CardMedia } from '@/types/editor';

export type FieldType =
    | 'text'
    | 'textarea'
    | 'number'
    | 'boolean'
    | 'select'
    | 'multiselect'
    | 'url'
    | 'email'
    | 'secret'
    | 'timezone'
    | 'color'
    | 'media'
    | 'pairs'
    | 'urls'
    | 'person'
    | 'hours';

export type FieldDefinition = {
    key: string;
    type: FieldType;
    label: string;
    description: string;
    options: Record<string, string>;
    min: number | null;
    max: number | null;
    maxLength: number | null;
    required: boolean;
    example: string | null;
    /** The unit a number counts in, shown beside its field; null for none. */
    unit: string | null;
    /** A person setting's choices: who may write, by name, and the chosen person while they have an account. */
    people?: { value: string; label: string }[];
    /** The other settings' values this one shows for: every entry must hold (empty: always). */
    visibleWhen?: Record<string, string | string[]>;
};

export type SecretStatus = {
    isSet: boolean;
    source: 'database' | 'environment' | null;
};

/** One Settings page in the Settings menu, as `settingsNav` shares it. */
export type SettingsNavItem = {
    key: string;
    label: string;
    href: string;
    /** "Site", or "Plugin settings" over a plugin's group. */
    heading: string;
};

/** What Find a setting answers: Settings pages, then settings, in the menu's order. */
export type SettingsFound = {
    screens: { key: string; label: string; href: string }[];
    settings: { key: string; label: string; screen: string; href: string }[];
};

/** Reading's Home page: the addresses group's three settings, and whether this person may change them. */
export type HomePageSection = {
    frontShows: string;
    frontPageId: string;
    postsPageId: string;
    frontModes: { latest: string; page: string };
    /** The chosen pages' titles, by ID. */
    chosenPages: Record<string, string>;
    /** The types the latest list holds, as the Home page names them: "posts", "posts and films"; "items" when none is ticked. */
    latestWords: string;
    /** The types a home page or posts page may be: public, with no archive. */
    pageTypes: string[];
    canChange: boolean;
};

/** General's brand colour (I-457): the design group's accent, and what its contrast is read against. */
export type BrandColourSection = {
    value: string;
    /** The live theme's own accent, used while the brand colour is empty. */
    themeAccent: string | null;
    /** The live theme's backgrounds: checked on light, lightened for dark. */
    bases: { light: string; dark?: string };
    /** The two text colours the brand colour can take. */
    onColors: [string, string];
    /** How each scheme draws the saved colour, as the server works it out; null while it is empty. */
    schemes: AccentSchemes | null;
    version: number;
    canChange: boolean;
};

export type SettingsGroupPage = {
    /** The page's own heading: a renamed group's (Writing, People), else the group's. */
    heading: { key: string; label: string; description: string };
    /** The heading the group's form sits under, such as People's "Sign-in rules", or null. */
    section: string | null;
    /** `sensitive`: saving asks for a fresh password confirmation. */
    group: {
        key: string;
        label: string;
        description: string;
        sensitive: boolean;
    };
    fields: FieldDefinition[];
    values: Record<string, unknown>;
    secrets: Record<string, SecretStatus>;
    media: Record<string, CardMedia | null>;
    version: number;
    /** Reading's line under Posts per page: the live theme's name, or null, and its own number. */
    themePerPage: { theme: string | null; number: number } | null;
    homePage: HomePageSection | null;
    brandColour: BrandColourSection | null;
    /** Media's image widths, as information: not a setting. */
    imageWidths: { label: string; value: string; hint: string } | null;
    /** General's panels, each its title, its line and its settings' keys, or null for one panel. */
    panels: { title: string; description: string; keys: string[] }[] | null;
    /** The group's words in other languages (docs/proposals/multilingual-screens.md, 6): null on a site with one language or a group with no words. */
    words?: SettingsWordsSection | null;
    /** One sentence above the form when something needs the person, such as Updates' Dokploy key; null on most pages. */
    notice?: string | null;
};

/** A settings group's "In other languages" table. */
export type SettingsWordsSection = {
    /** The site's other languages, in order. */
    languages: { tag: string; name: string }[];
    /** The group's word settings. */
    fields: { key: string; label: string; multiline: boolean }[];
    /** The default language's words, each field's placeholder. */
    defaults: Record<string, string>;
    /** Each language's own words, by tag; a word left out falls back. */
    values: Record<string, Record<string, string>>;
    version: number;
};

export type DiscussionFieldset = {
    legend: string;
    /** The fieldset's one line, under its title. */
    description: string;
    /** A drawn line of words that isn't a setting, first in its fieldset, or null (D4). */
    info: { label: string; value: string; hint: string } | null;
    fields: FieldDefinition[];
};

export type DiscussionSettingsPage = {
    /** The discussion group's description, under the page's heading. */
    description: string;
    /** The switch (E1): its field, its value, its group's version, and whether this person may change it. */
    comments: {
        field: FieldDefinition;
        value: 'on' | 'off';
        version: number;
        canSwitch: boolean;
    };
    /** v9's five fieldsets with their settings, or [] while comments are off (E1). */
    fieldsets: DiscussionFieldset[];
    /** The discussion group's values, defaults applied; {} while comments are off. */
    values: Record<string, unknown>;
    /** The discussion group's version, to save against. */
    version: number;
};

export type SiteCodeEntry = {
    id: string;
    name: string;
    placement: 'head' | 'body-start' | 'body-end';
    code: string;
    on: boolean;
    structuredData: boolean;
    origins: string[]; // the hosts declared for it, without https://
    found: string[]; // the origins found in its code, with https://
    changedBy: string;
    changedAt: string; // ISO 8601, UTC
};

export type SiteCodeChange = {
    id: number;
    what: 'Added' | 'Changed' | 'Switched on' | 'Switched off' | 'Deleted';
    name: string;
    who: string;
    when: string; // ISO 8601, UTC
    before: string | null; // "{label}, on|off\n{code}"
    after: string | null;
    beforeOrigins: string[] | null; // the declared hosts; null where there was nothing
    afterOrigins: string[] | null;
};

export type SiteCodePage = {
    adsTxt: string;
    adsTxtMode: 'text' | 'redirect';
    adsTxtRedirect: string;
    adsTxtVersion: number;
    locked: boolean;
    siteTimezone: string;
    places: {
        key: 'head' | 'body-start' | 'body-end';
        label: string;
        where: string;
        entries: SiteCodeEntry[];
    }[];
    changes: SiteCodeChange[];
};

export type SearchAndSocialRow = {
    /** TitlesAndListing::template()'s row: `type:{key}`, `taxonomy:{key}`, `home`, `author`, `date`, `search`, `not_found`. */
    key: string;
    label: string;
    /** The template setting's key, and its default for the placeholder and an empty row. */
    template: string;
    default: string;
    /** The hide box's setting key, or null where the row has none. */
    hide: string | null;
    /** The words in the hide box's place, or null. */
    note: string | null;
    /** The example's tokens, and the home row's second page's (I-904), or null. */
    tokens: Record<string, string>;
    pageTwo: Record<string, string> | null;
};

export type SearchAndSocialSection = {
    key: string;
    label: string;
    /** Core's words under the section's settings (SearchAndSocialScreen::NOTES), or null. */
    note: string | null;
    fields: FieldDefinition[];
};

export type PublisherScreen = {
    /** Each kind in the order drawn; group names its option group, null for Nobody and A person. */
    kinds: { value: string; label: string; group: string | null }[];
    /** What an empty Name gives. */
    siteTitle: string;
    /** The person the site represents has no account (I-878). */
    personGone: boolean;
};

export type SearchAndSocialPage = {
    description: string;
    /** app()->isProduction(): false shows the notice (I-873). */
    live: boolean;
    discouraged: boolean;
    /** Absolute addresses, made now (rule 9); the sitemap's null while discouraged. */
    files: { sitemap: string | null; robots: string; feed: string };
    sections: SearchAndSocialSection[];
    titles: {
        separator: string;
        themeName: string;
        tokens: string[];
        rows: SearchAndSocialRow[];
    };
    robots: { text: string; crawlers: string[] };
    /** Settings › Site code for whoever holds site-code.manage, else null. */
    siteCodeUrl: string | null;
    /** Tools › Redirects' 404 log for whoever holds redirects.manage, else null. */
    notFoundLogUrl: string | null;
    values: Record<string, unknown>;
    media: Record<string, CardMedia | null>;
    publisher: PublisherScreen;
    version: number;
};
