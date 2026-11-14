<?php

declare(strict_types=1);

use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingType;

it('declares a multiselect with an empty default', function (): void {
    $setting = Setting::multiselect('roles', 'Roles', 'Some roles.', '0.1.0', ['a' => 'A', 'b' => 'B']);

    expect($setting->type)->toBe(SettingType::MultiSelect)
        ->and($setting->default)->toBe([])
        ->and($setting->options)->toBe(['a' => 'A', 'b' => 'B']);
});

it('refuses a multiselect with no options', function (): void {
    expect(fn () => Setting::multiselect('roles', 'Roles', 'Some roles.', '0.1.0', []))->toThrow(InvalidArgumentException::class);
});
