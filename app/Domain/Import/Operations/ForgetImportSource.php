<?php

/**
 * Erases a source's application password and nothing else (I-1708): the
 * site, its runs, its map and its messages stay, since the report reads
 * them. Refused while a run is running, which needs the password.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Input\ForgetImportSourceInput;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class ForgetImportSource extends BaseOperation
{
    use AuthorizesImports;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ForgetImportSourceInput $input */
        Validator::make(
            ['source' => $input->sourceId],
            ['source' => ['required', 'string', Rule::exists('import_sources', 'id')]],
        )->validate();
    }

    protected function perform(OperationInput $input): mixed
    {
        /** @var ForgetImportSourceInput $input */
        $source = ImportSource::query()->whereKey($input->sourceId)->lockForUpdate()->firstOrFail();

        if ($source->runs()->where('state', ImportRunState::Running->value)->exists()) {
            throw ValidationException::withMessages(['source' => 'Stop the import that’s running first: it needs the application password.']);
        }

        $source->forceFill(['credential' => null, 'forgotten_at' => now()])->save();

        app(SecurityLog::class)->record(SecurityEventType::ImportSourceForgotten, $input->actorId(), context: [
            'source' => (string) $source->id,
            'address' => $source->address,
        ]);

        return null;
    }
}
