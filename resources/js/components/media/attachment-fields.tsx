import { useHttp } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { useCallback, useEffect, useRef } from 'react';
import { showToast } from '@/components/flash-toast';
import InputError from '@/components/input-error';
import { AltTextSuggestion } from '@/components/media/alt-text-suggestion';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { __ } from '@/lib/i18n';
import { update as updateMedia } from '@/routes/media';
import type { MediaDetailsData, MediaKind } from '@/types';

/** A file's kind in words, as the media list's Type column shows it. */
export function mediaKind(kind: MediaKind): string {
    switch (kind) {
        case 'image':
            return __('Image');
        case 'video':
            return __('Video');
        case 'audio':
            return __('Audio');
        default:
            return __('Document');
    }
}

/** A file's size in kilobytes or megabytes. */
export function attachmentSize(bytes: number): string {
    return bytes < 1024 * 1024
        ? __(':size KB', { size: Math.round(bytes / 1024) })
        : __(':size MB', { size: (bytes / 1024 / 1024).toFixed(1) });
}

/** Said when a request is refused for anything but a field's error. */
export function failedSentence(): string {
    return __('That didn’t work, so nothing changed. Try again.');
}

/** Every value a save sends: the fields and the focal point (media.update needs both). */
export type DetailValues = {
    title: string;
    alt: string;
    caption: string;
    credit: string;
    licence: string;
    focal_x: number;
    focal_y: number;
};

export type DetailField = 'title' | 'alt' | 'caption' | 'credit' | 'licence';

function fromDetails(details: MediaDetailsData): DetailValues {
    return {
        title: details.title,
        alt: details.alt ?? '',
        caption: details.caption ?? '',
        credit: details.credit ?? '',
        licence: details.licence ?? '',
        focal_x: details.focalX,
        focal_y: details.focalY,
    };
}

/** The server trims what it stores, so a value differing only in spaces is already saved. */
function same(values: DetailValues, saved: DetailValues): boolean {
    return (Object.keys(values) as (keyof DetailValues)[]).every(
        (key) => String(values[key]).trim() === String(saved[key]).trim(),
    );
}

/** The saver one file's fields and focal point share (docs/architecture/design-system.md, section 4.36). */
export type MediaSaver = {
    /** Saves every field as it stands, after any save already running; nothing when nothing changed. */
    saveNow: () => void;
    /** Notes a field's or the focal point's new value, to be sent by the next save. */
    set: <K extends keyof DetailValues>(key: K, value: DetailValues[K]) => void;
    /** Takes details changed elsewhere (a suggested alt text used) as saved. */
    accept: (details: MediaDetailsData) => void;
    /** The file is deleted: nothing more is sent for it. */
    forget: () => void;
    /** The server's message under each refused field. */
    errors: Partial<Record<DetailField, string>>;
};

/**
 * Saves one file's details as each field is left. Saves run one at a time,
 * and each sends every field as it stands when it runs, so a later save
 * never undoes an earlier one. Use it in a component drawn for one file
 * (keyed by its ID): a save asked for just before it goes still runs.
 */
export function useMediaSaver(
    item: MediaDetailsData,
    onSaved: (fresh: MediaDetailsData, focalMoved: boolean) => void,
): MediaSaver {
    // useHttp's submit() resolves undefined on a 422 (errors land in its own
    // `errors` state) rather than rejecting; other failures still throw.
    const { submit, setData, errors } = useHttp<DetailValues>();
    const values = useRef<DetailValues>(fromDetails(item));
    const saved = useRef<DetailValues>(fromDetails(item));
    const queue = useRef<Promise<void>>(Promise.resolve());
    const latest = useRef(onSaved);
    const gone = useRef(false);
    const refused = useRef(false);
    const id = item.id;

    const send = useCallback(
        async (sent: DetailValues): Promise<MediaDetailsData | undefined> => {
            setData(sent);

            return (await submit(updateMedia({ media: id }))) as
                | MediaDetailsData
                | undefined;
        },
        [id, setData, submit],
    );
    const sender = useRef(send);

    useEffect(() => {
        latest.current = onSaved;
        sender.current = send;
    }, [onSaved, send]);

    // One save at a time, each sending every field as it stands, so a later save never undoes an earlier one.
    const saveNow = useCallback(() => {
        queue.current = queue.current.then(async () => {
            // Every field and the focal point, read when this save runs, not when it was asked for.
            const read = { ...values.current };

            if (gone.current || same(read, saved.current)) {
                return;
            }

            const focalMoved =
                read.focal_x !== saved.current.focal_x ||
                read.focal_y !== saved.current.focal_y;

            try {
                const fresh = await sender.current(read);

                if (fresh !== undefined) {
                    saved.current = fromDetails(fresh);
                    latest.current(fresh, focalMoved);
                }
            } catch {
                showToast({ type: 'error', message: failedSentence() });
            }
        });
    }, []);

    const set = useCallback(
        <K extends keyof DetailValues>(key: K, value: DetailValues[K]) => {
            values.current = { ...values.current, [key]: value };
        },
        [],
    );

    const accept = useCallback((details: MediaDetailsData) => {
        saved.current = fromDetails(details);
        values.current = { ...values.current, alt: details.alt ?? '' };
    }, []);

    const forget = useCallback(() => {
        gone.current = true;
    }, []);

    useEffect(() => {
        refused.current = Object.keys(errors).length > 0;
    }, [errors]);

    // Closing, or moving to another file, saves what is left unsaved; not while a field is
    // refused, whose message would go with the panel, and never for a deleted file.
    useEffect(
        () => () => {
            if (!refused.current && !gone.current) {
                saveNow();
            }
        },
        [saveNow],
    );

    return {
        saveNow,
        set,
        accept,
        forget,
        errors: errors as Partial<Record<DetailField, string>>,
    };
}

/** The toast after a save: the focal point's says its sizes are being made again. */
export function savedSentence(focalMoved: boolean): string {
    return focalMoved
        ? __(
              'Saved. Its sizes are being made again around the new focal point.',
          )
        : __('Saved.');
}

type Props = {
    item: MediaDetailsData;
    saver: MediaSaver;
    disabled?: boolean;
    /** Fired with the image's fresh details once a suggested alt text is used; Suggest alt text shows only when it is given. */
    onAltTextUsed?: (details: MediaDetailsData) => void;
};

type FieldGroup = 'all' | 'primary' | 'secondary';

/**
 * Title, alt text, caption, credit and license, each saved as it is left
 * (docs/architecture/design-system.md, section 4.36). The picker's side keeps
 * Title first (`all`); the library detail panel splits primary (Alt, Caption,
 * Credit) from secondary (Title, License) around its actions.
 */
export function MediaFields({
    item,
    saver,
    disabled = false,
    onAltTextUsed,
    group = 'all',
}: Props & {
    /** `all` = picker order; primary / secondary = library detail panel. */
    group?: FieldGroup;
}) {
    const altField = useRef<HTMLTextAreaElement | null>(null);
    const field = (name: DetailField) => ({
        name,
        disabled,
        onChange: (
            event: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>,
        ) => saver.set(name, event.currentTarget.value),
        onBlur: saver.saveNow,
    });
    const missingAlt = (item.alt ?? '').trim() === '';
    const showTitle = group === 'all' || group === 'secondary';
    const showPrimary = group === 'all' || group === 'primary';
    const showLicence = group === 'all' || group === 'secondary';
    // Picker keeps Title before Alt; the library panel's secondary block puts Title with License.
    const titleFirst = group === 'all';

    const titleField = showTitle && (
        <div className="grid gap-1">
            <Label htmlFor={`attachment-${item.id}-title`}>
                {__('Title')}
            </Label>
            <Input
                {...field('title')}
                id={`attachment-${item.id}-title`}
                aria-describedby={`attachment-${item.id}-title-error`}
                defaultValue={item.title}
                key={`${item.id}-title`}
            />
            <InputError
                id={`attachment-${item.id}-title-error`}
                message={saver.errors.title}
            />
        </div>
    );

    const altFieldNode = showPrimary && item.kind === 'image' && (
        <div className="grid gap-1">
            <Label
                htmlFor={`attachment-${item.id}-alt`}
                className="flex items-center gap-2"
            >
                {__('Alt text')}
                {missingAlt && (
                    <span className="text-warn-ink text-caption inline-flex items-center gap-1 font-normal">
                        <TriangleAlert
                            aria-hidden="true"
                            className="size-3.5"
                        />
                        {__('missing')}
                    </span>
                )}
            </Label>
            {/* A new stored alt text, such as a suggestion used, redraws the field with it. */}
            <Textarea
                {...field('alt')}
                id={`attachment-${item.id}-alt`}
                ref={altField}
                aria-describedby={`attachment-${item.id}-alt-help attachment-${item.id}-alt-error`}
                rows={2}
                defaultValue={item.alt ?? ''}
                key={`${item.id}-alt-${item.alt ?? ''}`}
            />
            <p
                id={`attachment-${item.id}-alt-help`}
                className="text-ink-3 text-small"
            >
                {__('Used wherever an article does not give its own.')}
            </p>
            <InputError
                id={`attachment-${item.id}-alt-error`}
                message={saver.errors.alt}
            />
            {item.altText !== null && onAltTextUsed !== undefined && (
                <AltTextSuggestion
                    key={item.id}
                    item={item}
                    offer={item.altText}
                    currentAlt={() =>
                        altField.current?.value ?? item.alt ?? ''
                    }
                    onUsed={(details) => {
                        saver.accept(details);
                        onAltTextUsed(details);
                    }}
                />
            )}
        </div>
    );

    return (
        <div className="grid gap-4">
            {titleFirst && titleField}
            {altFieldNode}
            {showPrimary && (
                <>
                    <div className="grid gap-1">
                        <Label htmlFor={`attachment-${item.id}-caption`}>
                            {__('Caption')}
                        </Label>
                        <Textarea
                            {...field('caption')}
                            id={`attachment-${item.id}-caption`}
                            aria-describedby={`attachment-${item.id}-caption-error`}
                            rows={2}
                            defaultValue={item.caption ?? ''}
                            key={`${item.id}-caption`}
                        />
                        <InputError
                            id={`attachment-${item.id}-caption-error`}
                            message={saver.errors.caption}
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor={`attachment-${item.id}-credit`}>
                            {__('Credit')}
                        </Label>
                        <Input
                            {...field('credit')}
                            id={`attachment-${item.id}-credit`}
                            aria-describedby={`attachment-${item.id}-credit-error`}
                            defaultValue={item.credit ?? ''}
                            key={`${item.id}-credit`}
                        />
                        <InputError
                            id={`attachment-${item.id}-credit-error`}
                            message={saver.errors.credit}
                        />
                    </div>
                </>
            )}
            {!titleFirst && titleField}
            {showLicence && (
                <div className="grid gap-1">
                    <Label htmlFor={`attachment-${item.id}-licence`}>
                        {__('License')}
                    </Label>
                    <Input
                        {...field('licence')}
                        id={`attachment-${item.id}-licence`}
                        aria-describedby={`attachment-${item.id}-licence-error`}
                        defaultValue={item.licence ?? ''}
                        key={`${item.id}-licence`}
                    />
                    <InputError
                        id={`attachment-${item.id}-licence-error`}
                        message={saver.errors.licence}
                    />
                </div>
            )}
        </div>
    );
}
