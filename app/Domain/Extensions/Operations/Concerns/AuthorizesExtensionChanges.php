<?php

/**
 * Who may ask the installer for a change, and keep the sources and uploads it reads: a person who holds
 * extensions.install, or the operator at the server's shell.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions\Operations\Concerns;

use App\Domain\Extensions\ExtensionsAccess;
use App\Domain\Support\Security\Operator;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Teme\Contracts\Operations\OperationInput;

trait AuthorizesExtensionChanges
{
    protected function authorize(OperationInput $input): void
    {
        $actorId = $input->actorId();
        $allowed = $actorId === null
            ? Operator::isPresent()
            : app(Actors::class)->find($actorId)?->can(ExtensionsAccess::INSTALL) === true;

        if (! $allowed) {
            throw new AuthorizationException('Only an administrator installs plugins and themes.');
        }
    }
}
