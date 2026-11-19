<?php

/**
 * Lets a request that waits on restic for a person run as long as restic
 * does (I-2401): neither PHP's time limit, which counts the waiting on
 * Windows, nor a tab closed meanwhile stops an Operation halfway.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Tools\Backups;

trait WaitsForRestic
{
    protected function waitForRestic(): void
    {
        set_time_limit(0);
        ignore_user_abort(true);
    }
}
