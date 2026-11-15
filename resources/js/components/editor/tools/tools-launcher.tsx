import { Link, usePage } from '@inertiajs/react';
import { Pin, Plug } from 'lucide-react';
import { useId, useMemo, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { matchesEveryWord } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { ScrollArea } from '@/components/ui/scroll-area';
import { __, __n } from '@/lib/i18n';
import type { EditorTool } from './editor-tools';

/** How large a tool opens, as its row's tag says. */
function sizeWord(size: EditorTool['size']): string {
    switch (size) {
        case 'window':
            return __('Window');
        case 'full':
            return __('Full screen');
        default:
            return __('Pane');
    }
}

/** The groups the list shows, in order: the pins as pinned, then Teme's and the plugins' A to Z. */
function grouped(
    tools: EditorTool[],
    pins: string[],
): { label: string; tools: EditorTool[] }[] {
    const byName = (a: EditorTool, b: EditorTool) =>
        a.label.localeCompare(b.label);
    const pinned = pins
        .map((id) => tools.find((tool) => tool.id === id))
        .filter((tool): tool is EditorTool => tool !== undefined);
    const rest = tools.filter((tool) => !pins.includes(tool.id));

    return [
        { label: __('Pinned to the bar'), tools: pinned },
        {
            label: __('Teme'),
            tools: rest.filter((tool) => tool.owner === 'core').sort(byName),
        },
        {
            label: __('Plugins'),
            tools: rest.filter((tool) => tool.owner !== 'core').sort(byName),
        },
    ].filter((group) => group.tools.length > 0);
}

/**
 * The top bar's Tools (docs/architecture/design-system.md, 4.39): every
 * editor tool in one list, found by typing, each opened from its name and
 * pinned to the bar from its pin. A search field over rows, not a combobox,
 * since a row holds two buttons.
 */
export function ToolsLauncher({
    tools,
    pins,
    pinnable,
    saving,
    refused,
    onOpen,
    onTogglePin,
}: {
    tools: EditorTool[];
    pins: string[];
    /** The tools that may be pinned; the others are always on the bar. */
    pinnable: string[];
    saving: boolean;
    /** Whether the last pin was refused for being a fourth. */
    refused: boolean;
    onOpen: (id: string) => void;
    onTogglePin: (id: string) => void;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const list = useRef<HTMLDivElement>(null);
    const fieldId = useId();
    const nav = usePage().props.nav;
    const plugins = nav.find((entry) => entry.key === 'plugins');

    const shown = useMemo(
        () =>
            grouped(
                tools.filter((tool) =>
                    matchesEveryWord(
                        { value: tool.id, label: tool.label },
                        query,
                    ),
                ),
                pins,
            ),
        [tools, pins, query],
    );
    const owners = new Set(
        tools.filter((tool) => tool.owner !== 'core').map((tool) => tool.owner),
    );

    const choose = (id: string) => {
        setOpen(false);
        setQuery('');
        onOpen(id);
    };

    const names = (): HTMLButtonElement[] =>
        Array.from(list.current?.querySelectorAll('button') ?? []).filter(
            (button) => button.dataset.slot === 'tool-name',
        );

    const move = (event: KeyboardEvent<HTMLElement>) => {
        if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') {
            return;
        }

        const all = names();
        const at = all.indexOf(document.activeElement as HTMLButtonElement);
        const next =
            event.key === 'ArrowDown'
                ? Math.min(at + 1, all.length - 1)
                : Math.max(at - 1, 0);

        event.preventDefault();
        all[next]?.focus();
    };

    return (
        <Popover
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (!next) {
                    setQuery('');
                }
            }}
        >
            <PopoverTrigger asChild>
                <Button
                    variant="ghost"
                    size="sm"
                    aria-haspopup="dialog"
                    data-test="editor-tools"
                >
                    <Plug />
                    {__('Tools :count', { count: tools.length })}
                </Button>
            </PopoverTrigger>
            <PopoverContent
                align="end"
                aria-labelledby={`${fieldId}-label`}
                className="w-112 max-w-full p-0"
            >
                <div className="grid gap-2 p-3">
                    <Label id={`${fieldId}-label`} htmlFor={fieldId}>
                        {__('Find a tool')}
                    </Label>
                    <Input
                        id={fieldId}
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'ArrowDown') {
                                event.preventDefault();
                                names()[0]?.focus();
                            }

                            if (event.key === 'Enter') {
                                const first = shown[0]?.tools[0];

                                event.preventDefault();

                                if (first !== undefined) {
                                    choose(first.id);
                                }
                            }
                        }}
                    />
                </div>
                <ScrollArea className="max-h-80">
                    <div
                        ref={list}
                        aria-busy={saving}
                        className="grid gap-3 px-3 pb-3"
                        onKeyDown={move}
                    >
                        {shown.map((group) => (
                            <section
                                key={group.label}
                                aria-labelledby={`${fieldId}-${group.label}`}
                            >
                                <h3
                                    id={`${fieldId}-${group.label}`}
                                    className="text-caption text-ink-3 px-2 pb-1 font-semibold uppercase"
                                >
                                    {group.label}
                                </h3>
                                <ul className="grid">
                                    {group.tools.map((tool) => {
                                        const pinned = pins.includes(tool.id);

                                        return (
                                            <li
                                                key={tool.id}
                                                data-slot="tool-row"
                                                className="flex h-10 items-center gap-2"
                                            >
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    data-slot="tool-name"
                                                    className="min-w-0 flex-1 justify-start"
                                                    onClick={() =>
                                                        choose(tool.id)
                                                    }
                                                >
                                                    <tool.icon />
                                                    <span className="truncate">
                                                        {tool.label}
                                                    </span>
                                                </Button>
                                                {tool.owner !== 'core' && (
                                                    <span className="text-caption text-ink-3">
                                                        {tool.owner}
                                                    </span>
                                                )}
                                                <Badge variant="secondary">
                                                    {sizeWord(tool.size)}
                                                </Badge>
                                                {pinnable.includes(tool.id) ? (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon-xs"
                                                        aria-pressed={pinned}
                                                        aria-label={
                                                            pinned
                                                                ? __(
                                                                      'Unpin :name',
                                                                      {
                                                                          name: tool.label,
                                                                      },
                                                                  )
                                                                : __(
                                                                      'Pin :name to the bar',
                                                                      {
                                                                          name: tool.label,
                                                                      },
                                                                  )
                                                        }
                                                        onClick={() =>
                                                            onTogglePin(tool.id)
                                                        }
                                                    >
                                                        <Pin
                                                            className={
                                                                pinned
                                                                    ? 'fill-current'
                                                                    : undefined
                                                            }
                                                        />
                                                    </Button>
                                                ) : (
                                                    <span className="size-(--ctl-h-xs)" />
                                                )}
                                            </li>
                                        );
                                    })}
                                </ul>
                            </section>
                        ))}
                    </div>
                </ScrollArea>
                <p
                    role="status"
                    className="text-small text-ink-2 px-3 empty:hidden"
                >
                    {refused
                        ? __(
                              'Up to three tools can be pinned to the bar. Unpin one to pin this one.',
                          )
                        : ''}
                </p>
                <div className="bg-surface-2 text-caption text-ink-2 flex items-center gap-2 rounded-b-lg px-3 py-2">
                    <span className="flex-1">
                        {owners.size === 0
                            ? __("Teme's own tools")
                            : `${__n(':count tool|:count tools', tools.length)} ${__n('from :count plugin|from :count plugins', owners.size)}`}
                    </span>
                    {plugins !== undefined && (
                        <Link href={plugins.href} className="text-accent-ink">
                            {__('Manage plugins')}
                        </Link>
                    )}
                </div>
            </PopoverContent>
        </Popover>
    );
}
