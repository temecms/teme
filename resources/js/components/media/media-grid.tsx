import { Check, FileAudio, FileText, FileVideo } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { MediaRow } from '@/types';

const icons = {
    video: FileVideo,
    audio: FileAudio,
    document: FileText,
} as const;

export function Thumb({ row }: { row: MediaRow }) {
    if (row.thumbUrl) {
        return (
            <img
                src={row.thumbUrl}
                alt=""
                className="aspect-square w-full rounded object-cover"
                loading="lazy"
            />
        );
    }

    if (row.kind === 'image') {
        return (
            <span className="bg-surface-2 text-ink-2 flex aspect-square w-full items-center justify-center rounded text-xs">
                {row.processing === 'failed' ? 'Failed' : 'Preparing…'}
            </span>
        );
    }

    const Icon = icons[row.kind];

    return (
        <span className="bg-surface-2 flex aspect-square w-full items-center justify-center rounded">
            <Icon className="size-8" />
        </span>
    );
}

type Props = {
    rows: MediaRow[];
    /** Ticked tiles, for the modal's selection. Omit for the plain library grid. */
    selectedIds?: string[];
    /** The tile that has keyboard focus, for a ring without a selection tick. */
    focusedId?: string | null;
    onChoose: (id: string) => void;
    /** The modal's choice of several; the Media screen's grid chooses nothing. */
    multiselectable?: boolean;
};

// Shared by the Media screen's grid view and the media modal (I-236): one
// component, so the two never drift apart.
export function MediaGrid({
    rows,
    selectedIds = [],
    focusedId = null,
    onChoose,
    multiselectable = false,
}: Props) {
    return (
        <ul
            className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6"
            role="listbox"
            aria-label="Media"
            aria-multiselectable={multiselectable}
        >
            {rows.map((row) => {
                const selected = selectedIds.includes(row.id);

                return (
                    <li key={row.id} role="none">
                        <button
                            type="button"
                            role="option"
                            aria-selected={selected}
                            className={cn(
                                'relative w-full rounded text-left ring-offset-2',
                                (selected || row.id === focusedId) &&
                                    'ring-accent ring-2',
                            )}
                            onClick={() => onChoose(row.id)}
                        >
                            <Thumb row={row} />
                            {selected && (
                                <span className="bg-accent text-on-accent absolute top-1 right-1 rounded-full p-0.5">
                                    <Check className="size-3" />
                                    <span className="sr-only">Selected</span>
                                </span>
                            )}
                            <span className="block truncate text-xs">
                                {row.title}
                            </span>
                        </button>
                    </li>
                );
            })}
        </ul>
    );
}
