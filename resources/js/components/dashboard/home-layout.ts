/** A panel's place on Home: its key, its section, and its size. */
export type PanelSize = 'small' | 'medium' | 'large' | 'full';
export type Layout = {
    order: string[];
    hidden: string[];
    sizes: Record<string, PanelSize>;
};
export type Section = 'core' | 'plugins';

/** The section a panel sits in: core's own, or From plugins (I-200). */
export function sectionOf(owner: string): Section {
    return owner === 'core' ? 'core' : 'plugins';
}

/** The shown keys of one section, in the person's order. */
export function shownIn(
    layout: Layout,
    owners: Record<string, string>,
    section: Section,
): string[] {
    return layout.order.filter(
        (key) =>
            key in owners &&
            sectionOf(owners[key]) === section &&
            !layout.hidden.includes(key),
    );
}

/**
 * Moves a panel to `index` among the shown panels of its own section, and
 * returns the new order. Hidden panels and the other section keep their
 * places in the order, so showing a panel again puts it back where it was.
 */
export function moveTo(
    layout: Layout,
    owners: Record<string, string>,
    key: string,
    index: number,
): string[] {
    const section = sectionOf(owners[key]);
    const shown = shownIn(layout, owners, section);
    const from = shown.indexOf(key);
    const to = Math.max(0, Math.min(index, shown.length - 1));

    if (from === -1 || from === to) {
        return layout.order;
    }

    const next = shown.filter((k) => k !== key);
    next.splice(to, 0, key);

    // Write the section's new sequence back into the slots its shown panels held.
    const slots = layout.order
        .map((k, i) => (shown.includes(k) ? i : -1))
        .filter((i) => i !== -1);
    const order = [...layout.order];
    slots.forEach((slot, i) => {
        order[slot] = next[i];
    });

    return order;
}

/** One step earlier (-1) or later (+1); the same order at either end. */
export function moveBy(
    layout: Layout,
    owners: Record<string, string>,
    key: string,
    step: -1 | 1,
): string[] {
    const shown = shownIn(layout, owners, sectionOf(owners[key]));

    return moveTo(layout, owners, key, shown.indexOf(key) + step);
}

/** The preferences to save for a layout: sizes only where they differ from the declared one. */
export function toPreferences(
    layout: Layout,
    declared: Record<string, PanelSize>,
): {
    'dashboard.order': string[];
    'dashboard.hidden': string[];
    'dashboard.sizes': string[];
} {
    return {
        'dashboard.order': layout.order,
        'dashboard.hidden': layout.hidden,
        'dashboard.sizes': Object.entries(layout.sizes)
            .filter(([key, size]) => declared[key] !== size)
            .map(([key, size]) => `${key}:${size}`),
    };
}
