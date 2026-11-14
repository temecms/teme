import { textOf } from '@/components/editor/fields/field-control';
import type { FieldControlProps } from '@/components/editor/fields/field-control';
import { SelectField } from '@/components/ui/select-field';

type Option = { value: string; label: string };

/** The options a select field's settings carry, the empty choice left out. */
function optionsFrom(settings: Record<string, unknown>): Option[] {
    const options = Array.isArray(settings.options)
        ? (settings.options as Option[])
        : [];

    return options.filter((option) => option.value !== '');
}

/** The label of the field's empty choice, or undefined when it has none. */
function emptyLabelFrom(settings: Record<string, unknown>): string | undefined {
    const options = Array.isArray(settings.options)
        ? (settings.options as Option[])
        : [];

    return options.find((option) => option.value === '')?.label;
}

/** One choice from the field's options. */
export function SelectControl({
    field,
    value,
    onChange,
    id,
    describedBy,
    invalid,
    emptyLabel,
    options,
}: FieldControlProps & {
    /** Words for the empty choice, in place of the field's own. */
    emptyLabel?: string;
    /** A narrowed list in place of the field's options. */
    options?: Option[];
}) {
    return (
        <SelectField
            id={id}
            aria-describedby={describedBy}
            aria-invalid={invalid || undefined}
            emptyLabel={emptyLabel ?? emptyLabelFrom(field.settings)}
            options={options ?? optionsFrom(field.settings)}
            value={textOf(value)}
            onValueChange={(next) => onChange(next)}
        />
    );
}
