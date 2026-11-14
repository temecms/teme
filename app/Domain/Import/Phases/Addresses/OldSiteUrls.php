<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases\Addresses;

use App\Domain\Import\Source\SourceReader;
use App\Domain\Import\Source\SourceRefused;
use Illuminate\Support\Facades\DB;

/**
 * The old site's public addresses, from the companion's urls list (I-339):
 * read one kind after another, a page at a time, from a cursor of the kind
 * and the last ID read, by the clash step, the old addresses step and the
 * check.
 */
final readonly class OldSiteUrls
{
    /** Every kind the companion lists, in the order they're read. */
    public const array KINDS = ['post', 'term', 'author', 'attachment_page', 'attachment_file', 'archive'];

    /** Addresses a page. */
    public const int PAGE = 500;

    /** Attachments a page of their files: each lists every size of its file. */
    public const int FILES_PAGE = 20;

    /**
     * The next page of these kinds from $cursor (`kind:afterId`, null for
     * the start): the kind read, its items, and the cursor after the page,
     * null once every kind asked for is read.
     *
     * @param  list<string>  $kinds
     *
     * @return array{kind: string, items: list<array<string, mixed>>, cursor: string|null}
     *
     * @throws SourceRefused
     */
    public function page(SourceReader $reader, array $kinds, ?string $cursor): array
    {
        [$kind, $after] = self::position($kinds, $cursor);

        // The home page and the archives come whole, each with the ID 0.
        if ($kind === 'archive') {
            $answer = $reader->whole('urls', ['kind' => $kind]);
            $listed = is_array($answer['items'] ?? null) ? $answer['items'] : [];
            /** @var list<array<string, mixed>> $items */
            $items = array_values(array_filter($listed, fn (mixed $item): bool => is_array($item) && in_array($item['kind'] ?? $kind, ['home', $kind], true)));

            return ['kind' => $kind, 'items' => $items, 'cursor' => self::after($kinds, $kind)];
        }

        $page = $reader->page('urls', ['kind' => $kind], $after, $kind === 'attachment_file' ? self::FILES_PAGE : self::PAGE);
        $items = array_values(array_filter($page->items, fn (array $item): bool => ($item['kind'] ?? $kind) === $kind));

        return [
            'kind' => $kind,
            'items' => $items,
            'cursor' => $page->nextAfterId === null ? self::after($kinds, $kind) : "{$kind}:{$page->nextAfterId}",
        ];
    }

    /**
     * The path and query of an address on the old site's own host ($host,
     * as bare() gives it), as written there; null for another host's (a
     * CDN's, say) or for no address.
     *
     * @return array{path: string, query: string}|null
     */
    public static function own(string $address, string $host): ?array
    {
        $parts = parse_url(str_starts_with($address, '//') ? 'https:'.$address : $address);

        if (! is_array($parts) || ! isset($parts['host']) || self::bare($parts['host']) !== $host) {
            return null;
        }

        return ['path' => '/'.ltrim($parts['path'] ?? '/', '/'), 'query' => $parts['query'] ?? ''];
    }

    /**
     * A host lower-cased, without www.
     */
    public static function bare(string $host): string
    {
        return (string) preg_replace('/^www\./', '', strtolower($host));
    }

    /**
     * The old site's host, from its source's address, as bare() gives it.
     */
    public static function hostOf(string $sourceId): string
    {
        $address = (string) DB::table('import_sources')->where('id', $sourceId)->value('address');

        return self::bare((string) parse_url($address, PHP_URL_HOST));
    }

    /**
     * Where the cursor stands: the kind, and the ID read last.
     *
     * @param  list<string>  $kinds
     *
     * @return array{0: string, 1: int}
     */
    private static function position(array $kinds, ?string $cursor): array
    {
        if ($cursor !== null && preg_match('/^([a-z_]+):(\d{1,18})$/', $cursor, $match) === 1 && in_array($match[1], $kinds, true)) {
            return [$match[1], (int) $match[2]];
        }

        return [$kinds[0], 0];
    }

    /**
     * The cursor at the start of the kind after this one, or null after the last.
     *
     * @param  list<string>  $kinds
     */
    private static function after(array $kinds, string $kind): ?string
    {
        $index = array_search($kind, $kinds, true);
        $next = $index === false ? null : ($kinds[$index + 1] ?? null);

        return $next === null ? null : "{$next}:0";
    }
}
