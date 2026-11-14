<?php

declare(strict_types=1);

namespace App\Domain\Import\Convert\Blocks;

use App\Domain\Import\Convert\BlockContext;
use App\Domain\Import\Convert\BlockMapping;
use App\Domain\Import\Convert\Html\HtmlFragment;
use App\Domain\Import\Convert\Html\Nodes;
use App\Domain\Import\Convert\ParsedBlock;
use Illuminate\Support\Str;

/**
 * Embed blocks, and the core-embed blocks of WordPress before 5.6, as Embed
 * cards: the address, framed when a provider in
 * config/embeds.php matches it, otherwise shown as a link.
 */
final readonly class Embeds implements BlockMapping
{
    /** The longest address an Embed card keeps (EmbedCard's rule). */
    private const int LONGEST = 2048;

    public function blocks(): array
    {
        return ['core/embed', 'core-embed/*'];
    }

    public function convert(ParsedBlock $block, BlockContext $context): array
    {
        $root = $context->fragment($block->innerHtml);
        $wrapper = $root->querySelector('.wp-block-embed__wrapper');
        $url = $block->attr('url');
        $url = trim(is_string($url) ? $url : ($wrapper === null ? '' : HtmlFragment::text($wrapper)));
        $caption = $root->querySelector('figcaption');
        $slug = $block->attr('providerNameSlug');

        if (! is_string($slug) && str_starts_with((string) $block->name, 'core-embed/')) {
            $slug = substr((string) $block->name, strlen('core-embed/'));
        }

        return self::card($context, $url, $caption === null ? null : $context->captionOf($caption), is_string($slug) ? $slug : null);
    }

    /**
     * An Embed card for an address: framed when a provider in
     * config/embeds.php matches it, otherwise a link with the provider's
     * name when it has one. The old site's own address is never kept
     * (I-1702): it becomes a paragraph linking to it.
     *
     * @return list<array<string, mixed>>
     */
    public static function card(BlockContext $context, string $url, ?string $caption = null, ?string $slug = null): array
    {
        $url = trim($url);

        if (strlen($url) > self::LONGEST || ! Str::isUrl($url, ['http', 'https'])) {
            $context->report->count('embed.no-address');

            return [];
        }

        if ($context->site->isOwn($url)) {
            $path = (string) parse_url($url, PHP_URL_PATH);
            $words = $path === '' ? '/' : $path;
            $href = $context->links->href($url);

            return [Nodes::paragraph([['type' => 'text', 'text' => $words, ...($href === null ? [] : ['marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]])]])];
        }

        [$kind, $provider] = self::provider($url, $slug);

        return [['type' => 'embed', 'attrs' => [
            'id' => $context->newId(),
            'url' => $url,
            'kind' => $kind,
            'provider' => $provider,
            'title' => null,
            'html' => null,
            'thumbnailMediaId' => null,
            'caption' => $caption,
        ]]];
    }

    /**
     * The card's kind and provider: a framed provider's, else a link with
     * WordPress's provider name.
     *
     * @return array{0: string, 1: string|null}
     */
    private static function provider(string $url, ?string $slug): array
    {
        foreach ((array) config('embeds.providers', []) as $key => $provider) {
            if (is_array($provider) && is_string($provider['match'] ?? null) && preg_match($provider['match'], $url) === 1) {
                return [is_string($provider['kind'] ?? null) ? $provider['kind'] : 'link', (string) $key];
            }
        }

        return ['link', is_string($slug) && $slug !== '' ? mb_substr($slug, 0, 100) : null];
    }
}
