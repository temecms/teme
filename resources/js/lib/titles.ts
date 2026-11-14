/**
 * An item's title where it is the whole text of a link, a button, a chip,
 * a choice or a label: "(no title)" when it has none, as WordPress's admin
 * shows an empty one (I-425).
 */
export function shownTitle(title: string | null | undefined): string {
    return title?.trim() || '(no title)';
}

/** An item's title quoted inside a sentence: "Untitled" when it has none. */
export function quotedTitle(title: string | null | undefined): string {
    return title?.trim() || 'Untitled';
}
