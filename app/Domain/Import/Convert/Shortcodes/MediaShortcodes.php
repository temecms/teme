<?php

declare(strict_types=1);

namespace App\Domain\Import\Convert\Shortcodes;

use App\Domain\Import\Convert\BlockContext;
use App\Domain\Import\Convert\Html\HtmlFragment;
use App\Domain\Import\Convert\Media\PlayerCards;

/**
 * WordPress's audio, video and playlist shortcodes as Audio and Video cards
 * (I-1705): a file by `src` or its format's attribute, else the first one
 * attached to the item, as WordPress does; a playlist as a card for each
 * file.
 */
final readonly class MediaShortcodes implements ShortcodeMapping
{
    /** The attributes that may hold a sound's address, in the order WordPress tries them. */
    private const array AUDIO = ['src', 'mp3', 'ogg', 'flac', 'm4a', 'wav'];

    /** The attributes that may hold a video's address, in the order WordPress tries them. */
    private const array VIDEO = ['src', 'mp4', 'm4v', 'webm', 'ogv', 'flv'];

    public function shortcodes(): array
    {
        return ['audio', 'video', 'playlist'];
    }

    public function convert(Shortcode $shortcode, BlockContext $context): array
    {
        return match ($shortcode->name) {
            'audio' => $this->audio($shortcode, $context),
            'video' => $this->video($shortcode, $context),
            default => $this->playlist($shortcode, $context),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function audio(Shortcode $shortcode, BlockContext $context): array
    {
        [$id, $src] = $this->file($shortcode, $context, self::AUDIO, 'audio');

        return PlayerCards::audio($context, $id, $src);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function video(Shortcode $shortcode, BlockContext $context): array
    {
        [$id, $src] = $this->file($shortcode, $context, self::VIDEO, 'video');
        $tracks = [];

        foreach (iterator_to_array(HtmlFragment::body($shortcode->content ?? '')->querySelectorAll('track')) as $track) {
            $kind = strtolower(trim((string) $track->getAttribute('kind')));
            $tracks[] = [
                'src' => (string) $track->getAttribute('src'),
                'kind' => $kind === '' ? 'subtitles' : $kind,
                'language' => trim((string) $track->getAttribute('srclang')),
                'label' => (string) $track->getAttribute('label'),
                'default' => $track->hasAttribute('default'),
            ];
        }

        $size = fn (string $name): ?int => is_int($found = filter_var($shortcode->attribute($name), FILTER_VALIDATE_INT)) ? $found : null;

        return PlayerCards::video(
            $context,
            $id,
            $src,
            poster: $shortcode->attribute('poster'),
            width: $size('width'),
            height: $size('height'),
            loop: $shortcode->on('loop'),
            autoplay: $shortcode->on('autoplay'),
            muted: $shortcode->on('muted'),
            tracks: $tracks,
        );
    }

    /**
     * One card for each file a playlist names or its item has attached.
     *
     * @return list<array<string, mixed>>
     */
    private function playlist(Shortcode $shortcode, BlockContext $context): array
    {
        $type = strtolower(trim($shortcode->attribute('type'))) === 'video' ? 'video' : 'audio';
        $nodes = [];

        foreach (Galleries::attachments($shortcode, $context, $type) as $id) {
            array_push($nodes, ...($type === 'video' ? PlayerCards::video($context, $id, '') : PlayerCards::audio($context, $id, '')));
        }

        return $nodes;
    }

    /**
     * The attachment and the address of a player: the first attribute that
     * holds an address; with none, the first file of its type attached to
     * the item.
     *
     * @param  list<string>  $attributes
     *
     * @return array{0: int|null, 1: string}
     */
    private function file(Shortcode $shortcode, BlockContext $context, array $attributes, string $type): array
    {
        foreach ($attributes as $attribute) {
            $src = trim($shortcode->attribute($attribute));

            if ($src !== '') {
                return [null, $src];
            }
        }

        $attached = $context->item->id > 0 ? $context->targets->attached($context->item->id, $type) : [];

        return [$attached[0] ?? null, ''];
    }
}
