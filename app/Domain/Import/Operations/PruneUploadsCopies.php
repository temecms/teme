<?php

/**
 * Deletes the uploads copies Teme no longer needs: an upload nobody has
 * written to for a day, and a copy Teme stored itself that no import from
 * its site has used for 30 days, and that no run is still using. A copy the
 * operator attached by path is never deleted. Its files go after the commit.
 * Returns how many were deleted. Needs `imports.run`, or the operator.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportUpload;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Uploads\KeptUploads;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Operations\OperationInput;

final class PruneUploadsCopies extends BaseOperation
{
    use AuthorizesImports;

    /** An upload nobody has written to for this many hours is abandoned. */
    private const int ABANDONED_HOURS = 24;

    /** A copy no import from its site has used for this many days is deleted. */
    private const int UNUSED_DAYS = 30;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        // Nothing to refuse: the prune deletes only what is due.
    }

    /**
     * @return int how many uploads and copies were deleted
     */
    protected function perform(OperationInput $input): int
    {
        $abandonedBefore = now()->subHours(self::ABANDONED_HOURS);
        $unusedBefore = now()->subDays(self::UNUSED_DAYS);

        $due = ImportUpload::query()
            ->where(fn ($query) => $query
                ->where(fn ($upload) => $upload->where('state', 'uploading')->where('updated_at', '<', $abandonedBefore))
                ->orWhere(fn ($copy) => $copy
                    ->where('state', 'ready')
                    ->where('kept_by_teme', true)
                    ->where('updated_at', '<', $unusedBefore)
                    ->whereNotExists(fn (Builder $runs) => $runs->selectRaw('1')
                        ->from('import_runs')
                        ->whereColumn('import_runs.source_id', 'import_uploads.source_id')
                        ->where(fn (Builder $when) => $when
                            ->where('import_runs.state', ImportRunState::Running->value)
                            // A run that never ended counts from when it started.
                            ->orWhereRaw('COALESCE(import_runs.ended_at, import_runs.started_at) >= ?', [$unusedBefore])))))
            ->lockForUpdate()
            ->get();

        foreach ($due as $upload) {
            DB::afterCommit(fn () => KeptUploads::forget($upload));
            $upload->delete();
        }

        return $due->count();
    }
}
