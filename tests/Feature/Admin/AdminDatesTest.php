<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('writes a day and a moment on the site\'s clock in the admin\'s language, whatever the browser\'s (#530)', function (): void {
    $dates = guardNode('dates.mjs', ['zone' => 'Africa/Lagos', 'locale' => 'en', 'at' => '2026-11-13T10:20:00Z']);

    expect($dates['before'])->toBe(['date' => '13 Nov 2026', 'dateTime' => '13 Nov 2026, 10:20'])
        ->and($dates['date'])->toBe('13 Nov 2026')
        ->and($dates['dateTime'])->toBe('13 Nov 2026, 11:20')
        ->and($dates['elsewhere'])->toBe('13 Nov 2026, 05:20');
});

it('moves the day with the site\'s zone, not the moment\'s (#530)', function (): void {
    $dates = guardNode('dates.mjs', ['zone' => 'Pacific/Auckland', 'locale' => 'en', 'at' => '2026-11-13T20:00:00Z']);

    expect($dates['date'])->toBe('14 Nov 2026')
        ->and($dates['dateTime'])->toBe('14 Nov 2026, 09:00');
});

it('gives every admin page the site\'s zone, for the dates it writes (#530)', function (): void {
    $this->seed(RoleSeeder::class);
    cmSettings(['timezone' => 'Africa/Lagos'], 'general');

    $this->actingAs(User::factory()->create()->assignRole('administrator'))
        ->get('/admin/users')
        ->assertInertia(fn (Assert $page) => $page->where('siteTimezone', 'Africa/Lagos'));
});

it('leaves no date in the admin to the browser\'s locale (#530)', function (): void {
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS)) as $file) {
        $source = (string) file_get_contents($file->getPathname());

        if (preg_match('/toLocaleDateString\(|toLocaleTimeString\(|\bDate\([^;]*\)\.toLocaleString\(|dateStyle:/', $source) === 1) {
            $offenders[] = str_replace(resource_path('js').DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }

    expect($offenders)->toBe([]);
});
