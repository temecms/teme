<?php

declare(strict_types=1);

namespace App\Domain\SiteCode\Queries;

use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\SiteCode\Models\SiteCodeEntry;
use App\Domain\SiteCode\SiteCode;
use App\Domain\SiteCode\SiteCodePolicy;
use App\Domain\SiteCode\SiteCodeSettings;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Settings\SettingsReader;

/**
 * What Settings › Site code shows (I-866): every entry by placement, with
 * who changed it last and when; whether the host has switched site code
 * off; and the newest changes, read from the security log for display only,
 * each with the entry before and after it.
 */
final readonly class SiteCodeScreen
{
    /** How many changes the screen lists, newest first. */
    private const int CHANGES = 8;

    /**
     * Each change's word, by its security event's type.
     *
     * @var array<string, string>
     */
    private const array WHAT = [
        'site_code.added' => 'Added',
        'site_code.changed' => 'Changed',
        'site_code.switched_on' => 'Switched on',
        'site_code.switched_off' => 'Switched off',
        'site_code.deleted' => 'Deleted',
    ];

    public function __construct(private SettingsReader $settings) {}

    /**
     * The page's props: `locked`, `siteTimezone`, `places` (each placement's
     * key, label, where it is, and its entries in the order they were added,
     * each with the hosts declared for it and those found in its code)
     * and `changes` (the eight newest, newest first, each side with its
     * declared hosts).
     *
     * @return array{locked: bool, siteTimezone: string, places: list<array<string, mixed>>, changes: list<array<string, mixed>>, adsTxt: string, adsTxtMode: string, adsTxtRedirect: string, adsTxtVersion: int}
     */
    public function data(): array
    {
        $entries = SiteCodeEntry::query()->orderBy('id')->get();
        $changes = DB::table('security_events')
            ->whereIn('type', array_keys(self::WHAT))
            ->orderByDesc('id')
            ->limit(self::CHANGES)
            ->get(['id', 'type', 'user_id', 'context', 'first_at']);
        $names = User::query()
            ->whereIn('id', [...$entries->pluck('updated_by')->filter()->all(), ...$changes->pluck('user_id')->filter()->all()])
            ->pluck('name', 'id')
            ->all();
        $who = fn (mixed $id): string => $id === null ? 'a removed account' : (string) ($names[(int) $id] ?? 'a removed account');

        $places = [];

        foreach (SiteCode::PLACES as $key => $place) {
            $places[] = [
                'key' => $key,
                'label' => $place['label'],
                'where' => $place['where'],
                'entries' => $entries->where('placement', $key)->map(fn (SiteCodeEntry $entry): array => [
                    'id' => $entry->id,
                    'name' => $entry->name,
                    'placement' => $entry->placement,
                    'code' => $entry->code,
                    'on' => $entry->enabled,
                    'structuredData' => $entry->holdsStructuredData(),
                    'origins' => $entry->origins,
                    'found' => self::found($entry->code),
                    'changedBy' => $who($entry->updated_by),
                    'changedAt' => CarbonImmutable::parse((string) $entry->updated_at)->utc()->toIso8601String(),
                ])->values()->all(),
            ];
        }

        return [
            'locked' => SiteCode::disallowed(),
            'siteTimezone' => $this->settings->string('general', 'timezone'),
            'places' => $places,
            'changes' => $changes->map(function (object $row) use ($who): array {
                /** @var array<string, mixed> $context */
                $context = (array) json_decode((string) $row->context, true);

                return [
                    'id' => (int) $row->id,
                    'what' => self::WHAT[(string) $row->type],
                    'name' => (string) ($context['after_name'] ?? $context['before_name'] ?? ''),
                    'who' => $who($row->user_id),
                    'when' => CarbonImmutable::parse((string) $row->first_at)->utc()->toIso8601String(),
                    'before' => self::described($context, 'before'),
                    'after' => self::described($context, 'after'),
                    'beforeOrigins' => self::declared($context, 'before'),
                    'afterOrigins' => self::declared($context, 'after'),
                ];
            })->values()->all(),
            'adsTxt' => $this->settings->string(SiteCodeSettings::KEY, SiteCodeSettings::ADS_TXT),
            'adsTxtMode' => $this->settings->string(SiteCodeSettings::KEY, SiteCodeSettings::ADS_TXT_MODE),
            'adsTxtRedirect' => $this->settings->string(SiteCodeSettings::KEY, SiteCodeSettings::ADS_TXT_REDIRECT),
            'adsTxtVersion' => (int) (SettingsGroupRecord::query()->whereKey(SiteCodeSettings::KEY)->value('version') ?? 0),
        ];
    }

    /**
     * The origins worked out from the code itself (I-3375), every
     * directive's, each once and sorted.
     *
     * @return list<string>
     */
    private static function found(string $code): array
    {
        $found = array_values(array_unique(array_merge([], ...array_values(SiteCodePolicy::of($code, [])['origins']))));
        sort($found);

        return $found;
    }

    /**
     * One side of a change's declared hosts (I-3375), as the log keeps them,
     * space-separated; [] for a change logged before entries had them, and
     * null where there was nothing.
     *
     * @param  array<string, mixed>  $context
     *
     * @return list<string>|null
     */
    private static function declared(array $context, string $side): ?array
    {
        if (! is_string($context["{$side}_placement"] ?? null)) {
            return null;
        }

        return array_values(array_filter(explode(' ', (string) ($context["{$side}_origins"] ?? '')), fn (string $host): bool => $host !== ''));
    }

    /**
     * One side of a change as the screen writes it: the placement, on or
     * off, and the code on the next line; null where there was nothing.
     *
     * @param  array<string, mixed>  $context
     */
    private static function described(array $context, string $side): ?string
    {
        $placement = $context["{$side}_placement"] ?? null;

        if (! is_string($placement)) {
            return null;
        }

        $state = ($context["{$side}_on"] ?? false) === true ? 'on' : 'off';

        return (SiteCode::PLACES[$placement]['label'] ?? $placement).', '.$state."\n".(string) ($context["{$side}_code"] ?? '');
    }
}
