<?php

/**
 * What StartBulkRun needs: the content type's key, the action (trash,
 * restore or delete), the list's query as it was sent, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class StartBulkRunInput implements OperationInput
{
    /**
     * @param  array<string, mixed>  $filters  the list's own keys and values
     */
    public function __construct(
        public string $type,
        public string $action,
        public array $filters,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
