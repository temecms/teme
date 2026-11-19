import { TriangleAlert, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { SearchField } from '@/components/list/search-field';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { FilterChip } from '@/components/ui/filter-chip';
import { SelectField } from '@/components/ui/select-field';
import { __ } from '@/lib/i18n';
import type { MediaKind, MediaMonth, UploadTarget } from '@/types';

/** The filters' values: '' for a select's first choice, false for a chip left up. */
export type MediaFilterValues = {
    type: string;
    month: string;
    search: string;
    mine: boolean;
    unused: boolean;
    missingAlt: boolean;
    /** Only files brought in from another address; the library alone offers it. */
    imported?: boolean;
    /** The item whose uploads alone are shown (I-553), or '' for none. */
    uploadedFor: string;
};

/** How many files each chip would show, when the screen knows. */
export type MediaChipCounts = {
    missingAlt: number;
    unused: number;
    imported: number;
};

/** A chip's count, capped as the server caps it. */
const shownCount = (count: number | undefined) =>
    count === undefined ? undefined : count > 999 ? '999+' : count;

/** The Type select's choices, in the order the library's tabs had them. */
function mediaTypes(): { value: MediaKind; label: string }[] {
    return [
        { value: 'image', label: __('Images') },
        { value: 'video', label: __('Video') },
        { value: 'audio', label: __('Audio') },
        { value: 'document', label: __('Documents') },
    ];
}

/** Every kind the library holds. */
const ALL_KINDS: MediaKind[] = ['image', 'video', 'audio', 'document'];

type ChipKey = 'mine' | 'unused' | 'missingAlt' | 'imported';

/**
 * The media library's filter row, on the Media screen and in the media modal
 * alike (I-236): the search with its magnifier, Type, Date, then the toggle
 * chips (Missing alt text, Unused, Imported on the Media screen) with their
 * counts, and at the end whatever the screen adds there. A select or a chip
 * takes effect at once; the search on Enter.
 */
export function MediaFilters({
    values,
    onChange,
    months,
    kinds = ALL_KINDS,
    showMine = true,
    showImported = false,
    counts,
    end,
    idPrefix,
    openItem = null,
    chosenItem = null,
}: {
    values: MediaFilterValues;
    onChange: (changes: Partial<MediaFilterValues>) => void;
    months: MediaMonth[];
    /** The kinds the Type select offers; with only one, it isn't drawn. */
    kinds?: MediaKind[];
    showMine?: boolean;
    /** Draws the Imported chip. */
    showImported?: boolean;
    /** Each chip's count. */
    counts?: MediaChipCounts;
    /** Drawn at the row's end: the Media screen's tile size and layout. */
    end?: ReactNode;
    /** Keeps the fields' ids apart where two rows could be drawn at once. */
    idPrefix: string;
    /** The item open in the editor: draws its Uploaded for chip. */
    openItem?: UploadTarget | null;
    /** The item the Media screen is narrowed to: draws its chip, whose button removes it. */
    chosenItem?: { id: string; title: string } | null;
}) {
    const types = mediaTypes().filter((option) => kinds.includes(option.value));

    const chip = (
        key: ChipKey,
        label: string,
        count?: number,
        icon?: ReactNode,
    ) => (
        <FilterChip
            id={`${idPrefix}-${key}`}
            pressed={values[key] === true}
            count={shownCount(count)}
            onClick={() => {
                const change: Partial<MediaFilterValues> = {};
                change[key] = values[key] !== true;
                onChange(change);
            }}
        >
            {icon}
            {label}
        </FilterChip>
    );

    return (
        <div
            data-test="media-filters"
            role="search"
            className="flex flex-wrap items-center gap-2"
        >
            <SearchField
                key={values.search}
                id={`${idPrefix}-search`}
                className="w-full sm:w-70"
                defaultValue={values.search}
                placeholder={__('Search names, alt text, credits')}
                aria-label={__('Search media')}
                onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        onChange({ search: event.currentTarget.value.trim() });
                    }
                }}
            />
            {types.length > 1 && (
                <SelectField
                    aria-label={__('Type')}
                    emptyLabel={__('All types')}
                    value={values.type}
                    onValueChange={(type) => onChange({ type })}
                    options={types}
                />
            )}
            <SelectField
                aria-label={__('Date')}
                emptyLabel={__('All dates')}
                value={values.month}
                onValueChange={(month) => onChange({ month })}
                options={months}
            />
            {openItem && (
                <FilterChip
                    id={`${idPrefix}-uploadedFor`}
                    pressed={values.uploadedFor === openItem.id}
                    onClick={() =>
                        onChange({
                            uploadedFor:
                                values.uploadedFor === openItem.id
                                    ? ''
                                    : openItem.id,
                        })
                    }
                >
                    {__('Uploaded for this :noun', { noun: openItem.noun })}
                </FilterChip>
            )}
            {chip(
                'missingAlt',
                __('Missing alt text'),
                counts?.missingAlt,
                <TriangleAlert aria-hidden="true" />,
            )}
            {chip('unused', __('Unused'), counts?.unused)}
            {showImported && chip('imported', __('Imported'), counts?.imported)}
            {showMine && chip('mine', __('Mine'))}
            {chosenItem && values.uploadedFor === chosenItem.id && (
                <Badge
                    variant="secondary"
                    data-test="media-uploaded-for-chip"
                    className="h-7 gap-1 pr-1"
                >
                    {__('Uploaded for “:title”', {
                        title: chosenItem.title || __('Untitled'),
                    })}
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-xs"
                        aria-label={__(
                            'Remove this filter: Uploaded for “:title”',
                            {
                                title: chosenItem.title || __('Untitled'),
                            },
                        )}
                        className="size-6"
                        onClick={() => onChange({ uploadedFor: '' })}
                    >
                        <X aria-hidden className="size-3" />
                    </Button>
                </Badge>
            )}
            {end !== undefined && (
                <>
                    <span className="flex-1" />
                    {end}
                </>
            )}
        </div>
    );
}
