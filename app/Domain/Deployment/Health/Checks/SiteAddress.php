<?php

/**
 * Says whether a production site's address is https (I-378): without it
 * passwords and session cookies cross the network unencrypted.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Deployment\Health\Checks;

use App\Domain\Deployment\Health\DoctorCheck;
use App\Domain\Deployment\Health\Finding;
use App\Domain\Deployment\Health\Severity;

final readonly class SiteAddress implements DoctorCheck
{
    public function title(): string
    {
        return 'Site address';
    }

    public function examine(): array
    {
        $url = (string) config('app.url');

        if (app()->isProduction() && ! str_starts_with(strtolower($url), 'https://')) {
            return [new Finding(Severity::Warning, "APP_URL is {$url}, not https, so passwords and session cookies cross the network unencrypted.", "Set APP_URL to the site's https address, and let the proxy in front answer https.")];
        }

        return [new Finding(Severity::Ok, "APP_URL is {$url}.")];
    }
}
