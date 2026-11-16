<?php

declare(strict_types=1);

use App\Domain\Plugins\Doors\HostResolver;
use App\Domain\Plugins\Doors\PluginDoors;
use App\Domain\Plugins\Doors\PluginHttp;
use App\Domain\Plugins\Doors\PluginSecrets;
use App\Domain\Site\CoreSiteInfo;
use Illuminate\Support\Facades\Http;
use Teme\Contracts\Http\HostRefused;
use Teme\Contracts\Http\OutboundHttp;
use Teme\Contracts\Settings\SecretReader;
use Teme\Contracts\Settings\SettingsReader;
use Teme\Contracts\Site\SiteInfo;
use Tests\Fixtures\Plugins\Lists\FakeResolver;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('offers the outbound door only to a plugin granted http, limited to its hosts', function (): void {
    app()->instance(HostResolver::class, FakeResolver::answering(['api.example.com' => ['93.184.215.14'], 'other.example.com' => ['93.184.215.16']]));
    Http::fake(['https://api.example.com/*' => Http::response('yes')]);
    $doors = app(PluginDoors::class);

    $granted = $doors->for('acme/example-sync', 'example-sync', ['content.read', 'http'], ['api.example.com']);
    $without = $doors->for('acme/example-sync', 'example-sync', ['content.read'], ['api.example.com']);

    expect($granted[OutboundHttp::class])->toBeInstanceOf(PluginHttp::class)
        ->and($granted[OutboundHttp::class]->get('https://api.example.com/x')->body)->toBe('yes')
        ->and(fn () => $granted[OutboundHttp::class]->get('https://other.example.com/x'))->toThrow(HostRefused::class, 'It may send requests only to the hosts you agreed to: api.example.com.')
        ->and($without)->not->toHaveKey(OutboundHttp::class);
});

it('offers every plugin the settings reader, its own secrets and the site’s public facts', function (): void {
    $offered = app(PluginDoors::class)->for('acme/feeds', 'feeds', [], []);

    expect(array_keys($offered))->toEqualCanonicalizing([SettingsReader::class, SecretReader::class, SiteInfo::class])
        ->and($offered[SiteInfo::class])->toBeInstanceOf(CoreSiteInfo::class)
        ->and($offered[SettingsReader::class])->toBe(app(SettingsReader::class))
        ->and($offered[SecretReader::class])->toBeInstanceOf(PluginSecrets::class)
        ->and($offered[SecretReader::class]->secret('backups', 'machine_key'))->toBeNull();
});
