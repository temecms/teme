<?php

/**
 * teme:import:prune-files, run daily: deletes each uploaded export file 30
 * days after the last import from it ended, since a file holds drafts,
 * private posts and people's email addresses. What was imported stays, and
 * so does each import's report.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Import\Operations\DeleteImportFile;
use App\Domain\Import\Operations\Input\DeleteImportFileInput;
use App\Domain\Import\Queries\ImportFiles;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class PruneImportFiles extends Command
{
    /** @var string */
    protected $signature = 'teme:import:prune-files';

    /** @var string */
    protected $description = 'Delete uploaded WordPress export files 30 days after the last import from them ended';

    public function handle(ImportFiles $files, DeleteImportFile $delete): int
    {
        // The operator, as teme:trash:purge is (docs/architecture/operations.md).
        $this->laravel->instance(Elevation::class, new OperatorElevation);
        $deleted = 0;

        foreach ($files->due() as $fileId) {
            try {
                $delete->execute(new DeleteImportFileInput($fileId, null));
                $deleted++;
            } catch (ValidationException) {
                // Deleted meanwhile, from the report.
            }
        }

        $this->info(trans_choice('Deleted :count export file.|Deleted :count export files.', $deleted));

        return self::SUCCESS;
    }
}
