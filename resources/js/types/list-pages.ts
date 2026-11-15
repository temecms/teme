import type { SortDirection } from '@/components/list/data-table';

/**
 * Where a page sits among a list's pages (I-3421): its number, or null past
 * the cap; the last numbered page and whether more lie beyond it; the rows'
 * places, null past the cap; the total where it is known; and the keyset
 * cursors either side, used past the cap and by Next on the last numbered page.
 */
export type ListPages = {
    number: number | null;
    last: number;
    more: boolean;
    from: number | null;
    to: number | null;
    total: number | null;
    newer: string | null;
    older: string | null;
};

/**
 * How a list draws its rows as cards on a phone (I-3422): which columns make
 * the facts line, in order, and which hold the title, the tick box, the menu
 * and a body drawn full width.
 */
export type CardLayout = {
    /** Column ids shown as the facts line, in this order. */
    facts: string[];
    /** A column drawn full width under the title, such as a comment's text. */
    body?: string;
    /** The title column and the tick column. */
    titleColumn: string;
    select?: string;
    /** The column whose cell is the "…" menu, drawn at the card's top right. */
    menu?: string;
    /** The words beside the select column's own box above the cards, such as "Select all 25 posts shown". */
    selectLabel?: string;
    /** The sort control above the cards: the sortable columns and the one in force. */
    sort?: {
        options: { key: string; label: string }[];
        current: string;
        direction: SortDirection;
    };
};
