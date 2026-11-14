<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Contents\Queries\ContentList;
use App\Domain\Contents\Queries\ContentListRequest;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create(['name' => 'Ada'])->assignRole('author');
    $this->other = User::factory()->create(['name' => 'Bea'])->assignRole('author');
});

function listsPage(array $arguments): array
{
    return app(ContentList::class)->page(new ContentListRequest(...array_merge(['type' => 'post'], $arguments)));
}

it('pages 50 at a time by number, with the total where it is known', function (): void {
    foreach (range(1, 55) as $n) {
        $this->travel(1)->minutes();
        lifecycleDraft($this->author, "Post {$n}");
    }

    $first = listsPage([]);

    expect($first['rows'])->toHaveCount(50)
        ->and($first['rows'][0]['title'])->toBe('Post 55')
        ->and($first['paging'])->toMatchArray(['number' => 1, 'last' => 2, 'more' => false, 'from' => 1, 'to' => 50, 'total' => 55]);

    $second = listsPage(['page' => 2]);

    expect($second['rows'])->toHaveCount(5)
        ->and($second['rows'][0]['title'])->toBe('Post 5')
        ->and($second['paging'])->toMatchArray(['number' => 2, 'last' => 2, 'from' => 51, 'to' => 55, 'total' => 55]);

    $filtered = listsPage(['search' => 'Post']);

    expect($filtered['paging'])->toMatchArray(['number' => 1, 'last' => 2, 'total' => 55]);
});

it('lists the first page for a before cursor it cannot decode, not the oldest rows (a hand-edited link)', function (): void {
    foreach (range(1, 55) as $n) {
        $this->travel(1)->minutes();
        lifecycleDraft($this->author, "Post {$n}");
    }

    $first = listsPage([]);
    $edited = listsPage(['before' => 'not-a-cursor']);

    expect(array_column($edited['rows'], 'title'))->toBe(array_column($first['rows'], 'title'))
        ->and($edited['newer'])->toBeNull()
        ->and($edited['older'])->toBe($first['older']);
});

it('makes the same number of queries on every page', function (): void {
    foreach (range(1, 60) as $n) {
        $this->travel(1)->minutes();
        lifecycleDraft($this->author, "Post {$n}");
    }

    $count = function (array $arguments): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        listsPage($arguments);

        return count(DB::getQueryLog());
    };

    $firstQueries = $count([]);
    $older = listsPage([])['older'];

    expect($count(['after' => $older]))->toBe($firstQueries);
});

it('shows every tab with exact counts, even at zero, Mine beside All', function (): void {
    $published = lifecycleDraft($this->author, 'Out');
    listsPublish($published, $this->author);
    lifecycleDraft($this->author, 'Draft');
    lifecycleDraft($this->other, 'Theirs');

    $tabs = collect(listsPage(['viewerId' => $this->author->id])['tabs'])->pluck('count', 'key')->all();

    expect($tabs)->toBe(['all' => 3, 'mine' => 2, 'published' => 1, 'private' => 0, 'drafts' => 2, 'review' => 0, 'scheduled' => 0, 'trash' => 0]);
});

it('filters by tab, author, category with its children, tag and title', function (): void {
    $world = taxonomyTerm('category', 'World');
    $africa = taxonomyTerm('category', 'Africa', $world->id);
    $tag = taxonomyTerm('tag', 'Elections');

    app(CreateDraft::class)->execute(new CreateDraftInput('post', 'Lagos votes', ['type' => 'doc', 'content' => []], [], (string) $this->author->id, [$africa->id, $tag->id]));
    lifecycleDraft($this->other, 'Weather today');

    expect(array_column(listsPage(['terms' => ['category' => $world->id]])['rows'], 'title'))->toBe(['Lagos votes'])
        ->and(array_column(listsPage(['terms' => ['tag' => $tag->id]])['rows'], 'title'))->toBe(['Lagos votes'])
        ->and(array_column(listsPage(['authorId' => $this->other->id])['rows'], 'title'))->toBe(['Weather today'])
        ->and(array_column(listsPage(['search' => 'agos'])['rows'], 'title'))->toBe(['Lagos votes'])
        ->and(listsPage(['search' => 'agos'])['filtered'])->toBeTrue()
        ->and(listsPage([])['rows'][1]['terms']['category'])->toBe([['id' => $africa->id, 'name' => 'Africa']]);
});

it('lists trashed items only in the trash tab, newest trash first', function (): void {
    $first = lifecycleDraft($this->author, 'First');
    $second = lifecycleDraft($this->author, 'Second');
    lifecycleDraft($this->author, 'Live');

    foreach ([$first, $second] as $content) {
        $this->travel(1)->minutes();
        app(TrashContent::class)->execute(new ContentActionInput($content->id, (string) $this->author->id));
    }

    expect(array_column(listsPage(['tab' => 'trash'])['rows'], 'title'))->toBe(['Second', 'First'])
        ->and(array_column(listsPage([])['rows'], 'title'))->toBe(['Live']);
});

it('sorts by title and by published date', function (): void {
    foreach (['Banana', 'apple', 'Cherry'] as $title) {
        $this->travel(1)->minutes();
        listsPublish(lifecycleDraft($this->author, $title), $this->author);
    }

    expect(array_column(listsPage(['sort' => 'title', 'direction' => 'asc'])['rows'], 'title'))->toBe(['apple', 'Banana', 'Cherry'])
        ->and(array_column(listsPage(['tab' => 'published', 'sort' => 'published'])['rows'], 'title'))->toBe(['Cherry', 'apple', 'Banana']);
});

it('walks the list index when a term filter matches densely, with the same rows', function (): void {
    $news = taxonomyTerm('category', 'News');

    foreach (range(1, 6) as $n) {
        $this->travel(1)->minutes();
        app(CreateDraft::class)->execute(new CreateDraftInput('post', "News {$n}", ['type' => 'doc', 'content' => []], [], (string) $this->author->id, [$news->id]));
    }
    lifecycleDraft($this->author, 'Unfiled');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $rows = array_column(listsPage(['terms' => ['category' => $news->id]])['rows'], 'title');
    $log = implode("\n", array_column(DB::getQueryLog(), 'query'));

    expect($rows)->toBe(['News 6', 'News 5', 'News 4', 'News 3', 'News 2', 'News 1'])
        ->and($log)->toContain('enable_sort');
});

it('keeps the planner\'s own plan when a term filter matches sparsely', function (): void {
    $rare = taxonomyTerm('tag', 'Rare');

    foreach (range(1, 300) as $n) {
        lifecycleDraft($this->author, "Other {$n}");
    }

    app(CreateDraft::class)->execute(new CreateDraftInput('post', 'Tagged', ['type' => 'doc', 'content' => []], [], (string) $this->author->id, [$rare->id]));

    DB::flushQueryLog();
    DB::enableQueryLog();
    $rows = array_column(listsPage(['terms' => ['tag' => $rare->id]])['rows'], 'title');
    $log = implode("\n", array_column(DB::getQueryLog(), 'query'));

    expect($rows)->toBe(['Tagged'])
        ->and($log)->not->toContain('enable_sort');
});

it('searches titles by default, and bodies when asked', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour news');
    docSave($content, $this->author, docWith(docText('The lifeboat crew marked fifty years of service.')));
    listsPublish($content->refresh(), $this->author);

    $this->actingAs($this->author)
        ->get('/admin/posts?search=lifeboat')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 0));

    $this->actingAs($this->author)
        ->get('/admin/posts?search=lifeboat&in=body')
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.title', 'Harbour news')
            ->where('filters.in', 'body'));
});

it('asks the same number of queries whether or not the page holds a pending review', function (): void {
    foreach (range(1, 3) as $n) {
        lifecycleDraft($this->author, "Post {$n}");
    }

    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        listsPage(['viewerId' => $this->author->id]);

        return count(DB::getQueryLog());
    };

    $count(); // warms the permission cache
    $without = $count();

    $contributor = User::factory()->create()->assignRole('contributor');
    schedSubmit(lifecycleDraft($contributor, 'For review'), $contributor);

    expect($count())->toBe($without);
});

it('says who may decide on a pending review exactly as the policy does', function (string $role): void {
    $contributor = User::factory()->create()->assignRole('contributor');
    $content = lifecycleDraft($contributor, 'For review');
    schedSubmit($content, $contributor);
    $viewer = User::factory()->create()->assignRole($role);

    $row = collect(listsPage(['viewerId' => $viewer->id])['rows'])->firstWhere('id', $content->id);

    expect($row['review']['canDecide'])->toBe($viewer->can('publish', $content->refresh()));
})->with(['editor', 'author', 'contributor']);

it('returns as many rows as the person\'s per-page preference asks', function (): void {
    foreach (range(1, 30) as $n) {
        lifecycleDraft($this->author, "Item {$n}");
    }

    $page = listsPage(['perPage' => 25]);

    expect($page['rows'])->toHaveCount(25)
        ->and($page['older'])->not->toBeNull();
});

it('lists every item with a pending review under review=pending, a live article with a waiting update included', function (): void {
    $contributor = User::factory()->create()->assignRole('contributor');
    $editor = User::factory()->create()->assignRole('editor');
    $new = lifecycleDraft($contributor, 'Never published');
    schedSubmit($new, $contributor);
    $live = lifecycleDraft($contributor, 'Live with an update');
    listsPublish($live, $editor);
    docSave($live->refresh(), $contributor, docWith(docText('A correction.')));
    schedSubmit($live, $contributor);
    lifecycleDraft($contributor, 'Not in review');

    $ids = fn (array $arguments): array => collect(listsPage($arguments)['rows'])->pluck('id')->sort()->values()->all();

    expect($ids(['review' => 'pending']))->toBe(collect([$new->id, $live->id])->sort()->values()->all())
        // The In review tab counts statuses, so it leaves the live one out.
        ->and($ids(['tab' => 'review']))->toBe([$new->id]);
});

it('lists only the viewer\'s own submissions under review=submitted, and ignores an unknown value', function (): void {
    $mine = User::factory()->create()->assignRole('contributor');
    $theirs = User::factory()->create()->assignRole('contributor');
    $own = lifecycleDraft($mine, 'Mine');
    schedSubmit($own, $mine);
    schedSubmit(lifecycleDraft($theirs, 'Theirs'), $theirs);

    expect(collect(listsPage(['review' => 'submitted', 'viewerId' => $mine->id])['rows'])->pluck('id')->all())->toBe([$own->id])
        ->and(listsPage(['review' => 'everything'])['rows'])->toHaveCount(2)
        ->and(listsPage(['review' => 'everything'])['filtered'])->toBeFalse();
});

it('passes the review filter from the address to the list', function (): void {
    $contributor = User::factory()->create()->assignRole('contributor');
    $editor = User::factory()->create()->assignRole('editor');
    $pending = lifecycleDraft($contributor, 'Waiting');
    schedSubmit($pending, $contributor);
    lifecycleDraft($contributor, 'Not waiting');

    $this->actingAs($editor)->get('/admin/posts?review=pending')
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.id', $pending->id)
            ->where('filters.review', 'pending'));
});
