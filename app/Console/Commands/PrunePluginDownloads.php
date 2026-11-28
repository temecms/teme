<?php

/**
 * teme:plugin-downloads:prune, run daily: removes the files plugins downloaded
 * more than a day ago and did not remove themselves (DownloadFolders).
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Plugins\Downloads\DownloadFolders;
use Illuminate\Console\Command;

final class PrunePluginDownloads extends Command
{
    /** @var string */
    protected $signature = 'teme:plugin-downloads:prune';

    /** @var string */
    protected $description = 'Delete the files plugins downloaded more than a day ago';

    public function handle(): int
    {
        $this->info(trans_choice('Deleted :count plugin download.|Deleted :count plugin downloads.', DownloadFolders::sweep()));

        return self::SUCCESS;
    }
}
