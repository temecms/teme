import { FIELD_CONTROLS } from '@/components/editor/fields/controls';
import { Label } from '@/components/ui/label';
import { __ } from '@/lib/i18n';
import type { EditorField } from '@/types/editor';

/**
 * Details, under the body (I-3320): every field placed in Details, in the
 * type's order, one row each, drawn as the excerpt is: its label, Required
 * when it is, its control, its help, and its error. Nothing at all when the
 * type has none.
 */
export function DetailsSection({
    fields,
    values,
    onChange,
    errors,
    noun,
    plural,
}: {
    fields: EditorField[];
    values: Record<string, unknown>;
    onChange: (key: string, value: unknown) => void;
    /** Each field's error, by its key. */
    errors: Record<string, string>;
    noun: string;
    plural: string;
}) {
    const shown = fields.filter(
        (field) =>
            field.place === 'details' &&
            field.component !== null &&
            FIELD_CONTROLS[field.component] !== undefined,
    );

    if (shown.length === 0) {
        return null;
    }

    return (
        <section
            aria-labelledby="editor-details-heading"
            data-test="details-section"
            className="grid grid-cols-[minmax(0,1fr)] gap-4"
        >
            <div className="grid gap-1">
                <h2
                    id="editor-details-heading"
                    className="text-small font-semibold"
                >
                    {__('Details')}
                </h2>
                <p className="text-ink-2 text-caption">
                    {__(
                        'What every :noun records. Fields are set up in Site › Types › :plural.',
                        { noun, plural },
                    )}
                </p>
            </div>
            {shown.map((field) => {
                const Control = FIELD_CONTROLS[field.component as string];
                const id = `editor-field-${field.handle}`;
                const error = errors[field.key];
                const help = field.description.trim() !== '';
                const describedBy =
                    [help ? `${id}-help` : null, error ? `${id}-error` : null]
                        .filter(Boolean)
                        .join(' ') || undefined;
                // A tick box carries its label beside it, as every checkbox row does.
                const boxed = field.component === 'boolean';

                return (
                    <div
                        key={field.key}
                        data-test="details-row"
                        className="grid grid-cols-[minmax(0,1fr)] gap-2"
                    >
                        {!boxed && (
                            <div className="flex items-center gap-2">
                                <Label id={`${id}-label`} htmlFor={id}>
                                    {field.label}
                                </Label>
                                {field.required && (
                                    <span className="bg-surface-2 text-ink-2 text-caption rounded-sm px-2 py-1 font-medium">
                                        {__('Required')}
                                    </span>
                                )}
                            </div>
                        )}
                        <div
                            className={
                                field.component === 'number' ||
                                field.component === 'select'
                                    ? 'justify-self-start'
                                    : undefined
                            }
                        >
                            <Control
                                field={field}
                                value={values[field.key]}
                                onChange={(next) => onChange(field.key, next)}
                                id={id}
                                describedBy={describedBy}
                                invalid={error !== undefined}
                            />
                        </div>
                        {help && (
                            <p
                                id={`${id}-help`}
                                className="text-ink-2 text-caption"
                            >
                                {field.description}
                            </p>
                        )}
                        {error && (
                            <p
                                id={`${id}-error`}
                                role="alert"
                                className="text-danger-ink text-caption"
                            >
                                {error}
                            </p>
                        )}
                    </div>
                );
            })}
        </section>
    );
}
