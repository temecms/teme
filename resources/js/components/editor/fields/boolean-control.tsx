import type { FieldControlProps } from '@/components/editor/fields/field-control';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';

/** A box and its label in one row, as every checkbox row of the editor is. */
export function BooleanControl({
    field,
    value,
    onChange,
    id,
    describedBy,
    label = field.label,
}: FieldControlProps & { label?: string }) {
    return (
        <div className="flex min-h-6 items-center gap-2">
            <Checkbox
                id={id}
                aria-describedby={describedBy}
                checked={value === true}
                onCheckedChange={(next) => onChange(next === true)}
            />
            <Label htmlFor={id}>{label}</Label>
        </div>
    );
}
