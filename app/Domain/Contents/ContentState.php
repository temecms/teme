<?php

/**
 * Where one content item sits for counting: its type, its bucket — its
 * status, or "trash" — and its author. Operations take one before and one
 * after a change and hand both to ContentCounts.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents;

use App\Domain\Contents\Models\Content;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Teme\Contracts\Settings\SettingsReader;

final readonly class ContentState
{
    public function __construct(
        public string $type,
        public string $bucket,
        public int $authorId,
        /** The site month of its last change, as YYYY-MM, or null when it has none. */
        public ?string $updatedMonth = null,
        /** For a live public item, the site month it was published, as YYYY-MM; else null. */
        public ?string $publishedMonth = null,
    ) {}

    /**
     * The state of the item as it is now, its months in the site's zone (I-3131).
     */
    public static function of(Content $content): self
    {
        $bucket = self::bucketOf($content);
        $zone = app(SettingsReader::class)->string('general', 'timezone');

        return new self(
            $content->type,
            $bucket,
            (int) ($content->author_id ?? 0),
            self::monthOf($content->updated_at, $zone),
            $bucket === 'published' ? self::monthOf($content->published_at, $zone) : null,
        );
    }

    /**
     * The content_counts rows this state counts in, as [bucket, author]: its
     * bucket and the month of its last change, for everyone (0) and for its
     * author, then, for a live public item, the month it was published, for
     * everyone only (I-3131).
     *
     * @return list<array{0: string, 1: int}>
     */
    public function rows(): array
    {
        $rows = [];

        foreach (array_unique([0, $this->authorId]) as $authorId) {
            $rows[] = [$this->bucket, $authorId];

            if ($this->updatedMonth !== null) {
                $rows[] = ["u:{$this->bucket}:{$this->updatedMonth}", $authorId];
            }
        }

        if ($this->publishedMonth !== null) {
            $rows[] = ["p:{$this->publishedMonth}", 0];
        }

        return $rows;
    }

    /**
     * The bucket: "trash" in the trash, "private" for a published private
     * item, which readers don't count (I-1700), else the status.
     */
    private static function bucketOf(Content $content): string
    {
        if ($content->trashed()) {
            return 'trash';
        }

        return $content->status === 'published' && $content->visibility === Visibility::Private ? 'private' : $content->status;
    }

    /** A stored instant's month in the zone, as YYYY-MM; null for none. */
    private static function monthOf(mixed $at, string $zone): ?string
    {
        $instant = match (true) {
            $at instanceof DateTimeInterface => CarbonImmutable::instance($at),
            is_string($at) && $at !== '' => CarbonImmutable::parse($at, 'UTC'),
            default => null,
        };

        return $instant?->setTimezone($zone)->format('Y-m');
    }
}
