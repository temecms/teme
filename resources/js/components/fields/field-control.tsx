import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { SelectField } from '@/components/ui/select-field';
import type { CardMedia, FieldDefinition, SecretStatus } from '@/types';
import { HoursSetting } from './hours-setting';
import { MediaSetting } from './media-setting';
import { PairsSetting } from './pairs-setting';

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

type Props = {
    field: FieldDefinition;
    name: string;
    value: unknown;
    secret?: SecretStatus;
    /** A media setting's chosen image, or null when none or gone. */
    media?: CardMedia | null;
    /** Shown but not changeable; a disabled control sends nothing (1.8d). */
    disabled?: boolean;
};

export function FieldControl({
    field,
    name,
    value,
    secret,
    media,
    disabled,
}: Props) {
    const id = `field-${field.key}`;
    // SettingsField draws the hint and the error under these ids.
    const describedBy = `${id}-hint ${id}-error`;
    const text =
        typeof value === 'string' || typeof value === 'number'
            ? String(value)
            : '';

    switch (field.type) {
        case 'textarea':
            return (
                <textarea
                    id={id}
                    aria-describedby={describedBy}
                    name={name}
                    defaultValue={text}
                    required={field.required}
                    maxLength={field.maxLength ?? undefined}
                    rows={4}
                    disabled={disabled}
                    className={controlClass}
                />
            );
        case 'number':
            return (
                <div className="flex items-center gap-2">
                    <Input
                        id={id}
                        aria-describedby={describedBy}
                        type="number"
                        name={name}
                        defaultValue={text}
                        required={field.required}
                        min={field.min ?? undefined}
                        max={field.max ?? undefined}
                        disabled={disabled}
                        className="w-22 font-mono tabular-nums"
                    />
                    {field.unit !== null && field.unit !== undefined && (
                        <span
                            data-test="field-unit"
                            className="text-muted-foreground text-[13px]"
                        >
                            {field.unit}
                        </span>
                    )}
                </div>
            );
        case 'boolean':
            return (
                <div className="flex items-center gap-2">
                    <input
                        type="hidden"
                        name={name}
                        value="0"
                        disabled={disabled}
                    />
                    <Checkbox
                        id={id}
                        aria-describedby={describedBy}
                        name={name}
                        value="1"
                        defaultChecked={value === true}
                        disabled={disabled}
                    />
                </div>
            );
        case 'select':
        case 'timezone':
            return (
                <select
                    id={id}
                    aria-describedby={describedBy}
                    name={name}
                    defaultValue={text}
                    required={field.required}
                    disabled={disabled}
                    className={controlClass.replace(
                        'w-full',
                        'w-auto max-w-full',
                    )}
                >
                    {Object.entries(field.options).map(
                        ([optionValue, label]) => (
                            <option key={optionValue} value={optionValue}>
                                {label}
                            </option>
                        ),
                    )}
                </select>
            );
        case 'multiselect':
            return (
                <fieldset id={id} className="space-y-2">
                    {/* Sent when nothing is ticked, so clearing the selection saves. */}
                    <input
                        type="hidden"
                        name={`${name}[]`}
                        value=""
                        disabled={disabled}
                    />
                    {Object.entries(field.options).map(
                        ([optionValue, label]) => (
                            <label
                                key={optionValue}
                                className="flex items-center gap-2 text-sm"
                            >
                                <Checkbox
                                    name={`${name}[]`}
                                    value={optionValue}
                                    defaultChecked={
                                        Array.isArray(value) &&
                                        value.includes(optionValue)
                                    }
                                    disabled={disabled}
                                />
                                {label}
                            </label>
                        ),
                    )}
                </fieldset>
            );
        case 'media':
            return (
                <MediaSetting
                    field={field}
                    name={name}
                    value={value}
                    media={media}
                    disabled={disabled}
                />
            );
        case 'pairs':
            return (
                <PairsSetting
                    field={field}
                    name={name}
                    value={value}
                    disabled={disabled}
                />
            );
        case 'urls':
            return (
                <textarea
                    id={id}
                    aria-describedby={describedBy}
                    name={name}
                    defaultValue={
                        Array.isArray(value)
                            ? value
                                  .filter((line) => typeof line === 'string')
                                  .join('\n')
                            : text
                    }
                    rows={4}
                    spellCheck={false}
                    autoComplete="off"
                    placeholder="https://"
                    disabled={disabled}
                    className={`${controlClass} font-mono`}
                />
            );
        case 'person': {
            // A person whose account is gone shows as nobody chosen, so a
            // save asks for someone rather than sending their ID.
            const people = field.people ?? [];

            return (
                <SelectField
                    id={id}
                    aria-describedby={describedBy}
                    name={name}
                    options={people}
                    emptyLabel="Choose a person"
                    defaultValue={
                        people.some((person) => person.value === text)
                            ? text
                            : ''
                    }
                    disabled={disabled}
                />
            );
        }
        case 'hours':
            return (
                <HoursSetting
                    field={field}
                    name={name}
                    value={value}
                    disabled={disabled}
                />
            );
        case 'secret':
            return (
                <div className="space-y-2">
                    <Input
                        id={id}
                        aria-describedby={describedBy}
                        type="password"
                        name={name}
                        autoComplete="off"
                        placeholder={
                            secret?.isSet
                                ? 'Set — leave empty to keep'
                                : 'Not set'
                        }
                        disabled={disabled || secret?.source === 'environment'}
                    />
                    {secret?.source === 'environment' && (
                        <p className="text-muted-foreground text-xs">
                            Provided by the server's environment.
                        </p>
                    )}
                </div>
            );
        default:
            return (
                <Input
                    id={id}
                    aria-describedby={describedBy}
                    type={
                        field.type === 'email'
                            ? 'email'
                            : field.type === 'url'
                              ? 'url'
                              : 'text'
                    }
                    name={name}
                    defaultValue={text}
                    required={field.required}
                    maxLength={field.maxLength ?? undefined}
                    placeholder={field.example ?? undefined}
                    disabled={disabled}
                    className={
                        field.type === 'email' ? 'max-w-[360px]' : undefined
                    }
                />
            );
    }
}
