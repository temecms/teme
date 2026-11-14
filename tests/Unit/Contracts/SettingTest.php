<?php

declare(strict_types=1);

use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;
use Teme\Contracts\Settings\SettingType;

it('builds a setting with its type-appropriate default', function (): void {
    expect(Setting::text('title', 'Title', 'The title.', '0.1.0')->default)->toBe('')
        ->and(Setting::number('count', 'Count', 'A count.', '0.1.0')->default)->toBe(0)
        ->and(Setting::boolean('on', 'On', 'A switch.', '0.1.0')->default)->toBeFalse()
        ->and(Setting::timezone('tz', 'Time zone', 'A zone.', '0.1.0')->default)->toBe('UTC')
        ->and(Setting::secret('key', 'Key', 'A key.', '0.1.0')->default)->toBeNull()
        ->and(Setting::select('mode', 'Mode', 'A mode.', '0.1.0', ['a' => 'A', 'b' => 'B'])->default)->toBe('a');
});

it('never mutates a setting — modifiers return a new one', function (): void {
    $base = Setting::number('count', 'Count', 'A count.', '0.1.0');
    $bounded = $base->min(1)->max(10)->default(5)->required();

    expect($base->min)->toBeNull()
        ->and($base->required)->toBeFalse()
        ->and($bounded->min)->toBe(1)
        ->and($bounded->max)->toBe(10)
        ->and($bounded->default)->toBe(5)
        ->and($bounded->required)->toBeTrue()
        ->and($bounded->type)->toBe(SettingType::Number);
});

it('allows an environment variable only on a secret', function (): void {
    expect(Setting::secret('key', 'Key', 'A key.', '0.1.0')->env('TEME_KEY')->env)->toBe('TEME_KEY');

    expect(fn () => Setting::text('title', 'Title', 'The title.', '0.1.0')->env('TEME_TITLE'))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a select with no options', function (): void {
    expect(fn () => Setting::select('mode', 'Mode', 'A mode.', '0.1.0', []))
        ->toThrow(InvalidArgumentException::class);
});

it('finds a setting in its group by key', function (): void {
    $group = new SettingsGroup(
        key: 'demo',
        label: 'Demo',
        description: 'A demo group.',
        section: 'general',
        ability: 'settings.demo.manage',
        settings: [Setting::text('title', 'Title', 'The title.', '0.1.0')],
        since: '0.1.0',
    );

    expect($group->setting('title')?->label)->toBe('Title')
        ->and($group->setting('missing'))->toBeNull();
});
