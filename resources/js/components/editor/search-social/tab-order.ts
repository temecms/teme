/** A tab in the Search and social box: core's, or one a plugin adds where its author declared (I-3418). */
export type BoxTab = {
    id: string;
    owner: string;
    position?: 'after-search' | 'after-social' | 'last';
    order?: number;
};

const CORE = ['search', 'social', 'schema', 'advanced'];

/** Core's tabs sit at 0 to 3; an added tab sits between them. */
const SLOTS = { 'after-search': 0.5, 'after-social': 1.5, last: 3.5 } as const;

/** Core's tabs in their order, and each added tab where its author declared it; Search is always first (I-3418). */
export function orderTabs(added: BoxTab[]): string[] {
    const core = CORE.map((id, index) => ({ id, slot: index, order: 0 }));
    const placed = added
        .filter((tab) => tab.owner !== 'core' && !CORE.includes(tab.id))
        .map((tab) => ({
            id: tab.id,
            slot: SLOTS[tab.position ?? 'last'],
            order: tab.order ?? 0,
        }));

    return [...core, ...placed]
        .sort(
            (a, b) =>
                a.slot - b.slot ||
                a.order - b.order ||
                a.id.localeCompare(b.id),
        )
        .map((tab) => tab.id);
}
