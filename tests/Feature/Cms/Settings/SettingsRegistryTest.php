<?php

declare(strict_types=1);

use App\Domain\Settings\SettingsRegistry;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;
use Teme\Contracts\Settings\SettingsRegistrar;

function demoGroup(string $key = 'demo', ?Setting $setting = null): SettingsGroup
{
    return new SettingsGroup(
        key: $key,
        label: 'Demo',
        description: 'A demo group.',
        section: 'general',
        ability: 'settings.demo.manage',
        settings: [$setting ?? Setting::text('title', 'Title', 'The title.', '0.1.0')],
        since: '0.1.0',
    );
}

it('is the registrar the container hands out', function (): void {
    expect(app(SettingsRegistrar::class))->toBe(app(SettingsRegistry::class));
});

it('registers the general group at boot', function (): void {
    $general = app(SettingsRegistry::class)->find('general');

    expect($general)->not->toBeNull()
        ->and($general->ability)->toBe('settings.general.manage')
        ->and(array_map(fn (Setting $setting): string => $setting->key, $general->settings))
        ->toBe(['site_title', 'tagline', 'admin_email', 'timezone', 'language']);
});

it('refuses a group registered twice', function (): void {
    $registry = new SettingsRegistry;
    $registry->group(demoGroup());

    expect(fn () => $registry->group(demoGroup()))->toThrow(InvalidArgumentException::class);
});

it('refuses a malformed group key', function (): void {
    expect(fn () => (new SettingsRegistry)->group(demoGroup('Not A Key')))->toThrow(InvalidArgumentException::class);
});

it('refuses a setting that does not document itself', function (): void {
    $undocumented = Setting::text('title', 'Title', '   ', '0.1.0');

    expect(fn () => (new SettingsRegistry)->group(demoGroup(setting: $undocumented)))->toThrow(InvalidArgumentException::class);
});

it('accepts a theme-scoped group key', function (): void {
    $registry = new SettingsRegistry;
    $registry->group(demoGroup('theme.editorial'));

    expect($registry->find('theme.editorial'))->not->toBeNull();
});

it('replaces a group through replace(), checking it as group() does, and group() still refuses a duplicate', function (): void {
    $registry = new SettingsRegistry;
    $registry->group(demoGroup());
    $registry->replace(demoGroup(setting: Setting::text('subtitle', 'Subtitle', 'The subtitle.', '0.1.0')));

    expect($registry->find('demo')->setting('subtitle'))->not->toBeNull()
        ->and($registry->find('demo')->setting('title'))->toBeNull()
        ->and($registry->all())->toHaveCount(1)
        ->and(fn () => $registry->replace(demoGroup('Not A Key')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->group(demoGroup()))->toThrow(InvalidArgumentException::class);
});

it('builds a derived group when it is first read, in its place, and again only when its stamp changes', function (): void {
    $registry = new SettingsRegistry;
    $builds = 0;
    $stamp = 'one';
    $registry->group(demoGroup('first'));
    $registry->derive('demo', function () use (&$builds): SettingsGroup {
        $builds++;

        return demoGroup();
    }, function () use (&$stamp): string {
        return $stamp;
    });
    $registry->group(demoGroup('last'));

    expect($builds)->toBe(0)
        ->and($registry->find('demo')?->key)->toBe('demo')
        ->and(array_map(fn (SettingsGroup $group): string => $group->key, $registry->all()))->toBe(['first', 'demo', 'last'])
        ->and($builds)->toBe(1);

    $stamp = 'two';

    expect($registry->find('demo'))->not->toBeNull()
        ->and($builds)->toBe(2);
});

it('checks a derived group as it checks any, and refuses a second group under its key', function (): void {
    $registry = new SettingsRegistry;
    $registry->derive('demo', fn (): SettingsGroup => demoGroup(setting: Setting::text('Not A Key', 'Title', 'The title.', '0.1.0')), fn (): string => 'one');
    $registry->derive('other', fn (): SettingsGroup => demoGroup(), fn (): string => 'one');

    expect(fn () => $registry->find('demo'))->toThrow(InvalidArgumentException::class, 'Invalid setting key')
        ->and(fn () => $registry->find('other'))->toThrow(InvalidArgumentException::class, 'was built as [demo]')
        ->and(fn () => $registry->group(demoGroup()))->toThrow(InvalidArgumentException::class, 'already registered');
});

it('makes a derived group fixed when it is replaced', function (): void {
    $registry = new SettingsRegistry;
    $builds = 0;
    $registry->derive('demo', function () use (&$builds): SettingsGroup {
        $builds++;

        return demoGroup();
    }, fn (): string => 'one');
    $registry->replace(demoGroup(setting: Setting::text('subtitle', 'Subtitle', 'The subtitle.', '0.1.0')));

    expect($registry->find('demo')?->setting('subtitle'))->not->toBeNull()
        ->and($builds)->toBe(0);
});
