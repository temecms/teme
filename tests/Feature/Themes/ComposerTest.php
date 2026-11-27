<?php

declare(strict_types=1);

use App\Domain\Themes\Exceptions\ComposerKeyCollision;
use App\Domain\Themes\ThemeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
});

it('gives every theme view the site, from core', function (): void {
    $html = View::make('theme::templates.index')->render();

    expect($html)->toContain('data-site-name="'.e(app(SettingsReader::class)->string('general', 'site_title')).'"');
});

it('gives every theme view the version of Teme the site runs, and its release', function (): void {
    config(['teme.version' => '1.2.0']);

    expect(View::make('theme::partials.teme-version')->render())->toContain('data-teme-version="1.2.0"')->toContain('data-teme-release="1.2"');

    config(['teme.version' => '1.2.0-rc.1']);

    expect(View::make('theme::partials.teme-version')->render())->toContain('data-teme-version="1.2.0-rc.1"')->toContain('data-teme-release="1.2"');
});

it('runs the theme\'s own composers for the views they name', function (): void {
    // The archive, not the single: since 1.6c the single draws $page's lead, and this view has no page.
    expect(View::make('theme::templates.archive')->render())->toContain('data-template="archive">hello</main>');
});

it('refuses a composer that adds a key the view already has', function (): void {
    expect(fn () => View::make('theme::templates.archive', ['greeting' => 'already'])->render())
        ->toThrow(ComposerKeyCollision::class);
});

it('marks a theme broken when a composer asks for a non-contract service', function (): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/greedy')]]);

    expect(app(ThemeRegistry::class)->find('greedy')->errors)->toContain(
        'The composer Tests\Fixtures\Themes\Greedy\WantsTheDatabase may only depend on Teme\Contracts interfaces; it asks for Illuminate\Database\DatabaseManager.'
    );
});

it('lets a partial keep what its template was given, and gives every view the theme\'s own settings', function (): void {
    // partials/outer @includes partials/inner, and Blade hands inner every variable outer had.
    $html = View::make('theme::partials.outer')->render();

    expect($html)->toContain('data-inner-site="'.e(app(SettingsReader::class)->string('general', 'site_title')).'"')
        ->toContain('data-colour-scheme="auto"')
        ->toContain('data-slots="none"');
});

it('refuses a theme’s composer that returns a name core keeps for a page’s language, although no view has it yet', function (string $key): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/reserving')]]);
    themeActivate('reserving');

    expect(fn () => View::make("theme::partials.{$key}")->render())
        ->toThrow(ComposerKeyCollision::class, "The composer Tests\\Fixtures\\Themes\\Good\\ClaimsCoreNames of the theme reserving adds \"{$key}\" to partials.{$key}, a name kept for Teme.");
})->with(['language', 'direction', 'alternates', 'plugins']);

it('lets the same composer add a new name of its own', function (): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/reserving')]]);
    themeActivate('reserving');

    expect(View::make('theme::partials.mood')->render())->toContain('<p data-partial="mood">mood</p>');
});

it('draws the default theme’s pages as before, its composers adding none of the kept names', function (): void {
    config(['teme.theme_paths' => []]);
    themeActivate('default');

    addrGet('/')->assertOk();
    addrGet('/no-such-page/')->assertNotFound();
});
