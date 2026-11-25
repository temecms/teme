import { icons } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

// The whole Lucide set in one object, so the bundler keeps it in one lazy
// file instead of one file an icon (I-3311). Import this only from modules
// that are themselves loaded lazily.

/**
 * A Lucide icon's stored name: its PascalCase key in kebab case, so
 * `FileText` is `file-text` and `ArrowDown01` is `arrow-down01`.
 */
function storedName(key: string): string {
    return key
        .replace(/([a-z0-9])([A-Z])/g, '$1-$2')
        .replace(/([A-Z])([A-Z][a-z])/g, '$1-$2')
        .toLowerCase();
}

/** A name with its hyphens dropped and in lower case, the form names are looked up by. */
export function squashedName(name: string): string {
    return name.replaceAll('-', '').toLowerCase();
}

const byName = new Map<string, LucideIcon>(
    Object.entries(icons).map(([key, icon]) => [squashedName(key), icon]),
);

// The set must stay one lazy file (I-3311): the icons group in vite.config.ts depends on it.
/** Every Lucide icon with its stored name, in alphabetical order of name. */
export const lucideIcons: readonly { name: string; Icon: LucideIcon }[] =
    Object.entries(icons)
        .map(([key, Icon]) => ({ name: storedName(key), Icon }))
        .sort((left, right) => left.name.localeCompare(right.name));

/**
 * Core cards name their icons by the card (CardMenuEntry), as the editor's
 * insert menu draws them; the Lucide icon each of those names stands for.
 */
const CARD_ICONS: Record<string, string> = {
    audio: 'music',
    button: 'mouse-pointer-click',
    callout: 'message-square',
    embed: 'globe',
    faq: 'circle-help',
    gallery: 'images',
    steps: 'footprints',
};

/**
 * The Lucide icon a stored name names, or undefined when it names none.
 * Both `arrow-down01` and Lucide's own `arrow-down-0-1` find the same icon,
 * and a core card's own icon name finds the one its insert menu shows.
 */
export function lucideIcon(name: string): LucideIcon | undefined {
    return (
        byName.get(squashedName(name)) ??
        (Object.hasOwn(CARD_ICONS, name)
            ? byName.get(squashedName(CARD_ICONS[name]))
            : undefined)
    );
}
