<?php

/**
 * A plugin answered one of the search sockets after its time ran out: what
 * it answered is dropped, and this is recorded as its fault.
 *
 * @package Teme
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Seo;

use RuntimeException;

final class SocketTooSlow extends RuntimeException
{
    public function __construct(string $plugin, string $socket, int $budgetMs, int $tookMs)
    {
        parent::__construct("{$plugin} took {$tookMs} ms to answer {$socket}, where it has {$budgetMs} ms.");
    }
}
