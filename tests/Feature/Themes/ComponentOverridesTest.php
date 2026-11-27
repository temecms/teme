<?php

declare(strict_types=1);

use App\Domain\Themes\ActiveTheme;
use App\Domain\Themes\ComponentOverrides;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['teme.theme_paths' => [
        base_path('tests/Fixtures/themes/overrides'),
        base_path('tests/Fixtures/themes/overrides-undeclared'),
        base_path('tests/Fixtures/themes/overrides-unlisted'),
        base_path('tests/Fixtures/themes/overrides-inline'),
        base_path('tests/Fixtures/themes/overrides-missing'),
        base_path('tests/Fixtures/themes/overrides-future'),
        base_path('tests/Fixtures/themes/overrides-form'),
        base_path('tests/Fixtures/themes/overrides-form-bare'),
        base_path('tests/Fixtures/themes/good'),
    ]]);
});

it('draws a core component from the theme\'s view when the manifest declares it', function (): void {
    themeActivate('overrides');

    expect(Blade::render('<x-teme::navigation slot="primary" label="Main" />'))
        ->toContain('data-override="navigation"')
        ->toContain('aria-label="Main"');
});

it('draws core\'s component when the theme ships a view it does not declare', function (): void {
    themeActivate('overrides-undeclared');

    expect(Blade::render('<x-teme::navigation slot="primary" label="Main" />'))
        ->not->toContain('data-override');
});

it('names an undeclared view in the check, because core\'s component is used', function (): void {
    $this->artisan('teme:theme:check', ['code' => 'overrides-undeclared'])
        ->expectsOutputToContain('views/components/navigation.blade.php')
        ->assertSuccessful();
});

it('refuses an override of a component that is not on the list, the document included', function (): void {
    $this->artisan('teme:theme:check', ['code' => 'overrides-unlisted'])
        ->expectsOutputToContain('overrides names "document"')
        ->assertFailed();
});

it('refuses an override whose view carries an inline script', function (): void {
    $this->artisan('teme:theme:check', ['code' => 'overrides-inline'])
        ->expectsOutputToContain('views/components/navigation.blade.php, line 1')
        ->assertFailed();
});

it('refuses a declared override whose view the theme does not ship', function (): void {
    $this->artisan('teme:theme:check', ['code' => 'overrides-missing'])
        ->expectsOutputToContain('views/components/navigation.blade.php is missing')
        ->assertFailed();
});

it('warns when core\'s contract has moved past the theme\'s', function (): void {
    $this->app->instance(ComponentOverrides::class, new class(app(ActiveTheme::class)) extends ComponentOverrides
    {
        public function contractOf(string $name): int
        {
            return 2;
        }
    });

    $this->artisan('teme:theme:check', ['code' => 'overrides'])
        ->expectsOutputToContain('core is at contract 2')
        ->assertSuccessful();
});

it('passes a theme whose override is declared at core\'s contract', function (): void {
    $this->artisan('teme:theme:check', ['code' => 'overrides'])
        ->expectsOutputToContain('The theme passes.')
        ->assertSuccessful();
});

it('refuses a declared override whose contract is higher than core\'s', function (): void {
    $this->artisan('teme:theme:check', ['code' => 'overrides-future'])
        ->expectsOutputToContain('core is at contract 1')
        ->assertFailed();
});

it('never draws the document, the inline script or the thread script from a theme, even with a view of that name', function (): void {
    themeActivate('overrides');

    $html = Blade::render('<x-teme::document>Body</x-teme::document>')
        .Blade::render('<x-teme::inline-script name="menus" />')
        .Blade::render('<x-teme::thread-script />');

    expect($html)->not->toContain('data-fixture=');
});

it('draws a component without running the view composers of the theme', function (): void {
    themeActivate('overrides');
    $ran = false;
    View::composer('theme::*', function () use (&$ran): void {
        $ran = true;
    });

    Blade::render('<x-teme::navigation slot="primary" label="Main" />');

    expect($ran)->toBeFalse();
});

it('names only core\'s six components in its warnings about views', function (): void {
    $this->artisan('teme:theme:check', ['code' => 'overrides'])
        ->doesntExpectOutputToContain('document.blade.php')
        ->assertSuccessful();
});
