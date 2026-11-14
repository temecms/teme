<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\Input\UpdateContentDetailsInput;
use App\Domain\Contents\Operations\RestoreContent;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Contents\Operations\UnpublishContent;
use App\Domain\Contents\Operations\UpdateContentDetails;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create(['name' => 'Ada Obi'])->assignRole('editor');
    $this->news = typesTerm($this->editor, 'category', 'News');
    $this->folder = cacheOn();
});

afterEach(fn () => cacheOff($this->folder));

it('purges a "not found" page at the address a post takes, and each list it joins, when it is first published', function (): void {
    $draft = typesDraft($this->editor, 'post', 'Harbour news', [$this->news->id]);
    cachePage('/harbour-news/');
    cacheShown([
        '/lists-posts/' => ['list:post'],
        '/lists-news/' => ['term:category:'.$this->news->id],
        '/lists-ada/' => ['author:'.$this->editor->id],
        '/lists-this-month/' => ['date:'.now()->format('Y:m')],
        '/lists-pages/' => ['list:page'],
    ]);
    $pages = ['/harbour-news/', '/lists-posts/', '/lists-news/', '/lists-ada/', '/lists-this-month/', '/lists-pages/'];

    cacheRolledBack(fn () => listsPublish($draft, $this->editor));

    expect(cacheKept(...$pages))->toHaveCount(6);

    listsPublish($draft, $this->editor);

    expect(cacheKept(...$pages))->toBe(['/lists-pages/']);
});

it('keeps every page while a draft is saved, and purges both categories\' lists when it is published under another', function (): void {
    $business = typesTerm($this->editor, 'category', 'Business');
    $story = pageArticle($this->editor, 'Harbour news', categories: [$this->news])->refresh();
    cacheShown([
        '/shows-it/' => ['content:'.$story->id],
        '/lists-news/' => ['term:category:'.$this->news->id],
        '/lists-business/' => ['term:category:'.$business->id],
        '/lists-a-tag/' => ['term:tag:unrelated'],
    ]);
    $pages = ['/shows-it/', '/lists-news/', '/lists-business/', '/lists-a-tag/'];

    app(SaveDraft::class)->execute(new SaveDraftInput($story->id, 'Harbour news', ['type' => 'doc', 'content' => []], [], $story->working_revision_id, (string) $this->editor->id, [$business->id]));

    expect(cacheKept(...$pages))->toHaveCount(4);

    listsPublish($story, $this->editor);

    expect(cacheKept(...$pages))->toBe(['/lists-a-tag/']);
});

it('purges both categories\' lists when a quick edit moves a post from one to the other', function (): void {
    $business = typesTerm($this->editor, 'category', 'Business');
    $story = pageArticle($this->editor, 'Harbour news', categories: [$this->news])->refresh();
    cacheShown([
        '/lists-news/' => ['term:category:'.$this->news->id],
        '/lists-business/' => ['term:category:'.$business->id],
        '/lists-a-tag/' => ['term:tag:unrelated'],
    ]);
    $move = fn () => app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput($story->id, $story->working_revision_id, (string) $this->editor->id, terms: [$business->id]));

    cacheRolledBack($move);

    expect(cacheKept('/lists-news/', '/lists-business/', '/lists-a-tag/'))->toHaveCount(3);

    $move();

    expect(cacheKept('/lists-news/', '/lists-business/', '/lists-a-tag/'))->toBe(['/lists-a-tag/']);
});

it('purges the old address and the new when a post\'s slug changes', function (): void {
    $story = pageArticle($this->editor, 'Harbour news');
    cachePage('/harbour-news/');
    cachePage('/harbour-report/');
    cachePage('/tides/');

    cacheRolledBack(fn () => addrRename($story, 'harbour-report', $this->editor));

    expect(cacheKept('/harbour-news/', '/harbour-report/', '/tides/'))->toHaveCount(3);

    addrRename($story, 'harbour-report', $this->editor);

    expect(cacheKept('/harbour-news/', '/harbour-report/', '/tides/'))->toBe(['/tides/']);
});

it('purges both authors\' lists and both months\' when a post changes hands and dates', function (): void {
    $other = User::factory()->create()->assignRole('author');
    $story = pageArticle($this->editor, 'Harbour news')->refresh();
    cacheShown([
        '/lists-ada/' => ['author:'.$this->editor->id],
        '/lists-the-other/' => ['author:'.$other->id],
        '/lists-this-month/' => ['date:'.now()->format('Y:m')],
        '/lists-march-2025/' => ['date:2025:03'],
        '/lists-2025/' => ['date:2025'],
        '/lists-someone-else/' => ['author:999'],
    ]);
    $pages = ['/lists-ada/', '/lists-the-other/', '/lists-this-month/', '/lists-march-2025/', '/lists-2025/', '/lists-someone-else/'];
    $change = fn () => app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput($story->id, $story->working_revision_id, (string) $this->editor->id, authorId: (int) $other->id, publishedAt: '2025-03-10T09:00:00Z'));

    cacheRolledBack($change);

    expect(cacheKept(...$pages))->toHaveCount(6);

    $change();

    expect(cacheKept(...$pages))->toBe(['/lists-someone-else/']);
});

it('purges nothing when a quick edit waits in a draft for the next publish', function (): void {
    $story = pageArticle($this->editor, 'Harbour news');
    pageSaveDraft($story, 'Harbour news, a draft');
    $story->refresh();
    cacheShown(['/shows-it/' => ['content:'.$story->id], '/lists-posts/' => ['list:post']]);

    app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput($story->id, $story->working_revision_id, (string) $this->editor->id, title: 'Harbour news, retitled'));

    expect(cacheKept('/shows-it/', '/lists-posts/'))->toHaveCount(2);
});

it('purges a post, its address and every list it was in when it goes to the trash', function (): void {
    $story = pageArticle($this->editor, 'Harbour news', categories: [$this->news]);
    cachePage('/harbour-news/');
    cacheShown([
        '/shows-it/' => ['content:'.$story->id],
        '/lists-news/' => ['term:category:'.$this->news->id],
        '/lists-posts/' => ['list:post'],
        '/lists-this-year/' => ['date:'.now()->format('Y')],
        '/lists-pages/' => ['list:page'],
    ]);
    $pages = ['/harbour-news/', '/shows-it/', '/lists-news/', '/lists-posts/', '/lists-this-year/', '/lists-pages/'];

    cacheRolledBack(fn () => pageTrash($story, $this->editor));

    expect(cacheKept(...$pages))->toHaveCount(6);

    pageTrash($story, $this->editor);

    expect(cacheKept(...$pages))->toBe(['/lists-pages/']);
});

it('purges a post and every list it was in when it is unpublished', function (): void {
    $story = pageArticle($this->editor, 'Harbour news', categories: [$this->news]);
    cacheShown(['/shows-it/' => ['content:'.$story->id], '/lists-news/' => ['term:category:'.$this->news->id], '/lists-pages/' => ['list:page']]);
    $unpublish = fn () => app(UnpublishContent::class)->execute(new ContentActionInput($story->id, (string) $this->editor->id));

    cacheRolledBack($unpublish);

    expect(cacheKept('/shows-it/', '/lists-news/', '/lists-pages/'))->toHaveCount(3);

    $unpublish();

    expect(cacheKept('/shows-it/', '/lists-news/', '/lists-pages/'))->toBe(['/lists-pages/']);
});

it('purges the address a post comes back to and each list it rejoins when it is restored', function (): void {
    $story = pageArticle($this->editor, 'Harbour news', categories: [$this->news]);
    pageTrash($story, $this->editor);
    cachePage('/harbour-news/');
    cacheShown(['/lists-posts/' => ['list:post'], '/lists-news/' => ['term:category:'.$this->news->id], '/lists-pages/' => ['list:page']]);
    $pages = ['/harbour-news/', '/lists-posts/', '/lists-news/', '/lists-pages/'];
    $restore = fn () => app(RestoreContent::class)->execute(new ContentActionInput($story->id, (string) $this->editor->id));

    cacheRolledBack($restore);

    expect(cacheKept(...$pages))->toHaveCount(4);

    $restore();

    expect(cacheKept(...$pages))->toBe(['/lists-pages/']);
});

it('purges nothing when a post is scheduled, and its lists once the scheduled run publishes it', function (): void {
    $draft = typesDraft($this->editor, 'post', 'Harbour news', [$this->news->id]);
    cacheShown(['/lists-posts/' => ['list:post'], '/lists-news/' => ['term:category:'.$this->news->id], '/lists-pages/' => ['list:page']]);

    schedDue($draft, $this->editor);

    expect(cacheKept('/lists-posts/', '/lists-news/', '/lists-pages/'))->toHaveCount(3);

    $this->artisan('teme:content:run-due-publications')->assertSuccessful();

    expect(cacheKept('/lists-posts/', '/lists-news/', '/lists-pages/'))->toBe(['/lists-pages/']);
});

it('purges every page that draws a region when the region is published', function (): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $region = regionCreate($this->editor, 'good', 'after-content');
    regionSave($region, $this->editor, [regionCardNode('callout')]);
    cacheShown(['/draws-it/' => ['region:good--after-content'], '/draws-another/' => ['region:good--footer']]);

    cacheRolledBack(fn () => listsPublish($region, $this->editor));

    expect(cacheKept('/draws-it/', '/draws-another/'))->toHaveCount(2);

    listsPublish($region, $this->editor);

    expect(cacheKept('/draws-it/', '/draws-another/'))->toBe(['/draws-another/']);
});
