import { X } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SelectField } from '@/components/ui/select-field';
import type { MediaKind, MediaMonth, UploadTarget } from '@/types';

/** The filters' values: '' for a select's first choice, false for a box left clear. */
export type MediaFilterValues = {
    type: string;
    month: string;
    search: string;
    mine: boolean;
    unused: boolean;
    missingAlt: boolean;
    /** The item whose uploads alone are shown (I-553), or '' for none. */
    uploadedFor: string;
};

/** The Type select's choices, in the order the library's tabs had them. */
export const MEDIA_TYPES: { value: MediaKind; label: string }[] = [
    { value: 'image', label: 'Images' },
    { value: 'video', label: 'Video' },
    { value: 'audio', label: 'Audio' },
    { value: 'document', label: 'Documents' },
];

type BoxKey = 'mine' | 'unused' | 'missingAlt';

/**
 * The media library's filter bar, on the Media screen and in the media modal
 * alike (I-236): Type, Date, the search, and, in the editor's modal, Uploaded
 * for this post, then Mine, Unused and Missing alt text. A select or a box takes effect at once; the search on Enter.
 */
export function MediaFilters({
    values,
    onChange,
    months,
    kinds = MEDIA_TYPES.map((option) => option.value),
    showMine = true,
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
    /** Keeps the boxes' ids apart where two bars could be drawn at once. */
    idPrefix: string;
    /** The item open in the editor: draws its Uploaded for box. */
    openItem?: UploadTarget | null;
    /** The item the Media screen is narrowed to: draws its chip, whose button removes it. */
    chosenItem?: { id: string; title: string } | null;
}) {
    const types = MEDIA_TYPES.filter((option) => kinds.includes(option.value));

    const box = (key: BoxKey, label: string) => (
        <div className="flex min-h-6 items-center gap-2">
            <Checkbox
                id={`${idPrefix}-${key}`}
                checked={values[key]}
                onCheckedChange={(checked) => {
                    const change: Partial<MediaFilterValues> = {};
                    change[key] = checked === true;
                    onChange(change);
                }}
            />
            <Label
                htmlFor={`${idPrefix}-${key}`}
                className="text-[13px] font-normal"
            >
                {label}
            </Label>
        </div>
    );

    return (
        <div
            data-test="media-filters"
            className="flex flex-wrap items-center gap-x-4 gap-y-2"
        >
            {types.length > 1 && (
                <SelectField
                    aria-label="Type"
                    emptyLabel="All media"
                    value={values.type}
                    onValueChange={(type) => onChange({ type })}
                    options={types}
                />
            )}
            <SelectField
                aria-label="Date"
                emptyLabel="All dates"
                value={values.month}
                onValueChange={(month) => onChange({ month })}
                options={months}
            />
            <Input
                key={values.search}
                type="search"
                className="w-64"
                defaultValue={values.search}
                placeholder="Search file names, titles, alt text"
                aria-label="Search media"
                onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        onChange({ search: event.currentTarget.value.trim() });
                    }
                }}
            />
            {openItem && (
                <div className="flex min-h-6 items-center gap-2">
                    <Checkbox
                        id={`${idPrefix}-uploadedFor`}
                        checked={values.uploadedFor === openItem.id}
                        onCheckedChange={(checked) =>
                            onChange({
                                uploadedFor:
                                    checked === true ? openItem.id : '',
                            })
                        }
                    />
                    <Label
                        htmlFor={`${idPrefix}-uploadedFor`}
                        className="text-[13px] font-normal"
                    >
                        {`Uploaded for this ${openItem.noun}`}
                    </Label>
                </div>
            )}
            {showMine && box('mine', 'Mine')}
            {box('unused', 'Unused')}
            {box('missingAlt', 'Missing alt text')}
            {chosenItem && values.uploadedFor === chosenItem.id && (
                <Badge
                    variant="secondary"
                    data-test="media-uploaded-for-chip"
                    className="h-7 gap-1 pr-1"
                >
                    {`Uploaded for “${chosenItem.title || 'Untitled'}”`}
                    <button
                        type="button"
                        aria-label={`Remove this filter: Uploaded for “${chosenItem.title || 'Untitled'}”`}
                        className="hover:bg-surface-3 focus-visible:ring-ring inline-flex size-6 items-center justify-center rounded-sm focus-visible:ring-2 focus-visible:outline-none"
                        onClick={() => onChange({ uploadedFor: '' })}
                    >
                        <X aria-hidden className="size-3" />
                    </button>
                </Badge>
            )}
        </div>
    );
}
