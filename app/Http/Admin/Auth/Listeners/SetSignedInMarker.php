<?php

declare(strict_types=1);

namespace App\Http\Admin\Auth\Listeners;

use App\Domain\Cache\SignedInMarker;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Cookie;

/**
 * Marks the browser as signed in at every sign-in, a remembered one too,
 * so the page cache steps aside for it (I-262).
 */
final class SetSignedInMarker
{
    public function handle(Login $event): void
    {
        Cookie::queue(SignedInMarker::cookie());
    }
}
