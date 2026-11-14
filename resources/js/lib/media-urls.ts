import type { CardMedia } from '@/types/editor';

type Variants = CardMedia['variants'];

/**
 * One variant's URL, or null when the item has no variant by that key. The
 * browser's MediaUrls::variant(): the cards call it with the same keys.
 */
export function variant(variants: Variants, key = 'thumb'): string | null {
    return variants[key]?.url ?? null;
}

/** The widest `w…` variant's URL, or null when there is none: MediaUrls::largest(). */
export function largest(variants: Variants): string | null {
    const widths = Object.entries(variants)
        .filter(([key]) => key.startsWith('w'))
        .sort(([, a], [, b]) => b.width - a.width);

    return widths[0]?.[1].url ?? null;
}
