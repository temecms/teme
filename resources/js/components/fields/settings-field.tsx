import { OctagonAlertIcon } from 'lucide-react';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { __ } from '@/lib/i18n';
import type { CardMedia, FieldDefinition, SecretStatus } from '@/types';
import { FieldControl } from './field-control';

type Props = {
    field: FieldDefinition;
    value: unknown;
    secret?: SecretStatus;
    /** A media setting's chosen image, or null when none or gone. */
    media?: CardMedia | null;
    error?: string;
    /** The control's form name; the settings screen's `values[key]` when not given. */
    name?: string;
    /** Shown but not changeable, and sent with nothing (1.8d). */
    disabled?: boolean;
};

/** A field, and the mark Find a setting puts on the one it lands on. */
const FIELD_CLASS =
    'grid gap-1 rounded-md data-found:bg-accent-soft data-found:-m-2 data-found:p-2';

/**
 * One setting, drawn as a field (docs/architecture/design-system.md, 4.11):
 * the label above, the control, the help, then the error, each line keeping
 * the field-{key} ids the control points at.
 */
export function SettingsField({
    field,
    value,
    secret,
    media,
    error,
    name,
    disabled,
}: Props) {
    const controlName =
        name ??
        (field.type === 'secret'
            ? `secrets[${field.key}]`
            : `values[${field.key}]`);

    const hint = (
        <p
            id={`field-${field.key}-hint`}
            className="text-caption text-ink-3 text-pretty"
        >
            {field.description}
        </p>
    );
    const label = (
        <Label
            htmlFor={`field-${field.key}`}
            className={
                field.type === 'boolean' ? 'text-body text-ink gap-1' : 'gap-1'
            }
        >
            {field.label}
            {field.required && (
                <span className="text-ink-3 font-normal">{__('Required')}</span>
            )}
        </Label>
    );
    const problem = error !== undefined && error !== '' && (
        <div className="text-small text-danger-ink flex items-start gap-1">
            <span className="flex h-lh shrink-0 items-center">
                <OctagonAlertIcon aria-hidden="true" className="size-4" />
            </span>
            <InputError
                id={`field-${field.key}-error`}
                className="text-small"
                message={error}
            />
        </div>
    );
    const control = (
        <FieldControl
            field={field}
            name={controlName}
            value={value}
            secret={secret}
            media={media}
            disabled={disabled}
        />
    );

    if (field.type === 'boolean') {
        // The box beside its words, the hint under the words.
        return (
            <div data-slot="settings-field" className={FIELD_CLASS}>
                <div className="flex items-start gap-2">
                    <div className="flex h-lh items-center">{control}</div>
                    <div className="grid gap-1">
                        {label}
                        {hint}
                    </div>
                </div>
                {problem}
            </div>
        );
    }

    return (
        <div data-slot="settings-field" className={FIELD_CLASS}>
            {label}
            {control}
            {hint}
            {problem}
        </div>
    );
}
