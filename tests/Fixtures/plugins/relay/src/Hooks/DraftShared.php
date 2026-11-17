<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Relay\Hooks;

use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\Notification;

/**
 * Relay's own Notification hook that carries a draft's words, so only a
 * plugin granted content.read may listen.
 */
#[HookContract(capability: 'content.read')]
final readonly class DraftShared implements Notification
{
    public function __construct(public string $words) {}
}
