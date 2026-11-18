import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PluginMark } from '@/components/ui/plugin-mark';
import { SelectField } from '@/components/ui/select-field';
import { Textarea } from '@/components/ui/textarea';
import { __ } from '@/lib/i18n';
import type { PluginSearchField, PluginSearchTab } from '@/types/editor';
import { BoxRow } from './box-row';

type Values = Record<string, unknown>;

/** A field's id on the page, so a check can put the cursor on it. */
export function pluginFieldId(tab: string, key: string): string {
    return `editor-${tab}-${key}`;
}

/** The tab's values as the item's fields keep them: one object, or none yet. */
export function tabValues(value: unknown): Values {
    return value !== null && typeof value === 'object' && !Array.isArray(value)
        ? (value as Values)
        : {};
}

/** A stored value as text: a string, a number or a yes or no; '' for anything else. */
function asText(value: unknown): string {
    return typeof value === 'string' ||
        typeof value === 'number' ||
        typeof value === 'boolean'
        ? String(value)
        : '';
}

/** One value in words, for the summary; null when it says nothing. */
function said(field: PluginSearchField, value: unknown): string | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    if (field.type === 'boolean') {
        return value === true ? __('Yes') : null;
    }

    if (field.type === 'select') {
        return (
            field.options.find((option) => option.value === asText(value))
                ?.label ?? asText(value)
        );
    }

    return asText(value);
}

/**
 * The tab's one-line summary (docs/guides/developers/plugins/seo.md): each
 * summary field that holds a value, by its label; else that none is set.
 */
export function tabSummary(tab: PluginSearchTab, value: unknown): string {
    const values = tabValues(value);
    const parts = tab.fields
        .filter((field) => field.summary)
        .flatMap((field) => {
            const words = said(field, values[field.key]);

            return words === null ? [] : [`${field.label}: ${words}`];
        });

    return parts.length === 0 ? __('Nothing set yet.') : parts.join(' · ');
}

/**
 * A plugin's tab in Search and social, drawn by core from what its manifest
 * declares, marked as the plugin's: its line, its summary, then its fields
 * as the box's rows. Its values are saved with the item's revision.
 */
export function PluginTab({
    tab,
    value,
    onChange,
}: {
    tab: PluginSearchTab;
    value: unknown;
    onChange: (next: Values) => void;
}) {
    const values = tabValues(value);
    const set = (key: string, next: unknown) => {
        const changed: Values = { ...values, [key]: next };

        if (next === null || next === '' || next === false) {
            delete changed[key];
        }

        onChange(changed);
    };

    return (
        <div className="grid gap-3" data-test={`search-tab-${tab.code}`}>
            <div className="grid gap-1">
                <PluginMark name={tab.plugin} />
                {tab.description !== '' && (
                    <p className="text-ink-2 text-caption">{tab.description}</p>
                )}
                <p
                    className="text-small"
                    data-test={`search-tab-summary-${tab.code}`}
                >
                    {tabSummary(tab, value)}
                </p>
            </div>
            {tab.fields.map((field) => {
                const id = pluginFieldId(tab.key, field.key);
                const hint =
                    field.description !== '' ? `${id}-hint` : undefined;

                return (
                    <BoxRow
                        key={field.key}
                        guide={
                            <>
                                {field.type !== 'boolean' && (
                                    <Label htmlFor={id}>{field.label}</Label>
                                )}
                                {hint !== undefined && (
                                    <p
                                        id={hint}
                                        className="text-ink-2 text-caption"
                                    >
                                        {field.description}
                                    </p>
                                )}
                            </>
                        }
                    >
                        <PluginFieldControl
                            id={id}
                            hint={hint}
                            field={field}
                            value={values[field.key]}
                            onChange={(next) => set(field.key, next)}
                        />
                    </BoxRow>
                );
            })}
        </div>
    );
}

function PluginFieldControl({
    id,
    hint,
    field,
    value,
    onChange,
}: {
    id: string;
    hint: string | undefined;
    field: PluginSearchField;
    value: unknown;
    onChange: (next: unknown) => void;
}) {
    switch (field.type) {
        case 'boolean':
            return (
                <div className="flex min-h-6 items-center gap-2">
                    <Checkbox
                        id={id}
                        aria-describedby={hint}
                        checked={value === true}
                        onCheckedChange={(next) => onChange(next === true)}
                    />
                    <Label htmlFor={id}>{field.label}</Label>
                </div>
            );
        case 'select':
            return (
                <SelectField
                    id={id}
                    aria-describedby={hint}
                    options={field.options}
                    emptyLabel={__('None')}
                    value={asText(value)}
                    onValueChange={(next) =>
                        onChange(next === '' ? null : next)
                    }
                />
            );
        case 'textarea':
            return (
                <Textarea
                    id={id}
                    aria-describedby={hint}
                    rows={3}
                    maxLength={field.maxLength ?? undefined}
                    value={typeof value === 'string' ? value : ''}
                    onChange={(event) => onChange(event.target.value)}
                />
            );
        case 'number':
            return (
                <Input
                    id={id}
                    aria-describedby={hint}
                    type="number"
                    inputMode="decimal"
                    min={field.min ?? undefined}
                    max={field.max ?? undefined}
                    value={typeof value === 'number' ? String(value) : ''}
                    onChange={(event) =>
                        onChange(
                            event.target.value === ''
                                ? null
                                : Number(event.target.value),
                        )
                    }
                />
            );
        default:
            return (
                <Input
                    id={id}
                    aria-describedby={hint}
                    type={field.type === 'url' ? 'url' : 'text'}
                    maxLength={field.maxLength ?? undefined}
                    value={typeof value === 'string' ? value : ''}
                    onChange={(event) => onChange(event.target.value)}
                />
            );
    }
}
