import { LockIcon, OctagonAlertIcon } from 'lucide-react';
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
    /** A change to it asks for the person's password, and the field says so. */
    sensitive?: boolean;
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
    sensitive = false,
}: Props) {
    const controlName =
        name ??
        (field.type === 'secret'
            ? `secrets[${field.key}]`
            : `values[${field.key}]`);

    const hint = (
        <>
            <p
                id={`field-${field.key}-hint`}
                className="text-caption text-ink-3 text-pretty"
            >
                {field.description}
            </p>
            {sensitive && (
                <p
                    data-test="field-asks-password"
                    className="text-caption text-ink-2 flex items-start gap-1"
                >
                    <span className="flex h-lh shrink-0 items-center">
                        <LockIcon aria-hidden="true" className="size-3.5" />
                    </span>
                    {__('Asks for your password to change')}
                </p>
            )}
        </>
    );
    // Ticked boxes are a group: their name is a line that labels the group, not a label for one control.
    const group =
        field.type === 'multiselect' ||
        (field.type === 'term' && field.multiple === true);
    const words = (
        <>
            {field.label}
            {field.required && (
                <>
                    {' '}
                    <span className="text-ink-3 font-normal">
                        {__('Required')}
                    </span>
                </>
            )}
        </>
    );
    const label = group ? (
        <p
            id={`field-${field.key}-label`}
            className="text-small text-ink-2 font-medium"
        >
            {words}
        </p>
    ) : (
        <Label
            htmlFor={`field-${field.key}`}
            className={
                field.type === 'boolean' ? 'text-body text-ink' : undefined
            }
        >
            {words}
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
        // The switch beside its words, the hint under the words.
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
