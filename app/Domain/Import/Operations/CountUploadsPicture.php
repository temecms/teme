<?php

/**
 * Counts one picture an import brought, from the site's uploads copy or from
 * the old site, on the copy's record so the report can say how many came from
 * each. A site with no ready copy keeps no count. Needs `imports.run`.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\Models\ImportUpload;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Input\CountUploadsPictureInput;
use App\Domain\Support\Operations\BaseOperation;
use Teme\Contracts\Operations\OperationInput;

final class CountUploadsPicture extends BaseOperation
{
    use AuthorizesImports;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        // The upload is checked where it is read, under its lock.
    }

    protected function perform(OperationInput $input): mixed
    {
        /** @var CountUploadsPictureInput $input */
        ImportUpload::query()
            ->where('source_id', $input->sourceId)
            ->where('state', 'ready')
            ->increment($input->fromCopy ? 'copied_pictures' : 'site_pictures');

        return null;
    }
}
