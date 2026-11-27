<?php

/**
 * A plugin's export failed: it threw, or it answered something core refuses.
 * The export leaves that file out and goes on; a failure of the export's
 * own writing is never this.
 *
 * @package Teme
 * @since   1.7.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Exports;

use RuntimeException;
use Throwable;

final class PluginKindFailed extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
