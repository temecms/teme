<?php

declare(strict_types=1);

namespace App\Domain\Ai\Asking\Jobs;

use App\Domain\Ai\AiAvailability;
use App\Domain\Ai\AiFailure;
use App\Domain\Ai\AiFailureType;
use App\Domain\Ai\AiImage;
use App\Domain\Ai\AiOutcome;
use App\Domain\Ai\AiPrompt;
use App\Domain\Ai\AiProviders;
use App\Domain\Ai\AiRequest;
use App\Domain\Ai\AiResult;
use App\Domain\Ai\AiSubject;
use App\Domain\Ai\AiSubjectType;
use App\Domain\Ai\AiTask;
use App\Domain\Ai\AiTasks;
use App\Domain\Ai\AiUsage;
use App\Domain\Ai\Asking\AiSuggestions;
use App\Domain\Ai\Asking\AiTargets;
use App\Domain\Ai\Asking\Suggestion;
use App\Domain\Ai\Asking\SuggestionCheck;
use App\Domain\Ai\Exceptions\ActivityAlreadySettled;
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
use RuntimeException;
use Throwable;

/**
 * Answers one request for an AI suggestion (I-320): asks the task's model,
 * and its fallback once if the first can't be reached, is busy or takes too
 * long, all within one deadline (I-1321); checks the answer twice; records the request's figures for
 * the person who asked; and leaves the answer for the page to collect. It
 * carries IDs and offsets, never text, so no queue row holds an article.
 */
final class AnswerAiRequest implements ShouldQueue
{
    use Queueable;

    /** Every call to a model ends within this many seconds of the job's start. */
    public const int DEADLINE_SECONDS = 55;

    /** The fallback isn't asked with less time than this left (I-1321). */
    public const int FALLBACK_FLOOR_SECONDS = 10;

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

    public function handle(AiTasks $tasks, AiProviders $providers, ModelChoices $choices, AiAvailability $availability, AiSuggestions $suggestions, AiTargets $targets, SuggestionCheck $check, SiteLanguage $language): void
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

        $sentTo = [];
        $ref = $choice->primary;

        try {
            try {
                $result = $this->ask($providers, $task, $ref, $fields, $input->images, $task->timeoutSeconds(), $sentTo);
            } catch (AiFailure $failure) {
                $left = self::DEADLINE_SECONDS - (int) ceil($this->elapsedMs($started) / 1000);
                $fallback = $choice->fallback;

                if (! $failure->type->fallsBack() || $fallback === null || $left < self::FALLBACK_FLOOR_SECONDS
                    || ! ($providers->find($fallback->provider)?->ready() ?? false) || $suggestions->cancelled($this->id)) {
                    throw $failure;
                }

                // Exactly the time that is left under the deadline (I-1321).
                $ref = $fallback;
                $result = $this->ask($providers, $task, $ref, $fields, $input->images, $left, $sentTo);
            }
        } catch (AiFailure $failure) {
            $outcome = $failure->type === AiFailureType::Refused ? AiOutcome::Refused : AiOutcome::Failed;
            // A failure the service billed carries its tokens, and they count (I-1322).
            $usage = $failure->usage ?? new AiUsage;
            $cost = $failure->usage === null ? null : $providers->cost($ref, $failure->usage);
            $this->record($prompt, $outcome, $failure->type, $ref->provider, $ref->model, $usage, $cost, $started);
            $suggestions->fail($this->id, $failure->type->value, $sentTo);

            return;
        }

        $cost = $providers->cost($ref, $result->usage);

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
     * One call to one model, its provider added to who the text was sent to.
     *
     * @param  array<string, string>  $fields
     * @param  list<AiImage>  $images
     * @param  list<string>  $sentTo
     *
     * @throws AiFailure
     */
    private function ask(AiProviders $providers, AiTask $task, ModelRef $ref, array $fields, array $images, int $timeout, array &$sentTo): AiResult
    {
        $provider = $providers->find($ref->provider) ?? throw AiFailure::unavailable();

        if (! in_array($provider->label(), $sentTo, true)) {
            $sentTo[] = $provider->label();
        }

        $request = new AiRequest($ref->model, $task->prompt()->messages($fields), $task->maxOutputTokens(), $timeout, $images, $task->outputSchema());

        try {
            return $provider->complete($request);
        } catch (AiFailure $failure) {
            throw $failure;
        } catch (Throwable $unexpected) {
            // Its message may quote the prompt (I-320), so only its class is reported.
            report(new RuntimeException('An AI provider threw '.$unexpected::class.'; its message isn\'t kept, since it may quote the prompt.'));

            throw AiFailure::unavailable();
        }
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
