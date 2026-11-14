<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Seams;

use App\Domain\Hooks\HookDispatcher;
use App\Domain\Hooks\OwnedHooks;
use Teme\Contracts\Hooks\Hooks;

/**
 * Each plugin's Hooks from core's dispatcher (I-3353): bound to the plugin,
 * what it was granted, the hooks it declared and its stopped parts, so its
 * listeners hear what Operations announce, and every one of them goes when
 * it fails to start.
 */
final readonly class DispatcherSubscriptions implements HookSubscriptions
{
    public function __construct(private HookDispatcher $dispatcher) {}

    public function for(string $plugin, array $capabilities, array $declared, array $quarantined): Hooks
    {
        return $this->dispatcher->subscriber($plugin, $capabilities, $declared, $quarantined);
    }

    public function forget(string $plugin): void
    {
        $this->dispatcher->forget($plugin);
    }

    public function close(Hooks $hooks): void
    {
        if ($hooks instanceof OwnedHooks) {
            $hooks->close();
        }
    }
}
