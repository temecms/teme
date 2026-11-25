<?php

declare(strict_types=1);

use App\Domain\Comments\Queries\DiscussionScreen;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->props = fn (string $path): array => $this->actingAs($this->admin)->get($path)->assertOk()->inertiaProps();
});

it('splits General, Writing, Reading, Media and People into the approved design\'s sections, every setting in exactly one', function (string $page, array $titles): void {
    $props = ($this->props)("/admin/settings/{$page}");

    expect(array_column($props['panels'], 'title'))->toBe($titles)
        ->and(array_merge(...array_column($props['panels'], 'keys')))->toEqualCanonicalizing(array_column($props['fields'], 'key'));
})->with([
    'general' => ['general', ['Site', 'Contact', 'Time and language']],
    'writing' => ['writing', ['Trash']],
    'reading' => ['reading', ['Listings']],
    'media' => ['media', ['Uploads', 'Imports']],
    'people' => ['people', ['Passwords', 'Sign-in limits', 'Sessions', 'Two-factor authentication']],
]);

it('names Discussion\'s five sections as the approved design does, Moderation with its line', function (): void {
    $fieldsets = ($this->props)(route('settings.discussion', [], false))['fieldsets'];

    expect(array_column($fieldsets, 'legend'))->toBe(['Where comments are open', 'Threads and pages', 'Moderation', 'Emails', 'Privacy'])
        ->and(array_column($fieldsets, 'description'))->toBe(['', '', 'Which comments wait for someone to approve them.', '', ''])
        ->and(array_keys(DiscussionScreen::DESCRIPTIONS))->toBe(array_keys(DiscussionScreen::FIELDSETS));
});
