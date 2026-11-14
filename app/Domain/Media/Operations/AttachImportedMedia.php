<?php

declare(strict_types=1);

namespace App\Domain\Media\Operations;

use App\Domain\Contents\Models\Content;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Operations\Input\AttachImportedMediaInput;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Records the post or page a file was uploaded for, from an imported site's
 * attachment parent (I-553), only while it records none, so the first parent
 * stays. Only an import's actor may call it.
 */
final class AttachImportedMedia extends BaseOperation
{
    use AuthorizesImports;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var AttachImportedMediaInput $input */
        if (! Media::withTrashed()->whereKey($input->mediaId)->exists()) {
            throw ValidationException::withMessages(['media' => 'The item to attach no longer exists.']);
        }

        if (! Content::withTrashed()->whereKey($input->contentId)->exists()) {
            throw ValidationException::withMessages(['content' => 'The post or page it was uploaded for no longer exists.']);
        }
    }

    protected function perform(OperationInput $input): bool
    {
        /** @var AttachImportedMediaInput $input */
        return Media::withTrashed()->whereKey($input->mediaId)->whereNull('uploaded_for')->update(['uploaded_for' => $input->contentId]) > 0;
    }
}
