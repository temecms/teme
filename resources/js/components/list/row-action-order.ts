/**
 * How a row's actions split between its line and More (I-3130): up to four
 * show; past four, the first three and the first destructive one show, in
 * the list's order, and the rest wait under More in theirs. It imports
 * nothing, so Node runs it as it is (tests/Fixtures/row-actions.mjs).
 */
export function splitRowActions<T extends { destructive?: boolean }>(
    items: T[],
): { line: T[]; more: T[] } {
    if (items.length <= 4) {
        return { line: items, more: [] };
    }

    const destructive = items.find((item) => item.destructive === true);
    const first = items
        .filter((item) => item !== destructive)
        .slice(0, destructive === undefined ? 4 : 3);
    const line = items.filter(
        (item) => first.includes(item) || item === destructive,
    );

    return { line, more: items.filter((item) => !line.includes(item)) };
}
