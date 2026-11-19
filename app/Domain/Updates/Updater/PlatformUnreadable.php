<?php

/**
 * The platform refused a read the updater makes while watching an update. A refused read counts nothing that
 * pass: it never starts a switch back on its own, and the step's own time limit decides (the plan's R8).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Updates\Updater;

use RuntimeException;

final class PlatformUnreadable extends RuntimeException
{
    public static function from(PlatformRefused $refused): self
    {
        return new self($refused->getMessage(), 0, $refused);
    }
}
