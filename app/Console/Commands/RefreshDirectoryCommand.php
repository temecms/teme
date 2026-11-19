<?php

/**
 * teme:directory:refresh — asks Teme's plugin and theme directory for its
 * signed index as the operator, and keeps it once it is checked.
 *
 * @package Teme
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Extensions\Directory\DirectorySettings;
use App\Domain\Extensions\Directory\RefreshDirectory;
use App\Domain\Extensions\Queries\DirectoryPackages;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;

final class RefreshDirectoryCommand extends Command
{
    /** @var string */
    protected $signature = 'teme:directory:refresh';

    /** @var string */
    protected $description = 'Ask the plugin and theme directory for its signed list, sending nothing about this site';

    public function handle(RefreshDirectory $refresh, DirectoryPackages $packages): int
    {
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        if (! DirectorySettings::enabled()) {
            $this->line('The plugin and theme directory is switched off on this site, so nothing was asked.');

            return self::SUCCESS;
        }

        $refused = $refresh->run();

        if ($refused !== null) {
            $this->line($refused);

            return self::FAILURE;
        }

        $this->line('Kept the directory’s list number '.$packages->state()['sequence'].'.');

        return self::SUCCESS;
    }
}
