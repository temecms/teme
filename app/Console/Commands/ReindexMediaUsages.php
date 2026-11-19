<?php

/**
 * teme:content:reindex-references — re-derives where every media item is used
 * and which articles link to which, from the content revisions, a chunk at a
 * time, as the operator. Then run teme:counts:rebuild to square the media use
 * counters; links have no counter, so the added and removed rows are the
 * report. teme:media:reindex-usages still works, for one release.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Media\Operations\Input\ReindexMediaUsagesInput;
use App\Domain\Media\Operations\ReindexMediaUsages as ReindexMediaUsagesOperation;
use App\Domain\Media\Queries\ContentIdsInOrder;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;

final class ReindexMediaUsages extends Command
{
    /** @var string */
    protected $signature = 'teme:content:reindex-references {--chunk=500 : Content items per chunk}';

    /** @var string */
    protected $description = 'Re-derive media usage and links between articles from the content revisions';

    /**
     * The 1.4b name, kept for one release.
     *
     * @var list<string>
     */
    protected $aliases = ['teme:media:reindex-usages'];

    public function handle(ReindexMediaUsagesOperation $reindex): int
    {
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        $chunk = max(1, (int) $this->option('chunk'));
        [$last, $done, $added, $removed] = [null, 0, 0, 0];

        while (($ids = ContentIdsInOrder::after($last, $chunk)) !== []) {
            /** @var array{added: int, removed: int} $links */
            $links = $reindex->execute(new ReindexMediaUsagesInput($ids));
            $added += $links['added'];
            $removed += $links['removed'];
            $last = $ids[array_key_last($ids)];
            $done += count($ids);
        }

        $this->info("Re-derived media usage and links for {$done} content items: {$added} links added, {$removed} removed.");

        return self::SUCCESS;
    }
}
