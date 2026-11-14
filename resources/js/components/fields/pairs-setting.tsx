import { Plus } from 'lucide-react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { FieldDefinition } from '@/types';

type Pair = { name: string; value: string };

type Props = {
    field: FieldDefinition;
    name: string;
    value: unknown;
    disabled?: boolean;
    addLabel?: string;
    rowLabel?: (n: number) => string;
    emptyLabel?: string;
    namePlaceholder?: string;
    /** What a screen reader hears after "Remove", for row n. */
    removeLabel?: (n: number) => string;
};

function pairsOf(value: unknown): Pair[] {
    if (!Array.isArray(value)) {
        return [];
    }

    return value.map((row) => {
        const { name, value: content } = (row ?? {}) as Partial<Pair>;

        return {
            name: typeof name === 'string' ? name : '',
            value: typeof content === 'string' ? content : '',
        };
    });
}

/**
 * A pairs setting's control: rows of a name and a value. With no rows it
 * sends one empty hidden input, so removing every row saves the empty list.
 */
export function PairsSetting({
    field,
    name,
    value,
    disabled,
    addLabel = 'Add a row',
    rowLabel = (n) => `${field.label}, row ${n}`,
    emptyLabel = 'Nothing yet.',
    namePlaceholder,
    removeLabel = (n) => `row ${n}`,
}: Props) {
    const id = `field-${field.key}`;
    const nextKey = useRef(0);
    const [rows, setRows] = useState(() =>
        pairsOf(value).map((pair) => ({ ...pair, key: nextKey.current++ })),
    );
    const addButton = useRef<HTMLButtonElement>(null);
    const focusNew = useRef(false);

    return (
        <div
            id={id}
            aria-describedby={`${id}-hint ${id}-error`}
            className="grid gap-3"
        >
            {rows.length === 0 && (
                <>
                    <input
                        type="hidden"
                        name={name}
                        value=""
                        disabled={disabled}
                    />
                    <p className="text-ink-2 text-sm">{emptyLabel}</p>
                </>
            )}
            {rows.map((row, index) => (
                <div
                    key={row.key}
                    role="group"
                    aria-label={rowLabel(index + 1)}
                    data-test="pairs-row"
                    className="grid items-end gap-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,2fr)_auto]"
                >
                    <div className="grid gap-1">
                        <Label htmlFor={`${id}-${index}-name`}>
                            {field.options.name}
                        </Label>
                        <Input
                            id={`${id}-${index}-name`}
                            ref={(element) => {
                                if (
                                    element &&
                                    focusNew.current &&
                                    index === rows.length - 1
                                ) {
                                    focusNew.current = false;
                                    element.focus();
                                }
                            }}
                            name={`${name}[${index}][name]`}
                            defaultValue={row.name}
                            placeholder={namePlaceholder}
                            spellCheck={false}
                            autoComplete="off"
                            disabled={disabled}
                            className="font-mono"
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor={`${id}-${index}-value`}>
                            {field.options.value}
                        </Label>
                        <Input
                            id={`${id}-${index}-value`}
                            name={`${name}[${index}][value]`}
                            defaultValue={row.value}
                            spellCheck={false}
                            autoComplete="off"
                            disabled={disabled}
                            className="font-mono"
                        />
                    </div>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        data-test="pairs-remove"
                        disabled={disabled}
                        onClick={() => {
                            setRows((current) =>
                                current.filter(
                                    (other) => other.key !== row.key,
                                ),
                            );
                            requestAnimationFrame(() =>
                                addButton.current?.focus(),
                            );
                        }}
                    >
                        Remove
                        <span className="sr-only">
                            {' '}
                            {removeLabel(index + 1)}
                        </span>
                    </Button>
                </div>
            ))}
            <div>
                <Button
                    ref={addButton}
                    type="button"
                    size="sm"
                    variant="outline"
                    data-test="pairs-add"
                    disabled={disabled}
                    onClick={() => {
                        focusNew.current = true;
                        setRows((current) => [
                            ...current,
                            { name: '', value: '', key: nextKey.current++ },
                        ]);
                    }}
                >
                    <Plus />
                    {addLabel}
                </Button>
            </div>
        </div>
    );
}
