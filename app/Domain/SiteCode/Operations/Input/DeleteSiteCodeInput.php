<?php

/**
 * What `DeleteSiteCode` needs: the entry to delete, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\SiteCode\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

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
