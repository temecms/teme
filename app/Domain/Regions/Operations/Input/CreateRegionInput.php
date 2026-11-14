<?php

declare(strict_types=1);

namespace App\Domain\Regions\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What CreateRegion needs: the theme, the region it declares, and who is asking.
 */
final readonly class CreateRegionInput implements OperationInput
{
    public function __construct(
        public string $themeCode,
        public string $region,
        public string $actorId,
    ) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
