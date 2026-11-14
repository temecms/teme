<?php

declare(strict_types=1);

namespace App\Domain\Updates\Updater;

use App\Domain\Updates\TemeVersion;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The updater's side of the signed probe (I-3326): asks this site's own /up, through the address readers use, which Teme answers.
 */
final readonly class VersionProbe
{
    /**
     * Returns the version the site answers with, or null when it answers otherwise or not at all.
     */
    public function version(): ?string
    {
        try {
            $response = Http::withHeaders([ProbeSignature::HEADER => ProbeSignature::sign(now()->getTimestamp())])
                ->withoutRedirecting()
                ->connectTimeout(5)
                ->timeout(10)
                ->get(rtrim((string) config('app.url'), '/').'/up');
        } catch (ConnectionException) {
            return null;
        }

        $version = $response->header('X-Teme-Version');

        return $response->status() === 200 && TemeVersion::isRelease($version) ? $version : null;
    }
}
