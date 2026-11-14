import { useRef } from 'react';
import InputError from '@/components/input-error';
import { AltTextSuggestion } from '@/components/media/alt-text-suggestion';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { MediaDetailsData, MediaKind } from '@/types';

/** A file's kind in words, as the media list's Type column shows it. */
export const MEDIA_KINDS: Record<MediaKind, string> = {
    image: 'Image',
    video: 'Video',
    audio: 'Audio',
    document: 'Document',
};

export const attachmentTextareaClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

export function attachmentSize(bytes: number): string {
    return bytes < 1024 * 1024
        ? `${Math.round(bytes / 1024)} KB`
        : `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

/** File name, type, dimensions, size and uploader — shown by the drawer and the modal alike. */
export function AttachmentMeta({ item }: { item: MediaDetailsData }) {
    return (
        <dl className="text-muted-foreground grid grid-cols-2 gap-1 text-xs">
            <dt>File</dt>
            <dd className="truncate">{item.filename}</dd>
            <dt>Type</dt>
            <dd>
                {MEDIA_KINDS[item.kind]}
                {item.extension === ''
                    ? ''
                    : ` (${item.extension.toUpperCase()})`}
            </dd>
            {item.width !== null && (
                <>
                    <dt>Dimensions</dt>
                    <dd>
                        {item.width} × {item.height}
                    </dd>
                </>
            )}
            <dt>Size</dt>
            <dd>{attachmentSize(item.sizeBytes)}</dd>
            <dt>Uploaded</dt>
            <dd>
                {new Date(item.createdAt).toLocaleString()}
                {item.uploaderName ? ` by ${item.uploaderName}` : ''}
            </dd>
        </dl>
    );
}

export type AttachmentField =
    | 'title'
    | 'alt'
    | 'caption'
    | 'credit'
    | 'licence';
export type AttachmentFieldErrors = Partial<Record<AttachmentField, string>>;

type Props = {
    item: MediaDetailsData;
    errors?: AttachmentFieldErrors;
    disabled?: boolean;
    /** Fired on blur with the field and its new value — the modal saves one field at a time this way. Omit inside a <form> that submits all fields together. */
    onFieldBlur?: (field: AttachmentField, value: string) => void;
    altHint?: string;
    /** Fired with the image's fresh details once a suggested alt text is used; Suggest alt text shows only when it is given. */
    onAltTextUsed?: (details: MediaDetailsData) => void;
};

/**
 * Title, alt text, caption, credit and licence — the fields the drawer's form and the
 * modal's per-field blur save both write through `media.update` (I-236).
 * Each input keeps its own `name`, so a surrounding <form> can read it via
 * `FormData` whether or not `onFieldBlur` is also wired.
 */
export function AttachmentFields({
    item,
    errors = {},
    disabled = false,
    onFieldBlur,
    altHint = 'Describe what the image shows for someone who cannot see it.',
    onAltTextUsed,
}: Props) {
    const altField = useRef<HTMLTextAreaElement>(null);

    const blur = (field: AttachmentField) =>
        onFieldBlur &&
        ((event: React.FocusEvent<HTMLInputElement | HTMLTextAreaElement>) =>
            onFieldBlur(field, event.currentTarget.value));

    return (
        <>
            <div className="grid gap-1">
                <Label htmlFor={`attachment-${item.id}-title`}>Title</Label>
                <Input
                    id={`attachment-${item.id}-title`}
                    aria-describedby={`attachment-${item.id}-title-error`}
                    name="title"
                    defaultValue={item.title}
                    key={`${item.id}-title`}
                    disabled={disabled}
                    onBlur={blur('title')}
                />
                <InputError
                    id={`attachment-${item.id}-title-error`}
                    message={errors.title}
                />
            </div>
            {item.kind === 'image' && (
                <div className="grid gap-1">
                    <Label htmlFor={`attachment-${item.id}-alt`}>
                        Alt text
                    </Label>
                    {/* A new stored alt text, such as a suggestion used, redraws the field with it. */}
                    <textarea
                        ref={altField}
                        id={`attachment-${item.id}-alt`}
                        aria-describedby={`attachment-${item.id}-alt-error`}
                        name="alt"
                        rows={2}
                        defaultValue={item.alt ?? ''}
                        key={`${item.id}-alt-${item.alt ?? ''}`}
                        className={attachmentTextareaClass}
                        disabled={disabled}
                        onBlur={blur('alt')}
                    />
                    <p className="text-muted-foreground text-xs">{altHint}</p>
                    <InputError
                        id={`attachment-${item.id}-alt-error`}
                        message={errors.alt}
                    />
                    {item.altText !== null && onAltTextUsed !== undefined && (
                        <AltTextSuggestion
                            key={item.id}
                            item={item}
                            offer={item.altText}
                            currentAlt={() =>
                                altField.current?.value ?? item.alt ?? ''
                            }
                            onUsed={onAltTextUsed}
                        />
                    )}
                </div>
            )}
            <div className="grid gap-1">
                <Label htmlFor={`attachment-${item.id}-caption`}>Caption</Label>
                <textarea
                    id={`attachment-${item.id}-caption`}
                    aria-describedby={`attachment-${item.id}-caption-error`}
                    name="caption"
                    rows={2}
                    defaultValue={item.caption ?? ''}
                    key={`${item.id}-caption`}
                    className={attachmentTextareaClass}
                    disabled={disabled}
                    onBlur={blur('caption')}
                />
                <InputError
                    id={`attachment-${item.id}-caption-error`}
                    message={errors.caption}
                />
            </div>
            <div className="grid gap-1">
                <Label htmlFor={`attachment-${item.id}-credit`}>Credit</Label>
                <Input
                    id={`attachment-${item.id}-credit`}
                    aria-describedby={`attachment-${item.id}-credit-error`}
                    name="credit"
                    defaultValue={item.credit ?? ''}
                    key={`${item.id}-credit`}
                    disabled={disabled}
                    onBlur={blur('credit')}
                />
                <InputError
                    id={`attachment-${item.id}-credit-error`}
                    message={errors.credit}
                />
            </div>
            <div className="grid gap-1">
                <Label htmlFor={`attachment-${item.id}-licence`}>Licence</Label>
                <Input
                    id={`attachment-${item.id}-licence`}
                    aria-describedby={`attachment-${item.id}-licence-error`}
                    name="licence"
                    defaultValue={item.licence ?? ''}
                    key={`${item.id}-licence`}
                    disabled={disabled}
                    onBlur={blur('licence')}
                />
                <InputError
                    id={`attachment-${item.id}-licence-error`}
                    message={errors.licence}
                />
            </div>
        </>
    );
}
