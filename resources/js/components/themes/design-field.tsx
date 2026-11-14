import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldInline } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { SelectField } from '@/components/ui/select-field';
import type { DesignField } from '@/types';

/** Whether every condition holds: the other setting has this value, or one of these. */
export function visible(
    field: DesignField,
    values: Record<string, unknown>,
): boolean {
    return Object.entries(field.visibleWhen).every(([key, wanted]) =>
        Array.isArray(wanted)
            ? wanted.includes(values[key])
            : values[key] === wanted,
    );
}

/** A stored value as a control shows it: text, or nothing. */
export function text(value: unknown): string {
    return typeof value === 'string' || typeof value === 'number'
        ? String(value)
        : '';
}

/**
 * One of a theme's settings as its control: a tick box beside its label, a
 * select of its options, or a text box, each with its help and its error.
 */
export function DesignFieldInput({
    field,
    value,
    error,
    onChange,
}: {
    field: DesignField;
    value: unknown;
    error?: string;
    onChange: (value: unknown) => void;
}) {
    const id = `design-${field.key}`;
    const help = field.description !== '' ? field.description : undefined;

    if (field.type === 'boolean') {
        return (
            <FieldInline
                htmlFor={id}
                label={field.label}
                help={help}
                error={error}
            >
                <Checkbox
                    id={id}
                    checked={value === true}
                    onCheckedChange={(checked) => onChange(checked === true)}
                />
            </FieldInline>
        );
    }

    return (
        <Field htmlFor={id} label={field.label} help={help} error={error}>
            {field.type === 'select' ? (
                <SelectField
                    id={id}
                    value={text(value)}
                    options={Object.entries(field.options).map(
                        ([option, label]) => ({ value: option, label }),
                    )}
                    onValueChange={onChange}
                />
            ) : (
                <Input
                    id={id}
                    value={text(value)}
                    onChange={(event) => onChange(event.target.value)}
                />
            )}
        </Field>
    );
}
