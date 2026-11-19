<?php

/**
 * What `IssuePasswordResetLink` needs: the address. There is no actor, because
 * only the operator at the server's shell issues links by hand.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Users\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class IssuePasswordResetLinkInput implements OperationInput
{
    public function __construct(public string $email) {}

    /**
     * Only the operator issues links by hand.
     */
    public function actorId(): ?string
    {
        return null;
    }
}
