import { useState } from 'react';
import { Checkbox } from '@/components/ui/checkbox';
import { Combobox } from '@/components/ui/combobox';
import type { ComboboxOption } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { SelectField } from '@/components/ui/select-field';
import { Switch } from '@/components/ui/switch';
import { __ } from '@/lib/i18n';
import type { CardMedia, FieldDefinition, SecretStatus } from '@/types';
import { HoursSetting } from './hours-setting';
import { MediaSetting } from './media-setting';
import { PairsSetting } from './pairs-setting';
import { SecretControl } from './secret-control';

const controlClass =
    'border-edge bg-surface text-body focus-visible:outline-ring w-full rounded-md border px-3 py-2 focus-visible:outline-2 focus-visible:outline-offset-0';

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
                        className="w-32 tabular-nums"
                    />
                    {field.unit !== null && field.unit !== undefined && (
                        <span
                            data-test="field-unit"
                            className="text-body text-ink-2"
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
                    <Switch
                        id={id}
                        aria-describedby={describedBy}
                        name={name}
                        value="1"
                        defaultChecked={value === true}
                        disabled={disabled}
                    />
                </div>
            );
        case 'select': {
            const choices = Object.entries(field.options).map(
                ([optionValue, label]) => ({ value: optionValue, label }),
            );

            return (
                <SelectField
                    id={id}
                    aria-describedby={describedBy}
                    name={name}
                    options={choices}
                    defaultValue={text}
                    disabled={disabled}
                    className="w-80 max-w-full"
                />
            );
        }
        case 'timezone':
            return (
                <TimezoneControl
                    id={id}
                    name={name}
                    options={field.options}
                    value={text}
                    describedBy={describedBy}
                    disabled={disabled}
                />
            );
        case 'multiselect':
            return (
                <Choices
                    id={id}
                    name={name}
                    options={field.options}
                    value={value}
                    disabled={disabled}
                />
            );
        case 'term':
        case 'type': {
            if (
                field.multiple &&
                (field.type === 'term' || field.type === 'type')
            ) {
                return (
                    <Choices
                        id={id}
                        name={name}
                        options={field.options}
                        value={value}
                        disabled={disabled}
                    />
                );
            }

            // The site's own terms or types; one that is gone shows as none
            // chosen, so a save asks for one the site has.
            const choices = Object.entries(field.options).map(
                ([optionValue, label]) => ({ value: optionValue, label }),
            );

            return (
                <SelectField
                    id={id}
                    aria-describedby={describedBy}
                    name={name}
                    options={choices}
                    emptyLabel={
                        field.type === 'term'
                            ? __('Choose a term')
                            : __('Choose a type')
                    }
                    defaultValue={
                        choices.some((choice) => choice.value === text)
                            ? text
                            : ''
                    }
                    disabled={disabled}
                />
            );
        }
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
                <SecretControl
                    id={id}
                    name={name}
                    describedBy={describedBy}
                    secret={secret}
                    disabled={disabled}
                />
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
                    className={field.type === 'email' ? 'w-full' : undefined}
                />
            );
    }
}

/**
 * A time zone, chosen from a list typed into: there are hundreds. Each
 * reads as the server names it, such as "Africa/Lagos (WAT, UTC+1)".
 */
function TimezoneControl({
    id,
    name,
    options,
    value,
    describedBy,
    disabled,
}: {
    id: string;
    name: string;
    options: Record<string, string>;
    value: string;
    describedBy: string;
    disabled?: boolean;
}) {
    const choices: ComboboxOption[] = Object.entries(options).map(
        ([optionValue, label]) => ({ value: optionValue, label }),
    );
    const [chosen, setChosen] = useState<ComboboxOption | null>(
        choices.find((choice) => choice.value === value) ?? null,
    );

    return (
        <div className="w-80 max-w-full">
            <Combobox
                id={id}
                options={choices}
                value={chosen}
                onValueChange={setChosen}
                placeholder={__('Choose a time zone')}
                searchPlaceholder={__('Find a time zone')}
                emptyText={__('No time zone matches.')}
                aria-describedby={describedBy}
                disabled={disabled}
            />
            <input
                type="hidden"
                name={name}
                value={chosen?.value ?? ''}
                disabled={disabled}
            />
        </div>
    );
}

/**
 * Ticked boxes for a setting that holds several of its options; more than
 * three sit in two columns. The group is named by the field's label.
 */
function Choices({
    id,
    name,
    options,
    value,
    disabled,
}: {
    id: string;
    name: string;
    options: Record<string, string>;
    value: unknown;
    disabled?: boolean;
}) {
    const columns = Object.keys(options).length > 3;

    return (
        <div
            id={id}
            role="group"
            aria-labelledby={`${id}-label`}
            aria-describedby={`${id}-hint ${id}-error`}
            className={`grid gap-2 ${columns ? 'sm:grid-cols-2' : ''}`}
        >
            {/* Sent when nothing is ticked, so clearing the selection saves. */}
            <input
                type="hidden"
                name={`${name}[]`}
                value=""
                disabled={disabled}
            />
            {Object.entries(options).map(([optionValue, label]) => (
                <label
                    key={optionValue}
                    className="text-body text-ink flex items-center gap-2"
                >
                    <Checkbox
                        name={`${name}[]`}
                        value={optionValue}
                        defaultChecked={
                            Array.isArray(value) && value.includes(optionValue)
                        }
                        disabled={disabled}
                    />
                    {label}
                </label>
            ))}
        </div>
    );
}
