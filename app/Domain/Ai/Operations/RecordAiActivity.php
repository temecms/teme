<?php

declare(strict_types=1);

namespace App\Domain\Ai\Operations;

use App\Domain\Ai\AiFailureType;
use App\Domain\Ai\AiKeys;
use App\Domain\Ai\AiOutcome;
use App\Domain\Ai\Models\AiActivity;
use App\Domain\Ai\Operations\Input\RecordAiActivityInput;
use App\Domain\Ai\Queries\AiSpend;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Actors;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Records one AI request when it ends (I-318): its figures, never its words,
 * and its cost added to its UTC month's total in the same
 * transaction, so the budget never sums the record. It acts for the person
 * who asked, never for nobody or the operator; ai.use isn't asked again,
 * because the spend happened whatever changed since.
 */
final class RecordAiActivity extends BaseOperation
{
    /** How a request may end when it is recorded; it is used or found out of date later. */
    private const array RECORDED = [AiOutcome::Suggested, AiOutcome::Discarded, AiOutcome::Failed, AiOutcome::Refused];

    public function __construct(private readonly Actors $actors) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var RecordAiActivityInput $input */
        if ($this->actors->find($input->actorId()) === null) {
            throw new AuthorizationException('Only a request a person made is recorded.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var RecordAiActivityInput $input */
        $problem = match (true) {
            preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/', $input->id) !== 1 => 'An AI activity id is a lower-case ULID.',
            ! in_array($input->outcome, self::RECORDED, true) => 'A request is recorded as suggested, discarded, failed or refused; it is used or found out of date later.',
            in_array($input->outcome, [AiOutcome::Failed, AiOutcome::Refused], true) !== ($input->failure !== null) => 'A failed or refused request needs its failure\'s type, and only then.',
            $input->failure !== null && ($input->outcome === AiOutcome::Refused) !== ($input->failure === AiFailureType::Refused) => 'A refused request\'s type is refused; a failed one\'s is any other.',
            ($input->costMicrounits ?? 0) < 0 || $input->latencyMs < 0 => 'An AI activity\'s cost and latency can\'t be negative.',
            preg_match(AiKeys::PATTERN, $input->task) !== 1 || ($input->variant !== null && preg_match(AiKeys::PATTERN, $input->variant) !== 1) => 'An AI activity\'s task and variant are lower-case words joined by hyphens.',
            ! Str::isUlid($input->subjectId) => "An AI activity's subject [{$input->subjectId}] isn't a ULID.",
            mb_strlen($input->provider ?? '') > 64 || mb_strlen($input->model ?? '') > 200 => 'An AI activity\'s provider or model name is too long.',
            default => null,
        };

        if ($problem !== null) {
            throw new InvalidArgumentException($problem);
        }
    }

    protected function perform(OperationInput $input): string
    {
        /** @var RecordAiActivityInput $input */
        $now = CarbonImmutable::now('UTC');
        $usage = $input->usage;
        $used = $usage->input + $usage->output + $usage->cacheRead + $usage->cacheWrite > 0;

        // Unknown only when tokens were spent at a model with no price (I-1302).
        $cost = $input->costMicrounits ?? ($used ? null : 0);

        AiActivity::query()->create([
            'id' => $input->id,
            'user_id' => (int) $input->actorId(),
            'task' => $input->task,
            'variant' => $input->variant,
            'provider' => $input->provider,
            'model' => $input->model,
            'prompt_key' => $input->prompt->key,
            'prompt_version' => $input->prompt->version,
            'prompt_hash' => $input->prompt->hash,
            'subject_type' => $input->subjectType,
            'subject_id' => $input->subjectId,
            'outcome' => $input->outcome,
            'error_type' => $input->failure,
            'input_tokens' => $usage->input,
            'output_tokens' => $usage->output,
            'cache_read_tokens' => $usage->cacheRead,
            'cache_write_tokens' => $usage->cacheWrite,
            'cost_microunits' => $cost,
            'latency_ms' => $input->latencyMs,
            'created_at' => $now,
        ]);

        // Only additions, so the proposed row passes the table's checks as it stands.
        DB::statement(<<<'SQL'
            INSERT INTO ai_monthly_spend (month, cost_microunits, requests, unpriced_requests)
            VALUES (?::date, ?, 1, ?)
            ON CONFLICT (month) DO UPDATE SET
                cost_microunits = ai_monthly_spend.cost_microunits + EXCLUDED.cost_microunits,
                requests = ai_monthly_spend.requests + 1,
                unpriced_requests = ai_monthly_spend.unpriced_requests + EXCLUDED.unpriced_requests
            SQL, [AiSpend::monthOf($now), $cost ?? 0, $cost === null ? 1 : 0]);

        return $input->id;
    }
}
