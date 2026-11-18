import type { ReactNodeViewProps } from '@tiptap/react';
import { ImagePlus } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { __ } from '@/lib/i18n';
import { largest, variant } from '@/lib/media-urls';
import { ulid } from '@/lib/ulid';
import { useMediaPicker } from '../pick-media';
import { CardField, CardFrame, TextSetting } from './card-frame';
import { useCardEnvironment, useUploadTarget } from './card-environment';
import type { CardSettingsProps } from './card-environment';
import { blankProse, CardProse } from './card-prose';
import { AddEntry, EntryTools, moved } from './faq-card';

/** The most steps one card holds, as StepsCard::MOST_STEPS says. */
const MOST_STEPS = 50;

/** The most materials, and the most tools, as StepsCard::MOST_THINGS says. */
const MOST_THINGS = 30;

type Step = {
    id: string;
    title: string;
    body: unknown;
    mediaId: string | null;
};

/** The card's steps as far as they can be read. */
function stepsOf(value: unknown): Step[] {
    return Array.isArray(value)
        ? value.flatMap((step: unknown) => {
              if (step === null || typeof step !== 'object') {
                  return [];
              }

              const read = step as Partial<Step>;

              return [
                  {
                      id: String(read.id ?? ''),
                      title: typeof read.title === 'string' ? read.title : '',
                      body: read.body ?? null,
                      mediaId:
                          typeof read.mediaId === 'string'
                              ? read.mediaId
                              : null,
                  },
              ];
          })
        : [];
}

/** A new, empty step. */
function newStep(): Step {
    return { id: ulid(), title: '', body: blankProse(), mediaId: null };
}

/** An ISO 8601 duration's days, hours and minutes, as StepsCard::DURATION reads it. */
export function durationParts(value: unknown): {
    hours: number;
    minutes: number;
} {
    const match =
        typeof value === 'string'
            ? /^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?)?$/.exec(value)
            : null;

    return {
        hours: Number(match?.[1] ?? 0) * 24 + Number(match?.[2] ?? 0),
        minutes: Number(match?.[3] ?? 0),
    };
}

/** Hours and minutes as an ISO 8601 duration; null for none. */
export function durationOf(hours: number, minutes: number): string | null {
    const h = Math.max(0, Math.floor(hours));
    const m = Math.max(0, Math.floor(minutes));

    if (h === 0 && m === 0) {
        return null;
    }

    return `PT${h > 0 ? `${h}H` : ''}${m > 0 ? `${m}M` : ''}`;
}

/** Lines written one a line, as the card keeps them: trimmed, none empty. */
export function linesOf(text: string): string[] {
    return text
        .split('\n')
        .map((line) => line.trim().slice(0, 200))
        .filter((line) => line !== '')
        .slice(0, MOST_THINGS);
}

/** A list the card keeps, as text written one a line. */
function textOf(value: unknown): string {
    return Array.isArray(value)
        ? value.filter((line) => typeof line === 'string').join('\n')
        : '';
}

/**
 * A Steps card: its numbered steps written in place, each a title, short
 * rich text and an optional image; the summary, the time and what it
 * needs are in its settings. A new card starts with one empty step.
 */
export function StepsCard({
    node,
    selected,
    updateAttributes,
    deleteNode,
}: ReactNodeViewProps) {
    const { media, addMedia } = useCardEnvironment();
    const uploadedFor = useUploadTarget();
    const steps = stepsOf(node.attrs.steps);
    const baseId = useId();
    const { open, picker } = useMediaPicker();
    const untitled = steps.some((step) => step.title.trim() === '');

    // A new card, with no steps yet, starts with one to write.
    useEffect(() => {
        if (stepsOf(node.attrs.steps).length === 0) {
            updateAttributes({ steps: [newStep()] });
        }
    }, [node.attrs.steps, updateAttributes]);

    const write = (next: Step[]) => updateAttributes({ steps: next });
    const change = (index: number, patch: Partial<Step>) =>
        write(
            steps.map((step, at) =>
                at === index ? { ...step, ...patch } : step,
            ),
        );
    const chooseImage = (index: number) =>
        open({
            caller: 'choose',
            multi: false,
            action: __('Use this image'),
            uploadedFor,
            onInsert: (ids) => {
                void addMedia(ids);
                change(index, { mediaId: ids[0] ?? null });
            },
        });

    return (
        <>
            <CardFrame
                label={__('Steps')}
                selected={selected}
                warning={
                    untitled ? { label: __('Every step needs a title') } : null
                }
                onRemove={deleteNode}
                preview={steps[0]?.title ?? ''}
                settings={
                    <StepsSettings
                        attrs={node.attrs}
                        update={updateAttributes}
                    />
                }
            >
                <div className="grid gap-4 px-3 pb-3">
                    {typeof node.attrs.summary === 'string' &&
                        node.attrs.summary !== '' && (
                            <p className="text-ink-2 text-small">
                                {node.attrs.summary}
                            </p>
                        )}
                    <ol className="grid gap-4">
                        {steps.map((step, index) => {
                            const titleId = `${baseId}-${step.id}`;
                            const item =
                                step.mediaId !== null
                                    ? media[step.mediaId]
                                    : undefined;
                            const name =
                                step.title.trim() === ''
                                    ? __('step :number', { number: index + 1 })
                                    : step.title;

                            return (
                                <li
                                    key={step.id}
                                    className="border-line grid gap-2 border-b pb-4 last:border-b-0 last:pb-0"
                                >
                                    <div className="flex items-center gap-2">
                                        <span
                                            aria-hidden
                                            className="text-ink-3 w-5 shrink-0 font-semibold"
                                        >
                                            {index + 1}
                                        </span>
                                        <label
                                            htmlFor={titleId}
                                            className="sr-only"
                                        >
                                            {__('Step :number title', {
                                                number: index + 1,
                                            })}
                                        </label>
                                        <Input
                                            id={titleId}
                                            value={step.title}
                                            maxLength={200}
                                            placeholder={__('What to do')}
                                            className="font-semibold"
                                            onChange={(event) =>
                                                change(index, {
                                                    title: event.target.value,
                                                })
                                            }
                                        />
                                        <EntryTools
                                            name={name}
                                            index={index}
                                            count={steps.length}
                                            onMove={(to) =>
                                                write(moved(steps, index, to))
                                            }
                                            onRemove={() =>
                                                write(
                                                    steps.filter(
                                                        (_, at) => at !== index,
                                                    ),
                                                )
                                            }
                                        />
                                    </div>
                                    {item !== undefined && (
                                        <img
                                            src={
                                                variant(
                                                    item.variants,
                                                    'w640',
                                                ) ??
                                                largest(item.variants) ??
                                                ''
                                            }
                                            alt={item.alt ?? ''}
                                            width={item.width}
                                            height={item.height}
                                            className="h-auto max-h-48 w-auto rounded-sm"
                                        />
                                    )}
                                    <CardProse
                                        value={step.body}
                                        label={__('Step :number text', {
                                            number: index + 1,
                                        })}
                                        placeholder={__('How to do it')}
                                        onChange={(body) =>
                                            change(index, { body })
                                        }
                                    />
                                    <div className="flex gap-1">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="xs"
                                            onClick={() => chooseImage(index)}
                                        >
                                            <ImagePlus />
                                            {step.mediaId === null
                                                ? __('Add an image')
                                                : __('Replace the image')}
                                        </Button>
                                        {step.mediaId !== null && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="xs"
                                                onClick={() =>
                                                    change(index, {
                                                        mediaId: null,
                                                    })
                                                }
                                            >
                                                {__('Remove the image')}
                                            </Button>
                                        )}
                                    </div>
                                </li>
                            );
                        })}
                    </ol>
                    <AddEntry
                        label={__('Add a step')}
                        disabled={steps.length >= MOST_STEPS}
                        onAdd={() => write([...steps, newStep()])}
                    />
                </div>
            </CardFrame>
            {picker}
        </>
    );
}

/** A Steps card's settings: the summary, the total time and what it needs. */
export function StepsSettings({ attrs, update }: CardSettingsProps) {
    const { hours, minutes } = durationParts(attrs.totalTime);
    const [materials, setMaterials] = useState(textOf(attrs.materials));
    const [tools, setTools] = useState(textOf(attrs.tools));

    return (
        <div className="grid gap-4">
            <TextSetting
                label={__('Summary')}
                multiline
                value={attrs.summary}
                onChange={(summary) =>
                    update({ summary: summary === '' ? null : summary })
                }
            />
            <CardField label={__('Total time')}>
                {(props) => (
                    <div className="flex items-center gap-2">
                        <Input
                            {...props}
                            type="number"
                            min={0}
                            inputMode="numeric"
                            aria-label={__('Hours')}
                            className="w-20"
                            value={hours === 0 ? '' : String(hours)}
                            onChange={(event) =>
                                update({
                                    totalTime: durationOf(
                                        Number(event.target.value),
                                        minutes,
                                    ),
                                })
                            }
                        />
                        <span className="text-small text-ink-2">
                            {__('hours')}
                        </span>
                        <Input
                            type="number"
                            min={0}
                            max={59}
                            inputMode="numeric"
                            aria-label={__('Minutes')}
                            className="w-20"
                            value={minutes === 0 ? '' : String(minutes)}
                            onChange={(event) =>
                                update({
                                    totalTime: durationOf(
                                        hours,
                                        Number(event.target.value),
                                    ),
                                })
                            }
                        />
                        <span className="text-small text-ink-2">
                            {__('minutes')}
                        </span>
                    </div>
                )}
            </CardField>
            <TextSetting
                label={__('What you’ll need')}
                hint={__('One a line.')}
                multiline
                value={materials}
                onChange={(text) => {
                    setMaterials(text);
                    update({ materials: linesOf(text) });
                }}
            />
            <TextSetting
                label={__('Tools')}
                hint={__('One a line.')}
                multiline
                value={tools}
                onChange={(text) => {
                    setTools(text);
                    update({ tools: linesOf(text) });
                }}
            />
        </div>
    );
}
