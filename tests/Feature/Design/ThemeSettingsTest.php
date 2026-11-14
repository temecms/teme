<?php

declare(strict_types=1);

use App\Domain\Themes\ManifestValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
});

it('registers the theme\'s declared settings with their defaults, beside its slots', function (): void {
    $settings = app(SettingsReader::class);

    expect($settings->string('theme.good', 'color_scheme'))->toBe('auto')
        ->and($settings->bool('theme.good', 'show_related'))->toBeTrue()
        ->and($settings->string('theme.good', 'nav_primary'))->toBe('');
});

it('refuses a manifest setting that shadows a slot, has no options, or depends on a later key', function (): void {
    $errors = app(ManifestValidator::class)->errors(designManifest([
        ['key' => 'nav_extra', 'type' => 'boolean', 'label' => 'x', 'fieldset' => 'site', 'default' => false],
        ['key' => 'layout', 'type' => 'select', 'label' => 'Layout', 'fieldset' => 'latest', 'default' => 'list'],
        ['key' => 'images', 'type' => 'boolean', 'label' => 'Images', 'fieldset' => 'latest', 'default' => true, 'visible_when' => ['later' => true]],
        ['key' => 'later', 'type' => 'boolean', 'label' => 'Later', 'fieldset' => 'latest', 'default' => true],
    ]), base_path('tests/Fixtures/themes/good'));

    expect(implode("\n", $errors))
        ->toContain('"nav_extra"')
        ->toContain('"layout" needs options')
        ->toContain('"images" depends on "later"');
});

it('takes base and accent tokens only as hex colours, in tokens and tokens_dark alike', function (): void {
    $validator = app(ManifestValidator::class);
    $path = base_path('tests/Fixtures/themes/good');

    expect($validator->errors(array_replace(designManifest([]), [
        'tokens' => ['base' => '#FCFBF8', 'accent' => '#b3261e', 'muted' => 'color-mix(in oklch, currentColor 60%, transparent)'],
        'tokens_dark' => ['base' => '#121212', 'accent' => '#f28b82'],
    ]), $path))->toBe([]);

    expect(implode("\n", $validator->errors(array_replace(designManifest([]), [
        'tokens' => ['base' => 'white', 'accent' => '#b3261e'],
        'tokens_dark' => ['base' => 'oklch(20% 0 0)', 'shadow' => '#000'],
    ]), $path)))
        ->toContain('The token "base" must be a hex colour')
        ->toContain('The token "base" in tokens_dark must be a hex colour')
        ->toContain('Unknown token "shadow" in tokens_dark');
});

it('takes a screenshot path inside the theme to an image, and asks nothing of the file', function (): void {
    $validator = app(ManifestValidator::class);
    $path = base_path('tests/Fixtures/themes/good');
    $errors = fn (string $screenshot): array => $validator->errors(array_replace(designManifest([]), ['screenshot' => $screenshot]), $path);

    expect($errors('screenshot.png'))->toBe([])
        ->and($errors('../escape.png'))->toContain('screenshot must be a path inside the theme, with no .., to a .png, .jpg or .webp image.')
        ->and($errors('/etc/shot.png'))->not->toBe([])
        ->and($errors('screenshot.gif'))->not->toBe([]);
});
