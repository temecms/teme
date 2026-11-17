import { usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { toast } from 'sonner';
import { __ } from '@/lib/i18n';
import { savePreferences } from '@/lib/preferences';
import { togglePin } from './pins';

export { offeredTools } from './pins';

/** One tool in the launcher (docs/architecture/design-system.md, 6.1's Tool surface): core's, or a plugin's panel. */
export type EditorTool = {
    id: string;
    owner: string;
    label: string;
    icon: LucideIcon;
    size: 'pane' | 'dialog' | 'workspace';
    openLarger?: boolean;
    /** Outline and History: always on the bar, never pinnable. */
    fixed?: boolean;
    /** False hides it for this item (the Assistant without AI, Share on a theme region). */
    available: boolean;
    render: () => ReactNode;
};

/** The tools a person may pin, as the server's preference allows them. */
export const PINNABLE_TOOLS = ['assistant', 'share'];

/** Pinned when a person has chosen nothing, as the server declares it. */
const DEFAULT_PINS = ['assistant', 'share'];

/**
 * This person's pins, read from their preferences and saved there at once;
 * a refused save puts the old pins back and says so. `pinnable` adds the
 * plugins' panels this person sees to core's own.
 */
export function usePins(pinnable: string[] = PINNABLE_TOOLS): {
    pins: string[];
    saving: boolean;
    refused: boolean;
    toggle: (id: string) => void;
} {
    const stored = usePage().props.preferences['editor.pinned_tools'];
    const [pins, setPins] = useState<string[]>(() =>
        Array.isArray(stored) ? (stored as string[]) : DEFAULT_PINS,
    );
    const [saving, setSaving] = useState(false);
    const [refused, setRefused] = useState(false);

    const toggle = (id: string) => {
        const before = pins;
        const next = togglePin(pins, id, pinnable);

        setRefused(next.refused);

        if (next.refused || next.pins === pins) {
            return;
        }

        setPins(next.pins);
        setSaving(true);
        void savePreferences({ 'editor.pinned_tools': next.pins }).then(
            (kept) => {
                setSaving(false);

                if (!kept) {
                    setPins(before);
                    toast.error(__("Couldn't save your pinned tools."));
                }
            },
        );
    };

    return { pins, saving, refused, toggle };
}
