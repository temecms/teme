<?php

declare(strict_types=1);

namespace App\Domain\Api\Items;

use App\Domain\Api\ApiQuery;
use App\Domain\Api\ApiWords;
use App\Domain\Api\Cursor;
use App\Domain\Api\InvalidParameters;
use App\Domain\Api\Live;
use App\Domain\Api\Shapes\Dates;
use App\Domain\Api\Ulids;
use App\Domain\People\Credit;
use App\Domain\People\Queries\People;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What a request to the items list asks for (D5): its types, term, author,
 * dates, parent, sort, size and cursor, each checked, every problem refused
 * at once.
 */
final readonly class ItemFilters
{
    /** Newest first, the default. */
    public const string NEWEST = '-published_at';

    /** Oldest first. */
    public const string OLDEST = 'published_at';

    /** In their order under their parent, with parent only (I-3363). */
    public const string POSITION = 'position';

    /** The most types one list may name. */
    public const int MOST_TYPES = 10;

    /**
     * @param  list<string>|null  $types  the public types asked for, or null for every public type
     * @param  string|null  $termId  a public taxonomy's term, filed under which items are kept
     * @param  string|null  $termTaxonomy  that term's taxonomy
     * @param  Credit|null  $author  the person whose items are kept, an account or a person with no account; account 0 for a slug nobody has
     * @param  string|null  $parentId  the item whose live children are kept (I-3363)
     */
    private function __construct(
        public ?array $types,
        public ?string $termId,
        public ?string $termTaxonomy,
        public ?Credit $author,
        public ?CarbonImmutable $since,
        public ?CarbonImmutable $before,
        public string $sort,
        public int $limit,
        public ?Cursor $after,
        public ?string $parentId = null,
    ) {}

    /**
     * The filters a request's parameters name, the author's slug read in one
     * statement and the term checked in one.
     *
     * @throws InvalidParameters naming every parameter it refuses
     */
    public static function from(ApiQuery $query): self
    {
        $problems = [];

        try {
            $limit = ApiQuery::limit($query->get('limit'));
        } catch (InvalidParameters $refused) {
            $problems += $refused->problems;
            $limit = ApiQuery::DEFAULT_LIMIT;
        }

        $parentId = $query->get('parent');

        if ($parentId !== null && preg_match(Ulids::LOWER, $parentId) !== 1) {
            $problems['parent'] = ApiWords::badItemParent();
        }

        $sort = $query->get('sort') ?? self::NEWEST;

        if (! in_array($sort, [self::NEWEST, self::OLDEST, ...($parentId === null ? [] : [self::POSITION])], true)) {
            $problems['sort'] = ApiWords::badSort();
            $sort = self::NEWEST;
        }

        $after = null;
        $given = $query->get('after');

        if ($given !== null && ! array_key_exists('sort', $problems)) {
            $after = Cursor::read($given, $sort);

            // Cursor::read() checks the shape only; the key must be a moment as Dates writes it, or a position.
            if ($after === null || ($sort === self::POSITION ? preg_match('/^[0-9]{1,5}$/', $after->key) !== 1 : self::moment($after->key) === null)) {
                $problems['after'] = ApiWords::badAfter();
                $after = null;
            }
        }

        $types = self::types($query->get('type'), $problems);
        $since = self::date($query, 'published_since', $problems);
        $before = self::date($query, 'published_before', $problems);
        [$termId, $termTaxonomy] = self::term($query->get('term'), $problems);
        $author = self::author($query->get('author'));

        if ($problems !== []) {
            throw new InvalidParameters($problems);
        }

        return new self($types, $termId, $termTaxonomy, $author, $since, $before, $sort, $limit, $after, $parentId);
    }

    /**
     * Whether the list is newest first.
     */
    public function newestFirst(): bool
    {
        return $this->sort === self::NEWEST;
    }

    /**
     * The public types named, each once, in the order given; null when none is named.
     *
     * @param  array<string, string>  $problems
     *
     * @return list<string>|null
     */
    private static function types(?string $given, array &$problems): ?array
    {
        if ($given === null) {
            return null;
        }

        $public = Live::types();
        $named = array_values(array_unique(explode(',', $given)));

        // A key that isn't a public type's, whatever it is, gets a typo's words.
        if (count($named) > self::MOST_TYPES || array_diff($named, $public) !== []) {
            $problems['type'] = ApiWords::badType($public);

            return null;
        }

        return $named;
    }

    /**
     * The moment a date parameter names, which must carry its offset.
     *
     * @param  array<string, string>  $problems
     */
    private static function date(ApiQuery $query, string $name, array &$problems): ?CarbonImmutable
    {
        $given = $query->get($name);

        if ($given === null) {
            return null;
        }

        $moment = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/i', $given) === 1
            ? self::parsed($given)
            : null;

        if ($moment === null) {
            $problems[$name] = ApiWords::badDate($name);
        }

        return $moment;
    }

    /**
     * A well-formed date and time as a moment in UTC, or null for one the
     * calendar hasn't (a 13th month, a 30 February) or one before the year 1.
     */
    private static function parsed(string $given): ?CarbonImmutable
    {
        $date = substr($given, 0, 10);
        [$year, $month, $day] = array_map(intval(...), explode('-', $date));

        if (! checkdate($month, $day, $year) || (int) substr($given, 11, 2) > 23 || (int) substr($given, 14, 2) > 59 || (int) substr($given, 17, 2) > 59) {
            return null;
        }

        try {
            $moment = CarbonImmutable::parse($given)->utc();
        } catch (Throwable) {
            return null;
        }

        // PostgreSQL refuses a year before 1, so such a moment is refused here, never left to fail.
        return $moment->year >= 1 ? $moment : null;
    }

    /**
     * A cursor's key as the moment Dates wrote, or null for anything else,
     * a moment before the year 1 included.
     */
    private static function moment(string $key): ?CarbonImmutable
    {
        $moment = DateTimeImmutable::createFromFormat('!'.Dates::FORMAT, $key, new DateTimeZone('UTC'));

        return $moment !== false && $moment->format(Dates::FORMAT) === $key && (int) $moment->format('Y') >= 1 ? CarbonImmutable::instance($moment) : null;
    }

    /**
     * The term asked for, and its taxonomy, while it is a public taxonomy's;
     * one statement.
     *
     * @param  array<string, string>  $problems
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function term(?string $given, array &$problems): array
    {
        if ($given === null) {
            return [null, null];
        }

        $taxonomies = Live::taxonomies();
        $taxonomy = preg_match(Ulids::LOWER, $given) === 1 && $taxonomies !== []
            // The default language's terms only, as the terms list gives (A5): any other is as unknown as a made-up ID.
            ? DB::table('terms')->where('id', $given)->whereIn('taxonomy', $taxonomies)->whereNull('language')->value('taxonomy')
            : null;

        if ($taxonomy === null) {
            $problems['term'] = ApiWords::badTerm();

            return [null, null];
        }

        return [$given, (string) $taxonomy];
    }

    /**
     * The person with this slug, an account or a person with no account
     * (I-3366), or account 0, which no account is, when nobody has it, so
     * the list is empty and costs what a found one costs; one statement.
     */
    private static function author(?string $given): ?Credit
    {
        if ($given === null) {
            return null;
        }

        return app(People::class)->bySlug($given) ?? Credit::account(0);
    }
}
