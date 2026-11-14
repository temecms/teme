<?php

declare(strict_types=1);

use App\Domain\Settings\SettingsRegistry;
use App\Domain\Types\StoredTypes;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

it('documents every registered setting', function (): void {
    // CI's checks job has no database, and four groups are built from the
    // content types, the stored ones included: an empty copy in the cache
    // store answers for them (I-3296).
    Cache::put(StoredTypes::CACHE_KEY, ['version' => '', 'definitions' => []], 60);

    foreach (app(SettingsRegistry::class)->all() as $group) {
        expect(trim($group->description))->not->toBe('', "Group [{$group->key}] has no description.");

        foreach ($group->settings as $setting) {
            $name = "{$group->key}.{$setting->key}";

            expect(trim($setting->label))->not->toBe('', "[{$name}] has no label.")
                ->and(trim($setting->description))->not->toBe('', "[{$name}] has no description.")
                ->and($setting->since)->toMatch('/^\d+\.\d+\.\d+$/', "[{$name}] has no since version.");
        }
    }
});
