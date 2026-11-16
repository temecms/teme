import { router } from '@inertiajs/react';
import { useCallback, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import PluginActionController from '@/actions/App/Http/Admin/PluginActionController';
import { CodeField } from '@/components/fields/code-field';
import { SettingsPanel } from '@/components/fields/settings-panel';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import { PluginCell } from '@/components/list/plugin-cell';
import { AreaFieldInput } from '@/components/plugins/area/area-form';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Notice } from '@/components/ui/notice';
import { __ } from '@/lib/i18n';
import { visibleKeys } from '@/lib/visible-when';
import type {
    FieldDefinition,
    PluginActionOffer,
    PluginListShown,
} from '@/types';
import type { AreaField } from '@/types/plugin-area';

/** One row of a plugin's list, by its place. */
type PluginListRow = { index: number; cells: PluginListShown['rows'][number] };

/** A key's current value in the form, as a condition reads it. */
function formValue(data: FormData, field: FieldDefinition): unknown {
    const one = data.getAll(`values[${field.key}]`);
    const many = data.getAll(`values[${field.key}][]`);

    if (field.type === 'boolean') {
        return one.at(-1) === '1';
    }

    if (many.length > 0) {
        return many.filter((item) => item !== '');
    }

    return one.at(-1) ?? null;
}

/**
 * A plugin's settings with conditions (docs/plans/plugin-sockets-2.md, A4):
 * it follows the form's values as they change and tells its children which
 * settings show. A hidden setting stays in the form, switched off (Hidden),
 * and the server leaves it as stored.
 */
export function SettingsConditions({
    fields,
    values,
    children,
}: {
    fields: FieldDefinition[];
    values: Record<string, unknown>;
    children: (shown: Set<string>) => ReactNode;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const [current, setCurrent] = useState(values);
    const conditional = fields.some(
        (field) => Object.keys(field.visibleWhen ?? {}).length > 0,
    );

    const follow = useCallback(() => {
        const form = ref.current?.closest('form');

        if (form === null || form === undefined) {
            return;
        }

        const data = new FormData(form);

        setCurrent(
            Object.fromEntries(
                fields.map((field) => [field.key, formValue(data, field)]),
            ),
        );
    }, [fields]);

    const shown = visibleKeys(
        fields.map((field) => ({
            key: field.key,
            visibleWhen: field.visibleWhen ?? {},
        })),
        current,
    );

    return (
        <div
            ref={ref}
            className="contents"
            onInput={conditional ? follow : undefined}
            onChange={conditional ? follow : undefined}
            onClick={conditional ? () => queueMicrotask(follow) : undefined}
        >
            {children(shown)}
        </div>
    );
}

/**
 * A setting a condition hides: kept in the form, switched off, so the
 * browser neither checks nor sends it and the server leaves it as stored.
 * The same element either way, so what was typed stays when it shows again.
 */
export function Hidden({
    hidden,
    children,
}: {
    hidden: boolean;
    children: ReactNode;
}) {
    return (
        <fieldset
            hidden={hidden}
            disabled={hidden}
            className={hidden ? undefined : 'contents'}
            data-test={hidden ? 'setting-hidden' : undefined}
        >
            {children}
        </fieldset>
    );
}

/** A settings definition as the area's field input draws it. */
function asAreaField(field: FieldDefinition): AreaField {
    return {
        key: field.key,
        type: field.type as AreaField['type'],
        label: field.label,
        description: field.description,
        required: field.required,
        min: field.min,
        max: field.max,
        maxLength: field.maxLength,
        visibleWhen: field.visibleWhen ?? {},
        scripts: false,
        choices: Object.entries(field.options).map(([value, label]) => ({
            value,
            label,
        })),
        fields: (field.fields ?? []).map(asAreaField),
        minItems: field.min ?? 0,
        maxItems: field.max ?? 20,
        itemLabel: field.itemLabel ?? __('Item'),
        itemTitle: field.itemTitle ?? null,
        empty:
            field.type === 'boolean'
                ? false
                : field.type === 'number'
                  ? null
                  : '',
    };
}

/** A list's items as the form sends them: one hidden input a value. */
function sent(name: string, value: unknown): ReactNode[] {
    if (Array.isArray(value)) {
        return value.flatMap((one, index) => sent(`${name}[${index}]`, one));
    }

    if (value !== null && typeof value === 'object') {
        return Object.entries(value).flatMap(([key, one]) =>
            sent(`${name}[${key}]`, one),
        );
    }

    const text =
        value === true
            ? '1'
            : value === false
              ? '0'
              : typeof value === 'string' || typeof value === 'number'
                ? String(value)
                : '';

    return [<input key={name} type="hidden" name={name} value={text} />];
}

/**
 * A plugin's repeater setting (A5): its items drawn as a repeater, each
 * item's fields by the area's rules, sent as the form's hidden values.
 */
export function RepeaterSetting({
    field,
    value,
    errors,
}: {
    field: FieldDefinition;
    value: unknown;
    errors: Record<string, string>;
}) {
    const [items, setItems] = useState<unknown>(
        Array.isArray(value) ? value : [],
    );
    const area = asAreaField(field);
    const list = Array.isArray(items) ? items : [];

    return (
        <div className="grid gap-1" data-test="repeater-setting">
            <AreaFieldInput
                field={area}
                value={items}
                onChange={setItems}
                errors={errors}
                path={`values.${field.key}`}
            />
            {list.length === 0 ? (
                <input type="hidden" name={`values[${field.key}]`} value="" />
            ) : (
                sent(`values[${field.key}]`, list)
            )}
        </div>
    );
}

/** A plugin's code setting (A5): markup in a monospace box. */
export function CodeSetting({
    field,
    value,
    error,
    waiting = 0,
}: {
    field: FieldDefinition;
    value: unknown;
    error?: string;
    waiting?: number;
}) {
    const id = `field-${field.key}`;

    return (
        <Field
            htmlFor={id}
            label={field.label}
            help={field.description !== '' ? field.description : undefined}
            error={error}
            required={field.required}
        >
            <CodeField
                id={id}
                name={`values[${field.key}]`}
                defaultValue={typeof value === 'string' ? value : ''}
                invalid={error !== undefined}
                waiting={waiting}
            />
        </Field>
    );
}

/**
 * A plugin's actions on its settings page (round 1, A5): each a button that
 * queues the job the plugin declared for it.
 */
export function PluginActionsPanel({
    code,
    actions,
}: {
    code: string;
    actions: PluginActionOffer[];
}) {
    const [running, setRunning] = useState<string | null>(null);

    if (actions.length === 0) {
        return null;
    }

    return (
        <SettingsPanel as="section" title={__('Actions')} bare>
            <ul
                className="divide-line grid divide-y"
                data-test="plugin-actions"
            >
                {actions.map((action) => (
                    <li
                        key={action.id}
                        className="flex flex-wrap items-center justify-between gap-3 px-6 py-4"
                    >
                        <div className="grid gap-0.5">
                            <span className="text-body font-medium">
                                {action.label}
                            </span>
                            {action.description !== null && (
                                <span className="text-small text-ink-2">
                                    {action.description}
                                </span>
                            )}
                        </div>
                        <Button
                            type="button"
                            variant="neutral"
                            loading={running === action.id}
                            data-test={`plugin-action-${action.id}`}
                            onClick={() =>
                                router.post(
                                    PluginActionController.run.url({
                                        code,
                                        action: action.id,
                                    }),
                                    {},
                                    {
                                        preserveScroll: true,
                                        onStart: () => setRunning(action.id),
                                        onFinish: () => setRunning(null),
                                    },
                                )
                            }
                        >
                            {action.label}
                        </Button>
                    </li>
                ))}
            </ul>
        </SettingsPanel>
    );
}

/**
 * A plugin's lists on its settings page (round 1, A6): each core's table of
 * its columns and rows, every value plain text, or its one line when it
 * couldn't be shown.
 */
export function PluginListsPanel({ lists }: { lists: PluginListShown[] }) {
    return (
        <>
            {lists.map((list) => (
                <SettingsPanel
                    key={list.id}
                    as="section"
                    title={list.label}
                    description={list.description ?? undefined}
                    bare
                >
                    <div data-test="plugin-list" data-list={list.id}>
                        {list.failed !== null ? (
                            <Notice tone="warn" className="m-6">
                                {list.failed}
                            </Notice>
                        ) : (
                            <DataTable
                                columns={list.columns.map(
                                    (column): Column<PluginListRow> => ({
                                        id: column.id,
                                        owner: 'plugin',
                                        header: column.label,
                                        className:
                                            column.kind === 'number'
                                                ? 'text-right tabular-nums'
                                                : undefined,
                                        cell: (row) => (
                                            <PluginCell
                                                value={row.cells[column.id]}
                                            />
                                        ),
                                    }),
                                )}
                                rows={list.rows.map((cells, index) => ({
                                    index,
                                    cells,
                                }))}
                                rowKey={(row) => row.index}
                                caption={list.label}
                                empty={
                                    <p className="text-body text-ink-2 px-6 py-4">
                                        {__('Nothing here yet.')}
                                    </p>
                                }
                            />
                        )}
                    </div>
                </SettingsPanel>
            ))}
        </>
    );
}
