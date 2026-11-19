<?php

/**
 * Thrown when a scheduled publication's scheduler can no longer publish it:
 * their account was removed (`scheduler_removed`), or they may no longer
 * publish the article (`scheduler_cannot_publish`). The scheduler holds the
 * row with that reason rather than failing it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;

final class SchedulerCannotPublishException extends AuthorizationException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason === 'scheduler_removed'
            ? 'The person who scheduled this publication no longer has an account.'
            : 'The person who scheduled this publication may no longer publish it.');
    }
}
