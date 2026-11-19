<?php

/**
 * What RecordSecondPass needs: the run, the post, the import's record of it as
 * the step read it, the finished document and fields or none, where the pass
 * started and what it left open, its messages, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use App\Domain\Import\Phases\ImportMessage;
use Teme\Contracts\Operations\OperationInput;

final readonly class RecordSecondPassInput implements OperationInput
{
    /**
     * @param  array<string, mixed>|null  $document
     * @param  array<string, mixed>|null  $fields
     * @param  array<string, mixed>|null  $passed
     * @param  list<ImportMessage>  $messages
     */
    public function __construct(
        public string $runId,
        public string $objectId,
        public string $written,
        public ?array $document,
        public ?array $fields,
        public ?array $passed,
        public array $messages,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
