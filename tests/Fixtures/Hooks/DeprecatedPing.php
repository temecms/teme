<?php

declare(strict_types=1);

namespace Tests\Fixtures\Hooks;

use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\Notification;

/**
 * A notification on its way out, for the deprecation log's test.
 *
 * @deprecated 0.5.0 Use Pinged. Removed in 0.6.0.
 */
#[HookContract(deprecated: '0.5.0', removedIn: '0.6.0', use: Pinged::class)]
final readonly class DeprecatedPing implements Notification
{
    public function __construct(public string $what = '') {}
}
