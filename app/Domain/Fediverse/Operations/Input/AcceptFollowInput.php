<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Operations\Input;

use App\Domain\Fediverse\Signatures\VerifiedSender;
use Teme\Contracts\Operations\OperationInput;

/**
 * What AcceptFollow needs: which of the site's actors is followed, by which
 * remote account, the Follow as it came, and the verified sender, who must
 * be that account.
 */
final readonly class AcceptFollowInput implements OperationInput
{
    /**
     * @param  array<string, mixed>  $follow
     */
    public function __construct(
        public string $localActorId,
        public string $remoteActorId,
        public array $follow,
        public ?VerifiedSender $sender = null,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
