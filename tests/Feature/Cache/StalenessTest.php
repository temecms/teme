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
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->folder = cacheOn();
});

afterEach(fn () => cacheOff($this->folder));

it('shows a published change at once: publishing purges the article\'s page (I-263)', function (): void {
    $story = pageArticle($this->author, 'Harbour news');
    addrGet('/harbour-news/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');
    addrGet('/harbour-news/')->assertHeader('X-Teme-Cache', 'HIT');

    pageSaveDraft($story, 'Harbour news, updated');
    listsPublish($story, $this->author);

    expect(addrGet('/harbour-news/')->assertHeader('X-Teme-Cache', 'MISS')->getContent())->toContain('<title>Harbour news, updated – ');
});

it('never serves a trashed article: trashing purges its page (docs/studies/page-cache.md §3.9)', function (): void {
    $story = pageArticle($this->author, 'Harbour news');
    addrGet('/harbour-news/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');

    pageTrash($story, $this->author);

    addrGet('/harbour-news/')->assertNotFound()->assertHeader('X-Teme-Cache', 'MISS');
});

it('draws the latest posts afresh at once when a post is published: publishing purges the lists it joins', function (): void {
    pageArticle($this->author, 'Harbour news');
    addrGet('/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');
    addrGet('/')->assertHeader('X-Teme-Cache', 'HIT');

    $ferries = pageArticle($this->author, 'Ferry timetable');

    addrGet('/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');

    expect(cacheEntry('/')?->tags)->toContain('content:'.$ferries->id);
});
