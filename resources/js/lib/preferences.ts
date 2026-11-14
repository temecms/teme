import { router } from '@inertiajs/react';
import PreferencesController from '@/actions/App/Http/Admin/Account/PreferencesController';
import { send } from '@/components/editor/editor-http';
import type { Preferences } from '@/types';

/**
 * Saves some of the person's preferences in the background, as JSON, so the
 * page neither re-renders nor raises a toast; the route carries its own
 * method. Resolves to whether the server kept them.
 */
export async function savePreferences(
    values: Partial<Preferences>,
): Promise<boolean> {
    try {
        const answer = await send(PreferencesController.update(), {
            preferences: values,
        });

        return answer.status < 400;
    } catch {
        return false;
    }
}

/** The look that is the stylesheet's own default, and needs no attribute. */
const DEFAULT_LOOK = 'dark_chrome';

/**
 * Puts the person's look and high contrast on <html>, as the server's first
 * render does, when they change without a full page load. Only token values
 * follow from them (I-198): no component reads which look is on.
 */
export function applyLook(look: string, highContrast: boolean): void {
    if (typeof document === 'undefined') {
        return;
    }

    const root = document.documentElement;

    if (look === DEFAULT_LOOK || look === '') {
        delete root.dataset.look;
    } else {
        root.dataset.look = look;
    }

    if (highContrast) {
        root.dataset.contrast = 'high';
    } else {
        delete root.dataset.contrast;
    }
}

/** The preferences that change how the admin looks, applied as they are chosen. */
export type LookPreference = 'appearance.look' | 'appearance.high_contrast';

/**
 * What became of a chosen look: kept by the server; put back after the
 * server refused it; or refused once another choice had replaced it, so
 * nothing was put back.
 */
export type LookOutcome = 'saved' | 'put back' | 'replaced';

/**
 * Applies a look at once and saves it in the background (I-198). It
 * replaces the shared `preferences` prop on the page, so the layout puts it
 * on <html>, and a visit back through the browser's history reads it too.
 * A save the server refuses puts `before` back, but only while the refused
 * value is still the one shown: a later choice is never undone.
 */
export async function chooseLook<K extends LookPreference>(
    key: K,
    value: Preferences[K],
    before: Preferences[K],
): Promise<LookOutcome> {
    router.replaceProp('preferences', (current: Preferences) => ({
        ...current,
        [key]: value,
    }));

    if (await savePreferences({ [key]: value } as Partial<Preferences>)) {
        return 'saved';
    }

    return new Promise<LookOutcome>((resolve) => {
        let putBack = false;

        router.replaceProp(
            'preferences',
            (current: Preferences) => {
                putBack = current[key] === value;

                return putBack ? { ...current, [key]: before } : current;
            },
            { onFinish: () => resolve(putBack ? 'put back' : 'replaced') },
        );
    });
}
