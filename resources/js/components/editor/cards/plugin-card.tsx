import { useHttp } from '@inertiajs/react';
import type { ReactNodeViewProps } from '@tiptap/react';
import { Plug } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import PluginCardChoicesController from '@/actions/App/Http/Admin/Contents/PluginCardChoicesController';
import { SettingsField } from '@/components/fields/settings-field';
import { Combobox } from '@/components/ui/combobox';
import type { ComboboxOption } from '@/components/ui/combobox';
import { IconTile } from '@/components/ui/icon-tile';
import { Label } from '@/components/ui/label';
import { __ } from '@/lib/i18n';
import type { FieldDefinition, PluginCardEntry } from '@/types';
import { CardFrame } from './card-frame';
import { useCardEnvironment } from './card-environment';
import type { CardSettingsProps } from './card-environment';

/** One of a plugin card's fields: a setting's, or a choice. */
type CardField = PluginCardEntry['fields'][number];

/** The plugin's card this node holds, from the manifest; null while its plugin isn't running. */
function usePluginCard(key: string): PluginCardEntry | null {
    return (
        useCardEnvironment().manifest.pluginCards.find(
            (card) => card.key === key,
        ) ?? null
    );
}

/** A card's values, as stored: an object of the fields' values. */
function valuesOf(attrs: Record<string, unknown>): Record<string, unknown> {
    const values = attrs.values;

    return values !== null &&
        typeof values === 'object' &&
        !Array.isArray(values)
        ? (values as Record<string, unknown>)
        : {};
}

/** The plugin's code, the part of the card's key before the colon. */
function codeOf(key: string): string {
    return key.split(':')[0] ?? key;
}

/** A field's value read back from the form its controls write into, as the server stores it. */
function readField(form: FormData, field: CardField): unknown {
    const name = `values[${field.key}]`;

    if (
        field.type === 'multiselect' ||
        (field.type === 'term' && field.multiple === true)
    ) {
        return form
            .getAll(`${name}[]`)
            .map(String)
            .filter((item) => item !== '');
    }

    const all = form.getAll(name).map(String);
    const last = all.length === 0 ? null : all[all.length - 1];

    if (field.type === 'boolean') {
        return last === '1';
    }

    if (last === null || last === '') {
        return null;
    }

    return field.type === 'number' ? Number(last) : last;
}

/**
 * A plugin's card in a theme's region (docs/guides/developers/plugins/cards.md):
 * its plugin's name and its fields, drawn as plugin settings are. While its
 * plugin is off, the card is kept, greyed, read-only, and can be removed.
 */
export function PluginCardView({
    node,
    selected,
    updateAttributes,
    deleteNode,
}: ReactNodeViewProps) {
    const key = (node.attrs.card as string | null) ?? '';
    const card = usePluginCard(key);
    const values = valuesOf(node.attrs);
    const first = card?.fields.find(
        (field) =>
            typeof values[field.key] === 'string' && values[field.key] !== '',
    );

    if (card === null) {
        return (
            <CardFrame
                label={__('Plugin card')}
                selected={selected}
                onRemove={deleteNode}
                settings={
                    <PluginCardSettings
                        attrs={node.attrs}
                        update={updateAttributes}
                    />
                }
            >
                <div
                    data-test="plugin-card-off"
                    className="flex min-w-0 items-start gap-3 opacity-60"
                >
                    <IconTile icon={Plug} />
                    <p className="text-ink-2 text-small min-w-0">
                        {__(
                            'From the :plugin plugin, which is disabled. Kept here; it shows nothing on the site.',
                            { plugin: codeOf(key) },
                        )}
                    </p>
                </div>
            </CardFrame>
        );
    }

    return (
        <CardFrame
            label={card.label}
            selected={selected}
            onRemove={deleteNode}
            defaultOpen={
                Object.keys(values).length === 0 && card.fields.length > 0
            }
            preview={
                first === undefined ? undefined : String(values[first.key])
            }
            settings={
                <PluginCardSettings
                    attrs={node.attrs}
                    update={updateAttributes}
                />
            }
        >
            <div className="flex min-w-0 items-start gap-3">
                <IconTile icon={Plug} />
                <div className="grid min-w-0 gap-1">
                    <p className="text-ink truncate font-semibold">
                        {first === undefined
                            ? card.label
                            : String(values[first.key])}
                    </p>
                    <p className="text-ink-2 text-small">
                        {__('From the :plugin plugin', { plugin: card.plugin })}
                    </p>
                </div>
            </div>
        </CardFrame>
    );
}

/** A plugin card's fields, in the card's panel or the rail's inspector. */
export function PluginCardSettings({ attrs, update }: CardSettingsProps) {
    const key = (attrs.card as string | null) ?? '';
    const card = usePluginCard(key);
    const values = valuesOf(attrs);
    const form = useRef<HTMLFormElement>(null);

    if (card === null) {
        return (
            <p className="text-ink-2 text-small">
                {__(
                    'Its plugin is disabled, so its settings can’t be changed here. Remove the card, or enable the plugin again.',
                )}
            </p>
        );
    }

    // Controls write into the form; after each change, the card keeps what the form holds.
    const sync = (): void => {
        window.setTimeout(() => {
            if (form.current === null) {
                return;
            }

            const data = new FormData(form.current);
            const next: Record<string, unknown> = {};

            for (const field of card.fields) {
                const value =
                    field.type === 'choice'
                        ? values[field.key]
                        : readField(data, field);

                if (value !== null && value !== undefined && value !== '') {
                    next[field.key] = value;
                }
            }

            update({ values: next });
        }, 0);
    };

    return (
        <form
            ref={form}
            className="grid gap-4"
            onSubmit={(event: FormEvent) => event.preventDefault()}
            onInput={sync}
            onChange={sync}
            onClickCapture={sync}
            onKeyUpCapture={sync}
        >
            {card.fields.map((field) =>
                field.type === 'choice' ? (
                    <ChoiceField
                        key={field.key}
                        card={key}
                        field={field}
                        value={
                            typeof values[field.key] === 'string'
                                ? (values[field.key] as string)
                                : ''
                        }
                        onChange={(value) => {
                            const next = { ...values };

                            if (value === '') {
                                delete next[field.key];
                            } else {
                                next[field.key] = value;
                            }

                            update({ values: next });
                        }}
                    />
                ) : (
                    <SettingsField
                        key={field.key}
                        field={field as FieldDefinition}
                        value={values[field.key] ?? null}
                        name={`values[${field.key}]`}
                    />
                ),
            )}
        </form>
    );
}

/** A choice field: the options the plugin gives, asked as the person types. */
function ChoiceField({
    card,
    field,
    value,
    onChange,
}: {
    card: string;
    field: CardField;
    value: string;
    onChange: (value: string) => void;
}) {
    const { uploadedFor } = useCardEnvironment();
    const { submit } = useHttp();
    const [search, setSearch] = useState('');
    const [loading, setLoading] = useState(false);
    const [options, setOptions] = useState<ComboboxOption[]>([]);
    const id = `field-${field.key}`;

    useEffect(() => {
        let current = true;
        setLoading(true);

        void (
            submit(
                PluginCardChoicesController(
                    { content: uploadedFor.id, card, field: field.key },
                    { query: { q: search } },
                ),
            ) as Promise<{
                choices: {
                    value: string;
                    label: string;
                    hint: string | null;
                    fits: boolean | null;
                }[];
            }>
        )
            .then((answer) => {
                if (current) {
                    setOptions(
                        answer.choices.map((choice) => ({
                            value: choice.value,
                            label: choice.label,
                            description:
                                choice.fits === false
                                    ? [
                                          choice.hint,
                                          __("Doesn't fit this region"),
                                      ]
                                          .filter(Boolean)
                                          .join(' · ')
                                    : (choice.hint ?? undefined),
                        })),
                    );
                }
            })
            .catch(() => current && setOptions([]))
            .finally(() => current && setLoading(false));

        return () => {
            current = false;
        };
    }, [submit, uploadedFor.id, card, field.key, search]);

    const chosen =
        options.find((option) => option.value === value) ??
        (value === '' ? null : { value, label: value });

    return (
        <div className="grid gap-1">
            <Label htmlFor={id}>{field.label}</Label>
            <Combobox
                id={id}
                options={options}
                value={chosen}
                onValueChange={(next) => onChange(next?.value ?? '')}
                placeholder={__('Choose…')}
                searchPlaceholder={__('Find')}
                emptyText={__('Nothing to choose from.')}
                search={{ value: search, onChange: setSearch, loading }}
                aria-describedby={`${id}-hint`}
            />
            {field.description !== '' && (
                <p
                    id={`${id}-hint`}
                    className="text-caption text-ink-3 text-pretty"
                >
                    {field.description}
                </p>
            )}
        </div>
    );
}
