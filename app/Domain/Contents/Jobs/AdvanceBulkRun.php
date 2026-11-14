<?php

declare(strict_types=1);

namespace App\Domain\Contents\Jobs;

use App\Domain\Contents\BulkRunWords;
use App\Domain\Contents\Models\BulkRun;
use App\Domain\Contents\Operations\DeleteContentPermanently;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\Input\RecordBulkRunStepInput;
use App\Domain\Contents\Operations\RecordBulkRunStep;
use App\Domain\Contents\Operations\RestoreContent;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Contents\Queries\ContentList;
use App\Domain\Contents\Queries\ContentListFilters;
use App\Domain\Jobs\Operations\FailJob;
use App\Domain\Jobs\Operations\FinishJob;
use App\Domain\Jobs\Operations\Input\FailJobInput;
use App\Domain\Jobs\Operations\Input\FinishJobInput;
use App\Domain\Jobs\Operations\Input\ReportJobProgressInput;
use App\Domain\Jobs\Operations\ReportJobProgress;
use App\Domain\Support\Security\JobActor;
use App\Domain\Types\ContentTypeRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Settings\SettingsReader;
use Throwable;

/**
 * One step of Select all matching (I-143): the next hundred rows of the run's list, as its person sees them, each through its own Operation, then the run's record and the job's progress, then the next step or the end. It carries the run's ID and its person (I-392), nothing else.
 */
final class AdvanceBulkRun implements ShouldQueue
{
    use Queueable;

    /** The rows one step reads and acts on. */
    public const int ROWS = 100;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    /** Under the database queue's retry_after of 90 seconds, so no second worker takes the step while this one has it. */
    public int $timeout = 75;

    public function __construct(
        public readonly string $runId,
        public readonly JobActor $actor,
    ) {}

    public function handle(ContentList $list, ContentTypeRegistry $types, BulkRunWords $words, SettingsReader $settings): void
    {
        $this->actor->bind();
        $actorId = $this->actor->userId;
        $run = BulkRun::query()->find($this->runId);

        // Its person's account went, and the run with it: nothing to carry on.
        if ($run === null) {
            return;
        }

        $jobId = (string) $run->tracked_job_id;

        // A cancelled or ended job answers false, and nothing more is done (I-104).
        if (! app(ReportJobProgress::class)->execute(new ReportJobProgressInput($jobId, actorId: $actorId))) {
            return;
        }

        $type = $types->get($run->content_type);
        $filters = ContentListFilters::read($run->filters, $type, $settings->string('general', 'timezone'));
        $from = $run->after;
        $page = $list->page($filters->request($type, (int) $run->user_id, self::ROWS, after: $from));
        $handled = 0;
        $skipped = [];
        $refusal = null;

        // One Operation per row, each in its own transaction (I-143).
        foreach ($page['rows'] as $row) {
            // Created, saved, moved or rescheduled since the person confirmed:
            // not what they chose, in whatever order the list walks (I-143).
            if (CarbonImmutable::parse((string) $row['updatedAt'])->greaterThan($run->created_at)) {
                continue;
            }

            $handled++;

            try {
                $this->apply($run->action, (string) $row['id'], $actorId);
            } catch (AuthorizationException) {
                $skipped[BulkRunWords::NOT_ALLOWED] = ($skipped[BulkRunWords::NOT_ALLOWED] ?? 0) + 1;
            } catch (ModelNotFoundException) {
                $skipped[BulkRunWords::GONE] = ($skipped[BulkRunWords::GONE] ?? 0) + 1;
            } catch (ValidationException $refused) {
                $skipped[BulkRunWords::REFUSED] = ($skipped[BulkRunWords::REFUSED] ?? 0) + 1;
                $refusal ??= mb_substr((string) collect($refused->errors())->flatten()->first(), 0, 300);
            }
        }

        // Another worker took this step and recorded it first: it carries on, this one stops.
        if (! app(RecordBulkRunStep::class)->execute(new RecordBulkRunStepInput($run->id, $from, $page['older'], $handled, $skipped, $refusal, $actorId))) {
            return;
        }

        $run->refresh();
        $skippedSoFar = array_sum($run->skipped);

        $wanted = app(ReportJobProgress::class)->execute(new ReportJobProgressInput($jobId, advancedBy: $handled, message: $words->soFar($skippedSoFar), actorId: $actorId));

        if (! $wanted) {
            return;
        }

        if ($page['older'] === null) {
            app(FinishJob::class)->execute(new FinishJobInput($jobId, $words->closing($type, $run->action, $run->done - $skippedSoFar, $run->skipped, $run->refusal), $actorId));

            return;
        }

        self::dispatch($this->runId, $this->actor);
    }

    /**
     * Ends the job as failed once the queue has given up on the step, in words
     * that say how far the run got; Retry carries on from there.
     */
    public function failed(Throwable $exception): void
    {
        $run = BulkRun::query()->find($this->runId);

        if ($run === null) {
            return;
        }

        $type = app(ContentTypeRegistry::class)->get($run->content_type);

        app(FailJob::class)->execute(new FailJobInput((string) $run->tracked_job_id, app(BulkRunWords::class)->stopped($type, $run->done), (string) $run->user_id));
    }

    private function apply(string $action, string $contentId, ?string $actorId): void
    {
        $input = new ContentActionInput($contentId, $actorId);

        match ($action) {
            'trash' => app(TrashContent::class)->execute($input),
            'restore' => app(RestoreContent::class)->execute($input),
            default => app(DeleteContentPermanently::class)->execute($input),
        };
    }
}
