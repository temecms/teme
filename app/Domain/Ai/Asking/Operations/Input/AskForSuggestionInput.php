<?php

/**
 * What a person asks AI for: the task, what it's about (by IDs, with the
 * task's options), and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai\Asking\Operations\Input;

use App\Domain\Ai\AiSubject;
use Teme\Contracts\Operations\OperationInput;

final readonly class AskForSuggestionInput implements OperationInput
{
    public function __construct(
        public string $task,
        public AiSubject $subject,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
