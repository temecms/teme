<?php

/**
 * The handle a subscription gives back, and the only way to remove it.
 *
 * @package Teme
 * @since   0.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Hooks;

/**
 * The handle a subscription gives back, and the only way to remove it. Its
 * ID is `{plugin}#{hook's short name}#{n}`, n counting from 1 the plugin's
 * subscriptions to that hook in the order they were made.
 *
 * @since 0.5.0
 */
final readonly class Subscription
{
    /**
     * @param  string  $id  such as acme/example-sync#ContentPublished#1
     *
     * @since 0.5.0
     */
    public function __construct(public string $id) {}
}
