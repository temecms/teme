import { createContext, useContext } from 'react';

/**
 * What the Settings frame (layouts/settings/layout.tsx) lends the page
 * inside it: where the page's header is drawn, across the frame's full
 * width, and the "Saved just now" mark a successful save sets until the
 * next change.
 */
export type SettingsFrame = {
    /** The element the page's header is drawn into; null until it exists. */
    head: HTMLElement | null;
    /** Whether the page's form was just saved, with nothing changed since. */
    saved: boolean;
    setSaved: (saved: boolean) => void;
};

export const SettingsFrameContext = createContext<SettingsFrame | null>(null);

/** The Settings frame around this page, or null outside one (the account's pages). */
export function useSettingsFrame(): SettingsFrame | null {
    return useContext(SettingsFrameContext);
}
