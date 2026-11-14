import type { EditorState, Transaction } from '@tiptap/pm/state';

/** A link the server stored differently: the address sent, and the one kept, or null where only the words stay. */
export type LinkChange = { from: string; to: string | null };

/**
 * The server's link changes made in the editor, so it shows what is saved
 * (I-452): each link whose address changed takes the new one; one removed
 * keeps its words. Only marks change, so the cursor stays where it is. It is
 * kept out of the undo history, because undoing it would bring back an
 * address the next save changes again. Null when nothing changed.
 */
export function applyLinkChanges(
    state: EditorState,
    changes: LinkChange[],
): Transaction | null {
    const link = state.schema.marks.link;
    const kept = new Map(changes.map((change) => [change.from, change.to]));
    const tr = state.tr;

    state.doc.descendants((node, pos) => {
        if (!node.isText) {
            return true;
        }

        const mark = node.marks.find(
            (each) => each.type === link && kept.has(each.attrs.href as string),
        );

        if (mark !== undefined) {
            const href = kept.get(mark.attrs.href as string) ?? null;
            tr.removeMark(pos, pos + node.nodeSize, mark);

            if (href !== null) {
                tr.addMark(
                    pos,
                    pos + node.nodeSize,
                    link.create({ ...mark.attrs, href }),
                );
            }
        }

        return false;
    });

    return tr.docChanged ? tr.setMeta('addToHistory', false) : null;
}

/** A label as a notice quotes it, shortened past 40 characters. */
function quoted(label: string): string {
    return `“${label.length > 40 ? `${label.slice(0, 39)}…` : label}”`;
}

/** "a", "a and b", "a, b and c". */
function listed(items: string[]): string {
    return items.length < 2
        ? items.join('')
        : `${items.slice(0, -1).join(', ')} and ${items[items.length - 1]}`;
}

/**
 * What the editor says when a save removed links, naming each address
 * once; null when none was removed. A link the server kept, rewrote or
 * turned into an article's ID needs no word.
 */
export function linkChangesNotice(changes: LinkChange[]): string | null {
    const removed = [
        ...new Set(
            changes
                .filter((change) => change.to === null)
                .map((change) => change.from),
        ),
    ];

    if (removed.length === 0) {
        return null;
    }

    const one = removed.length === 1;

    return `${one ? 'A link was' : `${removed.length} links were`} removed and ${one ? 'its words' : 'their words'} kept: ${listed(removed.map(quoted))}. A link can go to an item on this site, a page on this site, a web or email address, a phone number, or a place on a page.`;
}
