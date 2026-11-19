<?php

/**
 * Where a listener's failure goes, by owner and ID, so that a failure no
 * listener made, such as a plugin's failing start, is kept the same way
 * (I-3353). Recording never throws.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Hooks;

use Throwable;

interface ListenerFaults
{
    /**
     * @param  string  $owner  the plugin, or core
     * @param  string  $id  the subscription's ID, or what failed
     * @param  string  $hook  the hook's class
     */
    public function record(string $owner, string $id, string $hook, Throwable $failure): void;
}
