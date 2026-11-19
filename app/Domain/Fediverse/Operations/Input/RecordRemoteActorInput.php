<?php

/**
 * What RecordRemoteActor needs: the actor's document, and the verified
 * sender whose own document it is, or the operator.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Operations\Input;

use App\Domain\Fediverse\Signatures\VerifiedSender;
use Teme\Contracts\Operations\OperationInput;

final readonly class RecordRemoteActorInput implements OperationInput
{
    /**
     * @param  array<string, mixed>  $document
     */
    public function __construct(
        public array $document,
        public ?VerifiedSender $sender = null,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
