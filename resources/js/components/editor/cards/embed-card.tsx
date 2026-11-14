import type { ReactNodeViewProps } from '@tiptap/react';
import { SelectField } from '@/components/ui/select-field';
import { variant } from '@/lib/media-urls';
import type { CardManifest } from '@/types';
import { CardField, CardFrame, TextSetting } from './card-frame';
import { optionsFor, useCardEnvironment } from './card-environment';
import type { CardSettingsProps } from './card-environment';

type EmbedAttrs = {
    url: string | null;
    kind: string | null;
    provider: string | null;
    title: string | null;
    thumbnailMediaId: string | null;
    caption: string | null;
};

/** The provider on the site's list whose pattern matches the address, if any. */
function providerFor(url: string, manifest: CardManifest): string | null {
    return (
        manifest.embedProviders.find((provider) =>
            new RegExp(provider.match).test(url),
        )?.name ?? null
    );
}

/** The host of an address, or the address itself when it has none. */
function hostOf(url: string): string {
    try {
        return new URL(url).hostname;
    } catch {
        return url;
    }
}

/**
 * An embed card: the provider, the title and a thumbnail. It never draws the
 * provider's own markup, in the editor or on the site; the site frames
 * providers on its list and shows anything else as a link. A new card opens
 * its settings to ask for the address.
 */
export function EmbedCard({
    node,
    selected,
    updateAttributes,
    deleteNode,
}: ReactNodeViewProps) {
    const { media, manifest } = useCardEnvironment();
    const attrs = node.attrs as EmbedAttrs;
    const url = attrs.url ?? '';
    const thumbnail = attrs.thumbnailMediaId
        ? media[attrs.thumbnailMediaId]
        : undefined;
    const framed = attrs.kind !== 'link' && providerFor(url, manifest) !== null;

    return (
        <CardFrame
            label="Embed"
            selected={selected}
            warning={url === '' ? { label: 'Needs an address' } : null}
            onRemove={deleteNode}
            defaultOpen={url === ''}
            settings={
                <EmbedSettings attrs={node.attrs} update={updateAttributes} />
            }
        >
            {url === '' ? (
                <p className="text-ink-2 text-[13px]">
                    Paste the address of what to embed.
                </p>
            ) : (
                <div className="flex items-center gap-3">
                    {thumbnail && (
                        <img
                            src={variant(thumbnail.variants) ?? ''}
                            alt=""
                            className="size-16 shrink-0 rounded-sm object-cover"
                        />
                    )}
                    <div className="min-w-0">
                        <p className="truncate text-[14px] font-semibold">
                            {attrs.title || url}
                        </p>
                        <p className="text-ink-2 truncate text-xs">
                            {attrs.provider ?? hostOf(url)} ·{' '}
                            {framed ? 'framed on the site' : 'shown as a link'}
                        </p>
                    </div>
                </div>
            )}
            {attrs.caption && (
                <p className="text-ink-2 mt-1.5 text-[13px]">{attrs.caption}</p>
            )}
        </CardFrame>
    );
}

/** An embed card's settings, in the card's panel or the rail's inspector. */
export function EmbedSettings({
    attrs: raw,
    update: updateAttributes,
}: CardSettingsProps) {
    const { manifest } = useCardEnvironment();
    const attrs = raw as EmbedAttrs;
    const url = attrs.url ?? '';

    return (
        <>
            <TextSetting
                label="Address"
                hint="A video, a track or a post. Addresses from providers the site does not frame show as a link."
                value={url}
                placeholder="https://"
                onChange={(next) =>
                    updateAttributes({
                        url: next,
                        provider: providerFor(next, manifest),
                    })
                }
            />
            <CardField label="Kind">
                {(props) => (
                    <SelectField
                        {...props}
                        value={attrs.kind ?? 'link'}
                        onValueChange={(kind) => updateAttributes({ kind })}
                        options={optionsFor(manifest, 'embed', 'kind')}
                        className="h-[34px] w-full max-w-40"
                    />
                )}
            </CardField>
            <TextSetting
                label="Title"
                hint="Read out for the frame, and shown on a link."
                value={attrs.title}
                onChange={(title) => updateAttributes({ title })}
            />
            <TextSetting
                label="Caption"
                value={attrs.caption}
                onChange={(caption) => updateAttributes({ caption })}
            />
        </>
    );
}
