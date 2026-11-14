<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Cache\PageCache;
use App\Domain\Cache\PageTags;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * `teme:cache:clear`: removes every page the page cache holds, found
 * through its tag table, as a purge of `*`, so a page drawn before it is
 * never stored after it (I-264). Run it after a deploy that changes a
 * theme's or the admin's built files, whose old addresses stored pages
 * still name. Tools › Page cache does the same for an administrator,
 * through the ClearPageCache Operation, which also records who cleared it
 * (I-467).
 */
final class ClearPageCache extends Command
{
    /** @var string */
    protected $signature = 'teme:cache:clear';

    /** @var string */
    protected $description = 'Remove every page the page cache holds';

    public function handle(PageCache $cache): int
    {
        $removed = $cache->purge([PageTags::EVERYTHING]);

        $this->components->info("Removed {$removed} ".Str::plural('page', $removed).' from the page cache.');

        return self::SUCCESS;
    }
}
