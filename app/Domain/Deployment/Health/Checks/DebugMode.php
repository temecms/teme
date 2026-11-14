<?php

declare(strict_types=1);

namespace App\Domain\Deployment\Health\Checks;

use App\Domain\Deployment\Health\DoctorCheck;
use App\Domain\Deployment\Health\Finding;
use App\Domain\Deployment\Health\Severity;

/**
 * Says whether debug mode is on (I-378): on a production site it makes an
 * error page show visitors the configuration and its secrets.
 */
final readonly class DebugMode implements DoctorCheck
{
    public function title(): string
    {
        return 'Debug mode';
    }

    public function examine(): array
    {
        if (! (bool) config('app.debug')) {
            return [new Finding(Severity::Ok, 'APP_DEBUG is off.')];
        }

        if (app()->isProduction()) {
            return [new Finding(Severity::Error, "APP_DEBUG is on, so an error page shows visitors the site's settings and secrets.", 'Set APP_DEBUG=false.')];
        }

        return [new Finding(Severity::Ok, 'APP_DEBUG is on, which a production site must never be.')];
    }
}
