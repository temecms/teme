<?php

declare(strict_types=1);

namespace App\Domain\Ai\Models;

use App\Domain\Ai\AiFailureType;
use App\Domain\Ai\AiOutcome;
use App\Domain\Ai\AiSubjectType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One AI request's figures (I-318): who asked, for which task and subject,
 * which model answered, its prompt's version and hash, its tokens, cost and
 * time, how it ended, and the revision it made. Never its prompt or its
 * answer. Only RecordAiActivity, SettleAiActivity and PruneAiActivity write it.
 *
 * @property string $id
 * @property int|null $user_id
 * @property string $task
 * @property string|null $variant
 * @property string|null $provider
 * @property string|null $model
 * @property string $prompt_key
 * @property int $prompt_version
 * @property string $prompt_hash
 * @property AiSubjectType $subject_type
 * @property string $subject_id
 * @property AiOutcome $outcome
 * @property AiFailureType|null $error_type
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_read_tokens
 * @property int $cache_write_tokens
 * @property int|null $cost_microunits
 * @property int $latency_ms
 * @property string|null $revision_id
 * @property CarbonImmutable $created_at
 */
final class AiActivity extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'ai_activity';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'prompt_version' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'cost_microunits' => 'integer',
            'latency_ms' => 'integer',
            'subject_type' => AiSubjectType::class,
            'outcome' => AiOutcome::class,
            'error_type' => AiFailureType::class,
            'created_at' => 'immutable_datetime',
        ];
    }
}
