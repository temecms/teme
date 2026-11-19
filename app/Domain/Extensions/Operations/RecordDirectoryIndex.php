<?php

/**
 * Records one ask of the plugin and theme directory: an index whose
 * signature was checked replaces the one kept, unless its number is lower
 * than the kept one's; a refusal keeps the last good index and says why.
 * The operator, or a person who installs plugins and themes. Returns the
 * number of the index kept, or null when none is.
 *
 * @package Teme
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions\Operations;

use App\Domain\Extensions\Directory\DirectoryIndex;
use App\Domain\Extensions\Directory\DirectoryRefused;
use App\Domain\Extensions\Operations\Concerns\AuthorizesExtensionChanges;
use App\Domain\Extensions\Operations\Input\RecordDirectoryIndexInput;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class RecordDirectoryIndex extends BaseOperation
{
    use AuthorizesExtensionChanges;

    /** The advisory lock that keeps two asks from interleaving. */
    public const string LOCK = 'teme.extensions.directory';

    protected function validate(OperationInput $input): void
    {
        /** @var RecordDirectoryIndexInput $input */
        if (($input->index === null) === ($input->error === null)) {
            throw ValidationException::withMessages(['index' => ['Record either an index or the reason there is none.']]);
        }

        if ($input->index !== null) {
            try {
                DirectoryIndex::read($input->index);
            } catch (DirectoryRefused $refused) {
                throw ValidationException::withMessages(['index' => [$refused->getMessage()]]);
            }
        }
    }

    protected function perform(OperationInput $input): ?int
    {
        /** @var RecordDirectoryIndexInput $input */
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [self::LOCK]);
        $kept = DB::table('directory_index')->where('id', 1)->value('sequence');
        $kept = $kept === null ? null : (int) $kept;

        if ($input->index === null) {
            $this->failed($input, (string) $input->error);

            return $kept;
        }

        $index = DirectoryIndex::read($input->index);

        // Checked again under the lock: two asks at once mustn't let an older list win.
        if ($kept !== null && $index->sequence < $kept) {
            $this->failed($input, DirectoryRefused::older($index->sequence, $kept)->getMessage());

            return $kept;
        }

        DB::table('directory_index')->upsert([[
            'id' => 1,
            'sequence' => $index->sequence,
            'generated_at' => $index->generated,
            'expires_at' => $index->expires,
            'index' => json_encode($input->index, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'checked_at' => $input->at,
            'succeeded_at' => $input->at,
            'error' => null,
        ]], ['id'], ['sequence', 'generated_at', 'expires_at', 'index', 'checked_at', 'succeeded_at', 'error']);

        return $index->sequence;
    }

    private function failed(RecordDirectoryIndexInput $input, string $error): void
    {
        DB::table('directory_index')->upsert([[
            'id' => 1,
            'checked_at' => $input->at,
            'error' => $error,
        ]], ['id'], ['checked_at', 'error']);
    }
}
