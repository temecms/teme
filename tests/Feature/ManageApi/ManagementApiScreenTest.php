<?php

declare(strict_types=1);

use App\Domain\ManageApi\ManageApiSettings;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\ManageApi\ManageApiFixture;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->writer = User::factory()->create(['name' => 'Wyn Writer'])->assignRole('author');
    $this->confirmed = ['auth.password_confirmed_at' => now()->getTimestamp()];
});

it('shows the switch, the limit and the keys, never a secret', function (): void {
    $secret = ManageApiFixture::key($this->writer, ['site.read'], 'Importer');

    $this->actingAs($this->admin)->get(route('settings.management-api'))
        ->assertOk()
        ->assertDontSee($secret)
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/management-api')
            ->where('values.enabled', false)
            ->where('keys.0.name', 'Importer')
            ->where('keys.0.person', 'Wyn Writer')
            ->where('keys.0.start', substr($secret, 0, 12)));
});

it('refuses the screen and every change to an editor', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    $this->actingAs($editor)->get(route('settings.management-api'))->assertForbidden();
    $this->actingAs($editor)->withSession($this->confirmed)
        ->postJson(route('settings.management-api.keys.store'), ['name' => 'Mine', 'user_id' => (string) $editor->id, 'abilities' => ['site.read']])
        ->assertForbidden();

    expect(DB::table('api_keys')->count())->toBe(0);
});

it('makes a key and answers its secret once, as JSON that is never stored', function (): void {
    $this->actingAs($this->admin)->withSession($this->confirmed)
        ->postJson(route('settings.management-api.keys.store'), ['name' => 'Importer', 'user_id' => (string) $this->writer->id, 'abilities' => ['site.read', 'content.write']])
        ->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('name', 'Importer')
        ->assertJson(fn ($json) => $json->where('secret', fn (string $secret): bool => (bool) preg_match('/^teme_[0-9A-Za-z]{40}$/', $secret))->etc());

    expect(DB::table('api_keys')->where('name', 'Importer')->value('user_id'))->toBe($this->writer->id);
});

it('answers 423 and makes nothing without a fresh password', function (): void {
    $this->actingAs($this->admin)
        ->postJson(route('settings.management-api.keys.store'), ['name' => 'Importer', 'user_id' => (string) $this->writer->id, 'abilities' => ['site.read']])
        ->assertStatus(423);

    expect(DB::table('api_keys')->count())->toBe(0);
});

it('answers a refused key with the field it is about', function (): void {
    $this->actingAs($this->admin)->withSession($this->confirmed)
        ->postJson(route('settings.management-api.keys.store'), ['name' => '', 'user_id' => (string) $this->writer->id, 'abilities' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'abilities']);

    expect(DB::table('api_keys')->count())->toBe(0);
});

it('revokes a key', function (): void {
    ManageApiFixture::key($this->writer, ['site.read'], 'Importer');
    $id = (string) DB::table('api_keys')->value('id');

    $this->actingAs($this->admin)->withSession($this->confirmed)
        ->delete(route('settings.management-api.keys.destroy', $id))
        ->assertRedirect(route('settings.management-api'));

    expect(DB::table('api_keys')->whereNotNull('revoked_at')->count())->toBe(1);
});

it('turns the API on and sets its limit through the settings', function (): void {
    $version = (int) DB::table('settings')->where('group_key', ManageApiSettings::KEY)->value('version');

    $this->actingAs($this->admin)->withSession($this->confirmed)
        ->put(route('settings.management-api.update'), ['values' => ['enabled' => true, 'per_minute' => 120, 'other' => 'x'], 'expected_version' => $version])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('settings.management-api'));

    expect(ManageApiSettings::on())->toBeTrue()
        ->and(ManageApiSettings::perMinute())->toBe(120);
});
