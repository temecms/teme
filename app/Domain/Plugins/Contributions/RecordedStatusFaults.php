<?php

/**
 * Records a plugin's failed status check with its other faults, through
 * the ListenerFaults bound when it fails, once a request however often its
 * banner is drawn (I-3353).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Contributions;

use App\Domain\Hooks\ListenerFaults;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Throwable;
use WeakMap;

final class RecordedStatusFaults implements StatusFaults
{
    /** @var WeakMap<Request, array<string, true>> each request's checks already recorded */
    private WeakMap $recorded;

    public function __construct(private readonly Container $container)
    {
        $this->recorded = new WeakMap;
    }

    public function report(string $owner, string $id, Throwable $failure): void
    {
        $request = $this->container->make('request');
        $done = $this->recorded[$request] ?? [];

        if (isset($done[$id])) {
            return;
        }

        $this->recorded[$request] = [...$done, $id => true];
        $this->container->make(ListenerFaults::class)->record($owner, $id, '', $failure);
    }
}
