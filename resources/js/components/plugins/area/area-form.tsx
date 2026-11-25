import { router } from '@inertiajs/react';
import { ChevronDown, ChevronRight, Plus } from 'lucide-react';
import { createContext, useContext, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import PluginAreaController from '@/actions/App/Http/Admin/PluginAreaController';
import { CodeField } from '@/components/fields/code-field';
import type { WaitingScript } from '@/components/fields/code-field';
import { FreshPasswordDialog } from '@/components/patterns/fresh-password';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ScrollArea } from '@/components/ui/scroll-area';
import { SelectField } from '@/components/ui/select-field';
import { Textarea } from '@/components/ui/textarea';
import { __ } from '@/lib/i18n';
import { visibleKeys } from '@/lib/visible-when';
import type { AreaField } from '@/types/plugin-area';

type Values = Record<string, unknown>;

/**
 * The form's waiting scripts by their field's path, and, for a person who
 * may change site code, how to approve one (the decided Q2).
 */
type AreaScripts = {
    waiting: Record<string, WaitingScript[]>;
    approve?: (id: string) => void;
    busy: boolean;
};

const ScriptsContext = createContext<AreaScripts>({ waiting: {}, busy: false });

/** Hands the form's waiting scripts to every code field under it. */
export const AreaScriptsProvider = ScriptsContext.Provider;

/** The waiting scripts handed down by the nearest AreaScriptsProvider. */
export function useAreaScripts(): AreaScripts {
    return useContext(ScriptsContext);
}

/**
 * Approves one of a plugin's waiting scripts from its form, asking for the
 * password in place when its window has closed (bootstrap/app.php answers
 * 423), then sending it again, so nothing typed in the form is lost.
 */
export function useScriptApproval(code: string): {
    approve: (id: string) => void;
    busy: boolean;
    password: ReactNode;
} {
    const [busy, setBusy] = useState(false);
    const [asking, setAsking] = useState(false);
    const again = useRef<string | null>(null);

    const approve = (id: string) => {
        setBusy(true);
        router.post(
            PluginAreaController.approveScript.url({ code, script: id }),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                headers: { 'X-Teme-Ask-In-Place': '1' },
                onHttpException: (response) => {
                    if (response.status === 423) {
                        again.current = id;
                        setAsking(true);

                        return false;
                    }
                },
                onFinish: () => setBusy(false),
            },
        );
    };

    return {
        approve,
        busy,
        password: (
            <FreshPasswordDialog
                open={asking}
                onOpenChange={setAsking}
                title={__('Confirm your password')}
                text={__(
                    'Approving a script for the site’s pages asks for your password.',
                )}
                onConfirmed={() => {
                    const id = again.current;
                    again.current = null;

                    if (id !== null) {
                        approve(id);
                    }
                }}
            />
        ),
    };
}

/** A value as a text box shows it. */
function text(value: unknown): string {
    return typeof value === 'string' || typeof value === 'number'
        ? String(value)
        : '';
}

/** A value as a list of strings. */
function list(value: unknown): string[] {
    return Array.isArray(value)
        ? value.filter((one): one is string => typeof one === 'string')
        : [];
}

/**
 * A repeater for an area field: Move up / Move down / Remove as words, a
 * count line ("3 of at most 20."), and no second copy of the section label
 * (the Field above already names it).
 */
function AreaRepeater({
    id,
    label,
    items,
    onChange,
    itemLabel,
    itemTitle,
    empty,
    minItems,
    maxItems,
    children,
}: {
    id: string;
    label: string;
    items: Values[];
    onChange: (items: Values[]) => void;
    itemLabel: string;
    itemTitle: string | null;
    empty: Values;
    minItems: number;
    maxItems: number;
    children: (
        item: Values,
        index: number,
        change: (item: Values) => void,
    ) => ReactNode;
}) {
    const [keys, setKeys] = useState(() => items.map((_, index) => index));
    const [next, setNext] = useState(items.length);
    const [closed, setClosed] = useState<Set<number>>(new Set());

    const titleOf = (item: Values, index: number): string => {
        const title = itemTitle === null ? '' : item[itemTitle];

        return typeof title === 'string' && title.trim() !== ''
            ? title
            : `${itemLabel} ${index + 1}`;
    };

    const named = itemLabel.trim() !== '' && itemLabel !== __('Item');
    const noun = itemLabel.trim().toLowerCase();
    const addLabel = !named
        ? __('Add item')
        : /^[aeiou]/.test(noun)
          ? __('Add an :item', { item: noun })
          : __('Add a :item', { item: noun });

    const move = (from: number, to: number) => {
        const moved = [...items];
        const movedKeys = [...keys];
        [moved[from], moved[to]] = [moved[to], moved[from]];
        [movedKeys[from], movedKeys[to]] = [movedKeys[to], movedKeys[from]];
        setKeys(movedKeys);
        onChange(moved);
    };

    return (
        <fieldset
            id={id}
            className="grid gap-3"
            data-test="repeater"
            data-count={items.length}
        >
            <legend className="sr-only">{label}</legend>
            <p className="text-caption text-ink-3" data-test="repeater-count">
                {__(':count of at most :max.', {
                    count: items.length,
                    max: maxItems,
                })}
            </p>
            <ol className="grid gap-3">
                {items.map((item, index) => {
                    const key = keys[index] ?? index;
                    const open = !closed.has(key);
                    const title = titleOf(item, index);

                    return (
                        <li
                            key={key}
                            data-test="repeater-item"
                            data-open={open || undefined}
                            className="border-line rounded-md border"
                        >
                            <div className="flex flex-wrap items-center gap-1 py-1 pr-2 pl-2">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="min-w-0 flex-1 justify-start"
                                    aria-expanded={open}
                                    onClick={() =>
                                        setClosed((current) => {
                                            const changed = new Set(current);

                                            if (open) {
                                                changed.add(key);
                                            } else {
                                                changed.delete(key);
                                            }

                                            return changed;
                                        })
                                    }
                                >
                                    {open ? (
                                        <ChevronDown aria-hidden="true" />
                                    ) : (
                                        <ChevronRight aria-hidden="true" />
                                    )}
                                    <span
                                        className="truncate"
                                        data-test="repeater-title"
                                    >
                                        {title}
                                    </span>
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    disabled={index === 0}
                                    onClick={() => move(index, index - 1)}
                                >
                                    {__('Move up')}
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    disabled={index === items.length - 1}
                                    onClick={() => move(index, index + 1)}
                                >
                                    {__('Move down')}
                                </Button>
                                <Button
                                    type="button"
                                    variant="danger-quiet"
                                    size="sm"
                                    disabled={items.length <= minItems}
                                    onClick={() => {
                                        setKeys(
                                            keys.filter(
                                                (_, at) => at !== index,
                                            ),
                                        );
                                        onChange(
                                            items.filter(
                                                (_, at) => at !== index,
                                            ),
                                        );
                                    }}
                                >
                                    {__('Remove')}
                                </Button>
                            </div>
                            {open && (
                                <div className="grid gap-5 px-4 pt-2 pb-4">
                                    {children(item, index, (changed) =>
                                        onChange(
                                            items.map((one, at) =>
                                                at === index ? changed : one,
                                            ),
                                        ),
                                    )}
                                </div>
                            )}
                        </li>
                    );
                })}
            </ol>
            <div>
                <Button
                    type="button"
                    variant="neutral"
                    disabled={items.length >= maxItems}
                    data-test="repeater-add"
                    onClick={() => {
                        setKeys([...keys, next]);
                        setNext(next + 1);
                        onChange([...items, { ...empty }]);
                    }}
                >
                    <Plus aria-hidden="true" />
                    {addLabel}
                </Button>
            </div>
        </fieldset>
    );
}

/** A list of tick boxes, one a choice, for multiselect and the pickers. */
function Choices({
    id,
    field,
    value,
    onChange,
}: {
    id: string;
    field: AreaField;
    value: unknown;
    onChange: (value: unknown) => void;
}) {
    const chosen = list(value);

    if (field.choices.length === 0) {
        return (
            <p id={id} className="text-small text-ink-3">
                {__('Nothing to choose from yet.')}
            </p>
        );
    }

    return (
        <ScrollArea className="max-h-56">
            <ul id={id} aria-label={field.label} className="grid gap-2">
                {field.choices.map((choice) => {
                    const box = `${id}-${choice.value}`;

                    return (
                        <li
                            key={choice.value}
                            className="flex items-center gap-2"
                        >
                            <Checkbox
                                id={box}
                                checked={chosen.includes(choice.value)}
                                onCheckedChange={(checked) =>
                                    onChange(
                                        checked === true
                                            ? [...chosen, choice.value]
                                            : chosen.filter(
                                                  (one) => one !== choice.value,
                                              ),
                                    )
                                }
                            />
                            <Label htmlFor={box} className="text-body text-ink">
                                {choice.label}
                            </Label>
                        </li>
                    );
                })}
            </ul>
        </ScrollArea>
    );
}

/**
 * One field of a plugin record's form (docs/plans/plugin-sockets-2.md, A2):
 * the control its type draws, every value plain text. A repeater draws its
 * items' fields with the same rule, one level down.
 */
export function AreaFieldInput({
    field,
    value,
    onChange,
    errors,
    path,
}: {
    field: AreaField;
    value: unknown;
    onChange: (value: unknown) => void;
    errors: Record<string, string>;
    /** Where its error is keyed, as in values.name or values.versions.0.code. */
    path: string;
}) {
    const scripts = useContext(ScriptsContext);
    const id = `field-${path.replaceAll('.', '-')}`;
    const error = errors[path];
    const describedBy = field.description !== '' ? `${id}-help` : undefined;

    if (field.type === 'boolean') {
        return (
            <div className="grid gap-1" data-field={field.key}>
                <div className="flex items-start gap-2">
                    <div className="flex h-lh items-center">
                        <Checkbox
                            id={id}
                            checked={value === true}
                            onCheckedChange={(checked) =>
                                onChange(checked === true)
                            }
                            aria-describedby={describedBy}
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor={id} className="text-body text-ink">
                            {field.label}
                        </Label>
                        {describedBy !== undefined && (
                            <p
                                id={describedBy}
                                className="text-caption text-ink-3"
                            >
                                {field.description}
                            </p>
                        )}
                    </div>
                </div>
                {error !== undefined && (
                    <p className="text-small text-danger-ink">{error}</p>
                )}
            </div>
        );
    }

    if (field.type === 'repeater') {
        const items = Array.isArray(value) ? (value as Values[]) : [];
        const empty = Object.fromEntries(
            field.fields.map((sub) => [sub.key, sub.empty]),
        );

        return (
            <div className="grid gap-2" data-field={field.key}>
                <p className="text-small text-ink-2 font-medium">
                    {field.label}
                </p>
                {field.description !== '' && (
                    <p className="text-caption text-ink-3">
                        {field.description}
                    </p>
                )}
                <AreaRepeater
                    id={id}
                    label={field.label}
                    items={items}
                    onChange={onChange}
                    itemLabel={field.itemLabel}
                    itemTitle={field.itemTitle}
                    empty={empty}
                    minItems={field.minItems}
                    maxItems={field.maxItems}
                >
                    {(item, index, change) => {
                        const shown = visibleKeys(field.fields, item);

                        return field.fields
                            .filter((sub) => shown.has(sub.key))
                            .map((sub) => (
                                <AreaFieldInput
                                    key={sub.key}
                                    field={sub}
                                    value={item[sub.key]}
                                    onChange={(next) =>
                                        change({ ...item, [sub.key]: next })
                                    }
                                    errors={errors}
                                    path={`${path}.${index}.${sub.key}`}
                                />
                            ));
                    }}
                </AreaRepeater>
                {error !== undefined && (
                    <p className="text-small text-danger-ink">{error}</p>
                )}
            </div>
        );
    }

    const control = (() => {
        switch (field.type) {
            case 'textarea':
                return (
                    <Textarea
                        id={id}
                        value={text(value)}
                        onChange={(event) => onChange(event.target.value)}
                        maxLength={field.maxLength ?? undefined}
                    />
                );
            case 'code':
                return (
                    <CodeField
                        id={id}
                        value={text(value)}
                        onChange={onChange}
                        describedBy={describedBy}
                        invalid={error !== undefined}
                        waiting={
                            field.scripts ? (scripts.waiting[path] ?? []) : []
                        }
                        approve={scripts.approve}
                        busy={scripts.busy}
                    />
                );
            case 'select':
            case 'region':
                return (
                    <SelectField
                        id={id}
                        className="w-full"
                        options={field.choices}
                        emptyLabel={
                            field.type === 'region' ? __('None') : undefined
                        }
                        value={text(value)}
                        onValueChange={onChange}
                    />
                );
            case 'multiselect':
            case 'types':
            case 'terms':
                return (
                    <Choices
                        id={id}
                        field={field}
                        value={value}
                        onChange={onChange}
                    />
                );
            case 'number':
                return (
                    <Input
                        id={id}
                        type="number"
                        inputMode="numeric"
                        value={text(value)}
                        min={field.min ?? undefined}
                        max={field.max ?? undefined}
                        onChange={(event) =>
                            onChange(
                                event.target.value === ''
                                    ? null
                                    : Number(event.target.value),
                            )
                        }
                    />
                );
            case 'datetime':
                return (
                    <Input
                        id={id}
                        type="datetime-local"
                        value={text(value).slice(0, 16)}
                        onChange={(event) => onChange(event.target.value)}
                    />
                );
            case 'color':
                return (
                    <Input
                        id={id}
                        type="text"
                        value={text(value)}
                        placeholder={__('#rrggbb')}
                        onChange={(event) => onChange(event.target.value)}
                    />
                );
            default:
                return (
                    <Input
                        id={id}
                        type={
                            field.type === 'email'
                                ? 'email'
                                : field.type === 'url'
                                  ? 'url'
                                  : 'text'
                        }
                        value={text(value)}
                        maxLength={field.maxLength ?? undefined}
                        onChange={(event) => onChange(event.target.value)}
                    />
                );
        }
    })();

    return (
        <div data-field={field.key}>
            <Field
                htmlFor={id}
                label={field.label}
                help={field.description !== '' ? field.description : undefined}
                error={error}
                required={field.required}
            >
                {control}
            </Field>
        </div>
    );
}
