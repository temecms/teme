<?php

declare(strict_types=1);

use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingType;

it('declares a media setting, holding nothing until an image is chosen', function (): void {
    $setting = Setting::media('picture', 'Picture', 'An image.', '0.1.0');

    expect($setting->type)->toBe(SettingType::Media)
        ->and($setting->type->value)->toBe('media')
        ->and($setting->default)->toBe('');
});

it('declares a pairs setting with its two columns, empty by default, bounded by max() and maxLength()', function (): void {
    $plain = Setting::pairs('rows', 'Rows', 'Pairs.', '0.1.0');
    $named = Setting::pairs('tags', 'Tags', 'Pairs.', '0.1.0', 'Name', 'Content')->max(20)->maxLength(500);

    expect($plain->type)->toBe(SettingType::Pairs)
        ->and($plain->type->value)->toBe('pairs')
        ->and($plain->default)->toBe([])
        ->and($plain->options)->toBe(['name' => 'Name', 'value' => 'Value'])
        ->and($named->options)->toBe(['name' => 'Name', 'value' => 'Content'])
        ->and([$named->max, $named->maxLength])->toBe([20, 500]);
});
