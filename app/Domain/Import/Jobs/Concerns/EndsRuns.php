<?php

declare(strict_types=1);

namespace App\Domain\Import\Jobs\Concerns;

use App\Domain\Import\Operations\Input\RecordImportMessagesInput;
use App\Domain\Import\Operations\Input\StopImportRunInput;
use App\Domain\Import\Operations\RecordImportMessages;
use App\Domain\Import\Operations\StopImportRun;
use App\Domain\Import\Phases\ImportMessage;

/**
 * How a job of a run stops it: the details as the run's own messages, then
 * the stop, both for this attempt's token only.
 */
trait EndsRuns
{
    /**
     * @param  list<ImportMessage>  $details
     */
    private function stopRun(string $reason, string $words, array $details = []): void
    {
        if ($details !== []) {
            app(RecordImportMessages::class)->execute(new RecordImportMessagesInput($this->runId, $this->token, $details, $this->actor->userId));
        }

        app(StopImportRun::class)->execute(new StopImportRunInput($this->runId, $this->actor->userId, $this->token, $reason, $words));
    }
}
