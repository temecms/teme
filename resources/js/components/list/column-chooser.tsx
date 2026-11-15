import { router } from "@inertiajs/react";
import { ArrowDown, ArrowUp, SlidersHorizontal } from "lucide-react";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from "@/components/ui/popover";
import { SelectField } from "@/components/ui/select-field";
import { __ } from "@/lib/i18n";
import { savePreferences } from "@/lib/preferences";
import type { Preferences } from "@/types";
import { TABLE_FRAME_ID } from "./data-table";

/** The page sizes lists.per_page offers. */
const PAGE_SIZES = [25, 50, 100];

/** The tick box, the title's minimum and the "…" column, which every list has (docs/architecture/design-system.md, section 4.26). */
const FIXED_WIDTH = 48 + 320 + 56;

/** The preference id the Thumbnails entry keeps, as it always has. */
const THUMBNAILS = "featured";

type ChooserColumn = { id: string; label: string; width?: number };

/**
 * The column chooser (I-204; docs/architecture/design-system.md, section
 * 4.26): which columns a list shows, in which order, whether titles show
 * their thumbnails, and how many rows a page holds, for this person only.
 * Title is always shown. Move earlier and Move later reorder by keyboard or
 * a single pointer. Apply saves them as preferences and reloads the list.
 */
export function ColumnChooser({
    list,
    columns,
    shown,
    thumbnails,
    perPage,
}: {
    /** The type's key, as the preference `lists.{list}.columns` names it. */
    list: string;
    /** Every optional column, in the person's order. */
    columns: ChooserColumn[];
    /** The ids of the columns shown now. */
    shown: string[];
    thumbnails: boolean;
    perPage: number;
}) {
    const [open, setOpen] = useState(false);
    const [order, setOrder] = useState<ChooserColumn[]>(columns);
    const [ticked, setTicked] = useState<string[]>(shown);
    const [withThumbnails, setWithThumbnails] = useState(thumbnails);
    const [rows, setRows] = useState(String(perPage));
    const [frame, setFrame] = useState<number | null>(null);
    const [state, setState] = useState<"idle" | "saving" | "failed">("idle");

    const reopen = (next: boolean) => {
        // Opened afresh from what the list shows now.
        if (next) {
            setOrder(columns);
            setTicked(shown);
            setWithThumbnails(thumbnails);
            setRows(String(perPage));
            setState("idle");
            setFrame(
                document.getElementById(TABLE_FRAME_ID)?.clientWidth ?? null,
            );
        }

        setOpen(next);
    };

    const move = (at: number, by: -1 | 1) =>
        setOrder((current) => {
            const next = [...current];
            [next[at], next[at + by]] = [next[at + by], next[at]];

            return next;
        });

    const wide =
        frame !== null &&
        order
            .filter((column) => ticked.includes(column.id))
            .reduce((total, column) => total + (column.width ?? 0), 0) +
            FIXED_WIDTH >
            frame;

    const apply = async () => {
        setState("saving");
        const values: Partial<Preferences> = { "lists.per_page": Number(rows) };
        const key: `lists.${string}.columns` = `lists.${list}.columns`;
        // In the order chosen here, whatever order they were ticked in.
        values[key] = [
            ...order
                .map((column) => column.id)
                .filter((id) => ticked.includes(id)),
            ...(withThumbnails ? [THUMBNAILS] : []),
        ];
        const saved = await savePreferences(values);

        if (saved) {
            setOpen(false);
            router.reload();
        } else {
            setState("failed");
        }
    };

    return (
        <Popover open={open} onOpenChange={reopen}>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    data-test="columns"
                    className="shrink-0"
                >
                    <SlidersHorizontal aria-hidden="true" />
                    {__("Columns")}
                    <span className="text-ink-3 tabular-nums">
                        {shown.length + 1}
                    </span>
                </Button>
            </PopoverTrigger>
            <PopoverContent
                align="end"
                data-test="column-chooser"
                aria-label={__("Columns")}
                className="w-95"
            >
                <div className="grid gap-4">
                    <fieldset className="grid gap-1">
                        <legend className="text-caption text-ink-3 mb-2 font-semibold uppercase">
                            {__("Columns, in order")}
                        </legend>
                        <div className="flex min-h-(--ctl-h-sm) items-center gap-2">
                            <Checkbox
                                id="column-title"
                                checked
                                disabled
                                aria-describedby="column-title-note"
                            />
                            <Label htmlFor="column-title" className="flex-1">
                                {__("Title")}
                            </Label>
                            <span
                                id="column-title-note"
                                className="text-small text-ink-3"
                            >
                                {__("always shown")}
                            </span>
                        </div>
                        {order.map((column, at) => (
                            <div
                                key={column.id}
                                data-chooser-column={column.id}
                                className="flex min-h-(--ctl-h-sm) items-center gap-2"
                            >
                                <Checkbox
                                    id={`column-${column.id}`}
                                    checked={ticked.includes(column.id)}
                                    onCheckedChange={(checked) =>
                                        setTicked((current) =>
                                            checked
                                                ? [...current, column.id]
                                                : current.filter(
                                                      (id) => id !== column.id,
                                                  ),
                                        )
                                    }
                                />
                                <Label
                                    htmlFor={`column-${column.id}`}
                                    className="flex-1"
                                >
                                    {column.label}
                                </Label>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-xs"
                                    aria-label={__("Move :name earlier", {
                                        name: column.label,
                                    })}
                                    aria-disabled={at === 0 || undefined}
                                    className="aria-disabled:cursor-not-allowed aria-disabled:opacity-55 aria-disabled:active:scale-100"
                                    onClick={() => {
                                        if (at > 0) {
                                            move(at, -1);
                                        }
                                    }}
                                >
                                    <ArrowUp aria-hidden="true" />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-xs"
                                    aria-label={__("Move :name later", {
                                        name: column.label,
                                    })}
                                    aria-disabled={
                                        at === order.length - 1 || undefined
                                    }
                                    className="aria-disabled:cursor-not-allowed aria-disabled:opacity-55 aria-disabled:active:scale-100"
                                    onClick={() => {
                                        if (at < order.length - 1) {
                                            move(at, 1);
                                        }
                                    }}
                                >
                                    <ArrowDown aria-hidden="true" />
                                </Button>
                            </div>
                        ))}
                    </fieldset>
                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="column-thumbnails"
                            checked={withThumbnails}
                            onCheckedChange={(checked) =>
                                setWithThumbnails(checked === true)
                            }
                        />
                        <Label htmlFor="column-thumbnails">
                            {__("Thumbnails")}
                        </Label>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="rows-per-page">
                            {__("Rows per page")}
                        </Label>
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
                    <p className="text-small text-ink-3">
                        {wide
                            ? __(
                                  "The table is now wider than the screen. It scrolls sideways, with the tick box and title kept in view.",
                              )
                            : __(
                                  "The title takes the room that is left, and never gets narrower than its minimum.",
                              )}
                    </p>
                    <div className="flex items-center gap-2">
                        <p role="status" className="text-small text-danger-ink">
                            {state === "failed" &&
                                __("Couldn’t save. Try again in a moment.")}
                        </p>
                        <span className="flex-1" />
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => setOpen(false)}
                        >
                            {__("Cancel")}
                        </Button>
                        <Button
                            type="button"
                            variant="primary"
                            size="sm"
                            disabled={state === "saving"}
                            onClick={() => void apply()}
                        >
                            {__("Apply")}
                        </Button>
                    </div>
                </div>
            </PopoverContent>
        </Popover>
    );
}
