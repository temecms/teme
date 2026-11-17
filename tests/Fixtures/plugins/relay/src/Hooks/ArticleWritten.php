<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Relay\Hooks;

use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\Notification;

/**
 * Relay's own Notification hook: an article was written.
 */
#[HookContract]
final readonly class ArticleWritten implements Notification
{
    public function __construct(public string $title) {}
}
