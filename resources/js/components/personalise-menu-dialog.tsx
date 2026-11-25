import { router } from '@inertiajs/react';
import { useState } from 'react';
import { ResponsiveDialogContent } from '@/components/patterns/responsive-dialog';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { __ } from '@/lib/i18n';
import { navIcon } from '@/lib/nav-icons';
import { savePreferences } from '@/lib/preferences';
import type { AdminNavEntry } from '@/types';

const headings: Record<AdminNavEntry['section'], string> = {
    home: 'Home',
    content: 'Content',
    site: 'Site',
};

/**
 * Customise menu: small ticks show or hide entries and apply at once.
 * No move, pin, reset or save; pinned entries are gone. Hidden entries
 * stay in the command palette.
 */
export function PersonaliseMenuDialog({
    nav,
    open,
    onOpenChange,
}: {
    nav: AdminNavEntry[];
    pinned: string[];
    storedOrder: string[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const sections = (
        Object.keys(headings) as AdminNavEntry['section'][]
    ).filter((section) => nav.some((entry) => entry.section === section));
    const [hidden, setHidden] = useState(() =>
        nav.filter((entry) => entry.hidden).map((entry) => entry.key),
    );
    const [saving, setSaving] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);

    const applyHidden = async (next: string[]) => {
        const previous = hidden;
        setHidden(next);
        setSaving(true);
        setProblem(null);
        const saved = await savePreferences({
            'nav.hidden': next,
            'nav.pinned': [],
        });
        setSaving(false);

        if (!saved) {
            setHidden(previous);
            setProblem("Couldn't save your menu. Try again in a moment.");

            return;
        }

        router.reload();
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <ResponsiveDialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{__('Customise menu')}</DialogTitle>
                    <DialogDescription>
                        {__(
                            'Only you see these changes. The command palette still finds hidden entries.',
                        )}
                    </DialogDescription>
                </DialogHeader>

                {problem !== null && (
                    <p role="alert" className="text-danger-ink text-small">
                        {problem}
                    </p>
                )}

                {sections.map((section) => (
                    <fieldset key={section} className="grid gap-1">
                        <legend className="text-caption text-ink-3 mb-1 font-semibold tracking-[0.04em] uppercase">
                            {headings[section]}
                        </legend>
                        <ul className="grid">
                            {nav
                                .filter((entry) => entry.section === section)
                                .map((entry) => {
                                    const Icon = navIcon(entry.icon);
                                    const isHidden = hidden.includes(entry.key);

                                    return (
                                        <li
                                            key={entry.key}
                                            className="border-line flex items-center gap-2 border-b py-1 last:border-b-0"
                                        >
                                            <Checkbox
                                                id={`show-${entry.key}`}
                                                checked={!isHidden}
                                                disabled={saving}
                                                onCheckedChange={(checked) => {
                                                    void applyHidden(
                                                        checked
                                                            ? hidden.filter(
                                                                  (hid) =>
                                                                      hid !==
                                                                      entry.key,
                                                              )
                                                            : [
                                                                  ...hidden,
                                                                  entry.key,
                                                              ],
                                                    );
                                                }}
                                                aria-label={__('Show :label', {
                                                    label: entry.label,
                                                })}
                                            />
                                            <Label
                                                htmlFor={`show-${entry.key}`}
                                                className="text-small flex flex-1 items-center gap-2 font-normal"
                                            >
                                                <Icon className="size-4" />
                                                {entry.label}
                                            </Label>
                                        </li>
                                    );
                                })}
                        </ul>
                    </fieldset>
                ))}
            </ResponsiveDialogContent>
        </Dialog>
    );
}
