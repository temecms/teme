<?php

/**
 * Answers one request for an AI suggestion (I-320): asks the task's model,
 * and its fallback once if the first can't be reached, is busy or takes too
 * long, all within one deadline (I-1321); checks the answer twice; records the request's figures for
 * the person who asked; and leaves the answer for the page to collect. It
 * carries IDs and offsets, never text, so no queue row holds an article.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai\Asking\Jobs;

use App\Domain\Ai\AiAvailability;
use App\Domain\Ai\AiFailure;
use App\Domain\Ai\AiFailureType;
use App\Domain\Ai\AiOutcome;
use App\Domain\Ai\AiPrompt;
use App\Domain\Ai\AiRequest;
use App\Domain\Ai\AiSubject;
use App\Domain\Ai\AiSubjectType;
use App\Domain\Ai\AiTasks;
use App\Domain\Ai\AiUsage;
use App\Domain\Ai\Asking\AiSuggestions;
use App\Domain\Ai\Asking\AiTargets;
use App\Domain\Ai\Asking\Suggestion;
use App\Domain\Ai\Asking\SuggestionCheck;
use App\Domain\Ai\Exceptions\ActivityAlreadySettled;
use App\Domain\Ai\ModelCall;
use App\Domain\Ai\ModelChoices;
use App\Domain\Ai\ModelRef;
use App\Domain\Ai\Operations\Input\RecordAiActivityInput;
use App\Domain\Ai\Operations\Input\SettleAiActivityInput;
use App\Domain\Ai\Operations\RecordAiActivity;
use App\Domain\Ai\Operations\SettleAiActivity;
use App\Domain\Site\SiteLanguage;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class AnswerAiRequest implements ShouldQueue
{
    use Queueable;

    /** Every call to a model ends within this many seconds of the job's start. */
    public const int DEADLINE_SECONDS = 55;

    /** The fallback isn't asked with less time than this left (I-1321). */
    public const int FALLBACK_FLOOR_SECONDS = ModelCall::FALLBACK_FLOOR_SECONDS;

    /** A retry would pay for the answer twice. */
    public int $tries = 1;

    /** Under the worker's 60 seconds, and over the deadline. */
    public int $timeout = 58;

    /**
     * @param  array<string, string|int>  $options
     */
    public function __construct(
        public string $id,
        public string $task,
        public string $subjectType,
        public string $subjectId,
        public ?string $revisionId,
        public ?string $target,
        public array $options,
        public string $actorId,
    ) {}

    public function handle(AiTasks $tasks, ModelCall $modelCall, ModelChoices $choices, AiAvailability $availability, AiSuggestions $suggestions, AiTargets $targets, SuggestionCheck $check, SiteLanguage $language): void
    {
        $started = CarbonImmutable::now();

        // Cancelled before any model was asked: it cost nothing, so nothing is recorded.
        if ($suggestions->cancelled($this->id)) {
            $suggestions->forget($this->id);

            return;
        }

        // A task taken away since the asking has no prompt to record against.
        if (! $tasks->has($this->task)) {
            $suggestions->fail($this->id, AiFailureType::Unavailable->value, []);

            return;
        }

        $task = $tasks->get($this->task);
        $prompt = $task->prompt();
        $choice = $choices->for($this->task);

        // Switched off, or the task lost its model, since the asking.
        if ($choice === null || ! $availability->taskReady($this->task)) {
            $this->record($prompt, AiOutcome::Failed, AiFailureType::Unavailable, null, null, new AiUsage, null, $started);
            $suggestions->fail($this->id, AiFailureType::Unavailable->value, []);

            return;
        }

        $subject = new AiSubject(AiSubjectType::from($this->subjectType), $this->subjectId, $this->revisionId, $this->target, $this->options);

        try {
            $input = $task->input($subject);
            $hash = $targets->asked($subject);
            $fields = [...$input->fields, 'language' => $this->language($language, $subject)];
        } catch (ModelNotFoundException) {
            // Gone before anything was sent: no model was asked.
            $suggestions->fail($this->id, Suggestion::GONE, []);

            return;
        }

        $call = $modelCall->ask(
            $choice,
            fn (ModelRef $ref, int $seconds): AiRequest => new AiRequest($ref->model, $task->prompt()->messages($fields), $task->maxOutputTokens(), $seconds, $input->images, $task->outputSchema()),
            self::DEADLINE_SECONDS,
            $task->timeoutSeconds(),
            fn (): bool => ! $suggestions->cancelled($this->id),
            $started,
        );
        $sentTo = $call->sentTo;
        $ref = $call->ref;

        if ($call->failure !== null || $call->result === null) {
            $type = $call->failure ?? AiFailureType::Unavailable;
            $outcome = $type === AiFailureType::Refused ? AiOutcome::Refused : AiOutcome::Failed;
            $this->record($prompt, $outcome, $type, $ref->provider, $ref->model, $call->usage, $call->cost, $started);
            $suggestions->fail($this->id, $type->value, $sentTo);

            return;
        }

        $result = $call->result;
        $cost = $call->cost;

        try {
            $values = $check->values($task, $subject, $result);
        } catch (AiFailure) {
            // The answer was paid for, so its tokens and cost are recorded.
            $this->record($prompt, AiOutcome::Failed, AiFailureType::InvalidOutput, $ref->provider, $result->model, $result->usage, $cost, $started);
            $suggestions->fail($this->id, AiFailureType::InvalidOutput->value, $sentTo);

            return;
        }

        if ($suggestions->cancelled($this->id)) {
            $this->record($prompt, AiOutcome::Discarded, null, $ref->provider, $result->model, $result->usage, $cost, $started);
            $suggestions->forget($this->id);

            return;
        }

        $this->record($prompt, AiOutcome::Suggested, null, $ref->provider, $result->model, $result->usage, $cost, $started);
        $suggestions->ready($this->id, $values, $hash, $sentTo);

        // A cancel that crossed the answer: settled here, or by the person's
        // own put-aside, which then finds it settled.
        if ($suggestions->cancelled($this->id)) {
            try {
                app(SettleAiActivity::class)->execute(new SettleAiActivityInput($this->id, AiOutcome::Discarded, null, $this->actorId));
            } catch (ActivityAlreadySettled) {
                // The put-aside settled it first.
            }

            $suggestions->forget($this->id);
        }
    }

    /**
     * Ended by something other than an answer or a typed failure: the page
     * hears the model couldn't be reached, rather than waiting 15 minutes.
     */
    public function failed(?Throwable $exception): void
    {
        app(AiSuggestions::class)->fail($this->id, AiFailureType::Unavailable->value, []);
    }

    /**
     * The request's figures, for the person who asked (I-318).
     */
    private function record(AiPrompt $prompt, AiOutcome $outcome, ?AiFailureType $failure, ?string $provider, ?string $model, AiUsage $usage, ?int $cost, CarbonImmutable $started): void
    {
        $variant = $this->options['variant'] ?? null;

        app(RecordAiActivity::class)->execute(new RecordAiActivityInput(
            id: $this->id,
            task: $this->task,
            prompt: $prompt,
            subjectType: AiSubjectType::from($this->subjectType),
            subjectId: $this->subjectId,
            outcome: $outcome,
            provider: $provider,
            model: $model,
            usage: $usage,
            costMicrounits: $cost,
            latencyMs: $this->elapsedMs($started),
            failure: $failure,
            variant: is_string($variant) ? $variant : null,
            actorId: $this->actorId,
        ));
    }

    /**
     * The language the answer is written in (I-1306): the item's, or the
     * site's for an image.
     */
    private function language(SiteLanguage $language, AiSubject $subject): string
    {
        return $subject->type === AiSubjectType::Content
            ? $language->label($language->forContent($subject->id))
            : $language->label();
    }

    private function elapsedMs(CarbonImmutable $started): int
    {
        return (int) abs($started->diffInMilliseconds(CarbonImmutable::now()));
    }
}
