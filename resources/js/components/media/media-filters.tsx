import { X } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SelectField } from '@/components/ui/select-field';
import { __ } from '@/lib/i18n';
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
    kinds = ALL_KINDS,
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
    const types = mediaTypes().filter((option) => kinds.includes(option.value));

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
                className="text-small font-normal"
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
                    aria-label={__('Type')}
                    emptyLabel={__('All media')}
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
            <Input
                key={values.search}
                type="search"
                className="w-64"
                defaultValue={values.search}
                placeholder={__('Search file names, titles, alt text')}
                aria-label={__('Search media')}
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
                        className="text-small font-normal"
                    >
                        {__('Uploaded for this :noun', { noun: openItem.noun })}
                    </Label>
                </div>
            )}
            {showMine && box('mine', __('Mine'))}
            {box('unused', __('Unused'))}
            {box('missingAlt', __('Missing alt text'))}
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
        </div>
    );
}
