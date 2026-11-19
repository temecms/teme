<?php

/**
 * What `RecordImportMessages` needs: the run and its token, the run's own messages, and who acts.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use App\Domain\Import\Phases\ImportMessage;
use Teme\Contracts\Operations\OperationInput;

final readonly class RecordImportMessagesInput implements OperationInput
{
    /**
     * @param  list<ImportMessage>  $messages
     */
    public function __construct(
        public string $runId,
        public string $token,
        public array $messages,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
