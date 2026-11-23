<?php

/**
 * Reads one page of one list of a list phase and imports its objects, each
 * through ImportObject, in import mode; then queues the list's next page,
 * so a list is read in ID order (I-332). It carries IDs only, never the old
 * site's data, and does nothing once its run has stopped or moved on.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Jobs;

use App\Domain\Import\ImportEngine;
use App\Domain\Import\ImportMode;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Jobs\Concerns\EndsRuns;
use App\Domain\Import\Jobs\Concerns\WaitsForTheSource;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Operations\ImportObject;
use App\Domain\Import\Operations\Input\ImportObjectInput;
use App\Domain\Import\Operations\Input\RecordImportFailureInput;
use App\Domain\Import\Operations\RecordImportFailure;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportFailed;
use App\Domain\Import\Phases\ImportList;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\ImportPhases;
use App\Domain\Import\Phases\ImportStopped;
use App\Domain\Import\Phases\ListPhase;
use App\Domain\Import\Phases\PreparesObjects;
use App\Domain\Import\Phases\SourceRef;
use App\Domain\Import\Queries\ImportMap;
use App\Domain\Import\Queries\ImportRuns;
use App\Domain\Import\Source\SourcePage;
use App\Domain\Import\Source\SourceReader;
use App\Domain\Import\Source\SourceReaders;
use App\Domain\Import\Source\SourceRefused;
use App\Domain\Support\Network\AddressRefused;
use App\Domain\Support\Security\JobActor;
use App\Domain\Users\Actors;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ImportListPage implements ShouldQueue
{
    use Batchable;
    use EndsRuns;
    use Queueable;
    use WaitsForTheSource;

    /** One more than the waits for the old site to answer again; any other error fails the page after three. */
    public int $tries = 10;

    public int $maxExceptions = 3;

    /** A worker that died during the page counts as one of its three errors. */
    public bool $countCrashesAsExceptions = true;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /** Under the database queue's retry_after of 90 seconds, so no second worker takes the page while this one has it. */
    public int $timeout = 75;

    /**
     * The seconds into the job after which a paged list's page starts no
     * new object, its first always, and leaves the rest to the next job: the
     * slowest object, an attachment's MediaPhase::ITEM_SECONDS, still ends
     * inside $timeout (#254).
     */
    public const int SECONDS = 45;

    public function __construct(
        public readonly string $runId,
        public readonly string $token,
        public readonly string $phase,
        public readonly int $list,
        public readonly int $afterId,
        public readonly JobActor $actor,
    ) {}

    public function handle(ImportPhases $phases, ImportRuns $runs, SourceReaders $readers, ImportMap $map, ImportMode $mode, Actors $actors): void
    {
        $deadline = CarbonImmutable::now()->addSeconds(self::SECONDS);
        $this->actor->bind();
        $run = ImportRun::query()->with('source')->find($this->runId);
        $phase = $phases->find($this->phase);

        if ($run === null || $run->state !== ImportRunState::Running || $run->phase_token !== $this->token || ! $phase instanceof ListPhase) {
            return;
        }

        $context = $runs->context($run, $this->actor);
        $list = $phase->lists($context)[$this->list] ?? null;

        if ($list === null) {
            return;
        }

        try {
            $page = $this->read($readers->for($run), $list);
        } catch (SourceRefused $refusal) {
            if ($refusal->retryable) {
                $this->waitForSource($refusal);

                return;
            }

            $this->stopRun($refusal->reason, $refusal->sentence().ImportEngine::AGAIN);

            return;
        } catch (AddressRefused $refusal) {
            $this->stopRun($refusal->reason, $refusal->sentence().ImportEngine::AGAIN);

            return;
        }

        if ($this->actor->userId !== null) {
            $actors->keep($this->actor->userId);
        }

        $next = $page->nextAfterId;

        try {
            $stop = $mode->during($this->runId, function () use ($phase, $list, $page, $context, $map, $run, $deadline, &$next): ?ImportStopped {
                return $this->importAll($phase, $list, $page, $context, $map, (string) $run->source_id, $deadline, $next);
            });
        } catch (AuthorizationException $refused) {
            // The actor can no longer import: nothing more is written, and no try again would help.
            $this->fail($refused);

            return;
        } finally {
            $actors->forget();
        }

        // Outside import mode, so the stop's one purge of everything isn't held.
        if ($stop !== null) {
            $this->stopRun($stop->reason, $stop->words, $stop->details);

            return;
        }

        if ($next !== null) {
            $this->batch()?->add([
                (new self($this->runId, $this->token, $this->phase, $this->list, $next, $this->actor))->delay(now()->addMilliseconds($context->pauseMs)),
            ]);
        }
    }

    /**
     * The list's page at this job's place, or the whole list's items.
     *
     * @throws SourceRefused
     */
    private function read(SourceReader $reader, ImportList $list): SourcePage
    {
        if ($list->paged) {
            return $reader->page($list->name, $list->query, $this->afterId, $list->limit);
        }

        $items = $reader->whole($list->name, $list->query)[(string) $list->itemsAt] ?? null;

        if (! is_array($items)) {
            throw SourceRefused::badAnswer();
        }

        return new SourcePage($items, null);
    }

    /**
     * Imports each object of the page on the work list; the stop a phase
     * asked for on the way, or null. Once $deadline has passed, a paged list
     * starts no new object: $next becomes the ID of the last one done, so the
     * next page starts right after it (#254).
     */
    private function importAll(ListPhase $phase, ImportList $list, SourcePage $page, ImportContext $context, ImportMap $map, string $sourceId, CarbonImmutable $deadline, ?int &$next): ?ImportStopped
    {
        $done = null;

        foreach ($page->items as $key => $item) {
            if ($done !== null && $list->paged && CarbonImmutable::now()->greaterThanOrEqualTo($deadline)) {
                $next = (int) $done['id'];

                return null;
            }

            $done = $item;
            $ref = $phase->identify($list, $item, $key);

            if (isset($item['error'])) {
                $error = Str::limit((string) preg_replace('/\W/', '', class_basename((string) strtok((string) $item['error'], ':'))), 80, '');
                $this->recordFailure($context, $ref, ImportMessage::error('source.unreadable', "WordPress couldn’t load this one ({$error}), so it wasn’t imported."));

                continue;
            }

            $hash = ImportMap::hashOf(self::hashable($item), $list->ignore);

            if (! ImportMap::wants($map->entry($sourceId, $ref), $hash)) {
                continue;
            }

            $stop = $this->importOne($phase, $ref, $item, $hash, $context);

            if ($stop !== null) {
                return $stop;
            }
        }

        return null;
    }

    /**
     * One object, prepared outside its transaction when its phase asks, and
     * released whatever happens; the stop its phase asked for, or null.
     *
     * @param  array<string, mixed>  $item
     */
    private function importOne(ListPhase $phase, SourceRef $ref, array $item, string $hash, ImportContext $context): ?ImportStopped
    {
        $prepared = null;

        try {
            $prepared = $phase instanceof PreparesObjects ? $phase->prepare($ref, $item, $context) : null;
            app(ImportObject::class)->execute(new ImportObjectInput($this->token, $this->phase, $context, $ref, $item, $hash, $prepared));
        } catch (ImportStopped $stop) {
            return $stop;
        } catch (AuthorizationException $refused) {
            throw $refused;
        } catch (ImportFailed $failed) {
            $this->recordFailure($context, $ref, $failed->why);
        } catch (ValidationException $refused) {
            $reason = (string) (Arr::first(Arr::flatten($refused->errors())) ?? '');
            $this->recordFailure($context, $ref, ImportMessage::error('object.refused', "Teme refused it: {$reason}"));
        } catch (Throwable $failure) {
            report($failure);
            $error = class_basename($failure);
            $this->recordFailure($context, $ref, ImportMessage::error('object.unexpected', "Teme couldn’t import this because of an unexpected error ({$error}). The application log has the details."));
        } finally {
            $prepared?->release();
        }

        return null;
    }

    private function recordFailure(ImportContext $context, SourceRef $ref, ImportMessage $why): void
    {
        app(RecordImportFailure::class)->execute(new RecordImportFailureInput($this->runId, $this->token, $ref, $why, $context->actorId()));
    }

    /**
     * The item as its hash reads it: a post's password keyed with the site's
     * key first, so the map holds no unkeyed hash a copy of the database
     * could test guesses against, while a changed password still changes the
     * hash; any other item as it is (I-1700). The phase reads the item itself.
     *
     * @param  array<string, mixed>  $item
     *
     * @return array<string, mixed>
     */
    private static function hashable(array $item): array
    {
        if (is_string($item['password'] ?? null) && $item['password'] !== '') {
            $item['password'] = hash_hmac('sha256', $item['password'], (string) config('app.key'));
        }

        return $item;
    }
}
