import { maxLengthOf, textOf } from '@/components/editor/fields/field-control';
import type { FieldControlProps } from '@/components/editor/fields/field-control';

/** The excerpt's look: the class a textarea has when its row doesn't say otherwise. */
const defaultClass =
    'border-input bg-card focus-visible:ring-ring focus-visible:ring-offset-background field-sizing-content min-h-[34px] w-full resize-none rounded-md border px-3 py-2 text-[13px] outline-none focus-visible:ring-2 focus-visible:ring-offset-1';

/** Several lines of text that grow with what is typed. */
export function TextareaControl({
    field,
    value,
    onChange,
    id,
    describedBy,
    invalid,
    rows = 2,
    className = defaultClass,
    spellCheck,
}: FieldControlProps & {
    rows?: number;
    className?: string;
    spellCheck?: boolean;
}) {
    return (
        <textarea
            id={id}
            spellCheck={spellCheck}
            aria-describedby={describedBy}
            aria-invalid={invalid || undefined}
            rows={rows}
            maxLength={maxLengthOf(field)}
            value={textOf(value)}
            onChange={(event) => onChange(event.target.value)}
            className={className}
        />
    );
}
