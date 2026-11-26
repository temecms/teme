<?php

/**
 * Something from another site: a video, a track, a post or a link. The editor
 * fetches its details (I-164); this class only renders. A video, audio or
 * social embed is drawn in a sandboxed frame whose address comes from the
 * allow-list in config/embeds.php. The provider's own markup, kept in `html`,
 * is never printed.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cards\Types;

use App\Domain\Cards\CardAttribute;
use App\Domain\Cards\CardMenuEntry;
use App\Domain\Cards\CardReference;
use App\Domain\Cards\CardType;
use App\Domain\Cards\ChangeList;
use App\Domain\Cards\DocumentSchema;
use App\Domain\Cards\RenderContext;
use App\Domain\Media\MediaUrls;

final class EmbedCard implements CardType
{
    private const string SANDBOX = 'allow-scripts allow-same-origin allow-presentation';

    public function name(): string
    {
        return 'embed';
    }

    public function label(): string
    {
        return 'Embed';
    }

    public function description(): string
    {
        return 'A video, a track, a post or a link from another site, framed safely or shown as a bookmark.';
    }

    public function since(): string
    {
        return '0.1.0';
    }

    public function attributes(): array
    {
        return [
            'id' => new CardAttribute('id', ['required', 'string', 'regex:'.DocumentSchema::NODE_ID]),
            'url' => new CardAttribute('url', ['required', 'url:http,https', 'max:2048']),
            'kind' => new CardAttribute('kind', ['in:video,audio,social,link'], 'link'),
            'provider' => new CardAttribute('provider', ['nullable', 'string', 'max:100']),
            'title' => new CardAttribute('title', ['nullable', 'string', 'max:255']),
            'html' => new CardAttribute('html', ['nullable', 'string', 'max:20000']),
            'thumbnailMediaId' => new CardAttribute('thumbnailMediaId', ['nullable', 'string', 'size:26']),
            'caption' => new CardAttribute('caption', ['nullable', 'string', 'max:2000']),
        ];
    }

    public function menu(): CardMenuEntry
    {
        return new CardMenuEntry('Embed', 'Cards', ['embed', 'video', 'youtube', 'vimeo', 'audio', 'link', 'bookmark'], 'embed');
    }

    public function renderHtml(array $attrs, RenderContext $context): string
    {
        $url = (string) ($attrs['url'] ?? '');
        $title = trim((string) ($attrs['title'] ?? ''));
        $caption = trim((string) ($attrs['caption'] ?? ''));
        $figcaption = $caption === '' ? '' : '<figcaption>'.e($caption).'</figcaption>';
        $kind = (string) ($attrs['kind'] ?? 'link');
        $frame = $kind === 'link' ? null : $this->frameFor($url);

        if ($frame !== null) {
            return sprintf(
                '<figure class="%s"><iframe src="%s" loading="lazy" sandbox="%s" referrerpolicy="strict-origin-when-cross-origin" title="%s"></iframe>%s</figure>',
                e('card-embed card-embed--'.$kind), e($frame), self::SANDBOX, e($title === '' ? 'Embedded content' : $title), $figcaption,
            );
        }

        // A link, or an embed from a provider not on the list: a bookmark.
        $host = (string) parse_url($url, PHP_URL_HOST);
        $thumbnail = $this->thumbnail($attrs['thumbnailMediaId'] ?? null, $context);

        return sprintf(
            '<figure class="card-embed card-embed--link"><a href="%s" rel="noopener">%s<span class="card-embed__title">%s</span><span class="card-embed__host">%s</span></a>%s</figure>',
            e($url),
            $thumbnail === null ? '' : '<img src="'.e($thumbnail).'" alt="" loading="lazy" decoding="async">',
            e($title === '' ? $url : $title),
            e($host),
            $figcaption,
        );
    }

    public function plainText(array $attrs): string
    {
        return trim(implode(' ', array_filter([$attrs['title'] ?? '', $attrs['caption'] ?? ''])));
    }

    public function references(array $attrs): array
    {
        $thumbnail = $attrs['thumbnailMediaId'] ?? null;

        return is_string($thumbnail) && $thumbnail !== '' ? [CardReference::media($thumbnail)] : [];
    }

    public function validate(array $attrs): void {}

    /**
     * The frame address for a URL whose provider is on the allow-list, or
     * null when none matches.
     */
    private function frameFor(string $url): ?string
    {
        foreach ((array) config('embeds.providers', []) as $provider) {
            if (preg_match((string) $provider['match'], $url, $groups) === 1) {
                return preg_replace_callback('/\$(\d)/', fn (array $m): string => rawurlencode($groups[(int) $m[1]] ?? ''), (string) $provider['frame']);
            }
        }

        return null;
    }

    /**
     * The address of the stored thumbnail, or null when there is none or it
     * has gone.
     */
    private function thumbnail(mixed $mediaId, RenderContext $context): ?string
    {
        $item = is_string($mediaId) ? ($context->media[$mediaId] ?? null) : null;

        return $item === null ? null : MediaUrls::variant($item['variants'], mediaId: $mediaId);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function describeChange(array $before, array $after): string
    {
        // The fetched provider, title, markup and kind follow the address, so
        // they are not named separately.
        $parts = [];

        foreach (['url' => 'embed replaced', 'thumbnailMediaId' => 'thumbnail changed', 'caption' => 'caption changed'] as $key => $words) {
            if (ChangeList::differs($before, $after, $key)) {
                $parts[] = $words;
            }
        }

        return ChangeList::line($this->label(), $parts);
    }
}
