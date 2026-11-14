<?php

declare(strict_types=1);

namespace App\Domain\Import\Jobs;

use App\Domain\Import\ImportEngine;
use App\Domain\Import\ImportMode;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Jobs\Concerns\EndsRuns;
use App\Domain\Import\Jobs\Concerns\WaitsForTheSource;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Operations\Input\RecordImportMessagesInput;
use App\Domain\Import\Operations\RecordImportMessages;
use App\Domain\Import\Phases\ImportPhases;
use App\Domain\Import\Phases\ImportStopped;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Queries\ImportRuns;
use App\Domain\Import\Source\CompanionClients;
use App\Domain\Import\Source\SourceRefused;
use App\Domain\Support\Network\AddressRefused;
use App\Domain\Support\Security\JobActor;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs one job of a step phase, in import mode, from its cursor, and queues the next when the step names one (I-332). It carries IDs and the cursor only, and does nothing once its run has stopped or moved on.
 */
final class RunImportStep implements ShouldQueue
{
    use Batchable;
    use EndsRuns;
    use Queueable;
    use WaitsForTheSource;

    /** One more than the waits for the old site to answer again; any other error fails the step after three. */
    public int $tries = 10;

    public int $maxExceptions = 3;

    /** A worker that died during the step counts as one of its three errors. */
    public bool $countCrashesAsExceptions = true;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /** Under the database queue's retry_after of 90 seconds, so no second worker takes the step while this one has it. */
    public int $timeout = 75;

    public function __construct(
        public readonly string $runId,
        public readonly string $token,
        public readonly string $phase,
        public readonly ?string $cursor,
        public readonly JobActor $actor,
    ) {}

    public function handle(ImportPhases $phases, ImportRuns $runs, CompanionClients $clients, ImportMode $mode, Actors $actors): void
    {
        $this->actor->bind();
        $run = ImportRun::query()->with('source')->find($this->runId);
        $phase = $phases->find($this->phase);

        if ($run === null || $run->state !== ImportRunState::Running || $run->phase_token !== $this->token || ! $phase instanceof StepPhase) {
            return;
        }

        $context = $runs->context($run, $this->actor);

        if ($this->actor->userId !== null) {
            $actors->keep($this->actor->userId);
        }

        try {
            $result = $mode->during($this->runId, fn (): StepResult => $phase->run($context, $clients->for($run->source), $this->cursor));
        } catch (ImportStopped $stop) {
            $actors->forget();
            $this->stopRun($stop->reason, $stop->words, $stop->details);

            return;
        } catch (SourceRefused $refusal) {
            $actors->forget();

            if ($refusal->retryable) {
                $this->waitForSource($refusal);

                return;
            }

            $this->stopRun($refusal->reason, $refusal->sentence().ImportEngine::AGAIN);

            return;
        } catch (AddressRefused $refusal) {
            $actors->forget();
            $this->stopRun($refusal->reason, $refusal->sentence().ImportEngine::AGAIN);

            return;
        } catch (AuthorizationException $refused) {
            $actors->forget();
            $this->fail($refused);

            return;
        }

        try {
            if ($result->messages !== []) {
                app(RecordImportMessages::class)->execute(new RecordImportMessagesInput($this->runId, $this->token, $result->messages, $this->actor->userId));
            }
        } finally {
            $actors->forget();
        }

        if ($result->cursor !== null) {
            $this->batch()?->add([
                (new self($this->runId, $this->token, $this->phase, $result->cursor, $this->actor))->delay(now()->addMilliseconds($context->pauseMs)),
            ]);
        }
    }
}
