import { Form } from '@inertiajs/react';
import {
    AlignLeft,
    Braces,
    Calendar,
    File,
    Globe,
    Group,
    Hash,
    Image,
    Link,
    List,
    ListChecks,
    Pilcrow,
    Plus,
    Rows3,
    ToggleLeft,
    Type,
    Users,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import TypeFieldsController from '@/actions/App/Http/Admin/TypeFieldsController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { cn } from '@/lib/utils';
import type {
    FieldCatalogueEntry,
    FieldChoice,
    FieldRow,
    FieldShowValue,
    FieldsPageType,
} from '@/types';

/** Each field type's icon, imported by name (I-3311). */
const ICONS: Record<string, LucideIcon> = {
    type: Type,
    'align-left': AlignLeft,
    hash: Hash,
    list: List,
    'toggle-left': ToggleLeft,
    globe: Globe,
    calendar: Calendar,
    image: Image,
    file: File,
};

/** The field types a later update brings, drawn greyed so the list never needs redrawing. */
const LATER: [LucideIcon, string, string][] = [
    [Group, 'Group', 'Several fields under one heading.'],
    [Rows3, 'Rows', 'The same fields repeated, such as awards.'],
    [ListChecks, 'Several choices', 'Any number from a list.'],
    [Pilcrow, 'Rich text', 'Formatted text with links.'],
    [Link, 'Link', 'To an item here or another site.'],
    [Braces, 'JSON', 'Raw data, for administrators.'],
    [
        Users,
        'Linked items',
        'Several items, each with a role and a note, such as a cast.',
    ],
];

const SHOW: [FieldShowValue, string][] = [
    ['after', 'After the article'],
    ['before', 'Before the article'],
    ['hidden', 'Not shown'],
];

/** A text area drawn as the admin's inputs are. */
const AREA =
    'border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-[13px] outline-none focus-visible:ring-2';

/** A choice row as the sheet keeps it: whether it was stored before, so its value stays. */
type ChoiceRow = FieldChoice & { id: number; stored: boolean };

/**
 * A handle made from a label: lower case, words joined by underscores,
 * starting with a letter, at most 64 characters.
 */
export function handleFrom(label: string): string {
    return label
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^[^a-z]+/, '')
        .replace(/_+$/, '')
        .slice(0, 64);
}

/**
 * The 480 px side sheet that adds one field to a type, or edits one
 * (I-3320): each saved on its own, through its own Operation. It closes on
 * success and keeps its input when the field is refused.
 */
export function FieldSheet({
    type,
    catalogue,
    editing,
    open,
    onClose,
    removeButton,
}: {
    type: FieldsPageType;
    catalogue: FieldCatalogueEntry[];
    /** The field being edited; null to add one. */
    editing: FieldRow | null;
    open: boolean;
    onClose: () => void;
    /** Edit's Remove field…, which asks first. */
    removeButton?: ReactNode;
}) {
    return (
        <Sheet open={open} onOpenChange={(next) => !next && onClose()}>
            <SheetContent
                className="w-full overflow-y-auto sm:max-w-[480px]"
                data-test="field-sheet"
            >
                <SheetHeader>
                    <SheetTitle>
                        {editing === null
                            ? `Add a field to ${type.plural}`
                            : `Edit ${editing.label}`}
                    </SheetTitle>
                    <SheetDescription>
                        {editing === null
                            ? `${type.plural}’ editor shows it in Details as soon as it’s saved.`
                            : `On ${type.plural}. A saved change works at once.`}
                    </SheetDescription>
                </SheetHeader>
                {open && (
                    <FieldForm
                        key={editing?.key ?? 'new'}
                        type={type}
                        catalogue={catalogue}
                        editing={editing}
                        onClose={onClose}
                        removeButton={removeButton}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}

function FieldForm({
    type,
    catalogue,
    editing,
    onClose,
    removeButton,
}: {
    type: FieldsPageType;
    catalogue: FieldCatalogueEntry[];
    editing: FieldRow | null;
    onClose: () => void;
    removeButton?: ReactNode;
}) {
    const [label, setLabel] = useState(editing?.label ?? '');
    const [handle, setHandle] = useState(editing?.handle ?? '');
    const [handleTouched, setHandleTouched] = useState(editing !== null);
    const [fieldType, setFieldType] = useState(editing?.fieldType ?? '');
    const [help, setHelp] = useState(editing?.help ?? '');
    const [choices, setChoices] = useState<ChoiceRow[]>(() =>
        (editing?.settings.choices ?? []).map((choice, at) => ({
            ...choice,
            id: at,
            stored: true,
        })),
    );
    const [nextId, setNextId] = useState(choices.length);
    const [dateOnly, setDateOnly] = useState(
        editing?.settings.dateOnly === true,
    );
    const [required, setRequired] = useState(editing?.required ?? false);
    const [search, setSearch] = useState(editing?.search ?? false);
    const [filterable, setFilterable] = useState(editing?.filterable ?? false);
    const [show, setShow] = useState<FieldShowValue>(editing?.show ?? 'after');
    const chosen = catalogue.find((entry) => entry.name === fieldType);
    const dateLocked = editing !== null && editing.holding > 0;

    const choose = (name: string) => {
        setFieldType(name);
        const entry = catalogue.find((item) => item.name === name);

        // On by default for text and choices; off where the type offers none.
        setSearch(entry?.search === true);
        setRequired((current) => current && entry?.required === true);
        setFilterable((current) => current && entry?.filterable === true);

        if (name === 'select' && choices.length === 0) {
            setChoices([{ id: nextId, value: '', label: '', stored: false }]);
            setNextId(nextId + 1);
        }
    };

    const settings: ReactNode =
        fieldType === 'select' ? (
            <Choices
                choices={choices}
                onChange={setChoices}
                onAdd={() => {
                    setChoices([
                        ...choices,
                        { id: nextId, value: '', label: '', stored: false },
                    ]);
                    setNextId(nextId + 1);
                }}
            />
        ) : fieldType === 'datetime' ? (
            <AskFor
                noun={type.noun}
                dateOnly={dateOnly}
                disabled={dateLocked}
                onChange={setDateOnly}
            />
        ) : null;

    return (
        <Form
            {...(editing === null
                ? TypeFieldsController.store.form({ key: type.key })
                : TypeFieldsController.update.form({
                      key: type.key,
                      field: editing.key,
                  }))}
            options={{ preserveScroll: true }}
            onSuccess={onClose}
            className="flex min-h-0 flex-1 flex-col"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-5 px-4 pb-4">
                        <InputError message={errors.key ?? errors.type} />
                        <div className="grid gap-5 sm:grid-cols-2">
                            <div className="grid min-w-0 content-start gap-2">
                                <Label htmlFor="field-label">Label</Label>
                                <Input
                                    id="field-label"
                                    name="label"
                                    value={label}
                                    aria-describedby="field-label-hint"
                                    aria-invalid={
                                        errors.label ? true : undefined
                                    }
                                    onChange={(event) => {
                                        setLabel(event.target.value);

                                        if (!handleTouched) {
                                            setHandle(
                                                handleFrom(event.target.value),
                                            );
                                        }
                                    }}
                                />
                                <Hint id="field-label-hint">
                                    What editors see.
                                </Hint>
                                <InputError message={errors.label} />
                            </div>
                            <div className="grid min-w-0 content-start gap-2">
                                <Label htmlFor="field-handle">Handle</Label>
                                <Input
                                    id="field-handle"
                                    name="handle"
                                    value={handle}
                                    className="font-mono"
                                    autoComplete="off"
                                    spellCheck={false}
                                    aria-describedby="field-handle-hint"
                                    aria-invalid={
                                        errors.handle ? true : undefined
                                    }
                                    onChange={(event) => {
                                        setHandle(event.target.value);
                                        setHandleTouched(true);
                                    }}
                                />
                                <Hint id="field-handle-hint">
                                    {editing === null ? (
                                        <>
                                            What templates use:{' '}
                                            <code>{`field('${handle}')`}</code>.
                                            Made from the label.
                                        </>
                                    ) : (
                                        'Changing it keeps every value; templates that use the old handle need the new one.'
                                    )}
                                </Hint>
                                <InputError message={errors.handle} />
                            </div>
                        </div>

                        {editing === null ? (
                            <div className="grid gap-3">
                                <div className="grid gap-1">
                                    <p
                                        id="field-type-label"
                                        className="text-sm leading-none font-medium"
                                    >
                                        Field type
                                    </p>
                                    <Hint id="field-type-hint">
                                        What kind of value it holds. It can’t
                                        change once the field is made.
                                    </Hint>
                                </div>
                                <div
                                    role="radiogroup"
                                    aria-labelledby="field-type-label"
                                    aria-describedby="field-type-hint"
                                    className="grid gap-1.5 sm:grid-cols-2"
                                >
                                    {catalogue.map((entry) => (
                                        <TypeChoice
                                            key={entry.name}
                                            icon={ICONS[entry.icon] ?? File}
                                            name={entry.label}
                                            line={entry.line}
                                            value={entry.name}
                                            checked={fieldType === entry.name}
                                            onChange={() => choose(entry.name)}
                                        />
                                    ))}
                                </div>
                                <InputError message={errors.fieldType} />
                                {settings}
                                <div className="grid gap-1.5">
                                    <p
                                        id="field-type-later"
                                        className="text-muted-foreground text-xs font-medium"
                                    >
                                        Coming in later updates
                                    </p>
                                    <div
                                        role="radiogroup"
                                        aria-labelledby="field-type-later"
                                        className="grid gap-1.5 sm:grid-cols-2"
                                    >
                                        {LATER.map(([icon, name, line]) => (
                                            <TypeChoice
                                                key={name}
                                                icon={icon}
                                                name={name}
                                                line={line}
                                                disabled
                                            />
                                        ))}
                                    </div>
                                </div>
                            </div>
                        ) : (
                            <div className="grid gap-3">
                                <div className="grid gap-2">
                                    <p className="text-sm leading-none font-medium">
                                        Field type
                                    </p>
                                    <input
                                        type="hidden"
                                        name="fieldType"
                                        value={fieldType}
                                    />
                                    <p className="flex items-center gap-2 text-[13px]">
                                        {(() => {
                                            const Icon =
                                                ICONS[chosen?.icon ?? ''] ??
                                                File;

                                            return (
                                                <Icon
                                                    aria-hidden
                                                    className="size-4"
                                                />
                                            );
                                        })()}
                                        {chosen?.label ?? fieldType}
                                    </p>
                                    <Hint>
                                        Can’t change: stored values would be
                                        read as another kind. To change it, add
                                        a new field and remove this one.
                                    </Hint>
                                    <InputError message={errors.fieldType} />
                                </div>
                                {settings}
                            </div>
                        )}
                        <InputError
                            message={
                                errors['settings.choices'] ??
                                errors['settings.dateOnly'] ??
                                errors.settings
                            }
                        />

                        <div className="grid gap-2">
                            <Label htmlFor="field-help">Help text</Label>
                            <textarea
                                id="field-help"
                                name="help"
                                rows={2}
                                value={help}
                                className={AREA}
                                aria-describedby="field-help-hint"
                                onChange={(event) =>
                                    setHelp(event.target.value)
                                }
                            />
                            <Hint id="field-help-hint">
                                Shown under the field in the editor.
                            </Hint>
                            <InputError message={errors.help} />
                        </div>

                        {chosen !== undefined && (
                            <div className="grid gap-3">
                                {chosen.required && (
                                    <Box
                                        id="field-required"
                                        name="required"
                                        label="Required"
                                        hint={`A ${type.noun} needs one to be published. ${type.plural} published before are checked when next published.`}
                                        checked={required}
                                        error={errors.required}
                                        onChange={setRequired}
                                    />
                                )}
                                {chosen.search && (
                                    <Box
                                        id="field-search"
                                        name="search"
                                        label="Include in site search"
                                        hint={`A search for one of its words finds the ${type.plural.toLowerCase()} with it. On by default for text and choices.`}
                                        checked={search}
                                        error={errors.search}
                                        onChange={setSearch}
                                    />
                                )}
                                {chosen.filterable && (
                                    <Box
                                        id="field-filterable"
                                        name="filterable"
                                        label="Filterable"
                                        hint="Lists can be filtered and sorted by it once a later update adds filters."
                                        checked={filterable}
                                        error={errors.filterable}
                                        onChange={setFilterable}
                                    />
                                )}
                            </div>
                        )}

                        <ShowOnSite value={show} onChange={setShow} />
                        <InputError message={errors.show} />
                    </div>

                    <SheetFooter className="bg-card sticky bottom-0 mt-auto flex-row flex-wrap border-t">
                        <Button type="submit" disabled={processing}>
                            {editing === null ? 'Add field' : 'Save field'}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        {removeButton !== undefined && (
                            <span className="ml-auto">{removeButton}</span>
                        )}
                    </SheetFooter>
                </>
            )}
        </Form>
    );
}

function Hint({ id, children }: { id?: string; children: ReactNode }) {
    return (
        <p id={id} className="text-muted-foreground text-xs text-pretty">
            {children}
        </p>
    );
}

function TypeChoice({
    icon: Icon,
    name,
    line,
    value,
    checked = false,
    disabled = false,
    onChange,
}: {
    icon: LucideIcon;
    name: string;
    line: string;
    value?: string;
    checked?: boolean;
    disabled?: boolean;
    onChange?: () => void;
}) {
    return (
        <label
            className={cn(
                'border-border flex items-start gap-2.5 rounded-md border p-2.5 text-[13px]',
                checked && 'border-primary bg-accent',
                disabled
                    ? 'text-muted-foreground cursor-not-allowed'
                    : 'cursor-pointer',
            )}
        >
            <input
                type="radio"
                name={disabled ? undefined : 'fieldType'}
                value={value}
                checked={checked}
                disabled={disabled}
                onChange={onChange}
                className="accent-primary mt-0.5 size-4 shrink-0"
            />
            <Icon aria-hidden className="mt-0.5 size-4 shrink-0" />
            <span className="grid gap-0.5">
                <span className="font-medium">{name}</span>
                <span className="text-muted-foreground text-xs">{line}</span>
            </span>
        </label>
    );
}

function Choices({
    choices,
    onChange,
    onAdd,
}: {
    choices: ChoiceRow[];
    onChange: (choices: ChoiceRow[]) => void;
    onAdd: () => void;
}) {
    const change = (id: number, part: Partial<FieldChoice>) =>
        onChange(
            choices.map((choice) =>
                choice.id === id ? { ...choice, ...part } : choice,
            ),
        );

    return (
        <div className="grid gap-2">
            <p
                id="field-choices-label"
                className="text-sm leading-none font-medium"
            >
                Choices
            </p>
            <div
                role="group"
                aria-labelledby="field-choices-label"
                className="grid gap-1.5"
            >
                <div className="text-muted-foreground grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_2rem] gap-2 text-xs font-medium">
                    <span id="field-choices-stored">Stored as</span>
                    <span id="field-choices-shown">Shown as</span>
                    <span />
                </div>
                {choices.map((choice, at) => (
                    <div
                        key={choice.id}
                        className="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_2rem] items-center gap-2"
                    >
                        <Input
                            name={`settings[choices][${at}][value]`}
                            value={choice.value}
                            readOnly={choice.stored}
                            className="read-only:bg-muted font-mono"
                            autoComplete="off"
                            spellCheck={false}
                            aria-labelledby="field-choices-stored"
                            onChange={(event) =>
                                change(choice.id, { value: event.target.value })
                            }
                        />
                        <Input
                            name={`settings[choices][${at}][label]`}
                            value={choice.label}
                            aria-labelledby="field-choices-shown"
                            onChange={(event) =>
                                change(choice.id, {
                                    label: event.target.value,
                                })
                            }
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-8"
                            onClick={() =>
                                onChange(
                                    choices.filter(
                                        (other) => other.id !== choice.id,
                                    ),
                                )
                            }
                        >
                            <X aria-hidden className="size-4" />
                            <span className="sr-only">
                                Take off{' '}
                                {choice.label === ''
                                    ? choice.value
                                    : choice.label}
                            </span>
                        </Button>
                    </div>
                ))}
            </div>
            <div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={onAdd}
                >
                    <Plus aria-hidden className="size-4" />
                    Add a choice
                </Button>
            </div>
            <Hint>
                Relabel freely. Taking off a choice items use keeps their value,
                shown as itself.
            </Hint>
        </div>
    );
}

function AskFor({
    noun,
    dateOnly,
    disabled,
    onChange,
}: {
    noun: string;
    dateOnly: boolean;
    disabled: boolean;
    onChange: (dateOnly: boolean) => void;
}) {
    const rows: [boolean, string, string][] = [
        [false, 'Date and time', 'field-ask-datetime'],
        [true, 'Date only', 'field-ask-date'],
    ];

    return (
        <div className="grid gap-2">
            <p
                id="field-ask-label"
                className="text-sm leading-none font-medium"
            >
                Ask for
            </p>
            <input
                type="hidden"
                name="settings[dateOnly]"
                value={dateOnly ? '1' : '0'}
            />
            <div
                role="radiogroup"
                aria-labelledby="field-ask-label"
                aria-describedby="field-ask-hint"
                className="grid gap-1.5"
            >
                {rows.map(([value, name, id]) => (
                    <RadioRow
                        key={id}
                        id={id}
                        group="field-ask"
                        label={name}
                        checked={dateOnly === value}
                        disabled={disabled}
                        onChange={() => onChange(value)}
                    />
                ))}
            </div>
            <Hint id="field-ask-hint">
                Can’t change once a {noun} holds a value.
            </Hint>
        </div>
    );
}

function ShowOnSite({
    value,
    onChange,
}: {
    value: FieldShowValue;
    onChange: (value: FieldShowValue) => void;
}) {
    return (
        <div className="grid gap-2">
            <p
                id="field-show-label"
                className="text-sm leading-none font-medium"
            >
                Show on the site
            </p>
            <div
                role="radiogroup"
                aria-labelledby="field-show-label"
                aria-describedby="field-show-hint"
                className="grid gap-1.5"
            >
                {SHOW.map(([option, label]) => (
                    <RadioRow
                        key={option}
                        id={`field-show-${option}`}
                        group="show"
                        value={option}
                        label={label}
                        checked={value === option}
                        onChange={() => onChange(option)}
                    />
                ))}
            </div>
            <Hint id="field-show-hint">
                The default theme lists shown fields with their labels and skips
                empty ones. A theme can place any field itself.
            </Hint>
        </div>
    );
}

function RadioRow({
    id,
    group,
    value,
    label,
    checked,
    disabled = false,
    onChange,
}: {
    id: string;
    group: string;
    value?: string;
    label: string;
    checked: boolean;
    disabled?: boolean;
    onChange: () => void;
}) {
    return (
        <label
            htmlFor={id}
            className={cn(
                'border-border flex items-center gap-2.5 rounded-md border px-2.5 py-2 text-[13px] font-medium',
                checked && 'border-primary bg-accent',
                disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer',
            )}
        >
            <input
                id={id}
                type="radio"
                name={group}
                value={value}
                checked={checked}
                disabled={disabled}
                onChange={onChange}
                className="accent-primary size-4 shrink-0"
            />
            {label}
        </label>
    );
}

function Box({
    id,
    name,
    label,
    hint,
    checked,
    error,
    onChange,
}: {
    id: string;
    name: string;
    label: string;
    hint: string;
    checked: boolean;
    error?: string;
    onChange: (checked: boolean) => void;
}) {
    return (
        <div className="flex items-start gap-2.5">
            <input type="hidden" name={name} value={checked ? '1' : '0'} />
            <Checkbox
                id={id}
                checked={checked}
                aria-describedby={`${id}-hint`}
                className="mt-0.5"
                onCheckedChange={(next) => onChange(next === true)}
            />
            <div className="grid gap-1">
                <Label htmlFor={id}>{label}</Label>
                <Hint id={`${id}-hint`}>{hint}</Hint>
                <InputError message={error} />
            </div>
        </div>
    );
}
