import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
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
            className="text-muted-foreground text-sm"
        >
            {field.description}
        </p>
    );
    const label = (
        <Label htmlFor={`field-${field.key}`}>
            {field.label}
            {field.required && <span aria-hidden="true"> *</span>}
        </Label>
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
            <div className="grid gap-1">
                <div className="flex items-start gap-2">
                    <div className="mt-0.5">{control}</div>
                    <div className="grid gap-0.5">
                        <div className="font-medium">{label}</div>
                        {hint}
                    </div>
                </div>
                <InputError id={`field-${field.key}-error`} message={error} />
            </div>
        );
    }

    return (
        <div className="grid gap-2">
            {label}
            {control}
            {hint}
            <InputError id={`field-${field.key}-error`} message={error} />
        </div>
    );
}
