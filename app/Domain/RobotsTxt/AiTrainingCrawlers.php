<?php

/**
 * The crawlers "Ask AI training crawlers not to use this site" names
 * (I-707): those that gather pages to train AI models, never those that
 * index for search or fetch a page a reader asked for, so the switch leaves
 * the site in AI search and link previews. Cloudflare's managed list first,
 * then each one its maker documents as a training crawler; every entry
 * names its source, checked by hand for each release, never fetched at run
 * time.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\RobotsTxt;

final class AiTrainingCrawlers
{
    /** @var list<array{agent: string, operator: string, source: string}> */
    public const array LIST = [
        ['agent' => 'Amazonbot', 'operator' => 'Amazon', 'source' => 'https://developer.amazon.com/amazonbot'],
        ['agent' => 'Applebot-Extended', 'operator' => 'Apple', 'source' => 'https://support.apple.com/en-us/119829'],
        // ByteDance documents no crawler; Cloudflare's list names it.
        ['agent' => 'Bytespider', 'operator' => 'ByteDance', 'source' => 'https://developers.cloudflare.com/bots/additional-configurations/managed-robots-txt/'],
        ['agent' => 'CCBot', 'operator' => 'Common Crawl', 'source' => 'https://commoncrawl.org/ccbot'],
        ['agent' => 'ClaudeBot', 'operator' => 'Anthropic', 'source' => 'https://support.claude.com/en/articles/8896518'],
        ['agent' => 'Google-Extended', 'operator' => 'Google', 'source' => 'https://developers.google.com/search/docs/crawling-indexing/google-common-crawlers'],
        ['agent' => 'GPTBot', 'operator' => 'OpenAI', 'source' => 'https://developers.openai.com/api/docs/bots'],
        ['agent' => 'meta-externalagent', 'operator' => 'Meta', 'source' => 'https://developers.facebook.com/docs/sharing/webmasters/web-crawlers'],
        ['agent' => 'MistralAI-Training', 'operator' => 'Mistral AI', 'source' => 'https://docs.mistral.ai/robots'],
        ['agent' => 'AI2Bot', 'operator' => 'Ai2', 'source' => 'https://allenai.org/crawler'],
    ];

    /**
     * Each crawler's user agent, in the list's order.
     *
     * @return list<string>
     */
    public static function agents(): array
    {
        return array_column(self::LIST, 'agent');
    }
}
