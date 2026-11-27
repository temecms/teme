import { useState } from 'react';
import { MediaModal } from '@/components/media/media-modal';
import { Button } from '@/components/ui/button';
import { Field, FieldInline } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { SelectField } from '@/components/ui/select-field';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { __ } from '@/lib/i18n';
import { largest, variant } from '@/lib/media-urls';
import type { CardMedia, DesignField } from '@/types';

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

type LinkRow = { label: string; address: string };

/** A links setting as a short list of label and address rows. */
function LinksInput({
    field,
    value,
    onChange,
}: {
    field: DesignField;
    value: unknown;
    onChange: (value: unknown) => void;
}) {
    const rows: LinkRow[] = Array.isArray(value)
        ? value.filter(
              (row): row is LinkRow =>
                  typeof row === 'object' &&
                  row !== null &&
                  typeof (row as LinkRow).label === 'string' &&
                  typeof (row as LinkRow).address === 'string',
          )
        : [];
    const most = field.max ?? 12;

    const setRow = (index: number, patch: Partial<LinkRow>) => {
        onChange(
            rows.map((row, i) => (i === index ? { ...row, ...patch } : row)),
        );
    };

    return (
        <div className="grid gap-3" data-test="design-links">
            {rows.map((row, index) => (
                <div
                    key={index}
                    className="grid gap-2 sm:grid-cols-[1fr_1fr_auto]"
                >
                    <Input
                        aria-label={__('Label for link :number', {
                            number: index + 1,
                        })}
                        value={row.label}
                        onChange={(event) =>
                            setRow(index, { label: event.target.value })
                        }
                    />
                    <Input
                        aria-label={__('Address for link :number', {
                            number: index + 1,
                        })}
                        value={row.address}
                        onChange={(event) =>
                            setRow(index, { address: event.target.value })
                        }
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                            onChange(rows.filter((_, i) => i !== index))
                        }
                    >
                        {__('Remove')}
                    </Button>
                </div>
            ))}
            {rows.length < most && (
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="justify-self-start"
                    onClick={() =>
                        onChange([...rows, { label: '', address: '' }])
                    }
                >
                    {__('Add a link')}
                </Button>
            )}
        </div>
    );
}

/** A media setting chosen through the library picker. */
function MediaInput({
    field,
    value,
    media,
    onChange,
}: {
    field: DesignField;
    value: unknown;
    media?: CardMedia | null;
    onChange: (value: unknown) => void;
}) {
    const chosen = typeof value === 'string' ? value : '';
    const [choosing, setChoosing] = useState(false);
    const preview =
        chosen !== '' && media
            ? (variant(media.variants, 'w640') ?? largest(media.variants) ?? '')
            : '';

    return (
        <div className="flex flex-wrap items-center gap-2">
            {chosen === '' ? (
                <span className="bg-surface-3 text-ink-2 text-caption inline-flex h-6 items-center rounded-sm px-2">
                    {__('No picture chosen')}
                </span>
            ) : preview !== '' ? (
                <img
                    data-test="media-preview"
                    src={preview}
                    alt=""
                    className="aspect-3/2 w-16 rounded-sm object-cover"
                />
            ) : null}
            <Button
                type="button"
                variant="outline"
                size="sm"
                data-test={chosen === '' ? 'media-choose' : 'media-replace'}
                onClick={() => setChoosing(true)}
            >
                {__('Choose picture')}
            </Button>
            {chosen !== '' && (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    data-test="media-remove"
                    onClick={() => onChange('')}
                >
                    {__('Remove')}
                </Button>
            )}
            <MediaModal
                open={choosing}
                onOpenChange={setChoosing}
                kinds={['image']}
                action={__('Use as :setting', {
                    setting: field.label.toLowerCase(),
                })}
                onChoose={(ids) => onChange(ids[0] ?? '')}
            />
        </div>
    );
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
                <MediaInput
                    field={field}
                    value={value}
                    media={media}
                    onChange={onChange}
                />
            ) : field.type === 'repeater' ? (
                <LinksInput field={field} value={value} onChange={onChange} />
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
