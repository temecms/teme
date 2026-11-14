import { useEffect, useRef, useState } from 'react';
import type { HTMLAttributes, KeyboardEvent } from 'react';
import type { CommentRow } from '@/types';

/** A row's element ID, so focus can be put back on it. */
export const commentRowId = (id: string): string => `cm-row-${id}`;

/** Where Reply and Quick edit are offered: a comment, not a ping, approved or waiting (C8, 1.8c′'s D16). */
export const takesReplyAndQuickEdit = (
    row: Pick<CommentRow, 'kind' | 'status'>,
): boolean =>
    row.kind === 'comment' &&
    (row.status === 'pending' || row.status === 'approved');

/** The statuses A, U, S and T move a comment to (v9's commentKeys). */
const MOVES = new Map([
    ['a', 'approved'],
    ['u', 'pending'],
    ['s', 'spam'],
    ['t', 'trash'],
]);

type Handlers = {
    move: (row: CommentRow, to: string) => void;
    toggle: (row: CommentRow) => void;
    reply: (row: CommentRow) => void;
    quickEdit: (row: CommentRow) => void;
};

/**
 * The Comments screen's keys (C11, I-653; 1.8c′'s D14; v9's commentKeys): J
 * and K move between rows, X selects, A, U, S and T moderate, R replies and Q
 * opens Quick edit. They act only while a row itself has focus: never from a
 * checkbox, button, link or field inside it, nor from a menu or sheet it
 * opened, whose key events React carries up through the row; never with
 * Ctrl, Alt or Meta held. One row is in the tab order at a time, the one last
 * focused; after a move, the row now at the same place takes focus once the
 * list is drawn again.
 */
export function useCommentKeys(rows: CommentRow[], handlers: Handlers) {
    const [focused, setFocused] = useState(0);
    const refocus = useRef(false);
    const at = Math.min(focused, Math.max(rows.length - 1, 0));

    useEffect(() => {
        if (!refocus.current) {
            return;
        }

        refocus.current = false;

        const row = rows[at];

        if (row) {
            document.getElementById(commentRowId(row.id))?.focus();
        }
    }, [rows, at]);

    const onKeyDown = (
        event: KeyboardEvent<HTMLTableRowElement>,
        row: CommentRow,
        index: number,
    ) => {
        if (
            event.target !== event.currentTarget ||
            event.ctrlKey ||
            event.altKey ||
            event.metaKey
        ) {
            return;
        }

        const key = event.key.toLowerCase();
        const to = MOVES.get(key);

        if (key === 'j' || key === 'k') {
            event.preventDefault();

            const next = Math.max(
                0,
                Math.min(rows.length - 1, index + (key === 'j' ? 1 : -1)),
            );
            const target = rows[next];

            if (target) {
                setFocused(next);
                document.getElementById(commentRowId(target.id))?.focus();
            }
        } else if (key === 'x') {
            event.preventDefault();
            handlers.toggle(row);
        } else if (to !== undefined) {
            event.preventDefault();

            // Already there: nothing to send, as the menu offers no such move.
            if (row.status !== to) {
                setFocused(index);
                refocus.current = true;
                handlers.move(row, to);
            }
        } else if (
            (key === 'r' || key === 'q') &&
            takesReplyAndQuickEdit(row)
        ) {
            event.preventDefault();
            setFocused(index);

            if (key === 'r') {
                handlers.reply(row);
            } else {
                handlers.quickEdit(row);
            }
        }
    };

    return {
        /** A row's ID, its place in the tab order, its keys and its focus outline. */
        rowProps: (
            row: CommentRow,
            index: number,
        ): HTMLAttributes<HTMLTableRowElement> => ({
            id: commentRowId(row.id),
            tabIndex: index === at ? 0 : -1,
            onKeyDown: (event) => onKeyDown(event, row, index),
            onFocus: () => setFocused(index),
            className:
                'focus-visible:outline-ring focus-visible:outline-2 focus-visible:-outline-offset-2',
        }),
        /** Puts focus on a row now: after its reply form is cancelled. */
        focusRow: (id: string) =>
            document.getElementById(commentRowId(id))?.focus(),
        /** Puts focus on the row at the place it was once the list is drawn again: after a reply is posted. */
        refocusRow: () => {
            refocus.current = true;
        },
    };
}
