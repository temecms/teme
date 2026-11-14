import { createContext, useContext } from 'react';
import type { CardManifest, CardMedia, UploadTarget } from '@/types';

/**
 * What a card's view needs from the editor around it: the media map the
 * server's renderer also uses, the manifest, and a way to fetch the media a
 * card has just been given (content.card-media), and the item being edited,
 * which uploads made here record.
 */
export type CardEnvironment = {
    media: Record<string, CardMedia>;
    /** Media on its way from content.card-media: not gone, only not here yet. */
    loading: string[];
    manifest: CardManifest;
    addMedia: (ids: string[]) => Promise<void>;
    /** The item open in the editor, which every upload made in it is for (I-553). */
    uploadedFor: UploadTarget;
};

export const CardEnvironmentContext = createContext<CardEnvironment | null>(
    null,
);

/** The card environment; card views render only inside ProseEditor. */
export function useCardEnvironment(): CardEnvironment {
    const environment = useContext(CardEnvironmentContext);

    if (environment === null) {
        throw new Error('A card view renders only inside ProseEditor.');
    }

    return environment;
}

/**
 * The item uploads are made for: the one open in the editor, or null
 * anywhere else, where an upload is made for the library alone (I-553).
 */
export function useUploadTarget(): UploadTarget | null {
    return useContext(CardEnvironmentContext)?.uploadedFor ?? null;
}

/**
 * What a card's settings are given, in the card's own panel or the rail's
 * inspector alike: its attributes, and a way to change some of them.
 */
export type CardSettingsProps = {
    attrs: Record<string, unknown>;
    update: (attrs: Record<string, unknown>) => void;
};

/** An attribute's allowed values, from its `in:` rule in the manifest. */
export function optionsFor(
    manifest: CardManifest,
    card: string,
    attribute: string,
): { value: string; label: string }[] {
    const values =
        manifest.cards
            .find((entry) => entry.name === card)
            ?.attributes.find((entry) => entry.name === attribute)?.options ??
        [];

    return values.map((value) => ({
        value,
        label: value.charAt(0).toUpperCase() + value.slice(1),
    }));
}
