<?php

/**
 * Tools › Backups (I-364): the verdict, the places, the checks and the keys,
 * read from the tables; it changes nothing.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Tools\Backups;

use App\Domain\Backups\Queries\BackupJobs;
use App\Domain\Backups\Queries\BackupsScreen;
use App\Domain\Media\MediaLocations;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class BackupsScreenController extends Controller
{
    public function index(Request $request, BackupsScreen $screen, SftpSteps $sftp, BackupJobs $jobs): Response
    {
        $now = CarbonImmutable::now();

        // Everything but the running line, once, and only when a prop asks (a poll asks for three alone).
        $all = self::memo(function () use ($request, $screen, $sftp, $now): array {
            // Forgets an SFTP step older than an hour (I-2403).
            $sftp->current($request);

            $challenge = $request->session()->get(RecoveryKeyPage::CHALLENGE);
            $data = $screen->data($now, is_array($challenge) ? $challenge : null);

            // A challenge the screen no longer waits for is let go (I-2401).
            if ($data['pending'] === null) {
                $request->session()->forget(RecoveryKeyPage::CHALLENGE);
            }

            return $data;
        });
        $running = self::memo(fn (): ?string => $screen->running($now));
        $job = self::memo(fn (): ?array => $jobs->current());

        $props = [];

        foreach (BackupsScreen::KEYS as $key) {
            $props[$key] = $key === 'running' ? fn () => $running() : fn () => $all()[$key];
        }

        $props['backupJob'] = fn () => $job();
        $props['firstBackupOnItsWay'] = fn () => $screen->firstBackupOnItsWay($screen->setupState(), $running(), $job(), $now);
        $props['backUpNow'] = fn () => $screen->backUpNow($screen->setupState(), $running(), $job());
        // Media in object storage is the provider's to keep (I-3456).
        $props['mediaInBucket'] = fn (): bool => MediaLocations::anyInBucket();

        return Inertia::render('tools/backups/index', $props);
    }

    /**
     * A closure that computes once, on first call, and answers the same after.
     *
     * @template T
     *
     * @param  Closure(): T  $compute
     *
     * @return Closure(): T
     */
    private static function memo(Closure $compute): Closure
    {
        $done = false;
        $value = null;

        return function () use ($compute, &$done, &$value): mixed {
            if (! $done) {
                $value = $compute();
                $done = true;
            }

            return $value;
        };
    }
}
