<?php

declare(strict_types=1);

use App\Domain\SiteCode\Operations\Input\UpdateAdsTxtInput;
use App\Domain\SiteCode\Operations\UpdateAdsTxt;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

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
            ->has('adsTxtVersion'));
});
