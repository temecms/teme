/** A run of a text: a word or the space after it, and whether it changed. */
export type Part = { text: string; changed: boolean };

/** Past this many word pairs, the whole of each side is marked, as a whole replacement. */
const MOST_PAIRS = 250_000;

const SPACE = /^\s+$/;

function tokens(text: string): string[] {
    return text.split(/(\s+)/).filter((token) => token !== '');
}

function marked(texts: string[], changed: boolean): Part[] {
    return texts.map((text) => ({
        text,
        changed: changed && !SPACE.test(text),
    }));
}

/**
 * Which words of the original a proposal removes, and which of its own it
 * adds, by their longest common run; spaces are never marked.
 */
export function wordDiff(
    before: string,
    after: string,
): { removed: Part[]; added: Part[] } {
    const a = tokens(before);
    const b = tokens(after);

    if (a.length * b.length > MOST_PAIRS) {
        return { removed: marked(a, true), added: marked(b, true) };
    }

    // common[i][j]: the longest common run of a from i and b from j.
    const common = Array.from({ length: a.length + 1 }, () =>
        Array.from({ length: b.length + 1 }, () => 0),
    );

    for (let i = a.length - 1; i >= 0; i--) {
        for (let j = b.length - 1; j >= 0; j--) {
            common[i][j] =
                a[i] === b[j]
                    ? common[i + 1][j + 1] + 1
                    : Math.max(common[i + 1][j], common[i][j + 1]);
        }
    }

    const removed: Part[] = [];
    const added: Part[] = [];
    let i = 0;
    let j = 0;

    while (i < a.length && j < b.length) {
        if (a[i] === b[j]) {
            removed.push({ text: a[i], changed: false });
            added.push({ text: b[j], changed: false });
            i++;
            j++;
        } else if (common[i + 1][j] >= common[i][j + 1]) {
            removed.push(...marked([a[i]], true));
            i++;
        } else {
            added.push(...marked([b[j]], true));
            j++;
        }
    }

    removed.push(...marked(a.slice(i), true));
    added.push(...marked(b.slice(j), true));

    return { removed, added };
}
