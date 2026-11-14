import { createContext, useContext } from 'react';
import type { CardMedia, EditorEntry, EditorField } from '@/types/editor';

/** What every field control is given by the row that draws it. */
export type FieldControlProps = {
    field: EditorField;
    value: unknown;
    onChange: (value: unknown) => void;
    /** The control's own id; the row's label points at it. */
    id: string;
    /** The ids of the row's help text and counters, space-separated. */
    describedBy?: string;
    /** Whether the row says the value is wrong, so the control is marked invalid. */
    invalid?: boolean;
};

/**
 * What the editor shares with every field control, from edit.tsx: the media
 * it has loaded and how to load more, and the type's noun. Later parts add
 * keys here; a control reads only the keys it needs.
 */
export type FieldControlContextValue = {
    /** The media the editor has loaded, by ID (useCardMedia). */
    media: Record<string, CardMedia>;
    /** Media IDs still being fetched. */
    loading: string[];
    /** Fetches media chosen in the library into `media`. */
    addMedia: (ids: string[]) => Promise<void>;
    /** The type's singular in lower case, for "Alt text for this post". */
    noun: string;
    /** The site's time zone, which a date-and-time field is read and written on. */
    siteTimezone: string;
    /** The items fields point at, from the session, for their first paint. */
    entries: Record<string, EditorEntry>;
};

export const FieldControlContext =
    createContext<FieldControlContextValue | null>(null);

/** The editor's field context; throws outside the editor, where no control belongs. */
export function useFieldControlContext(): FieldControlContextValue {
    const context = useContext(FieldControlContext);

    if (context === null) {
        throw new Error(
            'A field control is drawn outside the editor’s FieldControlContext.',
        );
    }

    return context;
}

/** A field's longest value from its control settings, or undefined for none. */
export function maxLengthOf(field: EditorField): number | undefined {
    const { maxLength } = field.settings;

    return typeof maxLength === 'number' ? maxLength : undefined;
}

/** A stored value as text: the string, or the empty string. */
export function textOf(value: unknown): string {
    return typeof value === 'string' ? value : '';
}
