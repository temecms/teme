<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Domain\Cache\RenderRecord;
use App\Domain\Settings\Models\SettingsGroupRecord;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Teme\Contracts\Settings\SettingsGroup;
use Teme\Contracts\Settings\SettingsReader;
use Teme\Contracts\Settings\SettingType;
use UnexpectedValueException;

/**
 * Per-request memo, then the cache store, then one primary-key lookup — per
 * group a request touches. Never every group: that would be autoload again.
 * While a design preview is active, the design groups read their drafts
 * over their live values. A page that reads a group records the group's
 * declared page-cache tags.
 */
final class SettingsRepository implements SettingsReader
{
    public function __construct(private readonly SettingsRegistry $registry) {}

    public static function cacheKey(string $group): string
    {
        return 'settings:'.$group;
    }

    public function group(string $group): array
    {
        $definition = $this->definition($group);

        // A page that reads a group carries the tags it declares, which a
        // change to it purges (SettingsGroup::$cacheTags, I-263). Asked for at
        // each read: this repository outlives a request.
        app(RenderRecord::class)->tag(...$definition->cacheTags);
        $overrides = $this->overrides($group);

        // A design preview shows the drafts over the live values, key by
        // key. Asked for at each read: this repository outlives a request.
        if (DesignPreview::covers($group)) {
            $overrides = app(DesignPreview::class)->draft($group) + $overrides;
        }

        $values = [];

        foreach ($definition->settings as $setting) {
            if ($setting->type === SettingType::Secret) {
                continue;
            }

            $values[$setting->key] = array_key_exists($setting->key, $overrides)
                ? $overrides[$setting->key]
                : $setting->default;
        }

        return $values;
    }

    public function get(string $group, string $key): mixed
    {
        $values = $this->group($group);

        if (! array_key_exists($key, $values)) {
            throw new InvalidArgumentException("Unknown setting [{$group}.{$key}].");
        }

        return $values[$key];
    }

    public function string(string $group, string $key): string
    {
        $value = $this->get($group, $key);

        return is_string($value) ? $value : throw new UnexpectedValueException("Setting [{$group}.{$key}] is not a string.");
    }

    public function int(string $group, string $key): int
    {
        $value = $this->get($group, $key);

        return is_int($value) ? $value : throw new UnexpectedValueException("Setting [{$group}.{$key}] is not an integer.");
    }

    public function bool(string $group, string $key): bool
    {
        $value = $this->get($group, $key);

        return is_bool($value) ? $value : throw new UnexpectedValueException("Setting [{$group}.{$key}] is not a boolean.");
    }

    public function stringList(string $group, string $key): array
    {
        $value = $this->get($group, $key);

        if (! is_array($value) || ! array_is_list($value) || array_filter($value, 'is_string') !== $value) {
            throw new UnexpectedValueException("Setting [{$group}.{$key}] is not a list of strings.");
        }

        /** @var list<string> $value */
        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function overrides(string $group): array
    {
        /** @var array<string, mixed> */
        return Cache::memo()->rememberForever(
            self::cacheKey($group),
            // find(), not value(): value() skips the model's casts and would
            // return the raw JSON string.
            fn (): array => SettingsGroupRecord::query()->find($group)->overrides ?? [],
        );
    }

    private function definition(string $group): SettingsGroup
    {
        return $this->registry->find($group) ?? throw new InvalidArgumentException("Unknown settings group [{$group}].");
    }
}
