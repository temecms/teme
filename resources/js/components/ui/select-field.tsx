import { useState } from 'react';
import type { CSSProperties } from 'react';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

/** Radix's value for the empty choice. It never leaves this file. */
const NONE = '__none__';

type Props = {
    /**
     * `depth` indents a nested choice, such as a child category, a step per
     * level.
     */
    options: { value: string | number; label: string; depth?: number }[];
    /** Adds a first choice, such as "All authors", whose value is "". */
    emptyLabel?: string;
    /** Submits the value through a hidden input, so the sentinel never reaches a form. */
    name?: string;
    value?: string;
    defaultValue?: string;
    onValueChange?: (value: string) => void;
    id?: string;
    'aria-label'?: string;
    'aria-describedby'?: string;
    className?: string;
    /** Shown but not changeable; the hidden input, if any, still carries the value. */
    disabled?: boolean;
};

/**
 * The one select control in the admin. Radix refuses an item whose value is
 * "", so an empty choice travels inside Radix as a sentinel and is mapped
 * back to "" on the way out.
 */
export function SelectField({
    options,
    emptyLabel,
    name,
    value,
    defaultValue,
    onValueChange,
    id,
    className,
    disabled,
    ...rest
}: Props) {
    const [own, setOwn] = useState(defaultValue ?? '');
    const current = value ?? own;
    const toRadix = (next: string) =>
        next === '' && emptyLabel !== undefined ? NONE : next;
    const fromRadix = (next: string) => (next === NONE ? '' : next);

    return (
        <>
            <Select
                disabled={disabled}
                value={toRadix(current)}
                onValueChange={(next) => {
                    setOwn(fromRadix(next));
                    onValueChange?.(fromRadix(next));
                }}
            >
                <SelectTrigger
                    id={id}
                    aria-label={rest['aria-label']}
                    aria-describedby={rest['aria-describedby']}
                    className={className}
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {emptyLabel !== undefined && (
                        <SelectItem value={NONE}>{emptyLabel}</SelectItem>
                    )}
                    {options.map((option) => (
                        <SelectItem
                            key={option.value}
                            value={String(option.value)}
                            className={
                                option.depth ? 'ps-(--indent)' : undefined
                            }
                            style={
                                option.depth
                                    ? ({
                                          '--indent': `${8 + option.depth * 12}px`,
                                      } as CSSProperties)
                                    : undefined
                            }
                        >
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            {name !== undefined && (
                <input type="hidden" name={name} value={current} />
            )}
        </>
    );
}
