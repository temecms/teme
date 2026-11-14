import { router } from '@inertiajs/react';
import { SlidersHorizontal } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { SelectField } from '@/components/ui/select-field';
import { useIsMobile } from '@/hooks/use-mobile';
import { savePreferences } from '@/lib/preferences';
import type { Preferences } from '@/types';
import { ListPanel } from './filter-button';

/** The page sizes lists.per_page offers. */
const PAGE_SIZES = [25, 50, 100];

/**
 * Screen options (I-204): which columns a list shows, and how many rows,
 * for this person only, in a panel under its button (I-3135). Both are preferences (1.5f), so they follow the
 * person between machines. `list` is the type's key, as the preference
 * `lists.{list}.columns` names it; Title is always shown, so it is no option.
 */
export function ScreenOptions({
    list,
    columns,
    perPage,
    open,
    onOpenChange,
}: {
    list: string;
    columns: { id: string; label: string; shown: boolean }[];
    perPage: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const isMobile = useIsMobile();
    const [shown, setShown] = useState<string[]>([]);
    const [rows, setRows] = useState(String(perPage));
    const [state, setState] = useState<'idle' | 'saving' | 'saved' | 'failed'>(
        'idle',
    );

    const apply = async () => {
        setState('saving');
        const values: Partial<Preferences> = { 'lists.per_page': Number(rows) };
        const key: `lists.${string}.columns` = `lists.${list}.columns`;
        // In the options' own order, whatever order they were ticked in.
        values[key] = columns
            .map((column) => column.id)
            .filter((id) => shown.includes(id));
        const saved = await savePreferences(values);
        setState(saved ? 'saved' : 'failed');

        if (saved) {
            router.reload();
        }
    };

    return (
        <ListPanel
            label="Screen options"
            open={open}
            onOpenChange={(next) => {
                // Opened afresh from what the list shows now.
                if (next) {
                    setShown(
                        columns
                            .filter((column) => column.shown)
                            .map((column) => column.id),
                    );
                    setRows(String(perPage));
                    setState('idle');
                }

                onOpenChange(next);
            }}
            align="end"
            testId="screen-options-panel"
            trigger={
                <Button
                    type="button"
                    variant="outline"
                    data-test="screen-options"
                    aria-label={isMobile ? 'Screen options' : undefined}
                >
                    <SlidersHorizontal />
                    {!isMobile && 'Screen options'}
                </Button>
            }
        >
            <div className="grid w-72 max-w-full gap-4 p-2">
                <p className="text-muted-foreground text-xs">
                    What this list shows for you. Other people keep their own
                    choices.
                </p>
                <fieldset className="grid gap-2">
                    <legend className="mb-2 text-sm font-medium">
                        Columns
                    </legend>
                    {columns.map((column) => (
                        <div
                            key={column.id}
                            className="flex items-center gap-2"
                        >
                            <Checkbox
                                id={`column-${column.id}`}
                                checked={shown.includes(column.id)}
                                onCheckedChange={(checked) =>
                                    setShown((current) =>
                                        checked
                                            ? [...current, column.id]
                                            : current.filter(
                                                  (id) => id !== column.id,
                                              ),
                                    )
                                }
                            />
                            <Label htmlFor={`column-${column.id}`}>
                                {column.label}
                            </Label>
                        </div>
                    ))}
                </fieldset>
                <div className="grid gap-2">
                    <Label htmlFor="rows-per-page">Rows per page</Label>
                    <SelectField
                        id="rows-per-page"
                        value={rows}
                        onValueChange={setRows}
                        options={PAGE_SIZES.map((size) => ({
                            value: String(size),
                            label: String(size),
                        }))}
                        className="w-28"
                    />
                </div>
                <div className="flex items-center gap-2">
                    <p role="status" className="text-muted-foreground text-xs">
                        {state === 'saved' && 'Saved to your preferences'}
                        {state === 'failed' &&
                            "Couldn't save. Try again in a moment."}
                    </p>
                    <span className="flex-1" />
                    <Button
                        type="button"
                        disabled={state === 'saving'}
                        onClick={() => void apply()}
                    >
                        Apply
                    </Button>
                </div>
            </div>
        </ListPanel>
    );
}
