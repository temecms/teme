<?php

/**
 * What ConfirmRestoredSite needs: the answer, and who gives it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Operations\Input;

use App\Domain\Backups\RestoredSiteAnswer;
use Teme\Contracts\Operations\OperationInput;

final readonly class ConfirmRestoredSiteInput implements OperationInput
{
    public function __construct(
        public RestoredSiteAnswer $answer,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
