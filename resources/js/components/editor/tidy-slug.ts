/**
 * Tidies a typed address the way WordPress's cleanForSlug does: accents
 * made plain, lower case, every other character a hyphen, no double
 * hyphens, none at either end. A pasted full link keeps only its path.
 *
 * @package Teme
 * @since next
 */

/**
 * The last path segment of a pasted address, or the typed text with
 * leading and trailing slashes dropped.
 */
function pathOf(raw: string): string {
    const trimmed = raw.trim();

    try {
        if (/^[a-z][a-z0-9+.-]*:/i.test(trimmed)) {
            const url = new URL(trimmed);
            const parts = url.pathname.split('/').filter(Boolean);

            return parts.at(-1) ?? '';
        }
    } catch {
        // Not a URL; tidy the typed text.
    }

    return trimmed.replace(/^\/+|\/+$/g, '');
}

/**
 * A slug from free text: accents plain, lower case, words joined by single
 * hyphens. Empty when nothing usable remains.
 */
export function tidySlug(raw: string): string {
    let text = pathOf(raw);

    // NFD then drop combining marks, as Str::ascii does for Latin letters.
    text = text.normalize('NFD').replace(/\p{M}+/gu, '');
    text = text.toLowerCase();
    text = text.replaceAll('_', '-').replaceAll('@', '-at-');
    text = text.replace(/[^a-z0-9-]+/g, '-');
    text = text.replace(/-{2,}/g, '-').replace(/^-+|-+$/g, '');

    return text.slice(0, 200).replace(/-+$/g, '');
}

/**
 * The address made from a title when the field is emptied, or "untitled"
 * when the title gives nothing usable.
 */
export function slugFromTitle(title: string): string {
    return tidySlug(title) || 'untitled';
}
