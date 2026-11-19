<?php

/**
 * Clears the signed-in marker at sign-out, so the browser is served stored
 * pages again (I-262).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Auth\Listeners;

use App\Domain\Cache\SignedInMarker;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Cookie;

final class ClearSignedInMarker
{
    public function handle(Logout $event): void
    {
        Cookie::queue(SignedInMarker::expired());
    }
}
