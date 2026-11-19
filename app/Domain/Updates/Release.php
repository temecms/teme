<?php

/**
 * One Teme release as the update check keeps it: its version, whether it is a pre-release, the two marks its notes carry, the notes and where to read them.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Updates;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

final readonly class Release
{
    /** The hidden line scripts/release-notes.php writes at the end of the notes. */
    public const string MARKS = '/<!--\s*teme-release\s+(\{[^{}]*\})\s*-->/';

    public const int NOTES_LIMIT = 65535;

    public function __construct(
        public string $version,
        public bool $prerelease,
        public bool $security,
        public bool $maintenance,
        public string $notes,
        public ?string $url,
        public CarbonImmutable $publishedAt,
    ) {}

    /**
     * One item of GitHub's release list as Teme keeps it, or null for a
     * draft, a tag that isn't a release's, or an item without a date.
     *
     * @param  array<array-key, mixed>  $item
     */
    public static function fromGitHub(array $item, string $repository): ?self
    {
        $tag = $item['tag_name'] ?? null;
        $version = is_string($tag) && str_starts_with($tag, 'v') ? substr($tag, 1) : '';
        $published = $item['published_at'] ?? null;

        if (($item['draft'] ?? true) !== false || ! TemeVersion::isRelease($version) || ! is_string($published)) {
            return null;
        }

        try {
            $publishedAt = CarbonImmutable::parse($published);
        } catch (InvalidFormatException) {
            return null;
        }

        $body = is_string($item['body'] ?? null) ? $item['body'] : '';
        $marks = preg_match(self::MARKS, $body, $found) === 1 ? json_decode($found[1], true) : null;
        $url = $item['html_url'] ?? null;

        return new self(
            version: $version,
            prerelease: TemeVersion::isPrerelease($version),
            security: is_array($marks) && ($marks['security'] ?? false) === true,
            maintenance: is_array($marks) && ($marks['maintenance'] ?? false) === true,
            notes: mb_substr(trim((string) preg_replace(self::MARKS, '', $body)), 0, self::NOTES_LIMIT),
            url: is_string($url) && str_starts_with($url, "https://github.com/{$repository}/releases/") ? $url : null,
            publishedAt: $publishedAt,
        );
    }

    /**
     * A release read back from a teme_releases row.
     */
    public static function fromRow(object $row): self
    {
        return new self(
            version: (string) $row->version,
            prerelease: (bool) $row->prerelease,
            security: (bool) $row->security,
            maintenance: (bool) $row->maintenance,
            notes: (string) $row->notes,
            url: $row->url === null ? null : (string) $row->url,
            publishedAt: CarbonImmutable::parse((string) $row->published_at),
        );
    }
}
