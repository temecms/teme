import { useEffect, useId, useState } from 'react';
import type { PaletteCommand } from '@/components/palette/palette-context';
import { usePaletteCommands } from '@/components/palette/use-palette';

/** The window event the palette's shortcut sends, its detail `{ target }` (I-1318). */
export const ALT_TEXT_EVENT = 'teme:alt-text';

// The open details that offer Suggest alt text, the most recent last. The
// editor's media window can open the drawer over itself on the same image.
let targets: string[] = [];

/** The open details the palette's "Suggest alt text for this image" asks through, or null when none is open. */
export function altTextTarget(): string | null {
    return targets.at(-1) ?? null;
}

/**
 * Makes these details the palette's "this image" until they close, and
 * returns the token its event names them by.
 */
export function useAltTextTarget(): string {
    const token = useId();

    useEffect(() => {
        targets = [...targets, token];

        return () => {
            targets = targets.filter((target) => target !== token);
        };
    }, [token]);

    // No commands of its own: registering makes the palette read every command's `when` again.
    const [none] = useState<PaletteCommand[]>([]);
    usePaletteCommands(none);

    return token;
}
