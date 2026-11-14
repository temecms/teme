<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->editor = User::factory()->create(['name' => 'Ada Obi'])->assignRole('editor');
    $this->folder = cacheOn();

    // Their addresses, in the order they were made: the term's, then each item's.
    $news = typesTerm($this->editor, 'category', 'News');
    pageArticle($this->editor, 'Older story', categories: [$news]);
    pageArticle($this->editor, 'Newer story', categories: [$news]);
    pageTrash(pageArticle($this->editor, 'Trashed story'), $this->editor);
});

afterEach(fn () => cacheOff($this->folder));

it('draws and stores the home page, each archive and every live item, and never a trashed one', function (): void {
    $this->artisan('teme:cache:warm')
        ->expectsOutputToContain('Warmed 4 pages: 4 drawn and stored, 0 already stored, 0 not stored.')
        ->assertSuccessful();

    expect(cacheKept('/', '/category/news/', '/newer-story/', '/older-story/', '/trashed-story/'))
        ->toBe(['/', '/category/news/', '/newer-story/', '/older-story/']);

    $this->artisan('teme:cache:warm')
        ->expectsOutputToContain('Warmed 4 pages: 0 drawn and stored, 4 already stored, 0 not stored.')
        ->assertSuccessful();
});

it('draws no more than --limit pages, the newest items before the older (D11)', function (): void {
    $this->artisan('teme:cache:warm', ['--limit' => 3])
        ->expectsOutputToContain('Warmed 3 pages: 3 drawn and stored, 0 already stored, 0 not stored.')
        ->assertSuccessful();

    expect(cacheKept('/', '/category/news/', '/newer-story/', '/older-story/'))->toBe(['/', '/category/news/', '/newer-story/']);
});

it('draws nothing while the page cache is off, and says so', function (): void {
    config(['page_cache.enabled' => false]);

    $this->artisan('teme:cache:warm')->expectsOutputToContain('The page cache is off')->assertSuccessful();

    expect(cacheKept('/'))->toBe([]);
});
