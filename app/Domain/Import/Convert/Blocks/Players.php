<?php

declare(strict_types=1);

namespace App\Domain\Import\Convert\Blocks;

use App\Domain\Import\Convert\BlockContext;
use App\Domain\Import\Convert\BlockMapping;
use App\Domain\Import\Convert\Html\HtmlFragment;
use App\Domain\Import\Convert\Media\PlayerCards;
use App\Domain\Import\Convert\ParsedBlock;
use Dom\Element;

/**
 * Core's audio, video and playlist blocks as Audio and Video cards
 * (I-1705): a file by its attachment ID, else its address; a playlist as
 * one card for each of its tracks.
 */
final readonly class Players implements BlockMapping
{
    public function blocks(): array
    {
        return ['core/audio', 'core/video', 'core/playlist', 'core/playlist-track'];
    }

    public function convert(ParsedBlock $block, BlockContext $context): array
    {
        return match ($block->name) {
            'core/playlist' => $this->playlist($block, $context),
            'core/playlist-track' => $this->track($block, $context, 'audio'),
            'core/video' => $this->video($block, $context),
            default => $this->audio($block, $context),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function audio(ParsedBlock $block, BlockContext $context): array
    {
        $root = HtmlFragment::body($block->innerHtml);
        $caption = $root->querySelector('figcaption');

        return PlayerCards::audio($context, $block->intAttr('id'), $this->source($root->querySelector('audio'), $block), $caption === null ? null : $context->captionOf($caption));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function video(ParsedBlock $block, BlockContext $context): array
    {
        $root = HtmlFragment::body($block->innerHtml);
        $video = $root->querySelector('video');
        $caption = $root->querySelector('figcaption');
        $tracks = [];

        foreach (is_array($block->attr('tracks')) ? $block->attr('tracks') : [] as $track) {
            if (! is_array($track)) {
                continue;
            }

            $kind = strtolower(trim((string) ($track['kind'] ?? '')));
            $tracks[] = [
                'src' => (string) ($track['src'] ?? ''),
                'kind' => $kind === '' ? 'subtitles' : $kind,
                'language' => trim((string) ($track['srcLang'] ?? '')),
                'label' => (string) ($track['label'] ?? ''),
                'default' => ($track['default'] ?? false) === true,
            ];
        }

        $poster = (string) $video?->getAttribute('poster');

        return PlayerCards::video(
            $context,
            $block->intAttr('id'),
            $this->source($video, $block),
            $caption === null ? null : $context->captionOf($caption),
            $poster !== '' ? $poster : (is_string($block->attr('poster')) ? (string) $block->attr('poster') : ''),
            $block->intAttr('width'),
            $block->intAttr('height'),
            $video?->hasAttribute('loop') ?? false,
            $video?->hasAttribute('autoplay') ?? false,
            $video?->hasAttribute('muted') ?? false,
            $tracks,
        );
    }

    /**
     * One card for each track of a playlist, of the track's type or the
     * playlist's.
     *
     * @return list<array<string, mixed>>
     */
    private function playlist(ParsedBlock $block, BlockContext $context): array
    {
        $type = is_string($block->attr('type')) ? strtolower((string) $block->attr('type')) : 'audio';
        $nodes = [];

        foreach ($block->innerBlocks as $track) {
            array_push($nodes, ...($track->name === 'core/playlist-track' ? $this->track($track, $context, $type) : $context->blocks([$track])));
        }

        return $nodes;
    }

    /**
     * A playlist track's card, by its `id`, else its `src`.
     *
     * @return list<array<string, mixed>>
     */
    private function track(ParsedBlock $block, BlockContext $context, string $type): array
    {
        $type = is_string($block->attr('type')) ? strtolower((string) $block->attr('type')) : $type;
        $src = is_string($block->attr('src')) ? trim((string) $block->attr('src')) : '';

        return str_starts_with($type, 'video')
            ? PlayerCards::video($context, $block->intAttr('id'), $src)
            : PlayerCards::audio($context, $block->intAttr('id'), $src);
    }

    /**
     * The address of a player: its `src`, else its first `<source>`'s, else
     * the block's own `src`.
     */
    private function source(?Element $player, ParsedBlock $block): string
    {
        $src = trim((string) $player?->getAttribute('src'));

        if ($src === '') {
            $src = trim((string) $player?->querySelector('source[src]')?->getAttribute('src'));
        }

        return $src !== '' ? $src : (is_string($block->attr('src')) ? trim((string) $block->attr('src')) : '');
    }
}
