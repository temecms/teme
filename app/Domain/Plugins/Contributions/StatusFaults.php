<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Contributions;

use Throwable;

/**
 * Where a plugin's status check that throws is reported, under the plugin
 * that added it, so the failure is seen without breaking the admin (I-3353).
 */
interface StatusFaults
{
    public function report(string $owner, string $id, Throwable $failure): void;
}
