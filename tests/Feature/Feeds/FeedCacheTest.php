<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->admin = User::factory()->create()->assignRole('administrator');
    app(PathProjection::class)->syncUser($this->author);
    $this->news = typesTerm($this->editor, 'category', 'News');
    $this->story = pageArticle($this->author, 'Harbour news', categories: [$this->news]);
    $this->folder = cacheOn();
});

afterEach(function (): void {
    cacheOff($this->folder);
});

it('stores a feed under the tags that remove it and serves it as it came (I-464)', function (): void {
    $miss = addrGet('/feed/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');
    $hit = addrGet('/feed/')->assertOk()->assertHeader('X-Teme-Cache', 'HIT')->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8');

    expect($hit->getContent())->toBe($miss->getContent())->not->toBe('')
        ->and($hit->headers->get('X-Robots-Tag'))->toBe($miss->headers->get('X-Robots-Tag'))
        ->and(cacheEntry('/feed/')?->tags)->toContain('feed', 'list:post', 'seo', 'content:'.$this->story->id, 'person:'.$this->author->id, 'term:'.$this->news->id);
});

it('tags a term\'s feed with its archive\'s lists and an author\'s with theirs, not with the type\'s', function (): void {
    addrGet('/category/news/feed/')->assertOk();
    addrGet('/author/jane-doe/feed/')->assertOk();

    expect(cacheEntry('/category/news/feed/')?->tags)->toContain('feed', 'term:category:'.$this->news->id, 'term:'.$this->news->id)->not->toContain('list:post')
        ->and(cacheEntry('/author/jane-doe/feed/')?->tags)->toContain('feed', 'author:'.$this->author->id, 'person:'.$this->author->id)->not->toContain('list:post');
});

it('sets a feed aside when another item joins its list', function (): void {
    addrGet('/feed/')->assertHeader('X-Teme-Cache', 'MISS');

    pageArticle($this->author, 'Another story');

    expect(cacheSetAside('/feed/'))->not->toBeNull();
});

it('removes a feed when an item it lists is trashed', function (): void {
    addrGet('/feed/')->assertHeader('X-Teme-Cache', 'MISS');
    expect(cacheEntry('/feed/'))->not->toBeNull();

    pageTrash($this->story, $this->editor);

    expect(cacheEntry('/feed/'))->toBeNull();
});

it('removes every stored feed when Search and social changes, once the change commits, and none when it rolls back', function (): void {
    addrGet('/feed/')->assertHeader('X-Teme-Cache', 'MISS');
    addrGet('/category/news/feed/')->assertHeader('X-Teme-Cache', 'MISS');
    $change = fn () => app(UpdateSettings::class)->execute(new UpdateSettingsInput('seo', ['feed_items' => 5], [], [], 0, (string) $this->admin->id));

    cacheRolledBack($change);

    expect(cacheKept('/feed/', '/category/news/feed/'))->toBe(['/feed/', '/category/news/feed/']);

    $change();

    expect(cacheKept('/feed/', '/category/news/feed/'))->toBe([]);
});

it('never stores a comment feed\'s 410', function (): void {
    addrGet('/harbour-news/feed/')->assertStatus(410)->assertHeader('X-Teme-Cache', 'BYPASS (status 410)');
    addrGet('/harbour-news/feed/')->assertStatus(410)->assertHeader('X-Teme-Cache', 'BYPASS (status 410)');
});
