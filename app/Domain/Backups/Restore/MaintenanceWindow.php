<?php

/**
 * The site closed for a restore: Laravel's maintenance mode with a bypass
 * secret for the restore's own checks and a marker of the restore and its
 * safety backup's place, kept outside the database, so a restore that stops leaves
 * the site closed and the next one knows. It never reopens by itself. The
 * payload the operator had set, if the site was already down, comes back
 * when the restore opens it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Restore;

use Illuminate\Foundation\Events\MaintenanceModeDisabled;
use Illuminate\Foundation\Events\MaintenanceModeEnabled;
use Illuminate\Support\Facades\Cache;

final class MaintenanceWindow
{
    public const string MARK = 'teme_restore';

    public const string BEFORE = 'teme_before';

    /** What Laravel's cache-based maintenance mode calls its flag. */
    private const string FLAG = 'illuminate:foundation:down';

    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(private array $payload) {}

    /**
     * The marker a restore that didn't finish left, or null.
     *
     * @return array{run: string, safety: string|null, safety_place: array{name: string, kind: string, location: array<string, string|int>, repository_id: string}|null, safety_missing?: int, since: string}|null
     */
    public static function unfinished(): ?array
    {
        $mode = app()->maintenanceMode();
        $marker = $mode->active() ? ($mode->data()[self::MARK] ?? null) : null;

        if (! is_array($marker) || ! is_string($marker['run'] ?? null) || ! is_string($marker['since'] ?? null)) {
            return null;
        }

        $place = $marker['safety_place'] ?? null;
        $place = is_array($place) && is_string($place['name'] ?? null) && is_string($place['kind'] ?? null) && is_array($place['location'] ?? null) && is_string($place['repository_id'] ?? null)
            ? ['name' => $place['name'], 'kind' => $place['kind'], 'location' => $place['location'], 'repository_id' => $place['repository_id']]
            : null;

        $missing = $marker['safety_missing'] ?? null;

        return [
            'run' => $marker['run'],
            'safety' => is_string($marker['safety'] ?? null) ? $marker['safety'] : null,
            'safety_place' => $place,
            ...(is_int($missing) && $missing > 0 ? ['safety_missing' => $missing] : []),
            'since' => $marker['since'],
        ];
    }

    /**
     * Closes the site for a restore.
     *
     * @param  array{run: string, safety: string|null, safety_place: array{name: string, kind: string, location: array<string, string|int>, repository_id: string}|null, safety_missing?: int, since: string}  $marker
     *
     * @throws RestoreStopped
     */
    public static function open(array $marker): self
    {
        $mode = app()->maintenanceMode();
        $before = $mode->active() ? $mode->data() : null;

        // An unfinished restore's window isn't the operator's: theirs is the one it kept.
        if (is_array($before) && is_array($before[self::MARK] ?? null)) {
            $before = is_array($before[self::BEFORE] ?? null) ? $before[self::BEFORE] : null;
        }

        $window = new self([
            'except' => [],
            'redirect' => null,
            'retry' => 60,
            'refresh' => null,
            'secret' => bin2hex(random_bytes(16)),
            'status' => 503,
            'template' => null,
            self::MARK => $marker,
            self::BEFORE => $before,
        ]);
        $mode->activate($window->payload);

        if (! $window->holds()) {
            throw new RestoreStopped('Teme couldn’t close the site for maintenance.');
        }

        event(new MaintenanceModeEnabled);

        return $window;
    }

    /**
     * Adds the safety backup and its place to the marker, once it's made, so
     * a restore that stops after it names the way back, and reaches it; and
     * the media files it lacks, when it lacks any, so a run again still
     * counts them.
     *
     * @param  array{name: string, kind: string, location: array<string, string|int>, repository_id: string}|null  $place
     */
    public function record(string $safetyRunId, ?array $place, int $missing = 0): void
    {
        $this->payload[self::MARK] = [...$this->payload[self::MARK], 'safety' => $safetyRunId, 'safety_place' => $place, ...($missing > 0 ? ['safety_missing' => $missing] : [])];
        app()->maintenanceMode()->activate($this->payload);
    }

    public function bypassSecret(): string
    {
        return (string) $this->payload['secret'];
    }

    public function wasDownBefore(): bool
    {
        return $this->payload[self::BEFORE] !== null;
    }

    /**
     * Whether the site is still closed by this window.
     */
    public function holds(): bool
    {
        $mode = app()->maintenanceMode();

        return $mode->active() && ($mode->data()['secret'] ?? null) === $this->payload['secret'];
    }

    /**
     * Puts the flag back when a cache that held it was emptied.
     */
    public function reassert(): void
    {
        if (! $this->holds()) {
            app()->maintenanceMode()->activate($this->payload);
        }
    }

    /**
     * Opens the site, or puts back the operator's own maintenance when the
     * site was down before the restore.
     */
    public function reopen(): void
    {
        $mode = app()->maintenanceMode();
        $before = $this->payload[self::BEFORE];

        if (is_array($before)) {
            $mode->activate($before);

            return;
        }

        $mode->deactivate();
        event(new MaintenanceModeDisabled);
    }

    /**
     * The key the flag has in cache store $store, as that store writes it;
     * null when the flag isn't kept there.
     */
    public static function flagKey(string $store): ?string
    {
        if (config('app.maintenance.driver') !== 'cache' || (string) config('app.maintenance.store') !== $store) {
            return null;
        }

        return Cache::store($store)->getStore()->getPrefix().self::FLAG;
    }
}
