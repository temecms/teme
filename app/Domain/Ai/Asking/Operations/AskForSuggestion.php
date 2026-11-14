<?php

declare(strict_types=1);

namespace App\Domain\Ai\Asking\Operations;

use App\Domain\Ai\AiAvailability;
use App\Domain\Ai\AiKeys;
use App\Domain\Ai\AiProviders;
use App\Domain\Ai\AiSubject;
use App\Domain\Ai\AiSubjectType;
use App\Domain\Ai\AiTasks;
use App\Domain\Ai\Asking\AiSuggestions;
use App\Domain\Ai\Asking\AiTargets;
use App\Domain\Ai\Asking\Exceptions\CannotAsk;
use App\Domain\Ai\Asking\Jobs\AnswerAiRequest;
use App\Domain\Ai\Asking\Operations\Input\AskForSuggestionInput;
use App\Domain\Ai\Asking\TextBlocks;
use App\Domain\Ai\ModelChoices;
use App\Domain\Ai\Queries\AiSpend;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Media\Models\Media;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Asks AI for a suggestion (I-317): checks that this person may ask about
 * this item or image, that the request names something real, and that the
 * task can run and the month's budget isn't spent; then starts the
 * request's hand-off and queues it with IDs only (I-320). Returns the
 * request's id, which becomes its activity row's.
 */
final class AskForSuggestion extends BaseOperation
{
    private const int MAX_OPTIONS = 8;

    private const int MAX_OPTION_LENGTH = 64;

    public function __construct(
        private readonly Actors $actors,
        private readonly AiTasks $tasks,
        private readonly AiAvailability $availability,
        private readonly AiSpend $spend,
        private readonly AiProviders $providers,
        private readonly ModelChoices $choices,
        private readonly AiSuggestions $suggestions,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var AskForSuggestionInput $input */
        $user = $this->actors->find($input->actorId());
        $subject = $input->subject->type === AiSubjectType::Content
            ? Content::query()->findOrFail($input->subject->id)
            : Media::withTrashed()->findOrFail($input->subject->id);

        // ai.use, and the right the applying Operation asks for (I-321).
        if ($user === null || ! $user->can('ai.use') || ! Gate::forUser($user)->allows('update', $subject)) {
            throw new AuthorizationException('Not permitted to ask AI about this.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var AskForSuggestionInput $input */
        $subject = $input->subject;

        if (! $this->tasks->has($input->task)) {
            throw new InvalidArgumentException("No AI task [{$input->task}] can be asked for.");
        }

        if ($this->tasks->get($input->task)->subject() !== $subject->type) {
            throw new InvalidArgumentException("AI task [{$input->task}] isn't asked about this kind of thing.");
        }

        $this->checkOptions($subject->options);

        if ($subject->type === AiSubjectType::Content) {
            $this->checkItem($subject);
        } elseif ($subject->target !== AiTargets::ALT) {
            throw new InvalidArgumentException('An image\'s suggestion is for its alt text.');
        }

        if (! $this->availability->taskReady($input->task)) {
            throw CannotAsk::unavailable();
        }

        // Soft: requests already running may pass it by their own cost.
        if ($this->spend->budgetReached()) {
            throw CannotAsk::budgetSpent();
        }
    }

    protected function perform(OperationInput $input): string
    {
        /** @var AskForSuggestionInput $input */
        $id = strtolower((string) Str::ulid());
        $subject = $input->subject;
        $actorId = (string) $input->actorId();
        $choice = $this->choices->for($input->task);
        $provider = $choice === null ? null : $this->providers->find($choice->primary->provider);
        $sentTo = $provider === null ? [] : [$provider->label()];

        // After commit, both: the hand-off first, so the job finds it; nothing for an asking that rolled back.
        DB::afterCommit(fn () => $this->suggestions->start($id, $actorId, $input->task, $subject, $sentTo));
        AnswerAiRequest::dispatch($id, $input->task, $subject->type->value, $subject->id, $subject->revisionId, $subject->target, $subject->options, $actorId)
            ->afterCommit();

        return $id;
    }

    /**
     * @param  array<string, string|int>  $options
     */
    private function checkOptions(array $options): void
    {
        $tooMany = count($options) > self::MAX_OPTIONS;

        foreach ($options as $name => $value) {
            $tooMany = $tooMany || preg_match('/^[a-z][a-z_]*$/', (string) $name) !== 1
                || (is_string($value) && mb_strlen($value) > self::MAX_OPTION_LENGTH);
        }

        if ($tooMany) {
            throw new InvalidArgumentException('An AI request has at most eight options, each a short word or a number.');
        }

        $variant = $options['variant'] ?? null;

        if ($variant !== null && (! is_string($variant) || preg_match(AiKeys::PATTERN, $variant) !== 1)) {
            throw new InvalidArgumentException('An AI request\'s variant is lower-case words joined by hyphens.');
        }
    }

    /**
     * An item's request names one of its revisions and, for a text block,
     * a paragraph or heading of it with a selection inside it (I-1305).
     */
    private function checkItem(AiSubject $subject): void
    {
        if ($subject->revisionId === null) {
            throw new InvalidArgumentException('Asking about an item needs one of its revisions.');
        }

        $revision = ContentRevision::query()->whereKey($subject->revisionId)->where('content_id', $subject->id)->first();

        if ($revision === null) {
            throw new InvalidArgumentException('The revision isn\'t one of this item\'s.');
        }

        // Without a target, the request is about the whole item.
        if ($subject->target === null) {
            return;
        }

        $block = TextBlocks::at((array) $revision->document, $subject->target);

        if ($block === null) {
            throw new InvalidArgumentException('AI can be asked about a paragraph or a heading, by its path.');
        }

        $from = $subject->options['from'] ?? null;
        $to = $subject->options['to'] ?? null;

        if (! is_int($from) || ! is_int($to) || $from >= $to || TextBlocks::slice(TextBlocks::text($block), $from, $to) === null) {
            throw new InvalidArgumentException(sprintf('The selection [%s, %s] isn\'t inside that paragraph.', $from ?? '', $to ?? ''));
        }
    }
}
