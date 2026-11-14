import type { ReactNodeViewProps } from '@tiptap/react';
import { SelectField } from '@/components/ui/select-field';
import { __ } from '@/lib/i18n';
import { CardField, CardFrame, TextSetting } from './card-frame';
import { optionsFor, useCardEnvironment } from './card-environment';
import type { CardSettingsProps } from './card-environment';

/**
 * A code card (stored as `snippet`, B-137): the code, written in place in a
 * monospace field, with its language and caption in the settings. The site
 * highlights it; the editor shows it plain.
 */
export function CodeCard({
    node,
    selected,
    updateAttributes,
    deleteNode,
}: ReactNodeViewProps) {
    const code = (node.attrs.code as string | null) ?? '';
    const caption = node.attrs.caption as string | null;

    return (
        <CardFrame
            label={__('Code')}
            selected={selected}
            warning={code === '' ? { label: __('Needs code') } : null}
            onRemove={deleteNode}
            settings={
                <CodeSettings attrs={node.attrs} update={updateAttributes} />
            }
        >
            <textarea
                aria-label={__('Code')}
                rows={4}
                spellCheck={false}
                value={code}
                onChange={(event) =>
                    updateAttributes({ code: event.target.value })
                }
                className="bg-surface-2 focus-visible:ring-ring field-sizing-content w-full resize-none rounded-sm px-3 py-2 font-mono text-[13px] outline-none focus-visible:ring-2"
            />
            {caption && (
                <p className="text-ink-2 mt-1.5 text-[13px]">{caption}</p>
            )}
        </CardFrame>
    );
}

/** A code card's settings, in the card's panel or the rail's inspector. */
export function CodeSettings({
    attrs: raw,
    update: updateAttributes,
}: CardSettingsProps) {
    const { manifest } = useCardEnvironment();
    const node = { attrs: raw };
    const caption = raw.caption as string | null;

    return (
        <>
            <CardField label={__('Language')}>
                {(props) => (
                    <SelectField
                        {...props}
                        value={
                            (node.attrs.language as string | null) ?? 'plain'
                        }
                        onValueChange={(language) =>
                            updateAttributes({ language })
                        }
                        options={optionsFor(manifest, 'snippet', 'language')}
                        className="h-[34px] w-full max-w-48"
                    />
                )}
            </CardField>
            <TextSetting
                label={__('Caption')}
                value={caption}
                onChange={(next) => updateAttributes({ caption: next })}
            />
        </>
    );
}
