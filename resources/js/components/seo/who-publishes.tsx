import { AlertTriangle } from 'lucide-react';
import { useState } from 'react';
import type { ComponentProps } from 'react';
import { SettingsField } from '@/components/fields/settings-field';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type {
    FieldDefinition,
    PublisherScreen,
    SearchAndSocialPage,
    SearchAndSocialSection,
} from '@/types';

const selectClass =
    'border-input bg-background focus-visible:ring-ring w-full max-w-[320px] rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

const PERSON = 'Person';

type Props = {
    section: SearchAndSocialSection;
    values: SearchAndSocialPage['values'];
    media: SearchAndSocialPage['media'];
    errors: Record<string, string>;
    publisher: PublisherScreen;
};

type TextProps = {
    field: FieldDefinition;
    value: unknown;
    error?: string;
    placeholder?: string;
    className?: string;
} & Pick<
    ComponentProps<typeof Input>,
    'type' | 'inputMode' | 'maxLength' | 'autoCapitalize'
>;

/**
 * Search and social's section "Who publishes the site" (I-874): the kind
 * first, then only the fields it describes, as their `visibleWhen` says, the
 * business and its owner each a fieldset of its own.
 */
export function WhoPublishesSection({
    section,
    values,
    media,
    errors,
    publisher,
}: Props) {
    const [kind, setKind] = useState(
        typeof values.publisher_type === 'string' ? values.publisher_type : '',
    );
    const fields = new Map(section.fields.map((field) => [field.key, field]));
    const kindField = fields.get('publisher_type');

    // A field the kind hides isn't drawn, so it isn't sent and keeps its value.
    const shown = (field: FieldDefinition | undefined): boolean => {
        if (field === undefined) {
            return false;
        }

        return Object.entries(field.visibleWhen ?? {}).every(
            ([key, wanted]) => {
                const current = key === 'publisher_type' ? kind : values[key];

                return Array.isArray(wanted)
                    ? wanted.includes(String(current))
                    : current === wanted;
            },
        );
    };
    const error = (key: string) => errors[`values.${key}`];
    const generic = (key: string) => {
        const field = fields.get(key);

        return field !== undefined && shown(field) ? (
            <SettingsField
                key={key}
                field={field}
                value={values[key]}
                media={media[key] ?? null}
                error={error(key)}
            />
        ) : null;
    };
    const text = (key: string, props: Partial<TextProps> = {}) => {
        const field = fields.get(key);

        return field !== undefined && shown(field) ? (
            <TextField
                key={key}
                field={field}
                value={values[key]}
                error={error(key)}
                {...props}
            />
        ) : null;
    };
    const ownerShown = [
        'publisher_owner_name',
        'publisher_owner_url',
        'publisher_owner_profiles',
    ].some((key) => shown(fields.get(key)));
    const businessShown = [
        'business_street_address',
        'business_locality',
        'business_hours',
    ].some((key) => shown(fields.get(key)));
    const groups = [
        ...new Set(
            publisher.kinds.flatMap((item) =>
                item.group === null ? [] : [item.group],
            ),
        ),
    ];
    const person = fields.get('publisher_person');

    return (
        <>
            {kindField !== undefined && (
                <div className="grid gap-2">
                    <Label htmlFor="field-publisher_type">
                        {kindField.label}
                    </Label>
                    <select
                        id="field-publisher_type"
                        name="values[publisher_type]"
                        value={kind}
                        onChange={(event) => setKind(event.target.value)}
                        aria-describedby="field-publisher_type-hint field-publisher_type-error"
                        className={selectClass}
                    >
                        {publisher.kinds
                            .filter(
                                (item) =>
                                    item.group === null && item.value === '',
                            )
                            .map((item) => (
                                <option key={item.value} value={item.value}>
                                    {item.label}
                                </option>
                            ))}
                        {groups.map((group) => (
                            <optgroup key={group} label={group}>
                                {publisher.kinds
                                    .filter((item) => item.group === group)
                                    .map((item) => (
                                        <option
                                            key={item.value}
                                            value={item.value}
                                        >
                                            {item.label}
                                        </option>
                                    ))}
                            </optgroup>
                        ))}
                        {publisher.kinds
                            .filter(
                                (item) =>
                                    item.group === null && item.value !== '',
                            )
                            .map((item) => (
                                <option key={item.value} value={item.value}>
                                    {item.label}
                                </option>
                            ))}
                    </select>
                    <p
                        id="field-publisher_type-hint"
                        className="text-muted-foreground text-sm"
                    >
                        {kindField.description}
                    </p>
                    <InputError
                        id="field-publisher_type-error"
                        message={error('publisher_type')}
                    />
                </div>
            )}

            {text('publisher_name', { placeholder: publisher.siteTitle })}
            {generic('publisher_alternate_name')}
            {generic('publisher_logo')}
            {generic('publisher_person')}
            {person !== undefined &&
                shown(person) &&
                publisher.personGone &&
                kind === PERSON && (
                    <div
                        role="note"
                        data-test="publisher-person-gone"
                        className="border-border flex gap-3 rounded-md border p-4 text-sm"
                    >
                        <AlertTriangle
                            aria-hidden="true"
                            className="text-warning mt-0.5 size-4 shrink-0"
                        />
                        <p>
                            This person no longer has an account, so search
                            engines are told nobody in particular publishes the
                            site. Choose someone else, or Nobody in particular.
                        </p>
                    </div>
                )}
            {generic('publisher_profiles')}

            {businessShown && (
                <fieldset className="border-border grid gap-4 rounded-md border p-4">
                    <legend className="px-1 text-sm font-medium">
                        The business
                    </legend>
                    <p className="text-muted-foreground text-sm">
                        One place. A business with several needs a plugin that
                        describes each one.
                    </p>
                    {text('business_street_address')}
                    <div className="grid gap-4 md:grid-cols-2">
                        {text('business_locality')}
                        {text('business_region')}
                    </div>
                    <div className="grid gap-4 md:grid-cols-2">
                        {text('business_postal_code')}
                        {text('business_country', {
                            className: 'w-20 uppercase',
                            maxLength: 2,
                            autoCapitalize: 'characters',
                        })}
                    </div>
                    {text('business_phone', { type: 'tel' })}
                    <div className="grid gap-4 md:grid-cols-2">
                        {text('business_latitude', { inputMode: 'decimal' })}
                        {text('business_longitude', { inputMode: 'decimal' })}
                    </div>
                    {text('business_price_range')}
                    {generic('business_hours')}
                </fieldset>
            )}

            {ownerShown && (
                <fieldset className="border-border grid gap-4 rounded-md border p-4">
                    <legend className="px-1 text-sm font-medium">
                        Owned by
                    </legend>
                    <p className="text-muted-foreground text-sm">
                        Leave these empty unless another organization owns this
                        one.
                    </p>
                    {generic('publisher_owner_name')}
                    {generic('publisher_owner_url')}
                    {generic('publisher_owner_profiles')}
                </fieldset>
            )}
        </>
    );
}

/** A text field as SettingsField draws one, with the options a business's fields need; never filled in by the browser, since they are the business's, not the person's. */
function TextField({
    field,
    value,
    error,
    placeholder,
    className,
    type,
    inputMode,
    maxLength,
    autoCapitalize,
}: TextProps) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={`field-${field.key}`}>{field.label}</Label>
            <Input
                id={`field-${field.key}`}
                name={`values[${field.key}]`}
                type={type}
                inputMode={inputMode}
                defaultValue={
                    typeof value === 'string' || typeof value === 'number'
                        ? String(value)
                        : ''
                }
                maxLength={maxLength ?? field.maxLength ?? undefined}
                placeholder={placeholder}
                autoCapitalize={autoCapitalize}
                autoComplete="off"
                aria-describedby={`field-${field.key}-hint field-${field.key}-error`}
                className={className}
            />
            <p
                id={`field-${field.key}-hint`}
                className="text-muted-foreground text-sm"
            >
                {field.description}
            </p>
            <InputError id={`field-${field.key}-error`} message={error} />
        </div>
    );
}
