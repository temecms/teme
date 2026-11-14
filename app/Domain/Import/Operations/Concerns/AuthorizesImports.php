<?php

declare(strict_types=1);

namespace App\Domain\Import\Operations\Concerns;

use App\Domain\Support\Security\Operator;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Who may import (I-1707): the operator at the server's shell, or a person
 * who holds imports.run. Every import Operation asks this first.
 */
trait AuthorizesImports
{
    /**
     * @throws AuthorizationException for anyone else
     */
    private function authorizeImporter(?string $actorId): void
    {
        $allowed = $actorId === null
            ? Operator::isPresent()
            : app(Actors::class)->find($actorId)?->can('imports.run') === true;

        if (! $allowed) {
            throw new AuthorizationException('Not permitted to import a site.');
        }
    }
}
