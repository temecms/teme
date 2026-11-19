import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StatusPill } from '@/components/ui/status-pill';
import { Switch } from '@/components/ui/switch';
import { __ } from '@/lib/i18n';
import { navIcon } from '@/lib/nav-icons';

/**
 * The parts the type builder and the taxonomy builder share: a labelled
 * field, a flag with its reason, the facts of a read-only page, the hint
 * under a control, the source pill and the list toggle.
 */

export function Field({
    id,
    name,
    label,
    value,
    hint,
    error,
    placeholder,
    onChange,
}: {
    id: string;
    name: string;
    label: string;
    value: string;
    hint: string;
    error?: string;
    placeholder?: string;
    onChange: (value: string) => void;
}) {
    return (
        <div className="grid min-w-0 gap-1">
            <Label htmlFor={id}>{label}</Label>
            <Input
                id={id}
                name={name}
                value={value}
                placeholder={placeholder}
                onChange={(event) => onChange(event.target.value)}
            />
            <Hint>{hint}</Hint>
            <InputError message={error} />
        </div>
    );
}

export function Flag({
    id,
    name,
    value = '1',
    label,
    checked,
    disabled = false,
    hint,
    reason,
    error,
    control = 'checkbox',
    onCheckedChange,
}: {
    id: string;
    name: string;
    value?: string;
    label: string;
    checked: boolean;
    disabled?: boolean;
    hint?: string;
    reason?: string;
    error?: string;
    /** A switch for a setting that turns something on; a tick box for one of several choices. */
    control?: 'checkbox' | 'switch';
    onCheckedChange: (checked: boolean | 'indeterminate') => void;
}) {
    return (
        <div className="grid gap-1">
            <div className="flex items-start gap-2">
                <span className="text-body flex h-lh shrink-0 items-center">
                    {control === 'switch' ? (
                        <Switch
                            id={id}
                            name={name}
                            value={value}
                            checked={checked}
                            disabled={disabled}
                            onCheckedChange={onCheckedChange}
                        />
                    ) : (
                        <Checkbox
                            id={id}
                            name={name}
                            value={value}
                            checked={checked}
                            disabled={disabled}
                            onCheckedChange={onCheckedChange}
                        />
                    )}
                </span>
                {disabled && checked && (
                    <input type="hidden" name={name} value={value} />
                )}
                <div className="grid gap-1">
                    <Label htmlFor={id} className="text-body font-normal">
                        {label}
                    </Label>
                    {hint !== undefined && <Hint>{hint}</Hint>}
                    {reason !== undefined && (
                        <p className="text-small">{reason}</p>
                    )}
                </div>
            </div>
            <InputError message={error} />
        </div>
    );
}

export function SourceBadge({
    mode,
    source,
}: {
    mode: string;
    source: string | null;
}) {
    if (mode === 'new') {
        return null;
    }

    if (mode === 'view' || source === 'code') {
        return <StatusPill tone="neutral">{__('Code')}</StatusPill>;
    }

    if (source === 'refused') {
        return <StatusPill tone="danger">{__('Can’t be used')}</StatusPill>;
    }

    if (mode === 'plugin' || source === 'plugin') {
        return <StatusPill tone="neutral">{__('Plugin')}</StatusPill>;
    }

    return <StatusPill tone="info">{__('Made here')}</StatusPill>;
}

export function IconName({ name }: { name: string }) {
    const Icon = navIcon(name);

    return (
        <span className="inline-flex items-center gap-2">
            <Icon aria-hidden className="size-4" />
            <code>{name}</code>
        </span>
    );
}

export function Facts({ children }: { children: ReactNode }) {
    return <dl className="grid gap-3">{children}</dl>;
}

export function Fact({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="grid gap-1 sm:grid-cols-3 sm:gap-4">
            <dt className="text-body font-medium">{label}</dt>
            <dd className="text-body min-w-0 sm:col-span-2">{children}</dd>
        </div>
    );
}

export function Hint({ children }: { children: ReactNode }) {
    return <p className="text-small text-ink-2">{children}</p>;
}

export function toggle(current: string[], key: string, on: boolean): string[] {
    if (on) {
        return current.includes(key) ? current : [...current, key];
    }

    return current.filter((item) => item !== key);
}
