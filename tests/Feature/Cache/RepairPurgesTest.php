<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\Input\ReindexSearchInput;
use App\Domain\Contents\Operations\ReindexSearch;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->folder = cacheOn();
});

afterEach(fn () => cacheOff($this->folder));

it('purges the search lists of each type whose items it rebuilds', function (): void {
    $admin = (string) User::factory()->create()->assignRole('administrator')->id;
    pageArticle($this->editor, 'Harbour news');
    addrPublished($this->editor, 'About', 'page');
    DB::table('contents')->update(['search_config' => 'simple']);
    cacheShown(['/searches-posts/' => ['list:post'], '/searches-pages/' => ['list:page'], '/shows-an-article/' => ['content:unrelated']]);
    $pages = ['/searches-posts/', '/searches-pages/', '/shows-an-article/'];

    cacheRolledBack(fn () => app(ReindexSearch::class)->execute(new ReindexSearchInput($admin)));

    expect(cacheKept(...$pages))->toHaveCount(3);

    app(ReindexSearch::class)->execute(new ReindexSearchInput($admin));

    expect(cacheKept(...$pages))->toBe(['/shows-an-article/']);
});

it('purges an item\'s pages when it fills in its reading time and search text', function (): void {
    $story = pageArticle($this->editor, 'Harbour news');
    DB::table('content_revisions')->where('content_id', $story->id)->update(['plain_text' => null, 'word_count' => 0]);
    DB::table('contents')->where('id', $story->id)->update(['search_text' => null, 'search_tsv' => null]);
    cacheShown(['/shows-it/' => ['content:'.$story->id], '/shows-another/' => ['content:unrelated']]);

    cacheRolledBack(fn () => Artisan::call('teme:content:rebuild-derivations'));

    expect(cacheKept('/shows-it/', '/shows-another/'))->toHaveCount(2);

    $this->artisan('teme:content:rebuild-derivations')->assertSuccessful();

    expect(cacheKept('/shows-it/', '/shows-another/'))->toBe(['/shows-another/']);
});

it('purges every page when it fixes a count the lists page by, and nothing when the counts are right', function (): void {
    pageArticle($this->editor, 'Harbour news');
    cacheShown(['/lists-posts/' => ['list:post'], '/shows-an-article/' => ['content:unrelated']]);

    $this->artisan('teme:counts:rebuild')->assertSuccessful();

    expect(cacheKept('/lists-posts/', '/shows-an-article/'))->toHaveCount(2);

    DB::table('content_counts')->update(['count' => 99]);
    cacheRolledBack(fn () => Artisan::call('teme:counts:rebuild'));

    expect(cacheKept('/lists-posts/', '/shows-an-article/'))->toHaveCount(2);

    $this->artisan('teme:counts:rebuild')->assertSuccessful();

    expect(cacheKept('/lists-posts/', '/shows-an-article/'))->toBe([]);
});

it('purges every page when it fixes an item\'s comment counts, which its thread\'s heading and pages show', function (): void {
    $item = cmItem($this->editor);
    cmComment($item);
    cacheShown(['/shows-its-thread/' => ['comments:'.$item->id], '/shows-an-article/' => ['content:unrelated']]);
    DB::table('comment_stats')->where('content_id', $item->id)->update(['thread_count' => 9]);

    $this->artisan('teme:counts:rebuild')->assertSuccessful();

    expect(cacheKept('/shows-its-thread/', '/shows-an-article/'))->toBe([]);
});
