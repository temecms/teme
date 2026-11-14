import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { navIcon } from '@/lib/nav-icons';

/**
 * The parts the type builder and the taxonomy builder share: a labelled
 * field, a flag with its reason, the facts of a read-only page, the hint
 * under a control, the source badge and the list toggle.
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
        <div className="grid min-w-0 gap-2">
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
    onCheckedChange: (checked: boolean | 'indeterminate') => void;
}) {
    return (
        <div className="grid gap-1">
            <div className="flex items-start gap-2">
                <Checkbox
                    id={id}
                    name={name}
                    value={value}
                    checked={checked}
                    disabled={disabled}
                    onCheckedChange={onCheckedChange}
                    className="mt-0.5"
                />
                {disabled && checked && (
                    <input type="hidden" name={name} value={value} />
                )}
                <div className="grid gap-0.5">
                    <Label htmlFor={id}>{label}</Label>
                    {hint !== undefined && <Hint>{hint}</Hint>}
                    {reason !== undefined && (
                        <p className="text-sm">{reason}</p>
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
        return <Badge variant="outline">Code</Badge>;
    }

    if (source === 'refused') {
        return <Badge variant="destructive">Can’t be used</Badge>;
    }

    return <Badge variant="secondary">Made here</Badge>;
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
        <div className="grid gap-0.5 sm:grid-cols-[180px_1fr] sm:gap-4">
            <dt className="text-sm font-medium">{label}</dt>
            <dd className="min-w-0 text-sm">{children}</dd>
        </div>
    );
}

export function Hint({ children }: { children: ReactNode }) {
    return <p className="text-muted-foreground text-sm">{children}</p>;
}

export function toggle(current: string[], key: string, on: boolean): string[] {
    if (on) {
        return current.includes(key) ? current : [...current, key];
    }

    return current.filter((item) => item !== key);
}
