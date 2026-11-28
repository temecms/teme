/** The "…" menu's order (I-3398): core actions, then plugins' in their order, then the destructive ones, last. Area tables pass `pluginsFirst` so plugin actions sit above Enable/Disable. */
export function orderRowMenu<
    T extends { owner: string; destructive?: boolean },
>(
    items: T[],
    options: { pluginsFirst?: boolean } = {},
): { core: T[]; plugins: T[]; destructive: T[] } {
    const core = items.filter(
        (item) => item.owner === 'core' && item.destructive !== true,
    );
    const plugins = items.filter(
        (item) => item.owner !== 'core' && item.destructive !== true,
    );
    const destructive = items.filter((item) => item.destructive === true);

    if (options.pluginsFirst === true) {
        return { plugins, core, destructive };
    }

    return { core, plugins, destructive };
}
