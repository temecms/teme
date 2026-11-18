<?php

/**
 * What RemoveFollower needs: the remote account, which of the site's
 * actors it stops following (all, when none is named), whether the account
 * is gone for good, and who asks: the account itself, verified, or a
 * person who manages the fediverse.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Operations\Input;

use App\Domain\Fediverse\Signatures\VerifiedSender;
use Teme\Contracts\Operations\OperationInput;

final readonly class RemoveFollowerInput implements OperationInput
{
    public function __construct(
        public string $remoteActorUri,
        public ?string $localActorId = null,
        public bool $gone = false,
        public ?VerifiedSender $sender = null,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
