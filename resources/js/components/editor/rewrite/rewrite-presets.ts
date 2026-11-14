/** The rewrite task's key, as the server registers it. */
export const REWRITE_TASK = 'rewrite';

/**
 * The rewrite's seven presets (I-1309), in the menu's order: the value is
 * the request's variant, the label what people read. The server's list is
 * RewritePreset, and a test holds the two together.
 */
export const REWRITE_PRESETS = [
    { value: 'improve', label: 'Improve' },
    { value: 'shorter', label: 'Shorter' },
    { value: 'longer', label: 'Longer' },
    { value: 'simpler', label: 'Simpler' },
    { value: 'fix-spelling-and-grammar', label: 'Fix spelling and grammar' },
    { value: 'more-formal', label: 'More formal' },
    { value: 'more-casual', label: 'More casual' },
] as const;
