<?php

declare(strict_types=1);

use App\Domain\Plugins\Consent;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Queries\PluginsScreen;
use App\Domain\Site\Policy\PolicyMode;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Plugins\Billboard\Billboard;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    PluginFixtures::use('billboard');
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    app()->instance(Elevation::class, new OperatorElevation);

    $this->manifest = app(PluginRegistry::class)->all()['tests/billboard']->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/billboard', $this->manifest->version(), $this->manifest->capabilities(), $this->manifest->hosts(), $this->manifest->hooks(), null));
    app(PluginLoader::class)->load();
    Billboard::reset();
});

afterEach(function (): void {
    Billboard::reset();
    PluginFixtures::forget();
});

/** The page's content security policy, as its header carries it. */
function ihPolicy(string $address): string
{
    return (string) addrGet($address)->assertOk()->headers->get((string) PolicyMode::current()->header());
}

it('lets a page load from the hosts a plugin declared only where the plugin drew', function (): void {
    expect(ihPolicy('/'))->toMatch('~frame-src [^;]*https://frames\.billboard\.example~')
        ->toMatch('~connect-src [^;]*https://\*\.billboard\.example~');

    Billboard::$gives = [];

    expect(ihPolicy('/'))->not->toContain('billboard.example');
});

it('keeps the hosts a person agreed to with the plugin\'s consent, and shows them in words', function (): void {
    $record = PluginRecord::query()->where('code', 'billboard')->sole();
    $rows = json_encode(app(PluginsScreen::class)->rows(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    expect($record->consent['csp'] ?? null)->toEqual(['connect' => ['*.billboard.example'], 'frame' => ['frames.billboard.example']])
        ->and($rows)->toContain('Lets the content it inserts load from: *.billboard.example, frames.billboard.example')
        ->toContain('Add content to every page of your site');
});

it('asks again when a plugin declares a host for its content that no one agreed to', function (): void {
    $consent = PluginRecord::query()->where('code', 'billboard')->sole()->consent;

    expect(Consent::covers($consent, $this->manifest))->toBeTrue()
        ->and(Consent::covers(['csp' => ['frame' => ['frames.billboard.example']]] + $consent, $this->manifest))->toBeFalse()
        ->and(Consent::added(['csp' => ['frame' => ['frames.billboard.example']]] + $consent, $this->manifest)['insertionHosts'])->toBe(['*.billboard.example']);
});
