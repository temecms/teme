<?php

declare(strict_types=1);

use App\Domain\Addresses\AddressSettings;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->admin = User::factory()->create()->assignRole('administrator');
});

it('serves the latest posts at the site\'s address, and marks only it and its numbered pages as the front', function (): void {
    // Twelve make a page 2: page 1 shows the lead and a feed of ten (1.6c).
    foreach (range(1, 12) as $i) {
        addrPublished($this->admin, "Story {$i}");
    }

    $this->artisan('teme:theme:resolve', ['url' => '/', '--json' => true])
        ->expectsOutputToContain('"kind":"latest"')
        ->expectsOutputToContain('"isFront":true')
        ->assertSuccessful();
    $this->artisan('teme:theme:resolve', ['url' => '/page/2/', '--json' => true])
        ->expectsOutputToContain('"kind":"latest"')
        ->expectsOutputToContain('"isFront":true')
        ->assertSuccessful();
    $this->artisan('teme:theme:resolve', ['url' => '/story-1/', '--json' => true])
        ->expectsOutputToContain('"kind":"single"')
        ->expectsOutputToContain('"isFront":false')
        ->assertSuccessful();
});

it('serves a home page that is a page as that page, and the latest posts at the posts page', function (): void {
    $welcome = addrPublished($this->admin, 'Welcome', 'page');
    $news = addrPublished($this->admin, 'News', 'page');
    addrChange($this->admin, ['frontShows' => AddressSettings::FRONT_PAGE, 'frontPageId' => $welcome->id, 'postsPageId' => $news->id]);

    $this->artisan('teme:theme:resolve', ['url' => '/', '--json' => true])
        ->expectsOutputToContain('"kind":"single"')
        ->expectsOutputToContain('"isFront":true')
        ->expectsOutputToContain('templates.single-page')
        ->assertSuccessful();
    $this->artisan('teme:theme:resolve', ['url' => '/news/', '--json' => true])
        ->expectsOutputToContain('"kind":"latest"')
        ->expectsOutputToContain('"isFront":false')
        ->assertSuccessful();
});
