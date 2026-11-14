import { NodeViewWrapper } from '@tiptap/react';
import type { ReactNodeViewProps } from '@tiptap/react';
import type { ComponentType } from 'react';
import { AudioCard, AudioSettings } from './audio-card';
import { ButtonCard, ButtonSettings } from './button-card';
import { CalloutCard, CalloutSettings } from './callout-card';
import type { CardSettingsProps } from './card-environment';
import { CodeCard, CodeSettings } from './code-card';
import { EmbedCard, EmbedSettings } from './embed-card';
import { GalleryCard, GallerySettings } from './gallery-card';
import { ImageCard, ImageSettings } from './image-card';
import {
    ImportedContentCard,
    ImportedContentSettings,
} from './imported-content-card';
import { VideoCard, VideoSettings } from './video-card';

/**
 * The view each card draws in the editor, by card name. A card with no view
 * here, or one this build does not know, is drawn by UnknownCard and keeps
 * its attributes untouched: a document must survive a round trip rather than
 * lose a card silently, as the server refuses rather than drops (1.5b).
 */
export const cardViews: Record<string, ComponentType<ReactNodeViewProps>> = {
    image: ImageCard,
    gallery: GalleryCard,
    embed: EmbedCard,
    callout: CalloutCard,
    snippet: CodeCard,
    button: ButtonCard,
    audio: AudioCard,
    video: VideoCard,
    imported: ImportedContentCard,
};

/**
 * Each card's settings, which its own panel and the rail's inspector both
 * draw. A card with none here is edited in place only.
 */
export const cardSettings: Record<string, ComponentType<CardSettingsProps>> = {
    image: ImageSettings,
    gallery: GallerySettings,
    embed: EmbedSettings,
    callout: CalloutSettings,
    snippet: CodeSettings,
    button: ButtonSettings,
    audio: AudioSettings,
    video: VideoSettings,
    imported: ImportedContentSettings,
};

export function UnknownCard({ node, selected }: ReactNodeViewProps) {
    return (
        <NodeViewWrapper
            data-card={node.type.name}
            className={
                'border-line bg-surface-2 text-ink-2 my-4 rounded-md border p-4 text-[13px]' +
                (selected ? ' ring-ring ring-2' : '')
            }
            contentEditable={false}
        >
            <span className="text-ink font-semibold">{node.type.name}</span>{' '}
            card
        </NodeViewWrapper>
    );
}
