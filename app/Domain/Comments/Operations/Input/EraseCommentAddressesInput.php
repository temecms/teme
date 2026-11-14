<?php

declare(strict_types=1);

namespace App\Domain\Comments\Operations\Input;

use Carbon\CarbonInterface;
use Teme\Contracts\Operations\OperationInput;

/**
 * What EraseCommentAddresses needs: the moment before which a comment's
 * arrival makes its addresses due, how many comments one chunk erases, and
 * who is asking (null for the operator).
 */
final readonly class EraseCommentAddressesInput implements OperationInput
{
    public function __construct(
        public CarbonInterface $arrivedBefore,
        public int $limit,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
