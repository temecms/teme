import { maxLengthOf, textOf } from '@/components/editor/fields/field-control';
import type { FieldControlProps } from '@/components/editor/fields/field-control';
import { Input } from '@/components/ui/input';

/** A single line of text, as the editor's text fields draw it. */
export function TextControl({
    field,
    value,
    onChange,
    id,
    describedBy,
    invalid,
    placeholder,
}: FieldControlProps & {
    /** What shows while it is empty, such as the value used in its place. */
    placeholder?: string;
}) {
    return (
        <Input
            id={id}
            aria-describedby={describedBy}
            aria-invalid={invalid || undefined}
            maxLength={maxLengthOf(field)}
            placeholder={placeholder}
            value={textOf(value)}
            onChange={(event) => onChange(event.target.value)}
        />
    );
}
