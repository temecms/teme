import { useState } from 'react';
import type { FieldControlProps } from '@/components/editor/fields/field-control';
import { Input } from '@/components/ui/input';

/** The text a number is shown as, or the empty string for none. */
function textFor(value: unknown): string {
    return typeof value === 'number' ? String(value) : '';
}

/**
 * A number. It keeps what is typed as text, and gives a number for a whole
 * one when `step` is 1, any number otherwise, and null for anything else.
 */
export function NumberControl({
    value,
    onChange,
    id,
    describedBy,
    invalid,
    min,
    step,
    placeholder,
    disabled,
}: FieldControlProps & {
    min?: number;
    step?: number;
    placeholder?: string;
    disabled?: boolean;
}) {
    const [text, setText] = useState(() => textFor(value));

    return (
        <Input
            id={id}
            type="number"
            min={min}
            step={step}
            inputMode="numeric"
            placeholder={placeholder}
            aria-describedby={describedBy}
            aria-invalid={invalid || undefined}
            disabled={disabled}
            value={text}
            onChange={(event) => {
                const typed = event.target.value;
                const whole = /^\d+$/.test(typed.trim());
                const any =
                    typed.trim() !== '' && Number.isFinite(Number(typed));

                setText(typed);
                onChange(
                    step === 1
                        ? whole
                            ? Number(typed.trim())
                            : null
                        : any
                          ? Number(typed)
                          : null,
                );
            }}
        />
    );
}
