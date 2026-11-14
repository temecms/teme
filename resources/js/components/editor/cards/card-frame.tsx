import { NodeViewWrapper } from '@tiptap/react';
import { AlertTriangle, Pencil, Replace, Trash2 } from 'lucide-react';
import { useId, useState } from 'react';
import type { ComponentProps, ReactNode } from 'react';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

function FrameButton({
    label,
    className,
    children,
    ...props
}: ComponentProps<'button'> & { label: string }) {
    return (
        <button
            type="button"
            aria-label={label}
            title={label}
            className={cn(
                'hover:bg-surface-3 focus-visible:ring-ring inline-flex h-[30px] shrink-0 items-center gap-1 rounded-md px-2 text-[12px] font-medium whitespace-nowrap outline-none focus-visible:ring-2 [&_svg]:size-3.5',
                className,
            )}
            {...props}
        >
            {children}
        </button>
    );
}

/**
 * What every card shares in the editor: an outline when selected, a toolbar
 * on hover or focus (Edit, Replace, Remove, and a warning when something is
 * missing), a settings panel that opens below, and a drag handle.
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
    children: ReactNode;
}) {
    const [open, setOpen] = useState(defaultOpen);
    const panel = useId();

    return (
        <NodeViewWrapper
            className={cn(
                'group border-line bg-surface relative my-4 rounded-md border',
                selected && 'ring-ring ring-2',
            )}
            contentEditable={false}
        >
            <div className="flex flex-wrap items-center gap-1 px-2 pt-1.5">
                <span className="text-ink-2 text-[11px] font-medium tracking-wide uppercase">
                    {label}
                </span>
                <div
                    role="toolbar"
                    aria-label={`${label} card`}
                    className={cn(
                        'ml-auto flex items-center gap-0.5 opacity-0 transition-opacity group-focus-within:opacity-100 group-hover:opacity-100',
                        (selected || open || warning) && 'opacity-100',
                    )}
                >
                    {warning && (
                        <FrameButton
                            label={warning.label}
                            onClick={warning.onClick ?? (() => setOpen(true))}
                            className="bg-warn-soft text-warn-ink hover:bg-warn-soft"
                        >
                            <AlertTriangle />
                            {warning.label}
                        </FrameButton>
                    )}
                    <FrameButton
                        label={open ? 'Close settings' : 'Edit'}
                        aria-expanded={open}
                        aria-controls={panel}
                        onClick={() => setOpen((current) => !current)}
                    >
                        <Pencil />
                        Edit
                    </FrameButton>
                    {onReplace && (
                        <FrameButton
                            label={replaceLabel ?? 'Replace'}
                            onClick={onReplace}
                        >
                            <Replace />
                            Replace
                        </FrameButton>
                    )}
                    <FrameButton
                        label={`Remove ${label.toLowerCase()}`}
                        onClick={onRemove}
                    >
                        <Trash2 />
                    </FrameButton>
                </div>
            </div>
            <div className="px-3 pt-1 pb-3">{children}</div>
            {open && (
                <div
                    id={panel}
                    className="border-line grid gap-3 border-t px-3 py-3"
                >
                    {settings}
                </div>
            )}
        </NodeViewWrapper>
    );
}

const FIELD =
    'border-edge bg-surface focus-visible:ring-ring focus-visible:ring-offset-page w-full rounded-md border px-3 py-2 text-[13px] outline-none focus-visible:ring-2 focus-visible:ring-offset-1';

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
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            {children({ id, 'aria-describedby': hint ? hintId : undefined })}
            {hint && (
                <p id={hintId} className="text-ink-2 text-xs">
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
                    <textarea
                        {...props}
                        rows={3}
                        value={text}
                        placeholder={placeholder}
                        onChange={(event) => onChange(event.target.value)}
                        className={cn(
                            FIELD,
                            'field-sizing-content resize-none',
                        )}
                    />
                ) : (
                    <input
                        {...props}
                        value={text}
                        placeholder={placeholder}
                        onChange={(event) => onChange(event.target.value)}
                        className={cn(FIELD, 'h-[34px] py-0')}
                    />
                )
            }
        </CardField>
    );
}
