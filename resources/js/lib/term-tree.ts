/** A term of a nesting taxonomy, as `TermPicker::tree()` sends it. */
export type TreeTerm = { id: string; name: string; parentId: string | null };

/**
 * A tree's terms in order, each child under its parent, with its depth. A
 * term whose parent isn't among them is drawn at the top level.
 */
export function inTreeOrder(
    terms: TreeTerm[],
): { term: TreeTerm; depth: number }[] {
    const known = new Set(terms.map((term) => term.id));
    const rows: { term: TreeTerm; depth: number }[] = [];
    const visit = (parent: string | null, depth: number) => {
        for (const term of terms) {
            const parentOf =
                term.parentId !== null && known.has(term.parentId)
                    ? term.parentId
                    : null;

            if (parentOf === parent) {
                rows.push({ term, depth });
                visit(term.id, depth + 1);
            }
        }
    };

    visit(null, 0);

    return rows;
}
