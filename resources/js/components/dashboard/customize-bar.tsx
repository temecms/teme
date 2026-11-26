import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Button } from '@/components/ui/button';
import { __ } from '@/lib/i18n';
import type { DashboardCatalogueEntry } from '@/types';
import { sectionOf } from '@/components/dashboard/home-layout';

type CustomizeBarProps = {
    catalogue: DashboardCatalogueEntry[];
    hidden: string[];
    onAdd: (key: string) => void;
    onReset: () => void;
    onDone: () => void;
};

/**
 * The Customise bar across Home: Add a panel, Reset, and Done.
 */
export function CustomizeBar({
    catalogue,
    hidden,
    onAdd,
    onReset,
    onDone,
}: CustomizeBarProps) {
    const hiddenEntries = catalogue.filter((entry) =>
        hidden.includes(entry.key),
    );
    const coreHidden = hiddenEntries.filter(
        (entry) => sectionOf(entry.owner) === 'core',
    );
    const pluginGroups = new Map<string, DashboardCatalogueEntry[]>();

    for (const entry of hiddenEntries) {
        if (sectionOf(entry.owner) === 'core') {
            continue;
        }

        const name = entry.ownerName ?? entry.owner;
        const group = pluginGroups.get(name) ?? [];
        group.push(entry);
        pluginGroups.set(name, group);
    }

    return (
        <div
            role="region"
            aria-label={__('Customising Home')}
            data-test="home-customize-bar"
            className="bg-accent-soft text-on-accent-soft mb-6 flex flex-wrap items-center gap-3 rounded-lg px-4 py-3"
        >
            <p className="text-body min-w-0 flex-1">
                <span className="font-semibold">
                    {__('Customising your Home.')}
                </span>{' '}
                {__('Move, resize or hide panels. Only you see this.')}
            </p>
            <div className="flex flex-wrap gap-2">
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="neutral" size="sm">
                            {__('Add a panel')}
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="min-w-56">
                        {hiddenEntries.length === 0 ? (
                            <DropdownMenuItem disabled>
                                {__('Every panel is already on your Home.')}
                            </DropdownMenuItem>
                        ) : (
                            <>
                                {coreHidden.length > 0 && (
                                    <DropdownMenuGroup>
                                        <DropdownMenuLabel>
                                            {__('Teme')}
                                        </DropdownMenuLabel>
                                        {coreHidden.map((entry) => (
                                            <DropdownMenuItem
                                                key={entry.key}
                                                onSelect={() =>
                                                    onAdd(entry.key)
                                                }
                                            >
                                                {__('Add :name', {
                                                    name: __(entry.label),
                                                })}
                                            </DropdownMenuItem>
                                        ))}
                                    </DropdownMenuGroup>
                                )}
                                {[...pluginGroups.entries()].map(
                                    ([name, entries], index) => (
                                        <DropdownMenuGroup key={name}>
                                            {(index > 0 ||
                                                coreHidden.length > 0) && (
                                                <DropdownMenuSeparator />
                                            )}
                                            <DropdownMenuLabel>
                                                {name}
                                            </DropdownMenuLabel>
                                            {entries.map((entry) => (
                                                <DropdownMenuItem
                                                    key={entry.key}
                                                    onSelect={() =>
                                                        onAdd(entry.key)
                                                    }
                                                >
                                                    {__('Add :name', {
                                                        name: __(entry.label),
                                                    })}
                                                </DropdownMenuItem>
                                            ))}
                                        </DropdownMenuGroup>
                                    ),
                                )}
                            </>
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
                <Button
                    variant="ghost"
                    size="sm"
                    data-test="home-customize-reset"
                    onClick={onReset}
                >
                    {__('Reset')}
                </Button>
                <Button
                    size="sm"
                    onClick={onDone}
                    data-test="home-customize-done"
                >
                    {__('Done')}
                </Button>
            </div>
        </div>
    );
}
