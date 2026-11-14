<?php

declare(strict_types=1);

use App\Domain\Navigation\Queries\AdminNavigation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');
});

it('hides an entry for that person only, and it stays in the palette', function (): void {
    $other = User::factory()->create()->assignRole('editor');
    lscrPrefer($this->editor, ['nav.hidden' => ['media']]);

    $mine = collect(app(AdminNavigation::class)->forUser($this->editor->id))->keyBy('key');
    $theirs = collect(app(AdminNavigation::class)->forUser($other->id))->keyBy('key');

    // Still returned, so the palette can offer it; the sidebar leaves it out.
    expect($mine)->toHaveKey('media')
        ->and($mine['media']['hidden'])->toBeTrue()
        ->and($mine['posts']['hidden'])->toBeFalse()
        ->and($theirs['media']['hidden'])->toBeFalse();
});

it('sends the pinned keys as their own prop, never as a section', function (): void {
    lscrPrefer($this->admin, ['nav.pinned' => ['media', 'posts']]);

    $props = $this->actingAs($this->admin)->get(route('dashboard'))->viewData('page')['props'];

    expect($props['navPinned'])->toBe(['media', 'posts'])
        ->and(array_column($props['nav'], 'section'))->each->toBeIn(['home', 'content', 'site'])
        ->and(array_column($props['nav'], 'key'))->toContain('media', 'posts');
});

it('reorders entries within their own section and never moves one to another', function (): void {
    $default = collect(app(AdminNavigation::class)->forUser($this->admin->id));
    lscrPrefer($this->admin, ['nav.order' => ['regions', 'settings', 'media']]);

    $nav = collect(app(AdminNavigation::class)->forUser($this->admin->id));
    $content = $nav->where('section', 'content')->pluck('key')->values();

    expect($content->take(2)->all())->toBe(['regions', 'media'])
        ->and($content->slice(2)->values()->all())
        ->toBe($default->where('section', 'content')->pluck('key')->reject(fn (string $key): bool => in_array($key, ['regions', 'media'], true))->values()->all())
        ->and($nav->where('section', 'site')->pluck('key')->first())->toBe('settings')
        ->and($nav->pluck('section', 'key')->sortKeys()->all())->toBe($default->pluck('section', 'key')->sortKeys()->all());
});

it('pins at most five entries to the top, and never an entry the person may not open', function (): void {
    expect(fn () => lscrPrefer($this->admin, ['nav.pinned' => ['dashboard', 'posts', 'pages', 'media', 'categories', 'tags']]))
        ->toThrow(ValidationException::class);

    lscrPrefer($this->admin, ['nav.pinned' => ['users', 'media']]);
    $this->admin->syncRoles(['editor']);

    // Their role changed: Users is no longer theirs to open, so its pin drops silently.
    $props = $this->actingAs($this->admin->refresh())->get(route('dashboard'))->assertOk()->viewData('page')['props'];

    expect($props['navPinned'])->toBe(['media'])
        ->and(array_column($props['nav'], 'key'))->not->toContain('users');
});

it('ignores keys nothing registers, so a removed entry leaves no hole', function (): void {
    expect(fn () => lscrPrefer($this->admin, ['nav.hidden' => ['extensions']]))
        ->toThrow(ValidationException::class);

    // Saved while the entry existed; the plugin that registered it is gone since.
    DB::table('users')->where('id', $this->admin->id)->update(['preferences' => json_encode([
        'nav.hidden' => ['gone'],
        'nav.order' => ['gone', 'media'],
        'nav.pinned' => ['gone', 'posts'],
    ])]);

    $props = $this->actingAs($this->admin->refresh())->get(route('dashboard'))->assertOk()->viewData('page')['props'];

    expect($props['navPinned'])->toBe(['posts'])
        ->and(collect($props['nav'])->where('section', 'content')->pluck('key')->first())->toBe('media')
        ->and(collect($props['nav'])->pluck('hidden')->unique()->values()->all())->toBe([false]);
});
