import type { Editor } from '@tiptap/core';

/** What an entry is told about the moment it is drawn or run. */
export type EditorContext = {
    editor: Editor;
    contentType: string;
    status: string;
    can: (permission: string) => boolean;
    selection: { empty: boolean; cardName: string | null };
};

/**
 * One thing a region shows: the toolbar's Insert menu, the slash menu, the
 * selection bubble, the rail. Core's own entries have owner "core". In phase
 * 5 a plugin's manifest supplies entries of the same shape (plugins.md), and
 * core draws them, so they look like the rest of the editor.
 */
export type RegionEntry = {
    id: string;
    owner: string;
    label: string;
    /** A line saying what it does, for the menus that show one. */
    description?: string;
    icon?: string;
    group: string;
    order: number;
    /** Words someone might type to find it in a searched menu. */
    keywords?: string[];
    when?: (context: EditorContext) => boolean;
    permission?: string;
    run: (context: EditorContext) => void;
};

/**
 * The entries a region draws: those whose `when` holds and whose permission
 * the person has, in their order. An entry that fails either is not drawn.
 */
export function visibleEntries(
    entries: RegionEntry[],
    context: EditorContext,
): RegionEntry[] {
    return entries
        .filter(
            (entry) =>
                (entry.when?.(context) ?? true) &&
                (entry.permission === undefined ||
                    context.can(entry.permission)),
        )
        .sort((a, b) => a.order - b.order);
}

/**
 * Splits a region's entries at its budget: the ones it shows, and the rest,
 * which go under "More". A searched region has no budget.
 */
export function withinBudget(
    entries: RegionEntry[],
    budget: number,
): { shown: RegionEntry[]; more: RegionEntry[] } {
    return { shown: entries.slice(0, budget), more: entries.slice(budget) };
}

/** Whether an entry matches what someone typed in a searched menu. */
export function matches(entry: RegionEntry, query: string): boolean {
    const needle = query.trim().toLowerCase();

    if (needle === '') {
        return true;
    }

    return [entry.label, ...(entry.keywords ?? [])].some((word) =>
        word.toLowerCase().includes(needle),
    );
}
