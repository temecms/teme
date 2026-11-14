<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases\Content;

use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Comments\Discussion;
use App\Domain\Contents\Slugs;
use App\Domain\Import\Phases\Terms\WordPressSlugs;
use App\Domain\Import\Phases\Terms\WordPressText;
use Carbon\CarbonImmutable;

/**
 * What a WordPress post or page, as the companion sends it, is in Teme's
 * terms (I-332): its status, title, slug, dates, discussion, excerpt,
 * addresses and guid. Pure: it reads nothing but the item and what it is
 * given, and passes a password on only for the item's own sealed column.
 */
final class WordPressPost
{
    /** WordPress's statuses that come across, each with Teme's: pairs, so a status is never an array key. */
    private const array STATUSES = [['publish', 'published'], ['future', 'scheduled'], ['draft', 'draft'], ['pending', 'review'], ['private', 'published']];

    /** The suffix WordPress puts on a trashed item's slug, which Teme's trash doesn't need (I-142). */
    private const string TRASHED = '__trashed';

    /** A date and time as WordPress's database keeps one. */
    private const string MOMENT = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

    /**
     * The status the item takes here (WordPress's private item is a
     * published one only its editors read, I-1700); who may read it, private
     * or behind its password; whether it was in the trash; and whether a
     * private item's password is left behind. Null for a status Teme
     * doesn't import.
     *
     * @param  array<string, mixed>  $item
     *
     * @return array{status: string, visibility: string|null, trashed: bool, dropped: bool}|null
     */
    public static function status(array $item): ?array
    {
        $wordpress = (string) ($item['status'] ?? '');
        $trashed = $wordpress === 'trash';

        if ($trashed) {
            $wordpress = (string) ($item['status_before_trash'] ?? '');
        }

        $status = null;

        foreach (self::STATUSES as [$from, $to]) {
            if ($from === $wordpress) {
                $status = $to;
            }
        }

        if ($status === null) {
            return $trashed ? ['status' => 'draft', 'visibility' => null, 'trashed' => true, 'dropped' => false] : null;
        }

        $hasPassword = is_string($item['password'] ?? null) && trim($item['password']) !== '';
        $visibility = match (true) {
            $wordpress === 'private' => 'private',
            $hasPassword => 'protected',
            default => null,
        };

        // In the trash, an item keeps only a published status or a draft's, as TrashContent leaves it.
        if ($trashed && $status !== 'published') {
            $status = 'draft';
        }

        return ['status' => $status, 'visibility' => $visibility, 'trashed' => $trashed, 'dropped' => $visibility === 'private' && $hasPassword];
    }

    /**
     * The password readers give, exactly as WordPress kept it, for an item
     * it protects; null for any other (I-1700). Never kept anywhere but the
     * item's sealed column.
     *
     * @param  array<string, mixed>  $item
     */
    public static function password(array $item): ?string
    {
        return (self::status($item)['visibility'] ?? null) === 'protected' ? (string) $item['password'] : null;
    }

    /**
     * The title Teme keeps: WordPress's, its tags removed, its escaping
     * undone once, runs of spaces as one, at most 512 characters; Untitled
     * when nothing is left.
     *
     * @param  array<string, mixed>  $item
     *
     * @return array{title: string, untitled: bool, shortened: bool}
     */
    public static function title(array $item): array
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', WordPressText::decoded(strip_tags((string) ($item['title'] ?? '')))));

        if ($title === '') {
            return ['title' => 'Untitled', 'untitled' => true, 'shortened' => false];
        }

        $shortened = mb_strlen($title) > 512;

        return ['title' => $shortened ? rtrim(mb_substr($title, 0, 512)) : $title, 'untitled' => false, 'shortened' => $shortened];
    }

    /**
     * The slug Teme gives it: WordPress's own, percent-decoded and kept where
     * Teme's rule allows (I-397), without WordPress's trash suffix; for an
     * item WordPress never named, one made from its title, as CreateDraft
     * makes one.
     *
     * @param  array<string, mixed>  $item
     */
    public static function slug(array $item, string $title): string
    {
        $wordpress = (string) ($item['slug'] ?? '');

        if (str_ends_with($wordpress, self::TRASHED)) {
            $wordpress = substr($wordpress, 0, -strlen(self::TRASHED));
        }

        $slug = WordPressSlugs::fromWordPress($wordpress, 200)['slug'];

        return $slug !== '' ? $slug : Slugs::fromTitle($title);
    }

    /**
     * When it was published (the instant: WordPress's GMT date, or for a
     * published, scheduled or private item with none its local date read
     * in the site's timezone, as get_gmt_from_date() does; WordPress keeps
     * no GMT date for a draft until it is published), created, and last
     * changed.
     *
     * @param  array<string, mixed>  $item
     *
     * @return array{published: CarbonImmutable|null, created: CarbonImmutable, modified: CarbonImmutable}
     */
    public static function dates(array $item, string $timezone, CarbonImmutable $now): array
    {
        $status = (string) ($item['status'] ?? '');

        if ($status === 'trash') {
            $status = (string) ($item['status_before_trash'] ?? '');
        }

        $local = self::moment($item['date'] ?? null, $timezone);
        $published = self::moment($item['date_gmt'] ?? null, 'UTC') ?? (in_array($status, ['publish', 'future', 'private'], true) ? $local : null);
        $created = $published ?? $local ?? $now;

        return ['published' => $published, 'created' => $created, 'modified' => self::moment($item['modified_gmt'] ?? null, 'UTC') ?? $created];
    }

    /**
     * When WordPress trashed it (its trash time, in Unix seconds), now when
     * it sent none or one still to come; null for an item not in the trash.
     *
     * @param  array<string, mixed>  $item
     */
    public static function trashedAt(array $item, CarbonImmutable $now): ?CarbonImmutable
    {
        if (($item['status'] ?? null) !== 'trash') {
            return null;
        }

        $at = $item['trashed_at'] ?? null;
        $seconds = is_int($at) ? $at : (is_string($at) && ctype_digit($at) ? (int) $at : null);
        $time = $seconds === null ? $now : CarbonImmutable::createFromTimestampUTC($seconds);

        return $time->greaterThan($now) ? $now : $time;
    }

    /**
     * Open only where WordPress had comments open; a closed item's comments
     * still show, as Teme's Closed shows them (I-582).
     *
     * @param  array<string, mixed>  $item
     */
    public static function discussion(array $item): Discussion
    {
        return ($item['comment_status'] ?? null) === 'open' ? Discussion::Open : Discussion::Closed;
    }

    /**
     * The excerpt as plain text, at most 1,000 characters, or none.
     *
     * @param  array<string, mixed>  $item
     *
     * @return array{text: string|null, shortened: bool}
     */
    public static function excerpt(array $item): array
    {
        $text = WordPressText::plain($item['excerpt'] ?? null);

        if ($text === null) {
            return ['text' => null, 'shortened' => false];
        }

        $shortened = mb_strlen($text) > 1000;

        return ['text' => $shortened ? rtrim(mb_substr($text, 0, 1000)) : $text, 'shortened' => $shortened];
    }

    /**
     * WordPress's own address for it, in the path table's form: the
     * permalink's whole path below the host, so a site in a folder keeps its
     * folder; '' for the home; null for one with a query (a draft's ?p=12)
     * or none.
     *
     * @param  array<string, mixed>  $item
     */
    public static function path(array $item): ?string
    {
        $parts = is_string($item['permalink'] ?? null) ? parse_url(trim($item['permalink'])) : false;

        if (! is_array($parts) || isset($parts['query']) || ! isset($parts['host'])) {
            return null;
        }

        return PublicRequestPath::normalise($parts['path'] ?? '/');
    }

    /**
     * WordPress's old slugs as Teme's rule keeps them, each once, never the
     * current one.
     *
     * @param  array<string, mixed>  $item
     *
     * @return list<string>
     */
    public static function oldSlugs(array $item, string $slug): array
    {
        $old = [];

        foreach ((array) ($item['old_slugs'] ?? []) as $value) {
            $kept = is_string($value) ? WordPressSlugs::fromWordPress($value, 200)['slug'] : '';

            if ($kept !== '' && $kept !== $slug && ! in_array($kept, $old, true)) {
                $old[] = $kept;
            }
        }

        return $old;
    }

    /**
     * WordPress's old local dates, Y-m-d, each a real day, each once.
     *
     * @param  array<string, mixed>  $item
     *
     * @return list<string>
     */
    public static function oldDates(array $item): array
    {
        $old = [];

        foreach ((array) ($item['old_dates'] ?? []) as $value) {
            $date = is_string($value) ? substr(trim($value), 0, 10) : '';
            $real = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) === 1 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);

            if ($real && ! in_array($date, $old, true)) {
                $old[] = $date;
            }
        }

        return $old;
    }

    /**
     * WordPress's guid as it is, or null: none, or longer than its column.
     *
     * @param  array<string, mixed>  $item
     */
    public static function guid(array $item): ?string
    {
        $guid = is_string($item['guid'] ?? null) ? trim($item['guid']) : '';

        return $guid === '' || mb_strlen($guid) > 255 ? null : $guid;
    }

    /**
     * Names joined as people read them: "a", "a and b", "a, b and c".
     *
     * @param  list<string>  $names
     */
    public static function listed(array $names): string
    {
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names).' and '.$last;
    }

    /**
     * A date as WordPress's database keeps one, read in $timezone, as UTC;
     * null for an empty or zero one.
     */
    private static function moment(mixed $value, string $timezone): ?CarbonImmutable
    {
        $text = is_string($value) ? trim($value) : '';

        if (preg_match(self::MOMENT, $text) !== 1 || str_starts_with($text, '0000')) {
            return null;
        }

        $moment = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $text, $timezone) ?: null;

        return $moment?->utc();
    }
}
