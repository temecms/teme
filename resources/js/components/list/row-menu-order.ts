/** The "…" menu's order (I-3398): core actions, then plugins' in their order, then the destructive ones, last. */
export function orderRowMenu<
    T extends { owner: string; destructive?: boolean },
>(items: T[]): { core: T[]; plugins: T[]; destructive: T[] } {
    return {
        core: items.filter(
            (item) => item.owner === 'core' && item.destructive !== true,
        ),
        plugins: items.filter(
            (item) => item.owner !== 'core' && item.destructive !== true,
        ),
        destructive: items.filter((item) => item.destructive === true),
    };
}
