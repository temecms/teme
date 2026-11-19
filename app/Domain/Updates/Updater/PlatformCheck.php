<?php

/**
 * Whether the updater can reach Dokploy with the API key, tried by the updater every two minutes and kept in the
 * cache, a telemetry sink: Settings › Updates shows the sentence Dokploy's refusal gave, and nothing decides from it.
 * The key itself is never kept here.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Updates\Updater;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

final readonly class PlatformCheck
{
    public const string KEY = 'teme.updater.platform-check';

    /** How often the updater tries the key, in seconds. */
    public const int EVERY = 120;

    public function __construct(private Platform $platform) {}

    /**
     * Tries the platform once, if the last try is two minutes old and the site runs on Dokploy.
     */
    public function runIfDue(): void
    {
        if (config('teme.updater.platform') !== 'dokploy') {
            return;
        }

        $last = $this->last();

        if ($last !== null && $last['checkedAt']->diffInSeconds(CarbonImmutable::now()) < self::EVERY) {
            return;
        }

        try {
            $this->platform->image(ImageSetting::Site);
            $refusal = null;
        } catch (PlatformRefused $refused) {
            // Teme's own words: DokployPlatform builds every refusal itself, never from Dokploy's answer.
            $refusal = $refused->getMessage();
        }

        try {
            Cache::put(self::KEY, ['refusal' => $refusal, 'checkedAt' => CarbonImmutable::now()->toIso8601String()], CarbonImmutable::now()->addDay());
        } catch (Throwable) {
            // Dropped, as a sink's line is.
        }
    }

    /**
     * The sentence saying why the updater can't use Dokploy now, or null when it can or hasn't tried.
     */
    public function sentence(): ?string
    {
        return $this->last()['refusal'] ?? null;
    }

    /**
     * @return array{refusal: ?string, checkedAt: CarbonImmutable}|null
     */
    private function last(): ?array
    {
        try {
            $last = Cache::get(self::KEY);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($last) || ! is_string($last['checkedAt'] ?? null)) {
            return null;
        }

        return ['refusal' => is_string($last['refusal'] ?? null) ? $last['refusal'] : null, 'checkedAt' => CarbonImmutable::parse($last['checkedAt'])];
    }
}
