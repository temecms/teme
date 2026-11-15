import { NodeViewWrapper } from '@tiptap/react';
import {
    AlertTriangle,
    ChevronsDownUp,
    ChevronsUpDown,
    Ellipsis,
    Pencil,
    Replace,
    Trash2,
} from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { __ } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** The event the block menu sends a card's frame to collapse it, or open it again. */
export const TOGGLE_CARD = 'teme:toggle-card';

/**
 * What every card shares in the editor (docs/architecture/design-system.md,
 * 4.39): a header with the card's name that shows on hover, when selected,
 * when collapsed and while focus is inside; its tools when selected;
 * Collapse; and "…" with Edit and Delete. Collapsing is the editor's view
 * only and is never saved.
 */
export function CardFrame({
    label,
    selected,
    warning,
    onReplace,
    replaceLabel,
    onRemove,
    settings,
    defaultOpen = false,
    tools,
    preview,
    children,
}: {
    label: string;
    selected: boolean;
    warning?: { label: string; onClick?: () => void } | null;
    onReplace?: () => void;
    replaceLabel?: string;
    onRemove: () => void;
    settings: ReactNode;
    defaultOpen?: boolean;
    /** More tools in the header while the card is selected. */
    tools?: ReactNode;
    /** A line of the card's content, shown beside its name when collapsed. */
    preview?: string;
    children: ReactNode;
}) {
    const [open, setOpen] = useState(defaultOpen);
    const [collapsed, setCollapsed] = useState(false);
    const frame = useRef<HTMLDivElement>(null);
    const panel = useId();

    // The block menu's "Collapse to one line" and "Expand".
    useEffect(() => {
        const element = frame.current;
        const toggle = (): void => setCollapsed((current) => !current);

        element?.addEventListener(TOGGLE_CARD, toggle);

        return () => element?.removeEventListener(TOGGLE_CARD, toggle);
    }, []);

    return (
        <NodeViewWrapper
            ref={frame}
            data-collapsed={collapsed}
            className={cn(
                'group relative my-2 rounded-lg',
                collapsed ? 'bg-surface-2' : 'bg-surface',
                selected
                    ? 'outline-accent outline-2'
                    : 'hover:outline-line hover:outline-1',
            )}
            contentEditable={false}
        >
            <div
                data-slot="card-head"
                className={cn(
                    'flex h-10 min-w-0 items-center gap-1 px-2',
                    selected || collapsed || open
                        ? 'visible'
                        : 'invisible group-focus-within:visible group-hover:visible',
                )}
            >
                <span className="text-caption text-ink-3 shrink-0 font-semibold uppercase">
                    {label}
                </span>
                {collapsed && preview !== undefined && preview !== '' && (
                    <span className="text-small text-ink-2 min-w-0 truncate">
                        {preview}
                    </span>
                )}
                {selected && !collapsed && (
                    <div className="ml-2 flex min-w-0 items-center gap-1">
                        {onReplace && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="xs"
                                aria-label={replaceLabel ?? __('Replace')}
                                onClick={onReplace}
                            >
                                <Replace />
                                {__('Replace')}
                            </Button>
                        )}
                        {tools}
                    </div>
                )}
                <div className="ml-auto flex shrink-0 items-center gap-0.5">
                    {warning && !collapsed && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="xs"
                            onClick={warning.onClick ?? (() => setOpen(true))}
                            className="bg-warn-soft text-warn-ink hover:bg-warn-soft"
                        >
                            <AlertTriangle />
                            {warning.label}
                        </Button>
                    )}
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-xs"
                        className="text-ink-3"
                        aria-label={collapsed ? __('Expand') : __('Collapse')}
                        title={collapsed ? __('Expand') : __('Collapse')}
                        aria-expanded={!collapsed}
                        onClick={() => setCollapsed((current) => !current)}
                    >
                        {collapsed ? <ChevronsUpDown /> : <ChevronsDownUp />}
                    </Button>
                    <DropdownMenu modal={false}>
                        <DropdownMenuTrigger asChild>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon-xs"
                                className="text-ink-3"
                                aria-label={__('More')}
                                title={__('More')}
                            >
                                <Ellipsis />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem
                                onSelect={() => {
                                    setCollapsed(false);
                                    setOpen((current) => !current);
                                }}
                            >
                                <Pencil />
                                {open ? __('Close settings') : __('Edit')}
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={onRemove}
                            >
                                <Trash2 />
                                {__('Delete')}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>
            {!collapsed && (
                <>
                    <div className="px-3 pb-3">{children}</div>
                    {open && (
                        <div
                            id={panel}
                            className="border-line grid gap-3 border-t px-3 py-3"
                        >
                            {settings}
                        </div>
                    )}
                </>
            )}
        </NodeViewWrapper>
    );
}

/** One labelled setting in a card's panel. */
export function CardField({
    label,
    hint,
    children,
}: {
    label: string;
    hint?: string;
    children: (props: { id: string; 'aria-describedby'?: string }) => ReactNode;
}) {
    const id = useId();
    const hintId = `${id}-hint`;

    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            {children({ id, 'aria-describedby': hint ? hintId : undefined })}
            {hint && (
                <p id={hintId} className="text-small text-ink-3">
                    {hint}
                </p>
            )}
        </div>
    );
}

/** A text setting that writes the attribute as the person types. */
export function TextSetting({
    label,
    hint,
    value,
    onChange,
    multiline = false,
    placeholder,
}: {
    label: string;
    hint?: string;
    value: unknown;
    onChange: (value: string) => void;
    multiline?: boolean;
    placeholder?: string;
}) {
    const text = typeof value === 'string' ? value : '';

    return (
        <CardField label={label} hint={hint}>
            {(props) =>
                multiline ? (
                    <Textarea
                        {...props}
                        rows={3}
                        autoGrow
                        value={text}
                        placeholder={placeholder}
                        onChange={(event) => onChange(event.target.value)}
                    />
                ) : (
                    <Input
                        {...props}
                        value={text}
                        placeholder={placeholder}
                        onChange={(event) => onChange(event.target.value)}
                    />
                )
            }
        </CardField>
    );
}
