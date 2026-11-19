<?php

/**
 * Who may enable or disable a plugin: a person who holds plugins.manage, or the operator at the server's shell.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Operations\Concerns;

use App\Domain\Plugins\PluginAccess;
use App\Domain\Support\Security\Operator;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Teme\Contracts\Operations\OperationInput;

trait AuthorizesPluginChanges
{
    protected function authorize(OperationInput $input): void
    {
        $actorId = $input->actorId();
        $allowed = $actorId === null
            ? Operator::isPresent()
            : app(Actors::class)->find($actorId)?->can(PluginAccess::MANAGE) === true;

        if (! $allowed) {
            throw new AuthorizationException;
        }
    }
}
