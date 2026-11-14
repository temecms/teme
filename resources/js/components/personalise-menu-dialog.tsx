import { router } from '@inertiajs/react';
import { ChevronDown, ChevronUp, GripVertical, Pin } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { navIcon } from '@/lib/nav-icons';
import { savePreferences } from '@/lib/preferences';
import type { AdminNavEntry } from '@/types';

/** The most entries a person may pin (nav.pinned's limit). */
const MAX_PINNED = 5;

const headings: Record<AdminNavEntry['section'], string> = {
    home: 'Home',
    content: 'Content',
    site: 'Site',
};

/**
 * Personalise menu (I-217): each person hides, reorders within a section and
 * pins their own entries. It rearranges only what they may open, and
 * changes nothing for anyone else; hidden entries stay in the palette.
 */
export function PersonaliseMenuDialog({
    nav,
    pinned: initialPinned,
    storedOrder,
    open,
    onOpenChange,
}: {
    nav: AdminNavEntry[];
    pinned: string[];
    /** Their saved nav.order, kept as it is unless they reorder. */
    storedOrder: string[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const sections = (
        Object.keys(headings) as AdminNavEntry['section'][]
    ).filter((section) => nav.some((entry) => entry.section === section));
    const byKey = new Map(nav.map((entry) => [entry.key, entry]));
    const [order, setOrder] = useState(() =>
        Object.fromEntries(
            sections.map((section) => [
                section,
                nav
                    .filter((entry) => entry.section === section)
                    .map((entry) => entry.key),
            ]),
        ),
    );
    const [reordered, setReordered] = useState(false);
    const [hidden, setHidden] = useState(() =>
        nav.filter((entry) => entry.hidden).map((entry) => entry.key),
    );
    const [pinned, setPinned] = useState(initialPinned);
    const [dragging, setDragging] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);

    // Within one section only: an entry never moves to another.
    const move = (section: string, key: string, to: number) => {
        const keys = [...order[section]];
        const from = keys.indexOf(key);

        if (from === -1 || to < 0 || to >= keys.length || to === from) {
            return;
        }

        keys.splice(from, 1);
        keys.splice(to, 0, key);
        setOrder({ ...order, [section]: keys });
        setReordered(true);
    };

    const save = async (values: {
        'nav.hidden': string[];
        'nav.order': string[];
        'nav.pinned': string[];
    }) => {
        setSaving(true);
        setProblem(null);
        const saved = await savePreferences(values);
        setSaving(false);

        if (!saved) {
            setProblem("Couldn't save your menu. Try again in a moment.");

            return;
        }

        onOpenChange(false);
        router.reload();
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Personalise menu</DialogTitle>
                    <DialogDescription>
                        Changes apply to your menu only. Pin up to {MAX_PINNED}{' '}
                        entries to the top. Hidden entries stay in search (Ctrl
                        K).
                    </DialogDescription>
                </DialogHeader>

                {sections.map((section) => (
                    <fieldset key={section} className="grid gap-1">
                        <legend className="text-muted-foreground mb-1 text-[11px] font-medium">
                            {headings[section]}
                        </legend>
                        <ul className="grid">
                            {order[section].map((key, index) => {
                                const entry = byKey.get(key);

                                if (entry === undefined) {
                                    return null;
                                }

                                const Icon = navIcon(entry.icon);
                                const isHidden = hidden.includes(key);
                                const isPinned = pinned.includes(key);

                                return (
                                    <li
                                        key={key}
                                        draggable
                                        onDragStart={() => setDragging(key)}
                                        onDragEnd={() => setDragging(null)}
                                        onDragOver={(event) => {
                                            if (
                                                dragging !== null &&
                                                order[section].includes(
                                                    dragging,
                                                )
                                            ) {
                                                event.preventDefault();
                                            }
                                        }}
                                        onDrop={() => {
                                            if (dragging !== null) {
                                                move(section, dragging, index);
                                            }
                                        }}
                                        className="border-border flex items-center gap-2 border-b py-1 last:border-b-0"
                                    >
                                        <GripVertical
                                            aria-hidden
                                            className="text-muted-foreground size-4 cursor-grab"
                                        />
                                        <Checkbox
                                            id={`show-${key}`}
                                            checked={!isHidden}
                                            onCheckedChange={(checked) => {
                                                setHidden((current) =>
                                                    checked
                                                        ? current.filter(
                                                              (hid) =>
                                                                  hid !== key,
                                                          )
                                                        : [...current, key],
                                                );

                                                // A hidden entry is not pinned.
                                                if (!checked) {
                                                    setPinned((current) =>
                                                        current.filter(
                                                            (pin) =>
                                                                pin !== key,
                                                        ),
                                                    );
                                                }
                                            }}
                                            aria-label={`Show ${entry.label}`}
                                        />
                                        <Label
                                            htmlFor={`show-${key}`}
                                            className="flex flex-1 items-center gap-2 text-[13px] font-normal"
                                        >
                                            <Icon className="size-4" />
                                            {entry.label}
                                        </Label>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            aria-label={`Move ${entry.label} up`}
                                            disabled={index === 0}
                                            onClick={() =>
                                                move(section, key, index - 1)
                                            }
                                        >
                                            <ChevronUp />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            aria-label={`Move ${entry.label} down`}
                                            disabled={
                                                index ===
                                                order[section].length - 1
                                            }
                                            onClick={() =>
                                                move(section, key, index + 1)
                                            }
                                        >
                                            <ChevronDown />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            aria-pressed={isPinned}
                                            disabled={
                                                isHidden ||
                                                (!isPinned &&
                                                    pinned.length >= MAX_PINNED)
                                            }
                                            onClick={() =>
                                                setPinned((current) =>
                                                    isPinned
                                                        ? current.filter(
                                                              (pin) =>
                                                                  pin !== key,
                                                          )
                                                        : [...current, key],
                                                )
                                            }
                                        >
                                            <Pin />
                                            {isPinned ? 'Pinned' : 'Pin'}
                                        </Button>
                                    </li>
                                );
                            })}
                        </ul>
                    </fieldset>
                ))}

                <DialogFooter className="items-center">
                    {problem && (
                        <p role="alert" className="text-destructive text-xs">
                            {problem}
                        </p>
                    )}
                    <Button
                        type="button"
                        variant="outline"
                        disabled={saving}
                        onClick={() =>
                            void save({
                                'nav.hidden': [],
                                'nav.order': [],
                                'nav.pinned': [],
                            })
                        }
                    >
                        Reset to default
                    </Button>
                    <span className="flex-1" />
                    <Button
                        type="button"
                        disabled={saving}
                        onClick={() =>
                            void save({
                                'nav.hidden': hidden,
                                // Only once they reorder, so an entry added
                                // later keeps its own place until then.
                                'nav.order': reordered
                                    ? sections.flatMap(
                                          (section) => order[section],
                                      )
                                    : storedOrder,
                                'nav.pinned': pinned,
                            })
                        }
                    >
                        Save
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
