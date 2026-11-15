/** The Status banner the admin shows at the top of every screen (I-221). */
export type StatusBanner = {
    level: 'error' | 'warning' | 'info';
    message: string;
    actionLabel: string | null;
    actionUrl: string | null;
    /** What the row is about: the check's area, or the plugin's name. */
    label: string;
    /** The plugin that raised it; null for Teme's own. */
    plugin: string | null;
};
