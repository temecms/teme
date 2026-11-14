<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\DeleteContentPermanently;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\RestoreContent;
use App\Domain\Media\MediaDisks;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\TrashMedia;
use App\Domain\Redirects\RedirectLookup;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost']);
    $this->editor = User::factory()->create()->assignRole('editor');
});

it('answers an address routing missed with an exact redirect, whatever its case or trailing slash (I-297)', function (int $status): void {
    rdSaved('/old-fares', '/news/fares/', $status);

    expect(addrLocation(addrGet('/old-fares/')->assertStatus($status)))->toBe('/news/fares/')
        ->and(addrLocation(addrGet('/Old-Fares')->assertStatus($status)))->toBe('/news/fares/')
        ->and(addrGet('/old-fares/')->headers->get('X-Teme-Address'))->toBe('redirect');
})->with([301, 302, 307, 308]);

it('never hides a live page, and lets an old address send readers on by itself first (I-858)', function (): void {
    $item = addrPublished($this->editor, 'Harbour news');
    rdSaved('/harbour-news', '/elsewhere/');

    addrGet('/harbour-news/')->assertOk();

    addrRename($item, 'harbour-news-2026', $this->editor);

    expect(addrLocation(addrGet('/harbour-news/')->assertStatus(301)))->toBe('/harbour-news-2026/');
});

it('fills a pattern\'s placeholders, percent-encoded, and passes the query on when the target has none (I-287)', function (): void {
    rdSaved('/2019/05/[slug]', '/[slug]/');
    rdSaved('/blog/[...rest]', 'https://archive.example/[...rest]');
    rdSaved('/promo', '/news/?from=promo');

    expect(addrLocation(addrGet('/2019/05/Caf%C3%A9?utm_source=print')->assertStatus(301)))->toBe('/caf%C3%A9/?utm_source=print')
        ->and(addrGet('/blog/2020/ferry/')->assertStatus(301)->headers->get('Location'))->toBe('https://archive.example/2020/ferry')
        ->and(addrLocation(addrGet('/promo?x=1')->assertStatus(301)))->toBe('/news/?from=promo');
});

it('tries the exact redirect first, then the pattern with the longest fixed start, then one without [...rest]', function (): void {
    rdSaved('/news/[...rest]', '/a/[...rest]');
    rdSaved('/news/[slug]', '/b/[slug]');
    rdSaved('/news/sport/[slug]', '/c/[slug]');
    rdSaved('/news/today', '/d/');

    expect(addrLocation(addrGet('/news/today/')))->toBe('/d/')
        ->and(addrLocation(addrGet('/news/sport/rowing/')))->toBe('/c/rowing')
        ->and(addrLocation(addrGet('/news/rowing/')))->toBe('/b/rowing')
        ->and(addrLocation(addrGet('/news/rowing/results/')))->toBe('/a/rowing/results');
});

it('sends readers to an item where it is now, waits while it isn\'t published, and stays when it is deleted for good (I-857)', function (): void {
    $item = addrPublished($this->editor, 'Ferry fares');
    rdSaved('/old-fares', item: $item->id);

    expect(addrLocation(addrGet('/old-fares/')->assertStatus(301)))->toBe('/ferry-fares/');

    addrRename($item, 'ferry-fares-2026', $this->editor);
    expect(addrLocation(addrGet('/old-fares/')->assertStatus(301)))->toBe('/ferry-fares-2026/');

    pageTrash($item, $this->editor);
    addrGet('/old-fares/')->assertNotFound();

    app(RestoreContent::class)->execute(new ContentActionInput($item->id, (string) $this->editor->id));
    addrGet('/old-fares/')->assertStatus(301);

    pageTrash($item, $this->editor);
    app(DeleteContentPermanently::class)->execute(new ContentActionInput($item->id, (string) $this->editor->id));

    addrGet('/old-fares/')->assertNotFound();
    expect(DB::table('redirects')->where('source', 'old-fares')->value('target'))->toBe($item->id);
});

it('sends readers to a media file by its ID, from the disk\'s address now, and waits while it is in the trash (I-860)', function (): void {
    $file = mediaUpload($this->editor, mediaPdf(), 'Timetable.pdf');
    rdSaved('/timetable', file: $file->id);

    expect(addrGet('/timetable/')->assertStatus(301)->headers->get('Location'))->toBe(MediaDisks::public()->url($file->original_path));

    app(TrashMedia::class)->execute(new MediaActionInput($file->id, (string) $this->editor->id));

    addrGet('/timetable/')->assertNotFound();
});

it('answers 410 and 451 with the theme\'s error page, and sends readers nowhere', function (int $status): void {
    rdSaved('/withdrawn', status: $status);

    addrGet('/withdrawn/')->assertStatus($status)->assertHeaderMissing('Location');
})->with([410, 451]);

it('leaves an address no redirect answers, or one switched off, to the 404 page', function (): void {
    rdSaved('/switched-off', '/news/', on: false);

    addrGet('/switched-off/')->assertNotFound();
    addrGet('/never-was/')->assertNotFound();
});

it('tags every stored not-found page, and no other, so a redirect can remove them (I-297)', function (): void {
    $folder = cacheOn();
    addrPublished($this->editor, 'Harbour news');

    addrGet('/never-was/')->assertNotFound()->assertHeader('X-Teme-Cache', 'MISS');
    addrGet('/harbour-news/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');

    expect(cacheEntry('/never-was/')?->tags)->toContain(RedirectLookup::NOT_FOUND_TAG)
        ->and(cacheEntry('/harbour-news/')?->tags)->not->toContain(RedirectLookup::NOT_FOUND_TAG);

    cacheOff($folder);
});

it('reads the patterns once a request: a second miss costs one statement', function (): void {
    rdSaved('/blog/[...rest]', '/news/[...rest]');
    $lookup = app(RedirectLookup::class);
    $lookup->answer('/first-miss/', '');

    DB::enableQueryLog();
    $lookup->answer('/second-miss/', '');

    expect(DB::getQueryLog())->toHaveCount(1)
        ->and($lookup->answer('/blog/2020/', '')?->location)->toBe('http://localhost/news/2020');
});
