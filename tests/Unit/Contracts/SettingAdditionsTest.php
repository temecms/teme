<?php

declare(strict_types=1);

use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingType;

it('declares a colour setting with a fieldset and a visibility rule', function (): void {
    $setting = Setting::color('accent', 'Accent', 'The link and button colour.', '0.1.0')
        ->fieldset('site')
        ->visibleWhen(['header_style' => ['lead', 'none']]);

    expect($setting->type)->toBe(SettingType::Color)
        ->and($setting->fieldset)->toBe('site')
        ->and($setting->visibleWhen)->toBe(['header_style' => ['lead', 'none']]);
});

it('lets a repeater hold a url field for each item’s address', function (): void {
    $links = Setting::repeater('links', 'Links', 'The site’s links.', 'next', [
        Setting::text('label', 'Label', 'Its name.', 'next'),
        Setting::url('address', 'Address', 'Its address.', 'next'),
    ]);

    expect(array_map(fn (Setting $field): string => $field->type->value, $links->fields))->toBe(['text', 'url']);
});
