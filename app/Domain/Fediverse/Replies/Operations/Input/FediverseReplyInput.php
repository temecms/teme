<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Replies\Operations\Input;

use App\Domain\Fediverse\Signatures\VerifiedSender;
use Teme\Contracts\Operations\OperationInput;

/**
 * What the reply Operations need: the remote account that wrote it, the
 * reply's object as it came (for a removal, only its address matters),
 * and the verified sender, who must be that account.
 */
final readonly class FediverseReplyInput implements OperationInput
{
    /**
     * @param  array<string, mixed>  $object
     */
    public function __construct(
        public string $remoteActorId,
        public array $object,
        public ?VerifiedSender $sender = null,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }

    /**
     * The reply's own address.
     */
    public function uri(): string
    {
        return is_string($this->object['id'] ?? null) ? $this->object['id'] : '';
    }
}
