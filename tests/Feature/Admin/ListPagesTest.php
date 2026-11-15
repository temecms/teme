<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Queries\ContentList;
use App\Domain\Contents\Queries\ContentListRequest;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

// Numbered pages with a capped count (I-3421), on the content, comment and term lists.

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create(['name' => 'Eve'])->assignRole('editor');
    lscrPrefer($this->editor, ['lists.per_page' => 25]);

    // The list screen's props, as the browser receives them.
    $this->props = fn (string $url): array => $this->actingAs($this->editor)->get($url)->assertOk()->viewData('page')['props'];
});

/**
 * Posts by this author written straight to the table, titled with a prefix
 * and a number, the higher numbers changed later.
 */
function clPosts(User $author, int $count, string $prefix = 'Post'): void
{
    Content::factory()->count($count)
        ->sequence(fn ($sequence): array => [
            'title' => sprintf('%s %04d', $prefix, $sequence->index + 1),
            'slug' => sprintf('%s-%04d', mb_strtolower($prefix), $sequence->index + 1),
            'updated_at' => now()->subMinutes(10000 - $sequence->index),
        ])
        ->create(['author_id' => $author->id, 'author_sort' => mb_strtolower((string) $author->name)]);
}

it('numbers the pages of an unfiltered list and gives its exact total from the view count', function (): void {
    foreach (range(1, 60) as $n) {
        $this->travel(1)->minutes();
        lifecycleDraft($this->editor, sprintf('Post %02d', $n));
    }

    $first = ($this->props)('/admin/posts');

    expect($first['paging'])->toMatchArray(['number' => 1, 'last' => 3, 'more' => false, 'from' => 1, 'to' => 25, 'total' => 60])
        ->and($first)->not->toHaveKey('newer')
        ->and($first)->not->toHaveKey('older');

    $third = ($this->props)('/admin/posts?page=3');

    expect(array_column($third['rows'], 'title'))->toBe(array_map(fn (int $n): string => sprintf('Post %02d', $n), range(10, 1)))
        ->and($third['paging'])->toMatchArray(['number' => 3, 'from' => 51, 'to' => 60, 'total' => 60]);
});

it('caps the count of a filtered list at fifty pages', function (): void {
    clPosts($this->editor, 1300, 'Match');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $props = ($this->props)('/admin/posts?search=match');
    $counts = array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $sql): bool => str_contains($sql, 'as "capped"'));

    expect($props['paging'])->toMatchArray(['number' => 1, 'last' => 50, 'more' => true, 'total' => null])
        ->and($counts)->toHaveCount(1)
        ->and(array_values($counts)[0])->toContain('limit 1251');
});

it('gives a filtered list under the cap its exact total', function (): void {
    clPosts($this->editor, 40, 'Match');
    clPosts($this->editor, 5, 'Other');

    expect(($this->props)('/admin/posts?search=match')['paging'])->toMatchArray(['total' => 40, 'last' => 2, 'more' => false]);
});

it('goes past the cap by keyset, with the same number of queries on every page', function (): void {
    clPosts($this->editor, 1300, 'Match');

    $page = fn (array $arguments): array => app(ContentList::class)->page(new ContentListRequest(...array_merge(
        ['type' => 'post', 'search' => 'match', 'perPage' => 25, 'viewerId' => $this->editor->id],
        $arguments,
    )));
    $queries = function (array $arguments) use ($page): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $page($arguments);

        return count(DB::getQueryLog());
    };

    $fiftieth = $page(['page' => 50]);

    expect($fiftieth['paging']['number'])->toBe(50)
        ->and($fiftieth['paging']['more'])->toBeTrue()
        ->and($fiftieth['paging']['older'])->not->toBeNull();

    $past = $page(['after' => $fiftieth['paging']['older']]);

    expect($past['rows'])->toHaveCount(25)
        ->and($past['rows'][0]['title'])->toBe('Match 0050')
        ->and($past['paging']['number'])->toBeNull()
        ->and($past['paging']['from'])->toBeNull();

    expect($queries(['page' => 50]))->toBe($queries([]))
        ->and($queries(['after' => $fiftieth['paging']['older']]))->toBe($queries([]));
});

it('reads anything but 1 to 50 as page 1', function (string $page): void {
    clPosts($this->editor, 30);

    $props = ($this->props)("/admin/posts?page={$page}");

    expect($props['paging']['number'])->toBe(1)
        ->and($props['rows'][0]['title'])->toBe('Post 0030');
})->with(['0', '51', 'x']);

it('numbers comments the same way', function (): void {
    $item = lifecycleDraft($this->editor, 'Commented');

    foreach (range(1, 30) as $n) {
        cmComment($item, 'pending');
    }

    expect(($this->props)('/admin/comments')['paging'])->toMatchArray(['number' => 1, 'last' => 2, 'total' => 30, 'more' => false]);
});

it('numbers nesting terms by their branches, exactly', function (): void {
    foreach (range(1, 60) as $n) {
        taxonomyTerm('category', sprintf('Branch %02d', $n));
    }

    $props = ($this->props)('/admin/categories');

    expect($props['paging'])->toMatchArray(['number' => 1, 'last' => 2, 'more' => false, 'total' => 60])
        ->and(($this->props)('/admin/categories?page=2')['rows'][0]['name'])->toBe('Branch 51');
});

it('numbers flat terms with a capped count', function (): void {
    foreach (range(1, 120) as $n) {
        taxonomyTerm('tag', sprintf('Tag %03d', $n));
    }

    expect(($this->props)('/admin/tags')['paging'])->toMatchArray(['number' => 1, 'last' => 3, 'total' => 120, 'more' => false])
        ->and(($this->props)('/admin/tags?page=3')['rows'][0]['name'])->toBe('Tag 101');
});

it('lists nothing it should not on a deep page', function (): void {
    $author = User::factory()->create(['name' => 'Ada'])->assignRole('author');
    lscrPrefer($author, ['lists.per_page' => 25]);
    clPosts($author, 30, 'Mine');
    clPosts($this->editor, 30, 'Theirs');

    $this->actingAs($author)->get('/admin/posts?tab=mine&page=2')
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 5)
            ->where('rows', fn ($rows): bool => collect($rows)->every(fn (array $row): bool => $row['authorId'] === $author->id))
            ->where('paging.number', 2));
});
