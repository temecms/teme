<?php

declare(strict_types=1);

namespace App\Domain\Import\Operations\Concerns;

use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Models\ImportMessageRecord;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Phases\ImportMessage;

/**
 * What the Operations a step calls on one object share (I-332): the run,
 * held while it writes so a stop waits for it, and the object's own
 * messages of one kind written again, each run listing each once.
 */
trait NotesObjects
{
    /**
     * The run, read FOR SHARE, when it is running; else null. A step is
     * given no token, so this checks the state, as ClearRunMessages does.
     */
    private function running(string $runId): ?ImportRun
    {
        $run = ImportRun::query()->whereKey($runId)->sharedLock()->first();

        return $run !== null && $run->state === ImportRunState::Running ? $run : null;
    }

    /**
     * The row's messages whose code begins with $prefix, replaced by these,
     * written in run $runId; its other messages kept.
     *
     * @param  list<ImportMessage>  $messages
     */
    private function noteMessages(ImportMapEntry $entry, string $runId, string $prefix, array $messages): void
    {
        ImportMessageRecord::query()->where('map_id', $entry->id)->where('code', 'like', $prefix.'%')->delete();
        $now = now();

        foreach ($messages as $message) {
            ImportMessageRecord::query()->create([
                'run_id' => $runId, 'map_id' => $entry->id, 'level' => $message->level, 'code' => $message->code,
                'message' => $message->message, 'context' => $message->context, 'created_at' => $now,
            ]);
        }
    }
}
