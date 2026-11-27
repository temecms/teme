<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Searcher;

use Closure;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;
use Teme\Contracts\Search\HasSearchEngine;
use Teme\Contracts\Search\SearchHits;
use Teme\Contracts\Search\SearchRequest;
use Teme\Contracts\Search\SiteSearch;

/**
 * A fixture plugin that fills the search slot. The tests set the hits its
 * engine answers with, or make it fail.
 */
final class Searcher implements HasSearchEngine, Plugin
{
    /** @var (Closure(SearchRequest): SearchHits)|null */
    public static ?Closure $answer = null;

    public static function reset(): void
    {
        self::$answer = null;
    }

    public function register(Hooks $hooks): void {}

    public function engine(): SiteSearch
    {
        return new class implements SiteSearch
        {
            public function search(SearchRequest $request): SearchHits
            {
                return (Searcher::$answer)($request);
            }
        };
    }
}
