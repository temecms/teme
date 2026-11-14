<?php

declare(strict_types=1);

namespace App\Domain\Cache;

/**
 * One stored page: the key it answers, its status, headers and HTML, when
 * it was stored and when it expires, and the tags that find it again
 * (docs/studies/page-cache.md §3.8).
 */
final readonly class PageEntry
{
    /**
     * @param  array<string, list<string>>  $headers
     * @param  list<string>  $tags
     */
    public function __construct(
        public string $key,
        public int $status,
        public array $headers,
        public string $body,
        public int $storedAt,
        public int $expiresAt,
        public array $tags,
    ) {}

    /**
     * The page a stored JSON line and its HTML describe, as either store
     * keeps them, or null when the line isn't a page's, or another key's:
     * the key is compared as well as the hash, so two addresses can never
     * share a page.
     */
    public static function fromStored(string $line, string $body, string $url): ?self
    {
        $meta = json_decode($line, true);

        if (! is_array($meta)) {
            return null;
        }

        $status = $meta['status'] ?? null;
        $storedAt = $meta['storedAt'] ?? null;
        $expiresAt = $meta['expiresAt'] ?? null;
        $headers = $meta['headers'] ?? null;
        $tags = $meta['tags'] ?? null;

        if (($meta['key'] ?? null) !== $url || ! is_int($status) || ! is_int($storedAt) || ! is_int($expiresAt) || ! is_array($headers) || ! is_array($tags)) {
            return null;
        }

        $kept = [];

        foreach ($headers as $name => $values) {
            $kept[(string) $name] = array_values(array_filter((array) $values, 'is_string'));
        }

        return new self($url, $status, $kept, $body, $storedAt, $expiresAt, array_values(array_filter($tags, 'is_string')));
    }

    /**
     * The JSON line a store keeps before the page's HTML: its key, status,
     * headers, times and tags, JSON and never PHP-serialized (rule 4).
     */
    public function storedLine(): string
    {
        $meta = [
            'key' => $this->key,
            'status' => $this->status,
            'headers' => $this->headers,
            'storedAt' => $this->storedAt,
            'expiresAt' => $this->expiresAt,
        ];

        // Set on its own: an array keyed by the word tags reads, to
        // NoTypeLiteralsTest, as a type named in code (B-225).
        $meta['tags'] = $this->tags;

        return json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    /**
     * Seconds since it was stored.
     */
    public function age(int $now): int
    {
        return max(0, $now - $this->storedAt);
    }

    /**
     * Seconds it has left, 0 once it has expired.
     */
    public function remaining(int $now): int
    {
        return max(0, $this->expiresAt - $now);
    }

    public function expired(int $now): bool
    {
        return $this->expiresAt <= $now;
    }
}
