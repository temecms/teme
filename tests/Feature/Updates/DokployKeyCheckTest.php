<?php

declare(strict_types=1);

use App\Console\Commands\RunUpdater;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * While nothing is being updated, the updater tries Dokploy with the key now
 * and then, and Settings › Updates says in one sentence when the key is
 * missing or wrong, and what to do.
 */

uses(RefreshDatabase::class);

const KC_BASE = 'https://dokploy.example.org';

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    config([
        'teme.updater.platform' => 'dokploy',
        'teme.updater.image_repository' => 'ghcr.io/temecms/teme',
        'teme.updater.dokploy.url' => KC_BASE,
        'teme.updater.dokploy.compose_id' => 'cmp-teme',
    ]);
    Http::preventStrayRequests();
    $this->pass = fn () => app(RunUpdater::class)->pass();
    $this->notice = fn (): ?string => $this->actingAs($this->admin)->get('/admin/settings/updates')->assertOk()->inertiaProps('notice');
    $this->one = ['composeId' => 'cmp-teme', 'appName' => 'teme-abc123', 'serverId' => null, 'env' => "TEME_IMAGE=ghcr.io/temecms/teme:0.1.0\n", 'composeFile' => "services: {}\n", 'sourceType' => 'raw'];
});

it('says the key is missing on Settings › Updates itself, pointing to the field below rather than to the page it is on (#534)', function (): void {
    ($this->pass)();

    expect(($this->notice)())->toBe('Updating from Tools › Updates needs a Dokploy API key. Paste it below.');
    Http::assertNothingSent();
});

it('says Dokploy refused the key', function (): void {
    dpKey('dkp_wrong');
    Http::fake([KC_BASE.'/api/*' => Http::response('', 401)]);

    ($this->pass)();

    expect(($this->notice)())->toBe('Dokploy refused Teme’s API key. Make a new key in Dokploy and paste it in Settings › Updates.');
});

it('says nothing once the key works', function (): void {
    dpKey('dkp_right');
    Http::fake([KC_BASE.'/api/compose.one*' => Http::response($this->one)]);

    ($this->pass)();

    expect(($this->notice)())->toBeNull();
    Http::assertSent(fn (Request $request): bool => $request->header('x-api-key') === ['dkp_right']);
});

it('tries the key at most every two minutes', function (): void {
    dpKey('dkp_right');
    Http::fake([KC_BASE.'/api/compose.one*' => Http::response($this->one)]);

    ($this->pass)();
    ($this->pass)();
    $this->travel(3)->minutes();
    ($this->pass)();

    expect(Http::recorded())->toHaveCount(2);
});

it('says nothing, and asks nothing, where Teme doesn’t run on Dokploy', function (): void {
    config(['teme.updater.platform' => null]);

    ($this->pass)();

    expect(($this->notice)())->toBeNull();
    Http::assertNothingSent();
});

it('shows the sentence on Settings › Updates and nowhere else', function (): void {
    ($this->pass)();

    $this->actingAs($this->admin)->get('/admin/settings/general')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('notice', null));
});
