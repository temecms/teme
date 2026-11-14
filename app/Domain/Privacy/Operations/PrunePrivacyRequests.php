<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Operations;

use App\Domain\Privacy\ExportFile;
use App\Domain\Privacy\Models\PrivacyRequest;
use App\Domain\Privacy\Operations\Input\PrunePrivacyRequestsInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Operations\OperationInput;

/**
 * Keeps privacy requests only as long as they are needed (I-3375): stops a
 * request left unfinished for 7 days, deletes every file of an export after
 * 7 days whatever its state, and of a failed one at once, clears the
 * address and the account after 30, keeping the hash, and deletes the
 * record after a year. The files go after commit. The operator's daily run
 * alone.
 */
final class PrunePrivacyRequests extends BaseOperation
{
    public const int EXPORT_DAYS = 7;

    public const int ADDRESS_DAYS = 30;

    public const int RECORD_DAYS = 365;

    protected function authorize(OperationInput $input): void
    {
        /** @var PrunePrivacyRequestsInput $input */
        if ($input->actorId() === null && Operator::isPresent()) {
            return;
        }

        throw new AuthorizationException(__('Not permitted to handle privacy requests.'));
    }

    protected function validate(OperationInput $input): void
    {
        //
    }

    /**
     * @return array{stopped: int, files: int, hashed: int, deleted: int, paths: list<string>}
     */
    protected function perform(OperationInput $input): array
    {
        // The cut-offs from the application's clock, bound, not the database's now().
        $now = now();
        $at = fn (int $days): string => $now->copy()->subDays($days)->format('Y-m-d H:i:s.uP');
        $files = app(ExportFile::class);
        $paths = [];

        $stopped = DB::select(<<<'SQL'
            UPDATE privacy_requests SET status = 'failed', failure = ?, completed_at = ?::timestamptz, updated_at = ?::timestamptz
            WHERE status IN ('pending', 'running') AND created_at <= ?::timestamptz
            RETURNING id, kind, results
            SQL, [__('Not finished within 7 days.'), $at(0), $at(0), $at(self::EXPORT_DAYS)]);

        // RETURNING gives the row as updated, so the old path is read beside it.
        $cleared = DB::select(<<<'SQL'
            UPDATE privacy_requests SET export_path = NULL, updated_at = ?::timestamptz
            FROM (
                SELECT id, export_path AS path FROM privacy_requests
                WHERE export_path IS NOT NULL AND coalesce(completed_at, created_at) <= ?::timestamptz
                FOR UPDATE
            ) AS due
            WHERE privacy_requests.id = due.id
            RETURNING due.id, due.path AS export_path
            SQL, [$at(0), $at(self::EXPORT_DAYS)]);

        $hashed = DB::update(<<<'SQL'
            UPDATE privacy_requests SET email = NULL, user_id = NULL, updated_at = ?::timestamptz
            WHERE (email IS NOT NULL OR user_id IS NOT NULL) AND coalesce(completed_at, created_at) <= ?::timestamptz
            SQL, [$at(0), $at(self::ADDRESS_DAYS)]);

        // Every file of an export whose run ended or stopped, by name: a killed
        // after-commit step can leave pages, or the file, that nothing else deletes.
        $swept = DB::select(<<<'SQL'
            SELECT id, kind, results FROM privacy_requests
            WHERE kind = 'export' AND (status = 'failed' OR coalesce(completed_at, created_at) <= ?::timestamptz)
            SQL, [$at(self::EXPORT_DAYS)]);

        $deleted = DB::select(<<<'SQL'
            DELETE FROM privacy_requests WHERE coalesce(completed_at, created_at) <= ?::timestamptz
            RETURNING id, kind, results
            SQL, [$at(self::RECORD_DAYS)]);

        foreach ($cleared as $row) {
            $paths[] = (string) $row->export_path;
        }

        foreach ([...$swept, ...$deleted] as $row) {
            if ($row->kind === PrivacyRequest::EXPORT) {
                $results = json_decode((string) $row->results, true, flags: JSON_THROW_ON_ERROR);
                array_push($paths, ...$files->allPaths((string) $row->id, is_array($results) ? array_values($results) : []));
            }
        }

        return ['stopped' => count($stopped), 'files' => count($cleared), 'hashed' => $hashed, 'deleted' => count($deleted), 'paths' => array_values(array_unique($paths))];
    }

    protected function afterCommit(mixed $result): void
    {
        /** @var array{paths: list<string>} $result */
        app(ExportFile::class)->delete($result['paths']);
    }
}
