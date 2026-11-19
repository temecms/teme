<?php

/**
 * The size and shape of the benchmark's synthetic WordPress site, every
 * value a function of its numbers, so every run imports the same site
 * (D4, D5; docs/benchmarks/). The companion that answers for it is
 * BenchmarkCompanion.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Benchmark\ImportBench;

final readonly class BenchmarkSite
{
    /** The old site's address: a private one, which the benchmark's command allows for itself. */
    public const string ADDRESS = 'http://127.0.0.1';

    /** The WordPress ID of the item before the first: item n has the ID FIRST + n. */
    public const int FIRST = 1000;

    /** The first item's date: 2016-01-01 00:00:00 UTC, as a timestamp. */
    public const int EPOCH = 1451606400;

    /** Minutes between one item's date and the next's. */
    public const int MINUTES = 90;

    /** How many entries one of WordPress's own sitemap files holds. */
    private const int SITEMAP = 2000;

    public function __construct(
        public int $items = 50000,
        public int $comments = 100000,
        public int $people = 50,
        public int $categories = 200,
        public int $tags = 1000,
        public int $rules = 500,
    ) {}

    /**
     * What the site should hold once it is imported: its redirects are the
     * old site's rules and one for each of WordPress's own sitemap files, which
     * the settings step sends to Teme's sitemap (B-1057).
     *
     * @return array{posts: int, comments: int, people: int, terms: int, redirects: int}
     */
    public function expected(): array
    {
        return ['posts' => $this->items, 'comments' => $this->comments, 'people' => $this->people, 'terms' => $this->categories + $this->tags, 'redirects' => $this->rules + $this->sitemaps()];
    }

    /**
     * How many sitemap files WordPress serves for the site: one file at least
     * for each of posts, pages, categories, tags and people, and one more for
     * every 2,000 entries.
     */
    public function sitemaps(): int
    {
        $pages = intdiv($this->items, 10);

        return array_sum(array_map(fn (int $entries): int => max(1, intdiv($entries + self::SITEMAP - 1, self::SITEMAP)), [$this->items - $pages, $pages, $this->categories, $this->tags, $this->people]));
    }

    /**
     * How many old addresses the site lists: its published items, its terms,
     * the authors with posts, and the home page.
     */
    public function addresses(): int
    {
        return $this->items + $this->categories + $this->tags + count($this->authorsWithPosts()) + 1;
    }

    /**
     * Whether item $n is a page: every tenth.
     */
    public function isPage(int $n): bool
    {
        return $n % 10 === 0;
    }

    /**
     * Item $n's slug.
     */
    public function slug(int $n): string
    {
        return ($this->isPage($n) ? 'page-' : 'story-').$n;
    }

    /**
     * Item $n's address on the old site (permalinks are /%postname%/).
     */
    public function permalink(int $n): string
    {
        return self::ADDRESS.'/'.$this->slug($n).'/';
    }

    /**
     * The person who wrote item $n: from the sixth on, round-robin; the
     * first when the site has fewer than six.
     */
    public function authorOf(int $n): int
    {
        return $this->people < 6 ? 1 : 6 + $n % ($this->people - 5);
    }

    /**
     * The people with at least one item, in ID order.
     *
     * @return list<int>
     */
    public function authorsWithPosts(): array
    {
        $authors = [];

        for ($n = 1; $n <= min($this->items, max(1, $this->people - 5)); $n++) {
            $authors[$this->authorOf($n)] = true;
        }

        $ids = array_keys($authors);
        sort($ids);

        return $ids;
    }

    /**
     * Item $n's date, as a timestamp.
     */
    public function timestamp(int $n): int
    {
        return self::EPOCH + $n * self::MINUTES * 60;
    }

    /**
     * How many posts (items that aren't pages) the site has.
     */
    public function postCount(): int
    {
        return $this->items - intdiv($this->items, 10);
    }

    /**
     * The item number of the $k-th post, counted from 0.
     */
    public function postNumber(int $k): int
    {
        return intdiv($k, 9) * 10 + $k % 9 + 1;
    }

    /**
     * The comments on item $n: comments go round the posts in turn.
     */
    public function commentsOn(int $n): int
    {
        $posts = $this->postCount();

        if ($this->isPage($n) || $posts === 0) {
            return 0;
        }

        $k = intdiv($n - 1, 10) * 9 + ($n - 1) % 10;

        return $this->comments > $k ? intdiv($this->comments - 1 - $k, $posts) + 1 : 0;
    }

    /**
     * The category the term ID $id names, as WordPress's address for it
     * writes it: a child under its parent.
     */
    public function categoryPath(int $id): string
    {
        return ($id % 10 === 0 && $id > 1 ? $this->categoryPath($id - 1).'/' : '').'topic-'.$id;
    }
}
