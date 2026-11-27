import { RepeaterField } from '@/components/fields/repeater-field';
import { MediaSetting } from '@/components/fields/media-setting';
import { Field, FieldInline } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { SelectField } from '@/components/ui/select-field';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { __ } from '@/lib/i18n';
import type { CardMedia, DesignField } from '@/types';
import type { FieldDefinition } from '@/types/settings';

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

/** Whether a choice is short enough for segmented buttons: three options or fewer, of few words. */
function short(options: Record<string, string>): boolean {
    const labels = Object.values(options);

    return (
        labels.length > 1 && labels.length <= 3 && labels.join('').length <= 24
    );
}

/** A DesignField as the shared media control expects a FieldDefinition. */
function asMediaField(field: DesignField): FieldDefinition {
    return {
        key: field.key,
        type: 'media',
        label: field.label,
        description: field.description,
        options: {},
        min: null,
        max: null,
        maxLength: null,
        required: false,
        example: null,
        unit: null,
    };
}

/**
 * One of a theme's settings as its control: a switch beside its label,
 * segmented buttons for a short choice, a select of its options, or a text
 * box, each with its help and its error.
 */
export function DesignFieldInput({
    field,
    value,
    error,
    onChange,
    media,
    menus = [],
}: {
    field: DesignField;
    value: unknown;
    error?: string;
    onChange: (value: unknown) => void;
    media?: CardMedia | null;
    menus?: { id: string; name: string }[];
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
                <Switch
                    id={id}
                    checked={value === true}
                    onCheckedChange={(checked) => onChange(checked)}
                />
            </FieldInline>
        );
    }

    if (field.type === 'repeater') {
        return (
            <RepeaterField
                id={id}
                label={field.label}
                help={help}
                error={error}
                items={
                    Array.isArray(value)
                        ? (value as Record<string, unknown>[])
                        : []
                }
                onChange={onChange}
                itemLabel={__('Link')}
                itemTitle={field.itemTitle ?? null}
                empty={{ label: '', address: '' }}
                minItems={0}
                maxItems={field.max ?? 12}
            >
                {(item, _index, change) => (
                    <div className="flex flex-wrap gap-2">
                        <Input
                            className="min-w-40 flex-1"
                            aria-label={__('Label')}
                            value={text(item.label)}
                            onChange={(event) =>
                                change({
                                    ...item,
                                    label: event.target.value,
                                })
                            }
                        />
                        <Input
                            className="min-w-40 flex-1"
                            aria-label={__('Address')}
                            value={text(item.address)}
                            onChange={(event) =>
                                change({
                                    ...item,
                                    address: event.target.value,
                                })
                            }
                        />
                    </div>
                )}
            </RepeaterField>
        );
    }

    return (
        <Field htmlFor={id} label={field.label} help={help} error={error}>
            {field.type === 'select' && short(field.options) ? (
                <Segmented
                    id={id}
                    aria-label={field.label}
                    value={text(value)}
                    onValueChange={onChange}
                    className="justify-self-start"
                >
                    {Object.entries(field.options).map(([option, label]) => (
                        <SegmentedItem key={option} value={option}>
                            {label}
                        </SegmentedItem>
                    ))}
                </Segmented>
            ) : field.type === 'select' ? (
                <SelectField
                    id={id}
                    className="w-full"
                    value={text(value)}
                    options={Object.entries(field.options).map(
                        ([option, label]) => ({ value: option, label }),
                    )}
                    onValueChange={onChange}
                />
            ) : field.type === 'menu' ? (
                <SelectField
                    id={id}
                    className="w-full"
                    value={text(value)}
                    emptyLabel={__('None')}
                    options={menus.map((menu) => ({
                        value: menu.id,
                        label: menu.name,
                    }))}
                    onValueChange={onChange}
                />
            ) : field.type === 'number' ? (
                <div className="flex items-center gap-2">
                    <Input
                        id={id}
                        type="number"
                        className="w-32 tabular-nums"
                        value={text(value)}
                        min={field.min ?? undefined}
                        max={field.max ?? undefined}
                        step={field.step ?? undefined}
                        onChange={(event) =>
                            onChange(
                                event.target.value === ''
                                    ? ''
                                    : Number(event.target.value),
                            )
                        }
                    />
                    {field.unit !== null && field.unit !== '' && (
                        <span className="text-body text-ink-2">
                            {field.unit}
                        </span>
                    )}
                </div>
            ) : field.type === 'textarea' ? (
                <Textarea
                    id={id}
                    rows={4}
                    value={text(value)}
                    onChange={(event) => onChange(event.target.value)}
                />
            ) : field.type === 'url' ? (
                <Input
                    id={id}
                    type="url"
                    value={text(value)}
                    onChange={(event) => onChange(event.target.value)}
                />
            ) : field.type === 'media' ? (
                <MediaSetting
                    field={asMediaField(field)}
                    value={value}
                    media={media}
                    onChange={(next) => onChange(next)}
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
