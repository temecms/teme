<?php

declare(strict_types=1);

namespace App\Domain\Ai\Plugins;

use App\Domain\Ai\AiFailureType;
use App\Domain\Ai\AiOutcome;
use App\Domain\Ai\AiPrompt;
use App\Domain\Ai\AiRequest;
use App\Domain\Ai\AiSubjectType;
use App\Domain\Ai\ModelCall;
use App\Domain\Ai\ModelChoices;
use App\Domain\Ai\ModelRef;
use App\Domain\Ai\Operations\Input\RecordAiActivityInput;
use App\Domain\Ai\Operations\RecordAiActivity;
use App\Domain\Plugins\Doors\ActingPerson;
use Illuminate\Support\Str;
use Teme\Contracts\Ai\AiAnswer;
use Teme\Contracts\Ai\AiAsk;
use Teme\Contracts\Ai\AiRefused;
use Teme\Contracts\Ai\AiStanding;
use Teme\Contracts\Ai\SiteAi;
use Teme\Contracts\Content\WriteRefused;

/**
 * The door a plugin granted `ai` asks the site's AI connection through
 * (I-3434): only for a task it declares, only for the person its work acts
 * for, through the model chosen for the task in Settings › AI, its inputs
 * fenced as core's own (I-316), and every request that was sent recorded under
 * the plugin, its figures and never its words (I-318). A request refused
 * before sending costs nothing and is not recorded.
 */
final readonly class PluginSiteAi implements SiteAi
{
    /**
     * @param  bool  $long  built for a long job's run, whose requests may wait up to 600 seconds
     */
    public function __construct(private string $code, private bool $long = false) {}

    public function standing(string $task): AiStanding
    {
        $declared = app(PluginAiTasks::class)->declared($this->code, $task);

        if ($declared === null) {
            return new AiStanding(false, 'no-task', $this->noTask($task), 0, 0, null);
        }

        return app(PluginAiStanding::class)->of($this->code, $declared);
    }

    public function ask(AiAsk $ask, string $actingPersonId): AiAnswer
    {
        // A rollback would take the record and the spend with it while the service still bills, and the answer would hold the transaction open.
        if (self::inTransaction()) {
            throw new AiRefused('in-transaction', __('A plugin asks AI only outside a database transaction.'));
        }

        try {
            ActingPerson::require($actingPersonId);                 // the person of this work; never the operator
        } catch (WriteRefused $refused) {
            throw new AiRefused('no-person', $refused->getMessage());
        }

        $task = app(PluginAiTasks::class)->declared($this->code, $ask->task)
            ?? throw new AiRefused('no-task', $this->noTask($ask->task));
        $this->checkBounds($ask, $task);                            // tokens, schema, too-long

        $standing = app(PluginAiStanding::class)->of($this->code, $task);

        if (! $standing->available) {
            throw new AiRefused($standing->reason, $standing->sentence);   // nothing sent, nothing recorded
        }

        $choice = app(ModelChoices::class)->forStem(PluginAiTasks::stem($this->code, $task->id))
            ?? throw new AiRefused('no-model', $standing->sentence);  // standing checked it
        $prompt = new AiPrompt($task->id, 1, $ask->instructions, AiPrompt::hashOf($ask->instructions));
        $request = fn (ModelRef $ref, int $seconds): AiRequest => new AiRequest(
            $ref->model, $prompt->messages($ask->inputs), $ask->maxOutputTokens, $seconds,
            schema: $ask->schema, long: $seconds > AiRequest::MAX_TIMEOUT,
        );

        $id = strtolower((string) Str::ulid());
        $call = app(ModelCall::class)->ask($choice, $request, $ask->timeoutSeconds);   // primary, then the fallback once (I-1321)

        app(RecordAiActivity::class)->execute(new RecordAiActivityInput(
            id: $id,
            task: $task->id,
            prompt: $prompt,
            subjectType: $ask->itemId === null ? AiSubjectType::None : AiSubjectType::Content,
            subjectId: $ask->itemId,
            outcome: $call->failure === null ? AiOutcome::Answered : ($call->failure === AiFailureType::Refused ? AiOutcome::Refused : AiOutcome::Failed),
            provider: $call->ref->provider,
            model: $call->model,
            usage: $call->usage,
            costMicrounits: $call->cost,
            latencyMs: $call->latencyMs,
            failure: $call->failure,
            actorId: $actingPersonId,
            plugin: $this->code,
        ));

        if ($call->failure !== null || $call->result === null) {
            $failure = $call->failure ?? AiFailureType::Unavailable;

            throw new AiRefused($failure->value, __($failure->sentence()));   // core's words, never the service's
        }

        $usage = $call->usage;

        return new AiAnswer($call->model, $call->result->text, $call->result->data, $usage->input + $usage->cacheRead + $usage->cacheWrite, $usage->output, $call->cost, $id);
    }

    /**
     * Refuses, before anything is sent, a request outside the task's
     * declaration or outside the time its run allows.
     *
     * @throws AiRefused
     */
    private function checkBounds(AiAsk $ask, DeclaredAiTask $task): void
    {
        if ($ask->maxOutputTokens > $task->maxOutputTokens) {
            throw new AiRefused('too-many-tokens', __(':task answers with at most :tokens tokens.', ['task' => $task->label, 'tokens' => number_format($task->maxOutputTokens)]));
        }

        if ($ask->schema !== null && ! in_array('structured-output', $task->needValues(), true)) {
            throw new AiRefused('no-schema', __(':task doesn’t need structured-output, so it can’t be sent a schema.', ['task' => $task->label]));
        }

        if ($ask->timeoutSeconds > AiRequest::MAX_TIMEOUT && ! $this->long) {
            throw new AiRefused('too-long', __('A request longer than 55 seconds runs only inside a long job.'));
        }
    }

    private function noTask(string $task): string
    {
        return __('This plugin doesn’t declare the AI task :task.', ['task' => $task]);
    }

    /**
     * Whether the caller is inside a database transaction of its own; a
     * test's wrapping transaction doesn't count, as for after-commit work.
     */
    private static function inTransaction(): bool
    {
        return app('db.transactions')->callbackApplicableTransactions()->isNotEmpty();
    }
}
