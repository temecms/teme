<?php

declare(strict_types=1);

namespace App\Domain\SiteCode\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What UpdateAdsTxt needs: the ads.txt text, the Site code settings group's
 * version the form was opened on, and who is asking.
 */
final readonly class UpdateAdsTxtInput implements OperationInput
{
    public function __construct(
        public string $adsTxt,
        public int $expectedVersion,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
