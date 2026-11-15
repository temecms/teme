<?php

declare(strict_types=1);

use App\Domain\ManageApi\Operations\Input\RevokeApiKeyInput;
use App\Domain\ManageApi\Operations\RevokeApiKey;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Support\Security\ElevationRequiredException;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\ManageApi\ManageApiFixture;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->person = User::factory()->create(['name' => 'Ada Writer'])->assignRole('author');
    RateLimiter::clear('api-manage-address:127.0.0.1');
});

it('answers who the key is, its person and its abilities', function (): void {
    ManageApiFixture::on();
    $secret = ManageApiFixture::key($this->person, ['site.read', 'content.write']);

    $response = $this->getJson('/api/manage/v1/me', ManageApiFixture::headers($secret));

    $response->assertOk()
        ->assertJsonPath('data.key.name', 'Importer')
        ->assertJsonPath('data.key.abilities', ['site.read', 'content.write'])
        ->assertJsonPath('data.person.id', $this->person->id)
        ->assertJsonPath('data.person.name', 'Ada Writer')
        ->assertJsonPath('data.site.url', rtrim(url('/'), '/').'/');

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->has('ETag'))->toBeFalse()
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('answers the same unauthorized problem for no key, a wrong key, a revoked key and an expired key', function (): void {
    ManageApiFixture::on();
    $revoked = ManageApiFixture::key($this->person, name: 'Revoked');
    $expired = ManageApiFixture::key($this->person, name: 'Expired', expiresAt: now()->addMinute()->toIso8601String());
    $keyId = (string) DB::table('api_keys')->where('name', 'Revoked')->value('id');
    $admin = User::factory()->create()->assignRole('administrator');
    scElevated(fn () => app(RevokeApiKey::class)->execute(new RevokeApiKeyInput($keyId, (string) $admin->id)));
    $this->travel(2)->minutes();

    $answers = [
        $this->getJson('/api/manage/v1/me'),
        $this->getJson('/api/manage/v1/me', ManageApiFixture::headers('teme_'.str_repeat('x', 40))),
        $this->getJson('/api/manage/v1/me', ['Authorization' => 'Basic abc']),
        $this->getJson('/api/manage/v1/me', ManageApiFixture::headers($revoked)),
        $this->getJson('/api/manage/v1/me', ManageApiFixture::headers($expired)),
    ];

    foreach ($answers as $response) {
        $response->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'unauthorized');

        expect($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->json())->toBe($answers[0]->json());
    }
});

it('answers api_off while the switch is off, whatever the key', function (): void {
    $secret = ManageApiFixture::key($this->person);

    $this->getJson('/api/manage/v1/me', ManageApiFixture::headers($secret))
        ->assertStatus(404)
        ->assertJsonPath('code', 'api_off');

    expect(DB::table('api_keys')->value('last_used_at'))->toBeNull();
});

it('keeps the management API apart from the delivery API’s switch', function (): void {
    ManageApiFixture::on();
    $secret = ManageApiFixture::key($this->person);

    $this->getJson('/api/v1', ManageApiFixture::headers($secret))->assertJsonPath('code', 'api_off');
    $this->getJson('/api/manage/v1/me', ManageApiFixture::headers($secret))->assertOk();
});

it('limits each key to the requests a minute the setting allows', function (): void {
    ManageApiFixture::on(perMinute: 3);
    $secret = ManageApiFixture::key($this->person);
    $other = ManageApiFixture::key($this->person, name: 'Other');

    foreach (range(1, 3) as $request) {
        $this->getJson('/api/manage/v1/me', ManageApiFixture::headers($secret))->assertOk();
    }

    $this->getJson('/api/manage/v1/me', ManageApiFixture::headers($secret))
        ->assertStatus(429)
        ->assertJsonPath('code', 'rate_limited')
        ->assertHeader('Retry-After');

    $this->getJson('/api/manage/v1/me', ManageApiFixture::headers($other))->assertOk();
});

it('limits each address before a key is read', function (): void {
    ManageApiFixture::on();

    foreach (range(1, 30) as $request) {
        $this->getJson('/api/manage/v1/me')->assertStatus(401);
    }

    $this->getJson('/api/manage/v1/me')->assertStatus(429)->assertJsonPath('code', 'rate_limited');
});

it('notes when a key was last used, at most once a minute', function (): void {
    ManageApiFixture::on();
    $secret = ManageApiFixture::key($this->person);
    $this->freezeTime();

    $this->getJson('/api/manage/v1/me', ManageApiFixture::headers($secret))->assertOk();
    $first = DB::table('api_keys')->value('last_used_at');

    $this->travel(30)->seconds();
    $this->getJson('/api/manage/v1/me', ManageApiFixture::headers($secret))->assertOk();

    expect(DB::table('api_keys')->value('last_used_at'))->toBe($first);

    $this->travel(31)->seconds();
    $this->getJson('/api/manage/v1/me', ManageApiFixture::headers($secret))->assertOk();

    expect(DB::table('api_keys')->value('last_used_at'))->not->toBe($first)->not->toBeNull();
});

it('never satisfies elevation: an elevated Operation reached as the key’s person is refused', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    ManageApiFixture::on();
    $secret = ManageApiFixture::key($admin, ['site.read']);

    // What a key's request runs under: the default, which no token satisfies.
    $this->getJson('/api/manage/v1/me', ManageApiFixture::headers($secret))->assertOk();

    expect(fn () => app(UpdateSettings::class)->execute(new UpdateSettingsInput('security', ['password_min_length' => 20], [], [], 0, (string) $admin->id)))
        ->toThrow(ElevationRequiredException::class);
});

it('sets no cookie and never stores an answer in the page cache', function (): void {
    ManageApiFixture::on();
    $secret = ManageApiFixture::key($this->person);

    $response = $this->getJson('/api/manage/v1/me', ManageApiFixture::headers($secret));

    expect($response->headers->getCookies())->toBe([])
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});
