<?php

/**
 * Where a plugin's status check that throws is reported, under the plugin
 * that added it, so the failure is seen without breaking the admin (I-3353).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Contributions;

use Throwable;

interface StatusFaults
{
    public function report(string $owner, string $id, Throwable $failure): void;
}
