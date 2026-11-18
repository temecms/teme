<?php

/**
 * Marks for the content phase, once, the posts and pages an import made
 * before Teme kept items private or protected (I-2643): drafts whose map
 * rows say WordPress had them so, brought again as they were. Only an
 * import's actor may call it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\ImportStatus;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Input\MarkVisibilityWaitingInput;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class MarkVisibilityWaiting extends BaseOperation
{
    use AuthorizesImports;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var MarkVisibilityWaitingInput $input */
        if (! ImportSource::query()->whereKey($input->sourceId)->exists()) {
            throw ValidationException::withMessages(['source' => 'The import source no longer exists.']);
        }
    }

    protected function perform(OperationInput $input): int
    {
        /** @var MarkVisibilityWaitingInput $input */
        return ImportMapEntry::query()
            ->where('source_id', $input->sourceId)
            ->where('object_type', 'post')
            ->where('teme_type', 'content')
            ->where('status', ImportStatus::Imported->value)
            ->where(fn (Builder $either) => $either
                ->whereRaw('facts @> ?::jsonb', ['{"visibility": "private"}'])
                ->orWhereRaw('facts @> ?::jsonb', ['{"visibility": "protected"}']))
            // Once: the content phase never writes this fact, and the map's merge keeps it.
            ->whereRaw('NOT (facts @> ?::jsonb)', ['{"visibility_marked": true}'])
            // Still as an import that couldn't keep it left it: public here.
            ->whereExists(fn ($item) => $item->selectRaw('1')->from('contents')
                ->whereColumn('contents.id', 'import_map.teme_id')
                ->where('contents.visibility', 'public'))
            ->update([
                'status' => ImportStatus::NeedsUpdate->value,
                'facts' => DB::raw("facts || '{\"visibility_marked\": true}'::jsonb"),
            ]);
    }
}
