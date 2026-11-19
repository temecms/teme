<?php

/**
 * What `SetBylines` needs: the item, the people it credits in order, each
 * as a credit's key (`user:12`, `person:01k…`), and who is asking. None
 * clears them, and the item credits its owner.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\People\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class SetBylinesInput implements OperationInput
{
    /**
     * @param  list<string>  $credits
     */
    public function __construct(
        public ?string $actorId,
        public string $contentId,
        public array $credits,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
