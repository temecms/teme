<?php

declare(strict_types=1);

namespace App\Domain\SiteCode\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What `DeleteSiteCode` needs: the entry to delete, and who is asking.
 */
final readonly class DeleteSiteCodeInput implements OperationInput
{
    public function __construct(
        public string $entryId,
        public string $actorId,
    ) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
