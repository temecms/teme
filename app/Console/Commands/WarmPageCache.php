<?php

/**
 * `teme:cache:warm`: draws the pages readers ask for most, so they are
 * stored before anyone asks (docs/studies/page-cache.md §3.11): the home
 * page, then every term's and every author's archive, then live items,
 * newest first, from `paths`. One page at a time, never all at once, and
 * never a list's later pages. Run it after teme:cache:clear, as a deploy
 * does.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Cache\PageCache;
use App\Domain\Cache\PageWarmer;
use Generator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class WarmPageCache extends Command
{
    /** Rows read from `paths` per statement. */
    private const int BATCH = 500;

    /** @var string */
    protected $signature = 'teme:cache:warm
        {--limit=1000 : The most pages to draw; 0 for every current address}';

    /** @var string */
    protected $description = 'Draw and store the home page, the archives and the newest items';

    public function handle(PageWarmer $warmer): int
    {
        if (! PageCache::enabled()) {
            $this->components->warn('The page cache is off, so nothing would be stored: nothing was warmed.');

            return self::SUCCESS;
        }

        $limit = max(0, (int) $this->option('limit'));
        $warmed = 0;
        $drawn = 0;
        $already = 0;

        foreach ($this->addresses() as $url) {
            if ($limit > 0 && $warmed >= $limit) {
                break;
            }

            $said = $warmer->warm($url);
            $warmed++;
            $drawn += $said === 'MISS' ? 1 : 0;
            $already += $said === 'HIT' ? 1 : 0;
        }

        $this->components->info(sprintf(
            'Warmed %d %s: %d drawn and stored, %d already stored, %d not stored.',
            $warmed,
            Str::plural('page', $warmed),
            $drawn,
            $already,
            $warmed - $drawn - $already,
        ));

        return self::SUCCESS;
    }

    /**
     * Every current address worth drawing first, in order, a batch of rows at
     * a time: the home page; each term's and each author's archive, its first
     * page only; then every live item, newest first. Each as a reader's
     * browser asks for it: APP_URL's scheme and host, the path
     * percent-encoded.
     *
     * @return Generator<int, string>
     */
    private function addresses(): Generator
    {
        $site = rtrim((string) config('app.url'), '/');

        yield $site.'/';

        $archives = DB::table('paths')
            ->where('is_current', true)
            ->whereIn('target_type', ['term', 'user', 'person'])
            ->select(['id', 'path']);

        foreach ($archives->lazyById(self::BATCH) as $row) {
            yield $site.PublicRequestPath::encode(Addresses::toUrl((string) $row->path));
        }

        // Live as PathResolver::live() has it: published, not trashed, dated now or before.
        $items = DB::table('paths')
            ->join('contents', 'contents.id', '=', 'paths.target_id')
            ->where('paths.is_current', true)
            ->where('paths.target_type', 'content')
            ->where('contents.status', 'published')
            ->whereNull('contents.deleted_at')
            ->whereNotNull('contents.published_revision_id')
            ->where('contents.published_at', '<=', now())
            ->select(['paths.id', 'paths.path', 'contents.type']);

        foreach ($items->lazyByIdDesc(self::BATCH, 'paths.id', 'id') as $row) {
            yield $site.PublicRequestPath::encode(Addresses::toUrl((string) $row->path, (string) $row->type));
        }
    }
}
