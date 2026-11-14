<?php

declare(strict_types=1);

namespace App\Domain\SiteCode\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What `SwitchSiteCode` needs: the entry, whether it is to be on, and who is asking.
 */
final readonly class SwitchSiteCodeInput implements OperationInput
{
    public function __construct(
        public string $entryId,
        public bool $on,
        public string $actorId,
    ) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
