import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { lucideIcons, squashedName } from '@/lib/lucide-icons';

/**
 * Every Lucide icon, by name, for a type's menu icon. Loaded the first time
 * Change icon is pressed.
 */
export default function IconPicker({
    open,
    onOpenChange,
    value,
    onChoose,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    value: string;
    onChoose: (name: string) => void;
}) {
    const [search, setSearch] = useState('');
    // Hyphens and spaces aside, so "arrow down" and "arrow-down-0-1" both find arrow-down01.
    const query = squashedName(search.replace(/\s+/g, ''));
    const chosen = squashedName(value);
    const matches = useMemo(
        () =>
            query === ''
                ? lucideIcons
                : lucideIcons.filter(({ name }) =>
                      squashedName(name).includes(query),
                  ),
        [query],
    );
    const shown = matches.slice(0, 120);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="sm:max-w-xl"
                onOpenAutoFocus={(event) => {
                    event.preventDefault();
                    document.getElementById('icon-search')?.focus();
                }}
            >
                <DialogHeader>
                    <DialogTitle>Choose an icon</DialogTitle>
                    <DialogDescription>
                        Every Lucide icon, by name. It shows beside the type’s
                        name in the menu.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-2">
                    <Label htmlFor="icon-search">Search icons</Label>
                    <Input
                        id="icon-search"
                        value={search}
                        autoComplete="off"
                        onChange={(event) => setSearch(event.target.value)}
                    />
                </div>

                <p aria-live="polite" className="text-ink-2 text-sm">
                    {countLine(matches.length, search.trim())}
                </p>

                <div className="grid max-h-[50vh] grid-cols-[repeat(auto-fill,minmax(44px,1fr))] gap-1 overflow-y-auto">
                    {shown.map(({ name, Icon }) => (
                        <Button
                            key={name}
                            type="button"
                            variant="outline"
                            aria-label={name}
                            aria-pressed={squashedName(name) === chosen}
                            className="size-11 p-0"
                            onClick={() => {
                                onChoose(name);
                                onOpenChange(false);
                            }}
                        >
                            <Icon className="size-6" />
                        </Button>
                    ))}
                </div>
            </DialogContent>
        </Dialog>
    );
}

/** How many names the search found, with a thousands comma. */
function countLine(matches: number, search: string): string {
    if (matches === 0) {
        return `No icon’s name has “${search}” in it.`;
    }

    if (matches === 1) {
        return '1 icon matches.';
    }

    const count = matches.toLocaleString('en-GB');

    if (matches > 120) {
        return `${count} icons match. The first 120 are shown: type more of a name to narrow them.`;
    }

    return `${count} icons match.`;
}
