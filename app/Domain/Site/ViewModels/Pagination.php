<?php

declare(strict_types=1);

namespace App\Domain\Site\ViewModels;

/**
 * A listing's pages: the current one, how many there are (null when
 * unknown), and the addresses of the newer page and the older one.
 */
final readonly class Pagination
{
    /** How many page links pages() gives, at most. */
    private const int WINDOW = 7;

    /**
     * @param  int  $current  this page's number
     * @param  int|null  $total  how many pages there are; null when unknown
     * @param  string|null  $previous  the address of the newer page, or null on the first
     * @param  string|null  $next  the address of the older page, or null on the last
     */
    public function __construct(
        public int $current,
        public ?int $total,
        public ?string $previous,
        public ?string $next,
        private string $base = '/',
        /** Kept on every page address pages() gives, such as `?s=heron` (1.6h). */
        private string $query = '',
        /** Page 1's address when it isn't the base, as a thread's first page is its item's own address at #comments. */
        private ?string $first = null,
    ) {}

    /**
     * An address with its page suffix, following the address's own
     * trailing slash; page 1 is the address itself.
     */
    public static function urlFor(string $base, int $page): string
    {
        if ($page < 2) {
            return $base;
        }

        $slash = str_ends_with($base, '/');

        return rtrim($base, '/').'/page/'.$page.($slash ? '/' : '');
    }

    /**
     * Up to seven page links around the current page, the first and last
     * always among them, with a gap (number and url null) where pages are
     * left out; none when the total is unknown.
     *
     * @return list<array{number: int|null, url: string|null, current: bool}>
     */
    public function pages(): array
    {
        if ($this->total === null || $this->total < 2) {
            return [];
        }

        $numbers = [1, $this->total];
        $half = intdiv(self::WINDOW - 2, 2);

        for ($n = max(2, $this->current - $half); $n <= min($this->total - 1, $this->current + $half); $n++) {
            $numbers[] = $n;
        }

        sort($numbers);
        $numbers = array_values(array_unique($numbers));
        $pages = [];
        $last = 0;

        foreach ($numbers as $number) {
            if ($number > $last + 1) {
                $pages[] = ['number' => null, 'url' => null, 'current' => false];
            }

            $pages[] = ['number' => $number, 'url' => $number === 1 && $this->first !== null ? $this->first : self::urlFor($this->base, $number).$this->query, 'current' => $number === $this->current];
            $last = $number;
        }

        return $pages;
    }
}
