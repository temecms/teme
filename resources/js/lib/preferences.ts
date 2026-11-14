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
