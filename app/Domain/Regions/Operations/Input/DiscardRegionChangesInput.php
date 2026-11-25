<?php

/**
 * What DiscardRegionChanges needs: the region, the working revision the
 * person saw, and who is asking.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Regions\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class DiscardRegionChangesInput implements OperationInput
{
    public function __construct(
        public string $contentId,
        public ?string $expectedWorkingRevisionId,
        public string $actorId,
    ) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
