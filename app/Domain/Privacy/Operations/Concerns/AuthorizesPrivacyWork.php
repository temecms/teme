<?php

/**
 * Who may handle a privacy request: the operator at the server's shell, or
 * a person who holds privacy.manage and isn't suspended (I-3375).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Privacy\Operations\Concerns;

use App\Domain\Support\Security\Operator;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Teme\Contracts\Operations\OperationInput;

trait AuthorizesPrivacyWork
{
    protected function authorize(OperationInput $input): void
    {
        $actorId = $input->actorId();

        if ($actorId === null && Operator::isPresent()) {
            return;
        }

        $actor = app(Actors::class)->find($actorId);

        if ($actor !== null && $actor->suspended_at === null && $actor->can('privacy.manage')) {
            return;
        }

        throw new AuthorizationException(__('Not permitted to handle privacy requests.'));
    }
}
