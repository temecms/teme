import type { ReactNodeViewProps } from '@tiptap/react';
import { SelectField } from '@/components/ui/select-field';
import { cn } from '@/lib/utils';
import { __ } from '@/lib/i18n';
import { CardField, CardFrame } from './card-frame';
import { optionsFor, useCardEnvironment } from './card-environment';
import type { CardSettingsProps } from './card-environment';

const TONES: Record<string, string> = {
    note: 'border-l-line',
    tip: 'border-l-ok',
    warning: 'border-l-warn',
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
            label={__('Callout')}
            selected={selected}
            warning={text.trim() === '' ? { label: __('Needs text') } : null}
            onRemove={deleteNode}
            settings={
                <CalloutSettings attrs={node.attrs} update={updateAttributes} />
            }
        >
            <textarea
                aria-label={__('Callout text')}
                rows={2}
                value={text}
                placeholder={__('What the reader should not miss')}
                onChange={(event) =>
                    updateAttributes({ text: event.target.value })
                }
                className={cn(
                    'bg-surface-2 focus-visible:ring-ring text-body field-sizing-content w-full resize-none rounded-sm border-l-4 px-3 py-2 outline-none focus-visible:ring-2',
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
        <CardField label={__('Tone')}>
            {(props) => (
                <SelectField
                    {...props}
                    value={tone}
                    onValueChange={(next) => updateAttributes({ tone: next })}
                    options={optionsFor(manifest, 'callout', 'tone')}
                    className="h-8.5 w-full max-w-40"
                />
            )}
        </CardField>
    );
}
