<?php

/**
 * teme:import:uploads: attaches a folder, or a .zip, of an old site's uploads
 * folder that is already on this server to the site's import, so its
 * pictures come from the copy instead of the old site. Read in place; Teme
 * never deletes or changes it. For a copy too large to upload in the browser.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Import\Operations\AttachUploadsCopy;
use App\Domain\Import\Operations\Input\AttachUploadsCopyInput;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class ImportUploads extends Command
{
    /** @var string */
    protected $signature = 'teme:import:uploads
        {source : The import site’s ID or its address}
        {path : A folder, or a .zip, of the old site’s wp-content/uploads already on this server}';

    /** @var string */
    protected $description = 'Attach a copy of an old site’s uploads folder to its import, read in place';

    public function handle(AttachUploadsCopy $attach): int
    {
        // The operator, as teme:import:prune-files is (docs/architecture/operations.md).
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        try {
            $result = $attach->execute(new AttachUploadsCopyInput(
                (string) $this->argument('source'),
                (string) $this->argument('path'),
                null,
            ));
        } catch (ValidationException $refused) {
            $this->error((string) (collect($refused->errors())->flatten()->first() ?? 'The copy couldn’t be attached.'));

            return self::FAILURE;
        }

        $this->info("Attached the {$result['kind']} at {$result['path']} to the import site {$result['sourceId']}.");

        return self::SUCCESS;
    }
}
