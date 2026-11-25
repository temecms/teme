import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
 * Customise menu: a small menu of ticks that show or hide entries and apply
 * at once. No move, pin, reset or save. Hidden entries stay in the command
 * palette. Existing pins are left alone.
 */
export function PersonaliseMenuDialog({
    nav,
    open,
    onOpenChange,
    trigger,
}: {
    nav: AdminNavEntry[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    trigger: ReactNode;
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
        // Only touch nav.hidden; leave nav.pinned as the person left it.
        const saved = await savePreferences({
            'nav.hidden': next,
        });
        setSaving(false);

        if (!saved) {
            setHidden(previous);
            setProblem(__("Couldn't save your menu. Try again in a moment."));

            return;
        }

        onOpenChange(false);
        router.reload();
    };

    return (
        <DropdownMenu open={open} onOpenChange={onOpenChange}>
            <DropdownMenuTrigger asChild>{trigger}</DropdownMenuTrigger>
            <DropdownMenuContent
                align="start"
                side="top"
                className="max-h-[min(28rem,70vh)] w-72 overflow-y-auto"
                data-test="personalise-menu-panel"
            >
                <DropdownMenuLabel className="text-body text-ink font-semibold">
                    {__('Customise menu')}
                </DropdownMenuLabel>
                <p className="text-caption text-ink-2 px-2 pb-2">
                    {__(
                        'Only you see these changes. The command palette still finds hidden entries.',
                    )}
                </p>

                {problem !== null && (
                    <p
                        role="alert"
                        className="text-danger-ink text-small px-2 pb-2"
                    >
                        {problem}
                    </p>
                )}

                {sections.map((section) => (
                    <DropdownMenuGroup key={section}>
                        <DropdownMenuLabel className="text-caption text-ink-3 font-semibold tracking-[0.04em] uppercase">
                            {headings[section]}
                        </DropdownMenuLabel>
                        {nav
                            .filter((entry) => entry.section === section)
                            .map((entry) => {
                                const Icon = navIcon(entry.icon);
                                const isHidden = hidden.includes(entry.key);

                                return (
                                    <DropdownMenuCheckboxItem
                                        key={entry.key}
                                        data-test={`personalise-${entry.key}`}
                                        checked={!isHidden}
                                        disabled={saving}
                                        onSelect={(event) =>
                                            event.preventDefault()
                                        }
                                        onCheckedChange={(checked) => {
                                            void applyHidden(
                                                checked
                                                    ? hidden.filter(
                                                          (hid) =>
                                                              hid !== entry.key,
                                                      )
                                                    : [...hidden, entry.key],
                                            );
                                        }}
                                        aria-label={__('Show :label', {
                                            label: entry.label,
                                        })}
                                    >
                                        <Icon className="size-4" />
                                        {entry.label}
                                    </DropdownMenuCheckboxItem>
                                );
                            })}
                    </DropdownMenuGroup>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
