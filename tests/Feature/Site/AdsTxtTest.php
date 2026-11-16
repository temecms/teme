<?php

declare(strict_types=1);

use App\Domain\SiteCode\Operations\Input\UpdateAdsTxtInput;
use App\Domain\SiteCode\Operations\UpdateAdsTxt;
use App\Domain\SiteCode\SiteCodeSettings;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->folder = null;
});

afterEach(function (): void {
    if ($this->folder !== null) {
        cacheOff($this->folder);
    }
});

it('answers 404 while ads.txt is empty, then the saved text as plain UTF-8, cached', function (): void {
    $this->folder = cacheOn();

    addrGet('/ads.txt')->assertNotFound();

    app(UpdateAdsTxt::class)->execute(new UpdateAdsTxtInput(
        'google.com, pub-123, DIRECT, f08c47fec0942fa0',
        0,
        (string) $this->admin->id,
    ));

    addrGet('/ads.txt')->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertContent('google.com, pub-123, DIRECT, f08c47fec0942fa0');

    expect(cacheEntry('/ads.txt'))->not->toBeNull();
});

it('refuses an editor saving ads.txt and writes nothing', function (): void {
    expect(fn () => app(UpdateAdsTxt::class)->execute(new UpdateAdsTxtInput(
        'evil.com, pub-9, DIRECT',
        0,
        (string) $this->editor->id,
    )))->toThrow(AuthorizationException::class);

    addrGet('/ads.txt')->assertNotFound();
});

it('shows the ads.txt box on the Site code page', function (): void {
    $this->actingAs($this->admin)->get('/admin/settings/site-code')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/site-code')
            ->where('adsTxt', '')
            ->where('adsTxtMode', 'text')
            ->where('adsTxtRedirect', '')
            ->has('adsTxtVersion'));
});

it('saves and serves ads.txt while the host disallows site code, since it is text, not code', function (): void {
    config(['teme.disallow_site_code' => true]);

    $this->actingAs($this->admin)
        ->put('/admin/settings/site-code/ads-txt', ['ads_txt' => 'example.com, pub-1, DIRECT', 'expected_version' => 0])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    addrGet('/ads.txt')->assertOk()->assertContent('example.com, pub-1, DIRECT');
});

it('saves a redirect mode with an https address on another host and answers /ads.txt with a permanent redirect there', function (): void {
    app(UpdateAdsTxt::class)->execute(new UpdateAdsTxtInput(
        adsTxt: '',
        expectedVersion: 0,
        actorId: (string) $this->admin->id,
        mode: 'redirect',
        redirectUrl: 'https://ads.example/ads.txt',
    ));

    addrGet('/ads.txt')
        ->assertStatus(301)
        ->assertHeader('Location', 'https://ads.example/ads.txt');
});

it('refuses a redirect to an http address and writes nothing', function (): void {
    app(UpdateAdsTxt::class)->execute(new UpdateAdsTxtInput(
        'kept.com, pub-1, DIRECT',
        0,
        (string) $this->admin->id,
    ));

    expect(fn () => app(UpdateAdsTxt::class)->execute(new UpdateAdsTxtInput(
        adsTxt: 'kept.com, pub-1, DIRECT',
        expectedVersion: 1,
        actorId: (string) $this->admin->id,
        mode: 'redirect',
        redirectUrl: 'http://ads.example/ads.txt',
    )))->toThrow(ValidationException::class);

    expect(app(SettingsReader::class)->string(SiteCodeSettings::KEY, SiteCodeSettings::ADS_TXT_MODE))
        ->toBe(SiteCodeSettings::MODE_TEXT);
    addrGet('/ads.txt')->assertOk()->assertContent('kept.com, pub-1, DIRECT');
});

it('refuses a redirect to the site\'s own host and writes nothing', function (): void {
    app(UpdateAdsTxt::class)->execute(new UpdateAdsTxtInput(
        'kept.com, pub-1, DIRECT',
        0,
        (string) $this->admin->id,
    ));

    expect(fn () => app(UpdateAdsTxt::class)->execute(new UpdateAdsTxtInput(
        adsTxt: 'kept.com, pub-1, DIRECT',
        expectedVersion: 1,
        actorId: (string) $this->admin->id,
        mode: 'redirect',
        redirectUrl: 'https://localhost/ads.txt',
    )))->toThrow(ValidationException::class);

    expect(app(SettingsReader::class)->string(SiteCodeSettings::KEY, SiteCodeSettings::ADS_TXT_MODE))
        ->toBe(SiteCodeSettings::MODE_TEXT);
    addrGet('/ads.txt')->assertOk()->assertContent('kept.com, pub-1, DIRECT');
});

it('refuses a redirect that is not an address and writes nothing', function (): void {
    app(UpdateAdsTxt::class)->execute(new UpdateAdsTxtInput(
        'kept.com, pub-1, DIRECT',
        0,
        (string) $this->admin->id,
    ));

    expect(fn () => app(UpdateAdsTxt::class)->execute(new UpdateAdsTxtInput(
        adsTxt: 'kept.com, pub-1, DIRECT',
        expectedVersion: 1,
        actorId: (string) $this->admin->id,
        mode: 'redirect',
        redirectUrl: 'not-an-address',
    )))->toThrow(ValidationException::class);

    expect(app(SettingsReader::class)->string(SiteCodeSettings::KEY, SiteCodeSettings::ADS_TXT_MODE))
        ->toBe(SiteCodeSettings::MODE_TEXT);
});

it('refuses a redirect address longer than 2000 characters and writes nothing', function (): void {
    app(UpdateAdsTxt::class)->execute(new UpdateAdsTxtInput(
        'kept.com, pub-1, DIRECT',
        0,
        (string) $this->admin->id,
    ));

    expect(fn () => app(UpdateAdsTxt::class)->execute(new UpdateAdsTxtInput(
        adsTxt: 'kept.com, pub-1, DIRECT',
        expectedVersion: 1,
        actorId: (string) $this->admin->id,
        mode: 'redirect',
        redirectUrl: 'https://ads.example/'.str_repeat('a', 2000),
    )))->toThrow(ValidationException::class);

    expect(app(SettingsReader::class)->string(SiteCodeSettings::KEY, SiteCodeSettings::ADS_TXT_MODE))
        ->toBe(SiteCodeSettings::MODE_TEXT);
});

it('refuses an editor saving a redirect and writes nothing', function (): void {
    expect(fn () => app(UpdateAdsTxt::class)->execute(new UpdateAdsTxtInput(
        adsTxt: '',
        expectedVersion: 0,
        actorId: (string) $this->editor->id,
        mode: 'redirect',
        redirectUrl: 'https://ads.example/ads.txt',
    )))->toThrow(AuthorizationException::class);

    addrGet('/ads.txt')->assertNotFound();
    expect(app(SettingsReader::class)->string(SiteCodeSettings::KEY, SiteCodeSettings::ADS_TXT_MODE))
        ->toBe(SiteCodeSettings::MODE_TEXT);
});

it('in text mode answers /ads.txt as plain UTF-8 after a redirect was saved before', function (): void {
    app(UpdateAdsTxt::class)->execute(new UpdateAdsTxtInput(
        adsTxt: '',
        expectedVersion: 0,
        actorId: (string) $this->admin->id,
        mode: 'redirect',
        redirectUrl: 'https://ads.example/ads.txt',
    ));

    app(UpdateAdsTxt::class)->execute(new UpdateAdsTxtInput(
        adsTxt: 'google.com, pub-123, DIRECT, f08c47fec0942fa0',
        expectedVersion: 1,
        actorId: (string) $this->admin->id,
        mode: 'text',
        redirectUrl: 'https://ads.example/ads.txt',
    ));

    addrGet('/ads.txt')->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertContent('google.com, pub-123, DIRECT, f08c47fec0942fa0');
});
