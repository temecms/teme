<?php

declare(strict_types=1);

namespace Tests\Fixtures\Hooks;

use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\Notification;

/**
 * A notification for the dispatcher's tests, needing content.read.
 */
#[HookContract(capability: 'content.read')]
final readonly class Pinged implements Notification
{
    public function __construct(public string $what) {}
}
