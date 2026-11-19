import {
    Check,
    FileAudio,
    FileText,
    FileVideo,
    TriangleAlert,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { CSSProperties, KeyboardEvent } from 'react';
import { __, __n } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { MediaRow } from '@/types';

const icons = {
    video: FileVideo,
    audio: FileAudio,
    document: FileText,
} as const;

/** The tile widths (docs/architecture/design-system.md, section 4.35); on a phone M is two across, S three, L one. */
export const TILE_SIZES = {
    s: { wide: 136, phone: 96 },
    m: { wide: 172, phone: 148 },
    l: { wide: 228, phone: 0 },
} as const;

/** A tile width at wide and phone widths, in pixels; 0 at phone width means one column. */
export type TileSize = { wide: number; phone: number };

// A custom property set here, so the scanner counts it as defined (R11); 0 means one column.
const columns = (px: number) =>
    px === 0 ? '1fr' : `repeat(auto-fill, minmax(min(${px}px, 100%), 1fr))`;

/** Where an arrow, Home or End moves focus in a grid drawn `columns` across; null for any other key. */
export function nextTile(
    key: string,
    index: number,
    count: number,
    columns: number,
): number | null {
    switch (key) {
        case 'ArrowRight':
            return Math.min(index + 1, count - 1);
        case 'ArrowLeft':
            return Math.max(index - 1, 0);
        case 'ArrowDown':
            return Math.min(index + columns, count - 1);
        case 'ArrowUp':
            return Math.max(index - columns, 0);
        case 'Home':
            return 0;
        case 'End':
            return count - 1;
        default:
            return null;
    }
}

// The columns as drawn: the tiles on the first tile's row.
const drawnColumns = (list: HTMLElement) => {
    const tiles = [...list.children] as HTMLElement[];
    const top = tiles[0]?.offsetTop;

    return Math.max(1, tiles.filter((tile) => tile.offsetTop === top).length);
};

/** The tile of a file, by its ID, in one grid or the page, so focus can be put back on it. */
export function tileOf(
    id: string,
    within: ParentNode = document,
): HTMLElement | null {
    return within.querySelector<HTMLElement>(
        `[role="option"][data-media-id="${CSS.escape(id)}"]`,
    );
}

/** A file's square picture: its smallest size, or its kind's icon, or why there is none yet. */
export function Thumb({
    row,
    chosen = false,
}: {
    row: MediaRow;
    chosen?: boolean;
}) {
    const frame = cn(
        'outline-img-outline aspect-square w-full rounded-md outline-1 -outline-offset-1',
        chosen && 'outline-accent outline-2 -outline-offset-2',
    );

    if (row.thumbUrl) {
        return (
            <img
                src={row.thumbUrl}
                alt=""
                className={cn(frame, 'object-cover')}
                loading="lazy"
            />
        );
    }

    if (row.kind === 'image') {
        return (
            <span
                className={cn(
                    frame,
                    'bg-surface-2 text-ink-2 text-caption flex items-center justify-center',
                )}
            >
                {row.processing === 'failed' ? __('Failed') : __('Preparing…')}
            </span>
        );
    }

    const Icon = icons[row.kind];

    return (
        <span
            className={cn(
                frame,
                'bg-surface-2 flex items-center justify-center',
            )}
        >
            <Icon aria-hidden="true" className="text-ink-3 size-8" />
        </span>
    );
}

/** How a tile was acted on: a click, Space, Enter, or a double-click. */
export type TileAction = 'click' | 'space' | 'enter' | 'double';

type TileProps = {
    row: MediaRow;
    /** Ticked in the picker. */
    chosen: boolean;
    /** Open in the library's detail panel. */
    open: boolean;
    /** The picker's tick is drawn; the library's tiles have none. */
    tick: boolean;
    /** Anything is ticked, so every tick shows. */
    anyChosen: boolean;
    /** The one tile in the tab order. */
    current: boolean;
    onAction: (action: TileAction) => void;
    onFocus: () => void;
};

/**
 * One file in the grid (docs/architecture/design-system.md, section 4.35):
 * its picture, its name on one line, and its facts: how often it is used,
 * and "No alt" for an image without alt text.
 */
export function MediaTile({
    row,
    chosen,
    open,
    tick,
    anyChosen,
    current,
    onAction,
    onFocus,
}: TileProps) {
    const missingAlt = row.kind === 'image' && !row.hasAlt;

    return (
        <li
            role="option"
            aria-selected={tick ? chosen : open}
            tabIndex={current ? 0 : -1}
            data-media-id={row.id}
            className={cn(
                'group hover:bg-surface-3 focus-visible:outline-ring grid cursor-pointer content-start gap-2 rounded-lg p-2 outline-none select-none focus-visible:outline-2',
                open && 'bg-surface-3',
                chosen && 'bg-accent-soft hover:bg-accent-soft',
            )}
            onClick={() => onAction('click')}
            onDoubleClick={() => onAction('double')}
            onFocus={onFocus}
        >
            <span className="relative block">
                <Thumb row={row} chosen={chosen} />
                {row.kind !== 'image' && row.extension !== '' && (
                    <span className="bg-photo-scrim text-on-photo text-caption absolute end-1 top-1 rounded-sm px-1 font-semibold">
                        {row.extension.toUpperCase()}
                    </span>
                )}
                {tick && (
                    <span
                        aria-hidden="true"
                        className={cn(
                            'border-edge bg-surface absolute start-1 top-1 flex size-6 items-center justify-center rounded-sm border',
                            'opacity-0 group-hover:opacity-100 group-focus-visible:opacity-100 pointer-coarse:opacity-100',
                            (chosen || anyChosen) && 'opacity-100',
                            chosen && 'bg-accent text-on-accent border-accent',
                        )}
                    >
                        {chosen && <Check className="size-4" />}
                    </span>
                )}
            </span>
            <span className="text-small truncate font-medium">
                {row.filename || row.title}
            </span>
            <span className="text-caption text-ink-3 flex flex-wrap items-center gap-x-2">
                <span>
                    {row.importedFrom
                        ? __('Imported · :site', { site: row.importedFrom })
                        : row.uses > 0
                          ? __n('Used :count×|Used :count×', row.uses)
                          : __('Unused')}
                </span>
                {missingAlt && (
                    <span className="text-warn-ink inline-flex items-center gap-1">
                        <TriangleAlert
                            aria-hidden="true"
                            className="size-3.5"
                        />
                        {__('No alt')}
                    </span>
                )}
            </span>
        </li>
    );
}

type Props = {
    rows: MediaRow[];
    /** The tile width; the library's chosen size, or the picker's 160. */
    size?: TileSize;
    /** Ticked tiles, in the picker. */
    selectedIds?: string[];
    /** The tile open in the library's detail panel. */
    openId?: string | null;
    /** The picker draws ticks; the library does not. */
    picker?: boolean;
    /** Several may be ticked (the picker's gallery). */
    multiselectable?: boolean;
    onAction: (id: string, action: TileAction) => void;
    /** Esc in the grid: the library closes its panel. */
    onEscape?: () => void;
    /** Hears the tile that has focus. */
    onFocusTile?: (id: string) => void;
};

/**
 * The grid of files, shared by the Media screen and the picker (I-236): a
 * listbox moved by the arrow keys, Home and End, with one tile in the tab
 * order.
 */
export function MediaGrid({
    rows,
    size = TILE_SIZES.m,
    selectedIds = [],
    openId = null,
    picker = false,
    multiselectable = false,
    onAction,
    onEscape,
    onFocusTile,
}: Props) {
    const list = useRef<HTMLUListElement>(null);
    const [current, setCurrent] = useState<string | null>(null);
    const anyChosen = selectedIds.length > 0;
    const shown =
        current !== null && rows.some((row) => row.id === current)
            ? current
            : (openId ??
              selectedIds[selectedIds.length - 1] ??
              rows[0]?.id ??
              null);

    // A tile opened or chosen elsewhere becomes the one in the tab order.
    useEffect(() => {
        if (openId !== null) {
            setCurrent(openId);
        }
    }, [openId]);

    const keyDown = (event: KeyboardEvent<HTMLUListElement>) => {
        const tile = (event.target as HTMLElement).closest<HTMLElement>(
            '[role="option"]',
        );
        const id = tile?.dataset.mediaId;

        if (!tile || id === undefined || list.current === null) {
            return;
        }

        const index = rows.findIndex((row) => row.id === id);
        const next = nextTile(
            event.key,
            index,
            rows.length,
            drawnColumns(list.current),
        );

        if (next !== null) {
            event.preventDefault();
            const target = rows[next];
            setCurrent(target.id);
            // In this grid: the picker's grid and the library's can show the same file.
            tileOf(target.id, list.current)?.focus();

            return;
        }

        if (event.key === ' ') {
            event.preventDefault();
            onAction(id, 'space');
        } else if (event.key === 'Enter') {
            event.preventDefault();
            onAction(id, 'enter');
        } else if (event.key === 'Escape' && onEscape !== undefined) {
            onEscape();
        }
    };

    return (
        <ul
            ref={list}
            role="listbox"
            aria-label={__('Files')}
            aria-multiselectable={multiselectable || undefined}
            className="grid grid-cols-(--media-cols) gap-4 max-sm:grid-cols-(--media-cols-phone)"
            style={
                {
                    '--media-cols': columns(size.wide),
                    '--media-cols-phone': columns(size.phone),
                } as CSSProperties
            }
            onKeyDown={keyDown}
        >
            {rows.map((row) => (
                <MediaTile
                    key={row.id}
                    row={row}
                    chosen={selectedIds.includes(row.id)}
                    open={row.id === openId}
                    tick={picker}
                    anyChosen={anyChosen}
                    current={row.id === shown}
                    onFocus={() => {
                        setCurrent(row.id);
                        onFocusTile?.(row.id);
                    }}
                    onAction={(action) => {
                        setCurrent(row.id);
                        onAction(row.id, action);
                    }}
                />
            ))}
        </ul>
    );
}
