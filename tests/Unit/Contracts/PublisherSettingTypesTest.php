<?php

declare(strict_types=1);

use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingType;

it('declares a list of addresses, empty by default, bounded by max()', function (): void {
    $links = Setting::urls('links', 'Links', 'Addresses.', '0.1.0');

    expect([$links->type, $links->type->value, $links->default, $links->max])->toBe([SettingType::Urls, 'urls', [], null])
        ->and($links->max(5)->max)->toBe(5);
});

it('declares a person, nobody until one is chosen', function (): void {
    $person = Setting::person('someone', 'Someone', 'A person.', '0.1.0');

    expect([$person->type, $person->type->value, $person->default])->toBe([SettingType::Person, 'person', '']);
});

it('declares a week of opening hours, saying nothing until set, its days Monday first', function (): void {
    $week = Setting::hours('week', 'Week', 'Opening hours.', '0.1.0');

    expect([$week->type, $week->type->value, $week->default])->toBe([SettingType::Hours, 'hours', []])
        ->and(Setting::DAYS)->toBe(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']);
});
