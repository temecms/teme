<?php

/**
 * teme:updates:cancel — cancels the update request that waits, as the operator (I-3326), while the updater
 * hasn't begun it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Domain\Updates\Operations\CancelTemeUpdate;
use App\Domain\Updates\Operations\Input\CancelTemeUpdateInput;
use App\Domain\Updates\Queries\TemeUpdates;
use App\Domain\Updates\Requests\UpdateStatus;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class CancelUpdateCommand extends Command
{
    /** @var string */
    protected $signature = 'teme:updates:cancel';

    /** @var string */
    protected $description = 'Cancel the update that waits for the updater to begin it';

    public function handle(TemeUpdates $updates, CancelTemeUpdate $cancel): int
    {
        $this->laravel->instance(Elevation::class, new OperatorElevation);
        $open = $updates->open();

        if ($open === null || $open->status !== UpdateStatus::Requested) {
            $this->line('No update is waiting.');

            return self::FAILURE;
        }

        try {
            $cancel->execute(new CancelTemeUpdateInput($open->id, null));
        } catch (ValidationException) {
            // The updater began it between the look and the cancel.
            $this->line('No update is waiting.');

            return self::FAILURE;
        }

        $this->line("The update to Teme {$open->to} was cancelled.");

        return self::SUCCESS;
    }
}
