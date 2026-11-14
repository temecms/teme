<?php

declare(strict_types=1);

namespace App\Domain\Cache\Queries;

use App\Domain\Cache\PageCache;
use App\Domain\Security\SecurityEventType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Settings\SettingsReader;

/**
 * What Tools › Page cache shows (I-467, I-500): whether the page cache is
 * on, where it keeps pages, how long they live, the CDN in front of it,
 * and when it was last cleared from the admin and by whom, read from the
 * security log for display only.
 */
final readonly class PageCacheScreen
{
    public function __construct(private SettingsReader $settings) {}

    /**
     * Returns the page's props: `on`; `keptIn`, in words; `lives`, any
     * page's, a search's and a "not found" page's, in words, each capped at
     * any page's; `cdn`, its name, or null; `cleared`, when (ISO 8601, UTC)
     * and by whom, or null; and `siteTimezone`.
     *
     * @return array{on: bool, keptIn: string, lives: array{any: string, search: string, notFound: string}, cdn: string|null, cleared: array{at: string, by: string}|null, siteTimezone: string}
     */
    public function data(): array
    {
        $life = PageCache::lifetime();
        $cdn = (string) config('page_cache.cdn.driver');
        $last = DB::table('security_events')
            ->where('type', SecurityEventType::PageCacheCleared->value)
            ->orderByDesc('id')
            ->first(['user_id', 'first_at']);
        $name = $last === null || $last->user_id === null ? null : User::query()->whereKey($last->user_id)->value('name');

        return [
            'on' => PageCache::enabled(),
            'keptIn' => match ((string) config('page_cache.driver')) {
                'redis' => __('Redis, shared by every server'),
                'static' => __('Files on this server, sent by the web server itself'),
                default => __('Files on this server'),
            },
            // "any", not a type's name: no type literal in app/ (tests/Architecture/NoTypeLiteralsTest.php).
            'lives' => [
                'any' => self::span($life),
                'search' => self::span(min(PageCache::SEARCH_LIFETIME, $life)),
                'notFound' => self::span(min(PageCache::NOT_FOUND_LIFETIME, $life)),
            ],
            'cdn' => match ($cdn) {
                '' => null,
                'cloudflare' => 'Cloudflare',
                default => $cdn,
            },
            'cleared' => $last === null ? null : [
                'at' => CarbonImmutable::parse((string) $last->first_at)->utc()->toIso8601String(),
                'by' => $name === null ? __('a removed account') : (string) $name,
            ],
            'siteTimezone' => $this->settings->string('general', 'timezone'),
        ];
    }

    /**
     * Seconds in words: whole hours, else whole minutes, else seconds.
     */
    private static function span(int $seconds): string
    {
        return match (true) {
            $seconds % 3600 === 0 => trans_choice('1 hour|:count hours', intdiv($seconds, 3600)),
            $seconds % 60 === 0 => trans_choice('1 minute|:count minutes', intdiv($seconds, 60)),
            default => trans_choice('1 second|:count seconds', $seconds),
        };
    }
}
