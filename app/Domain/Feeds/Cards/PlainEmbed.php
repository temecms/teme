<?php

/**
 * The embed card in a feed: a link to the original, worded by its kind and provider, since a feed reader runs no frame.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Feeds\Cards;

use App\Domain\Cards\RenderContext;
use App\Domain\Feeds\FeedCardRenderer;

final readonly class PlainEmbed implements FeedCardRenderer
{
    private const array PROVIDERS = ['youtube' => 'YouTube', 'vimeo' => 'Vimeo', 'spotify' => 'Spotify'];

    public function card(): string
    {
        return 'embed';
    }

    public function html(array $attrs, RenderContext $context): string
    {
        $url = (string) ($attrs['url'] ?? '');

        if (preg_match('~^https?://\S+$~i', $url) !== 1) {
            return '';
        }

        $kind = (string) ($attrs['kind'] ?? 'link');
        $title = trim((string) ($attrs['title'] ?? ''));
        $caption = trim((string) ($attrs['caption'] ?? ''));
        $text = $kind === 'link'
            ? ($title === '' ? $url : $title)
            : $this->verb($kind).' '.$this->provider((string) ($attrs['provider'] ?? ''), $url).($title === '' ? '' : ': '.$title);

        return '<figure><p><a href="'.e($url).'">'.e($text).'</a></p>'.($caption === '' ? '' : '<figcaption>'.e($caption).'</figcaption>').'</figure>';
    }

    private function verb(string $kind): string
    {
        return match ($kind) {
            'video' => 'Watch on',
            'audio' => 'Listen on',
            default => 'See it on',
        };
    }

    private function provider(string $provider, string $url): string
    {
        return self::PROVIDERS[strtolower($provider)] ?? (string) preg_replace('/^www\./i', '', (string) parse_url($url, PHP_URL_HOST));
    }
}
