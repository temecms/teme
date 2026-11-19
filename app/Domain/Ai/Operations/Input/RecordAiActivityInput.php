<?php

/**
 * One request's figures, as the job that asked has them: the id made when
 * asking (a lower-case ULID), the task and its option, the prompt, the subject,
 * how it ended and why, who answered, the tokens, the cost (null only when
 * tokens were spent at a model with no price, I-1302) and the time taken; and
 * the person who asked. A plugin's request through SiteAi names the plugin,
 * and may be about no item: its subject is then none, with no ID.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai\Operations\Input;

use App\Domain\Ai\AiFailureType;
use App\Domain\Ai\AiOutcome;
use App\Domain\Ai\AiPrompt;
use App\Domain\Ai\AiSubjectType;
use App\Domain\Ai\AiUsage;
use Teme\Contracts\Operations\OperationInput;

final readonly class RecordAiActivityInput implements OperationInput
{
    public function __construct(
        public string $id,
        public string $task,
        public AiPrompt $prompt,
        public AiSubjectType $subjectType,
        public ?string $subjectId,
        public AiOutcome $outcome,
        public ?string $provider,
        public ?string $model,
        public AiUsage $usage,
        public ?int $costMicrounits,
        public int $latencyMs,
        public ?AiFailureType $failure = null,
        public ?string $variant = null,
        public ?string $actorId = null,
        public ?string $plugin = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
