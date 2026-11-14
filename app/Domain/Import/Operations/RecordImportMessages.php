<?php

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportMessageRecord;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Input\RecordImportMessagesInput;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Records a run's own messages (I-345), those that belong to no object: a step's notes. Does nothing for a stopped attempt's token.
 */
final class RecordImportMessages extends BaseOperation
{
    use AuthorizesImports;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var RecordImportMessagesInput $input */
        $ulid = $input->runId;

        if (! Str::isUlid($ulid)) {
            throw new InvalidArgumentException('An import run needs a run ID.');
        }
    }

    protected function perform(OperationInput $input): int
    {
        /** @var RecordImportMessagesInput $input */
        $run = ImportRun::query()->whereKey($input->runId)->sharedLock()->first();

        if ($run === null || $run->state !== ImportRunState::Running || $run->phase_token !== $input->token) {
            return 0;
        }

        $now = now();

        foreach ($input->messages as $message) {
            ImportMessageRecord::query()->create([
                'run_id' => $run->id, 'map_id' => null, 'level' => $message->level, 'code' => $message->code,
                'message' => $message->message, 'context' => $message->context, 'created_at' => $now,
            ]);
        }

        return count($input->messages);
    }
}
