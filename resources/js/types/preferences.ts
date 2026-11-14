/**
 * The signed-in person's preferences, defaults applied (PreferencesFor), as
 * every screen reads them from the shared `preferences` prop. Keyed by the
 * built-in keys; a preference a later part declares arrives as another key.
 */
export type Preferences = {
    'editor.spellcheck': boolean;
    'editor.smart_quotes': boolean;
    'editor.rail_width': number;
    'editor.focus_mode_default': boolean;
    'lists.per_page': number;
    'appearance.mode': 'light' | 'dark' | 'system';
    'dashboard.getting_started_hidden': boolean;
    /** Screen options: the columns each type's list shows, by column id. */
    [key: `lists.${string}.columns`]: string[];
    'nav.hidden': string[];
    'nav.order': string[];
    'nav.pinned': string[];
    [key: string]: unknown;
};
