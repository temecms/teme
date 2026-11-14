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

it('splits General into The site and Time and language, every General setting in exactly one (I-3128)', function (): void {
    $props = ($this->props)('/admin/settings/general');

    expect(array_column($props['panels'], 'title'))->toBe(['The site', 'Time and language'])
        ->and(array_column($props['panels'], 'description'))->toBe([
            'Its name and tagline, and where Teme sends notices.',
            'How dates are shown and scheduled, and the language the site is written in.',
        ])
        ->and(array_column($props['panels'], 'keys'))->toBe([['site_title', 'tagline', 'admin_email'], ['timezone', 'language']])
        ->and(array_merge(...array_column($props['panels'], 'keys')))->toEqualCanonicalizing(array_column($props['fields'], 'key'));
});

it('draws every other group page as one panel', function (string $page): void {
    expect(($this->props)("/admin/settings/{$page}")['panels'])->toBeNull();
})->with(['writing', 'reading', 'media', 'people']);

it('gives each of Discussion\'s five sections its line (I-3128)', function (): void {
    $fieldsets = ($this->props)(route('settings.discussion', [], false))['fieldsets'];

    expect(array_column($fieldsets, 'description'))->toBe([
        'Which new items take comments, and who may write them.',
        'How deep replies go, and how many threads each page shows.',
        'Which comments wait for approval, and which go straight to spam.',
        'Who is told about new comments.',
        'What Teme keeps about the people who comment, and for how long.',
    ])
        ->and(array_keys(DiscussionScreen::DESCRIPTIONS))->toBe(array_keys(DiscussionScreen::FIELDSETS));
});
