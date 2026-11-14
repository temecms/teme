<?php

declare(strict_types=1);

use App\Domain\Cache\PageCache;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\UnpublishContent;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create(['name' => 'Ada Obi'])->assignRole('editor');
    $this->news = typesTerm($this->editor, 'category', 'News');
    $this->folder = cacheOn();
});

afterEach(fn () => cacheOff($this->folder));

it('sets aside the lists a new post joins, and removes the "not found" page at its address (docs/studies/page-cache.md §3.9)', function (): void {
    $draft = typesDraft($this->editor, 'post', 'Harbour news', [$this->news->id]);
    cachePage('/harbour-news/');
    cacheShown([
        '/lists-posts/' => ['list:post'],
        '/lists-news/' => ['term:category:'.$this->news->id],
        '/lists-ada/' => ['author:'.$this->editor->id],
    ]);

    listsPublish($draft, $this->editor);

    expect(cacheKept('/harbour-news/', '/lists-posts/', '/lists-news/', '/lists-ada/'))->toBe([])
        ->and(cacheSetAside('/harbour-news/'))->toBeNull()
        ->and(cacheSetAside('/lists-posts/'))->not->toBeNull()
        ->and(cacheSetAside('/lists-news/'))->not->toBeNull()
        ->and(cacheSetAside('/lists-ada/'))->not->toBeNull();
});

it('removes every page that shows a post it corrects, a list among them, and sets aside only the lists that don\'t show it', function (): void {
    $story = pageArticle($this->editor, 'Harbour news', categories: [$this->news]);
    cacheShown([
        '/shows-it/' => ['content:'.$story->id],
        '/lists-it/' => ['list:post', 'content:'.$story->id],
        '/lists-others/' => ['list:post'],
    ]);

    pageSaveDraft($story, 'Harbour news, corrected');
    listsPublish($story, $this->editor);

    expect(cacheKept('/shows-it/', '/lists-it/', '/lists-others/'))->toBe([])
        ->and(cacheSetAside('/shows-it/'))->toBeNull()
        ->and(cacheSetAside('/lists-it/'))->toBeNull()
        ->and(cacheSetAside('/lists-others/'))->not->toBeNull();
});

it('never sets aside a page that shows a post taken down: unpublishing removes each one', function (): void {
    $story = pageArticle($this->editor, 'Harbour news', categories: [$this->news]);
    cacheShown([
        '/shows-it/' => ['content:'.$story->id],
        '/lists-it/' => ['term:category:'.$this->news->id, 'content:'.$story->id],
        '/lists-its-category/' => ['term:category:'.$this->news->id],
    ]);

    app(UnpublishContent::class)->execute(new ContentActionInput($story->id, (string) $this->editor->id));

    expect(cacheSetAside('/shows-it/'))->toBeNull()
        ->and(cacheSetAside('/lists-it/'))->toBeNull()
        ->and(cacheSetAside('/lists-its-category/'))->not->toBeNull();
});

it('notes the lists it sets aside for the race guard, as it notes what it removes (I-264)', function (): void {
    app(PageCache::class)->invalidate(['list:post', 'content:ferry']);

    expect(DB::table('page_cache_purges')->pluck('tag')->all())->toEqualCanonicalizing(['list:post', 'content:ferry']);
});
