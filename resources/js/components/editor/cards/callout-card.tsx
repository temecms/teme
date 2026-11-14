import type { ReactNodeViewProps } from '@tiptap/react';
import { SelectField } from '@/components/ui/select-field';
import { cn } from '@/lib/utils';
import { CardField, CardFrame } from './card-frame';
import { optionsFor, useCardEnvironment } from './card-environment';
import type { CardSettingsProps } from './card-environment';

const TONES: Record<string, string> = {
    note: 'border-l-border',
    tip: 'border-l-success',
    warning: 'border-l-warning',
};

/**
 * A callout: a short aside in a tone. Its text is written in place; the
 * tone is in the settings.
 */
export function CalloutCard({
    node,
    selected,
    updateAttributes,
    deleteNode,
}: ReactNodeViewProps) {
    const tone = (node.attrs.tone as string | null) ?? 'note';
    const text = (node.attrs.text as string | null) ?? '';

    return (
        <CardFrame
            label="Callout"
            selected={selected}
            warning={text.trim() === '' ? { label: 'Needs text' } : null}
            onRemove={deleteNode}
            settings={
                <CalloutSettings attrs={node.attrs} update={updateAttributes} />
            }
        >
            <textarea
                aria-label="Callout text"
                rows={2}
                value={text}
                placeholder="What the reader should not miss"
                onChange={(event) =>
                    updateAttributes({ text: event.target.value })
                }
                className={cn(
                    'bg-muted focus-visible:ring-ring field-sizing-content w-full resize-none rounded-sm border-l-4 px-3 py-2 text-[15px] outline-none focus-visible:ring-2',
                    TONES[tone] ?? TONES.note,
                )}
            />
        </CardFrame>
    );
}

/** A callout card's settings, in the card's panel or the rail's inspector. */
export function CalloutSettings({
    attrs: raw,
    update: updateAttributes,
}: CardSettingsProps) {
    const { manifest } = useCardEnvironment();
    const tone = (raw.tone as string | null) ?? 'note';

    return (
        <CardField label="Tone">
            {(props) => (
                <SelectField
                    {...props}
                    value={tone}
                    onValueChange={(next) => updateAttributes({ tone: next })}
                    options={optionsFor(manifest, 'callout', 'tone')}
                    className="h-[34px] w-full max-w-40"
                />
            )}
        </CardField>
    );
}
