<?php

declare(strict_types=1);

namespace App\Domain\SiteCode\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What `SaveSiteCode` needs: the entry to change (null adds one), its name, placement and code, whether it is on, who is asking, and the hosts the code also connects to, one or more a line.
 */
final readonly class SaveSiteCodeInput implements OperationInput
{
    public function __construct(
        public ?string $entryId,
        public string $name,
        public string $placement,
        public string $code,
        public bool $on,
        public string $actorId,
        /** @var list<string> */
        public array $origins = [],
    ) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
