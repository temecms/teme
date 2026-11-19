<?php

/**
 * What ImportOldRedirect needs: the source and run, the rule's object and hash, the redirects it becomes and what was said of it, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\SourceRef;
use Teme\Contracts\Operations\OperationInput;

final readonly class ImportOldRedirectInput implements OperationInput
{
    /**
     * @param  list<array{from: string, to: string, contentId: string|null, mediaId: string|null, status: int}>  $redirects
     * @param  list<ImportMessage>  $notes
     */
    public function __construct(
        public string $sourceId,
        public string $runId,
        public SourceRef $ref,
        public string $hash,
        public array $redirects,
        public array $notes,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
