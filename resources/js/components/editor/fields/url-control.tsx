import { maxLengthOf, textOf } from '@/components/editor/fields/field-control';
import type { FieldControlProps } from '@/components/editor/fields/field-control';
import { Input } from '@/components/ui/input';

/** A web address on another site, typed as one line. */
export function UrlControl({
    field,
    value,
    onChange,
    id,
    describedBy,
    invalid,
}: FieldControlProps) {
    return (
        <Input
            id={id}
            type="url"
            inputMode="url"
            autoComplete="off"
            aria-describedby={describedBy}
            aria-invalid={invalid || undefined}
            maxLength={maxLengthOf(field) ?? 2048}
            value={textOf(value)}
            onChange={(event) => onChange(event.target.value)}
        />
    );
}
