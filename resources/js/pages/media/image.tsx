import { Head, Link, router, setLayoutProps } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    Crop,
    FlipHorizontal,
    RotateCw,
} from 'lucide-react';
import { useCallback, useState } from 'react';
import type { CSSProperties } from 'react';
import { showToast } from '@/components/flash-toast';
import {
    savedSentence,
    useMediaSaver,
} from '@/components/media/attachment-fields';
import { FocalPoint } from '@/components/media/focal-point';
import { DetailPage } from '@/components/patterns/detail-page';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Panel } from '@/components/ui/panel';
import { SoonTag } from '@/components/ui/soon-tag';
import { Textarea } from '@/components/ui/textarea';
import { __ } from '@/lib/i18n';
import { editImage, index as mediaIndex } from '@/routes/media';
import type { MediaDetailsData } from '@/types';

type ImagePage = {
    item: MediaDetailsData;
    /** The picture's place among the library's images, newest first. */
    place: {
        number: number;
        total: number;
        previous: string | null;
        next: string | null;
    };
};

/** The crops the site makes, by where each is used, and their shapes. */
function crops(): { label: string; ratio: string; shape: string }[] {
    return [
        { label: __('Lead story'), ratio: '16 / 9', shape: __('16 : 9') },
        { label: __('Lists'), ratio: '1 / 1', shape: __('1 : 1') },
        { label: __('Portrait'), ratio: '4 / 5', shape: __('4 : 5') },
        { label: __('Social card'), ratio: '1.91 / 1', shape: __('1.91 : 1') },
    ];
}

const within = (value: number) =>
    Number.isNaN(value) ? 50 : Math.min(100, Math.max(0, Math.round(value)));

/**
 * Edit image: the picture large with its focal point, the four crops the
 * site makes from it, its alt text, and its place among the library's
 * images. Crop, Rotate and Flip are drawn, marked Later, and do nothing.
 */
export default function MediaImage({ item, place }: ImagePage) {
    const [focal, setFocal] = useState<[number, number]>([
        item.focalX,
        item.focalY,
    ]);
    const [alt, setAlt] = useState(item.alt ?? '');
    const name = item.filename || item.title;

    const onSaved = useCallback(
        (_fresh: MediaDetailsData, focalMoved: boolean) => {
            showToast({ type: 'success', message: savedSentence(focalMoved) });
        },
        [],
    );
    const saver = useMediaSaver(item, onSaved);

    setLayoutProps({
        breadcrumbs: [
            { title: __('Media'), href: mediaIndex() },
            { title: name, href: editImage({ media: item.id }) },
        ],
    });

    const moveTo = (next: [number, number]) => {
        setFocal(next);
        saver.set('focal_x', next[0]);
        saver.set('focal_y', next[1]);
    };

    const step = (id: string | null) =>
        id === null
            ? undefined
            : () => router.visit(editImage({ media: id }).url);

    const title = __('Edit image');

    return (
        <>
            <Head title={title} />
            <DetailPage
                title={title}
                showTitle
                description={
                    item.width !== null && item.height !== null
                        ? __(
                              ':name · :width × :height. Click where the subject is: every crop keeps it in view.',
                              { name, width: item.width, height: item.height },
                          )
                        : __(
                              ':name. Click where the subject is: every crop keeps it in view.',
                              { name },
                          )
                }
                panelLabel={__('Focal point and alt text')}
                actions={
                    <>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label={__('Previous image')}
                            disabled={place.previous === null}
                            onClick={step(place.previous)}
                        >
                            <ChevronLeft aria-hidden="true" />
                        </Button>
                        <span className="text-small text-ink-3 self-center tabular-nums">
                            {__(':number of :total', {
                                number: place.number,
                                total: place.total,
                            })}
                        </span>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label={__('Next image')}
                            disabled={place.next === null}
                            onClick={step(place.next)}
                        >
                            <ChevronRight aria-hidden="true" />
                        </Button>
                        <Button asChild variant="neutral">
                            <Link href={mediaIndex().url}>
                                {__('Back to media')}
                            </Link>
                        </Button>
                        <Button
                            type="button"
                            variant="primary"
                            data-test="image-save"
                            disabled={!item.can.edit}
                            onClick={saver.saveNow}
                        >
                            {__('Save')}
                        </Button>
                    </>
                }
                panel={
                    <Panel className="grid gap-6 p-5">
                        <section className="grid gap-3">
                            <h2 className="text-lead text-ink font-semibold">
                                {__('Focal point')}
                            </h2>
                            <div className="grid grid-cols-2 gap-3">
                                <Field
                                    htmlFor="image-across"
                                    label={__('Across (%)')}
                                >
                                    <Input
                                        id="image-across"
                                        type="number"
                                        min={0}
                                        max={100}
                                        value={focal[0]}
                                        disabled={!item.can.edit}
                                        onChange={(event) =>
                                            moveTo([
                                                within(
                                                    event.target.valueAsNumber,
                                                ),
                                                focal[1],
                                            ])
                                        }
                                    />
                                </Field>
                                <Field
                                    htmlFor="image-down"
                                    label={__('Down (%)')}
                                >
                                    <Input
                                        id="image-down"
                                        type="number"
                                        min={0}
                                        max={100}
                                        value={focal[1]}
                                        disabled={!item.can.edit}
                                        onChange={(event) =>
                                            moveTo([
                                                focal[0],
                                                within(
                                                    event.target.valueAsNumber,
                                                ),
                                            ])
                                        }
                                    />
                                </Field>
                            </div>
                        </section>
                        <section className="grid gap-3">
                            <h2 className="text-lead text-ink font-semibold">
                                {__('How it will be cropped')}
                            </h2>
                            <div className="grid grid-cols-2 gap-3">
                                {crops().map((crop) => (
                                    <figure
                                        key={crop.label}
                                        data-test="image-crop"
                                        className="grid content-start gap-1"
                                    >
                                        <img
                                            src={item.previewUrl ?? ''}
                                            alt=""
                                            className="outline-img-outline aspect-(--ratio) w-full rounded-md object-cover object-(--focal) outline-1 -outline-offset-1"
                                            style={
                                                {
                                                    '--ratio': crop.ratio,
                                                    '--focal': `${focal[0]}% ${focal[1]}%`,
                                                } as CSSProperties
                                            }
                                        />
                                        <figcaption className="text-small text-ink-2">
                                            {crop.label}{' '}
                                            <span className="text-ink-3">
                                                {crop.shape}
                                            </span>
                                        </figcaption>
                                    </figure>
                                ))}
                            </div>
                        </section>
                        <Field htmlFor="image-alt" label={__('Alt text')}>
                            <Textarea
                                id="image-alt"
                                rows={2}
                                value={alt}
                                disabled={!item.can.edit}
                                placeholder={__(
                                    'Describe what the picture shows',
                                )}
                                onChange={(event) => {
                                    setAlt(event.target.value);
                                    saver.set('alt', event.target.value);
                                }}
                            />
                        </Field>
                    </Panel>
                }
            >
                <div className="grid gap-4">
                    {item.previewUrl ? (
                        <FocalPoint
                            src={item.previewUrl}
                            name={name}
                            value={focal}
                            disabled={!item.can.edit}
                            onChange={moveTo}
                            onLeave={() => undefined}
                        />
                    ) : (
                        <p className="text-small text-ink-2">
                            {__('Preparing the preview…')}
                        </p>
                    )}
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="inline-flex items-center">
                            <Button type="button" variant="neutral" disabled>
                                <Crop aria-hidden="true" />
                                {__('Crop')}
                            </Button>
                            <SoonTag later />
                        </span>
                        <span className="inline-flex items-center">
                            <Button type="button" variant="neutral" disabled>
                                <RotateCw aria-hidden="true" />
                                {__('Rotate')}
                            </Button>
                            <SoonTag later />
                        </span>
                        <span className="inline-flex items-center">
                            <Button type="button" variant="neutral" disabled>
                                <FlipHorizontal aria-hidden="true" />
                                {__('Flip')}
                            </Button>
                            <SoonTag later />
                        </span>
                        <span className="flex-1" />
                        <Button
                            type="button"
                            variant="ghost"
                            disabled={!item.can.edit}
                            onClick={() => moveTo([50, 50])}
                        >
                            {__('Center the focal point')}
                        </Button>
                    </div>
                </div>
            </DetailPage>
        </>
    );
}
