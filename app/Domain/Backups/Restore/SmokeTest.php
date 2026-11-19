<?php

/**
 * Whether the restored site answers, before it opens (I-363): this Teme's
 * newest migration is the database's, and the home page and the newest
 * published item answer 200 at this server's address, each a request this
 * process handles, carrying the restore's bypass cookie.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Restore;

use App\Domain\Addresses\Addresses;
use App\Domain\Contents\Models\Content;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final readonly class SmokeTest
{
    /**
     * The problem in words, or null when the site answers as it should.
     */
    public function run(string $bypassSecret): ?string
    {
        if (RestoreCompatibility::pendingMigrations() > 0 || DB::table('migrations')->max('migration') !== RestoreCompatibility::newestMigration()) {
            return 'The restored site’s database isn’t at this Teme’s newest migration.';
        }

        $addresses = [url('/')];
        $newest = Content::query()->whereNotNull('published_at')->where('published_at', '<=', now())->orderByDesc('published_at')->first();
        $address = $newest === null ? null : Addresses::urlFor($newest);

        if ($address !== null) {
            $addresses[] = $address;
        }

        foreach ($addresses as $address) {
            $status = $this->status($address, $bypassSecret);

            if ($status !== 200) {
                return "The restored site doesn’t answer as it should: {$address} answered {$status}.";
            }
        }

        return null;
    }

    private function status(string $address, string $bypassSecret): int
    {
        $kernel = app(Kernel::class);
        $request = Request::create($address, 'GET', cookies: ['laravel_maintenance' => MaintenanceModeBypassCookie::create($bypassSecret)->getValue()]);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response->getStatusCode();
    }
}
