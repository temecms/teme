import { useState } from 'react';
import { useCardMedia } from '@/components/editor/use-card-media';
import { MediaModal } from '@/components/media/media-modal';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { __ } from '@/lib/i18n';
import { largest, variant } from '@/lib/media-urls';
import type { CardMedia, FieldDefinition } from '@/types';

type Props = {
    field: FieldDefinition;
    name?: string;
    value: unknown;
    /** The chosen image as the server sees it, or null when none or gone. */
    media?: CardMedia | null;
    disabled?: boolean;
    /** When set, the choice is handed to the parent instead of a form input. */
    onChange?: (value: string) => void;
};

/**
 * A media setting's control: one image from the library, chosen as a
 * featured image is (I-237) and sent as its ID in a hidden input, empty when
 * none is chosen (I-418). Design drafts may pass onChange instead of name.
 */
export function MediaSetting({
    field,
    name,
    value,
    media,
    disabled,
    onChange,
}: Props) {
    const id = `field-${field.key}`;
    const [choosing, setChoosing] = useState(false);
    const [chosen, setChosen] = useState(
        typeof value === 'string' ? value : '',
    );

    const pick = (next: string) => {
        setChosen(next);
        onChange?.(next);
    };
    const initial: Record<string, CardMedia> =
        media && typeof value === 'string' && value !== ''
            ? { [value]: media }
            : {};
    const { media: known, loading, addMedia } = useCardMedia(initial);
    const item: CardMedia | undefined =
        chosen !== '' ? known[chosen] : undefined;

    return (
        <div
            id={id}
            aria-describedby={`${id}-hint ${id}-error`}
            className="grid gap-2"
        >
            {name !== undefined && (
                <input
                    type="hidden"
                    name={name}
                    value={chosen}
                    disabled={disabled}
                />
            )}
            <div className="flex flex-wrap items-center gap-2">
                {chosen === '' ? (
                    <span className="bg-surface-3 text-ink-2 text-caption inline-flex h-6 items-center rounded-sm px-2">
                        {__('No picture chosen')}
                    </span>
                ) : item ? (
                    <>
                        <img
                            data-test="media-preview"
                            src={
                                variant(item.variants, 'w640') ??
                                largest(item.variants) ??
                                ''
                            }
                            alt=""
                            className="aspect-3/2 w-16 rounded-sm object-cover"
                        />
                        <span className="bg-surface-3 text-ink-2 text-caption inline-flex h-6 max-w-48 items-center truncate rounded-sm px-2">
                            {item.title}
                        </span>
                    </>
                ) : loading.includes(chosen) ? (
                    <Skeleton aria-busy="true" className="h-6 w-24" />
                ) : (
                    <span
                        data-test="media-gone"
                        className="bg-surface-3 text-ink-2 text-caption inline-flex h-6 items-center rounded-sm px-2"
                    >
                        {__('This image is no longer in the library.')}
                    </span>
                )}
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    data-test={chosen === '' ? 'media-choose' : 'media-replace'}
                    aria-label={__('Choose picture for :setting', {
                        setting: field.label,
                    })}
                    disabled={disabled}
                    onClick={() => setChoosing(true)}
                >
                    {__('Choose picture')}
                </Button>
                {chosen !== '' && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        data-test="media-remove"
                        aria-label={__('Remove picture for :setting', {
                            setting: field.label,
                        })}
                        disabled={disabled}
                        onClick={() => pick('')}
                    >
                        {__('Remove')}
                    </Button>
                )}
            </div>
            <MediaModal
                open={choosing}
                onOpenChange={setChoosing}
                kinds={['image']}
                action={__('Use as :setting', {
                    setting: field.label.toLowerCase(),
                })}
                onChoose={(ids) => {
                    void addMedia(ids);
                    pick(ids[0] ?? '');
                }}
            />
        </div>
    );
}
