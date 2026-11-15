<?php

declare(strict_types=1);

use App\Domain\Preferences\PreferenceRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
    $this->editor = User::factory()->create()->assignRole('editor');
});

it('declares one columns preference per type, offering each of its taxonomies', function (): void {
    $registry = app(PreferenceRegistry::class);

    // toContain, not toBe: 1.8 adds comments, and phase 5 plugin columns.
    expect($registry->get('lists.post.columns')->default)->toContain('author', 'taxonomy-category', 'taxonomy-tag', 'date')
        ->and($registry->get('lists.post.columns')->default)->not->toContain('status')
        ->and($registry->get('lists.page.columns')->default)->toContain('author', 'date')
        ->and($registry->get('lists.page.columns')->default)->not->toContain('status')
        ->and($registry->get('lists.page.columns')->default)->not->toContain('taxonomy-category')
        ->and($registry->get('lists.post.columns')->default)->not->toContain('title');
});

it('shows only the columns a person chose, and keeps Title always', function (): void {
    lscrPrefer($this->author, ['lists.post.columns' => ['author', 'date']]);

    $this->actingAs($this->author)->get(route('content.index', ['type' => 'posts']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('preferences', fn ($preferences): bool => $preferences['lists.post.columns'] === ['author', 'date']));

    $this->actingAs($this->editor)->get(route('content.index', ['type' => 'posts']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('preferences', fn ($preferences): bool => in_array('taxonomy-tag', $preferences['lists.post.columns'], true)));

    expect(fn () => lscrPrefer($this->author, ['lists.post.columns' => ['title', 'author']]))
        ->toThrow(ValidationException::class)
        ->and(fn () => lscrPrefer($this->author, ['lists.page.columns' => ['taxonomy-tag']]))
        ->toThrow(ValidationException::class);
});

it('remembers rows per page for that person and nobody else', function (): void {
    foreach (range(1, 30) as $n) {
        lifecycleDraft($this->author, "Post {$n}");
    }

    lscrPrefer($this->author, ['lists.per_page' => 25]);

    $this->actingAs($this->author)->get(route('content.index', ['type' => 'posts']))
        ->assertInertia(fn (Assert $page) => $page->has('rows', 25)->where('paging.last', 2));

    $this->actingAs($this->editor)->get(route('content.index', ['type' => 'posts']))
        ->assertInertia(fn (Assert $page) => $page->has('rows', 30)->where('paging.last', 1));
});

it('filters by month in the site\'s time zone, on the date the list shows', function (): void {
    lscrTimezone('Africa/Lagos'); // UTC+1 all year

    $april = lifecycleDraft($this->editor, 'April in Lagos');
    $march = lifecycleDraft($this->editor, 'March in Lagos');
    $may = lifecycleDraft($this->editor, 'May in Lagos');
    $edited = lifecycleDraft($this->editor, 'Edited in April');

    foreach ([$april, $march, $may] as $content) {
        listsPublish($content, $this->editor);
    }

    // 23:30 UTC on 31 March is 00:30 on 1 April in Lagos, and so on.
    DB::table('contents')->where('id', $april->id)->update(['published_at' => '2026-03-31 23:30:00+00', 'updated_at' => '2026-01-10 12:00:00+00']);
    DB::table('contents')->where('id', $march->id)->update(['published_at' => '2026-03-31 22:30:00+00', 'updated_at' => '2026-01-10 12:00:00+00']);
    DB::table('contents')->where('id', $may->id)->update(['published_at' => '2026-04-30 23:30:00+00', 'updated_at' => '2026-01-10 12:00:00+00']);
    DB::table('contents')->where('id', $edited->id)->update(['updated_at' => '2026-04-15 12:00:00+00']);

    $this->actingAs($this->editor)->get(route('content.index', ['type' => 'posts', 'tab' => 'published', 'month' => '2026-04']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows', fn ($rows): bool => $rows->pluck('title')->all() === ['April in Lagos'])
            ->where('filtered', true)
            ->where('filters.month', '2026-04'));

    $this->actingAs($this->editor)->get(route('content.index', ['type' => 'posts', 'month' => '2026-04']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows', fn ($rows): bool => $rows->pluck('title')->all() === ['Edited in April']));
});

it('ignores a month that is not YYYY-MM, as the media filter does', function (string $month): void {
    lifecycleDraft($this->editor, 'One');
    lifecycleDraft($this->editor, 'Two');

    $this->actingAs($this->editor)->get(route('content.index', ['type' => 'posts', 'month' => $month]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 2)
            ->where('filtered', false)
            ->missing('filters.month'));
})->with(['2026-13', '2026-00', '2026-4', 'April', '2026-04-01']);
