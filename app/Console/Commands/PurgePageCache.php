<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Cache\PageCache;
use App\Domain\Cache\PageTags;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * `teme:cache:purge`: removes the stored pages that carry any of the tags
 * given, or stand at the addresses given, in any case or query, for an
 * operator who changed something outside Teme (docs/architecture/page-cache.md).
 * Operations purge on their own.
 */
final class PurgePageCache extends Command
{
    /** @var string */
    protected $signature = 'teme:cache:purge
        {tags?* : Tags, such as content:01jx… or list:post}
        {--url=* : An address, such as /about/, in any case, with or without its host}';

    /** @var string */
    protected $description = 'Remove the pages the page cache holds for these tags or addresses';

    public function handle(PageCache $cache): int
    {
        $tags = array_values(array_filter((array) $this->argument('tags'), 'is_string'));

        foreach ((array) $this->option('url') as $url) {
            $tags[] = PageTags::url((string) (parse_url((string) $url, PHP_URL_PATH) ?: '/'));
        }

        if ($tags === []) {
            $this->components->error('Name a tag, or an address with --url.');

            return self::FAILURE;
        }

        $removed = $cache->purge($tags);

        $this->components->info("Removed {$removed} ".Str::plural('page', $removed).' from the page cache.');

        return self::SUCCESS;
    }
}
