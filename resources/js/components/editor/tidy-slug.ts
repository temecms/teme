/**
 * Tidies a typed address to match the server's slug rules: letters and
 * numbers of every script, lower case, joined by single hyphens. A pasted
 * full link keeps only its path.
 *
 * @package Teme
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
 * A slug from free text: lower case, letters of every script kept with their
 * combining marks (Mn/Mc, as Hindi and Thai need), words joined by single
 * hyphens. Empty when nothing usable remains. Matches the server's Slugs rule.
 */
export function tidySlug(raw: string): string {
    let text = pathOf(raw).normalize('NFC');

    text = text.toLocaleLowerCase();
    text = text.replaceAll('_', '-').replaceAll('@', '-at-');
    // Letters and numbers with their vowel signs; drop the rest.
    text = text.replace(/[^\p{Ll}\p{Lo}\p{N}\p{Mn}\p{Mc}-]+/gu, '-');
    // A mark belongs to the letter before it; one with none goes.
    text = text.replace(/(?<=^|-)[\p{Mn}\p{Mc}]+/gu, '');
    text = text.replace(/-{2,}/g, '-').replace(/^-+|-+$/g, '');

    return text.slice(0, 200).replace(/-+$/g, '');
}

/**
 * The address made from a title when the field is emptied, or "untitled"
 * when the title gives nothing usable. Used for the live preview; an empty
 * field still sends empty so the server makes the title's address.
 */
export function slugFromTitle(title: string): string {
    return tidySlug(title) || 'untitled';
}
