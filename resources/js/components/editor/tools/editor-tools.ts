import { usePage } from '@inertiajs/react';
import type { FileText } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { __ } from '@/lib/i18n';
import { savePreferences } from '@/lib/preferences';
import { togglePin } from './pins';

/** A tool the editor's bar and Tools offer (docs/architecture/design-system.md, 4.39). */
export type EditorTool = {
    id: string;
    /** 'core' for Teme's own; a plugin's name for a plugin's. */
    owner: string;
    label: string;
    icon: typeof FileText;
    /** How large it opens. */
    size: 'pane' | 'window' | 'full';
    /** False while the part that fills it is not built: nothing is drawn for it. */
    available: boolean;
};

/** The tools always on the bar, before the pins. */
export const FIXED_TOOLS = ['outline', 'history'];

/** The tools a person may pin, as the server's preference allows them. */
export const PINNABLE_TOOLS = ['assistant', 'share'];

/** Pinned when a person has chosen nothing, as the server declares it. */
const DEFAULT_PINS = ['assistant', 'share'];

/**
 * This person's pins, read from their preferences and saved there at once;
 * a refused save puts the old pins back and says so.
 */
export function usePins(): {
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
        const next = togglePin(pins, id, PINNABLE_TOOLS);

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
