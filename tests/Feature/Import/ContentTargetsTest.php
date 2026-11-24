<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Contents\Models\Content;
use App\Domain\Import\Phases\Content\ContentSite;
use App\Domain\Import\Phases\Content\MapTargets;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Import\ContentImports;
use Tests\Fixtures\Import\ImportRecords;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->author = User::factory()->create(['slug' => 'ada'])->assignRole('author');
    $this->sourceId = ImportRecords::source();
    $this->runId = ImportRecords::run($this->sourceId);
    $this->targets = new MapTargets($this->sourceId);
});

/**
 * An item imported through ImportContent and its post row on the map, as
 * the content phase leaves them; the item.
 *
 * @param  array<string, mixed>  $changes
 */
function ctgImported(string $sourceId, string $runId, User $author, int $wordpressId, array $changes = []): Content
{
    $result = ContentImports::import(ContentImports::item($author, $changes));
    ImportRecords::entry($sourceId, $runId, 'post', (string) $wordpressId, 'created', ['teme_type' => 'content', 'teme_id' => $result['contentId']]);

    return Content::withTrashed()->findOrFail($result['contentId']);
}

/**
 * The statements $read sends.
 */
function ctgStatements(Closure $read): int
{
    $count = 0;
    DB::listen(function () use (&$count): void {
        $count++;
    });
    $read();

    return $count;
}

it('finds an old page by its address now or WordPress’s own, an item, a term or a person still here', function (): void {
    $story = ctgImported($this->sourceId, $this->runId, $this->author, 12, ['wordpressPath' => '2019/05/04/harbour-news']);
    $gone = ctgImported($this->sourceId, $this->runId, $this->author, 13, ['slug' => 'old-story', 'title' => 'Old story', 'wordpressPath' => null]);
    pageTrash($gone, $this->admin);
    $news = typesTerm($this->admin, 'category', 'News');
    // A factory's person has no author address yet; the people Operations write it.
    app(PathProjection::class)->syncUser($this->author);

    expect($this->targets->page('/harbour-news'))->toBe(['kind' => 'content', 'id' => $story->id])
        ->and($this->targets->page('/2019/05/04/Harbour-News'))->toBe(['kind' => 'content', 'id' => $story->id])
        ->and($this->targets->page('/category/news'))->toBe(['kind' => 'term', 'id' => $news->id])
        ->and($this->targets->page('/author/ada'))->toBe(['kind' => 'user', 'id' => (string) $this->author->id])
        ->and($this->targets->page('/old-story'))->toBeNull()
        ->and($this->targets->page('/never-here'))->toBeNull();
});

it('finds a post or page by WordPress’s number, never one in the trash or one that became nothing', function (): void {
    $story = ctgImported($this->sourceId, $this->runId, $this->author, 12);
    $trashed = ctgImported($this->sourceId, $this->runId, $this->author, 13, ['slug' => 'old-story', 'title' => 'Old story']);
    pageTrash($trashed, $this->admin);
    ImportRecords::entry($this->sourceId, $this->runId, 'post', '14', 'skipped');

    expect($this->targets->post(12))->toBe($story->id)
        ->and($this->targets->post(13))->toBeNull()
        ->and($this->targets->post(14))->toBeNull()
        ->and($this->targets->post(99))->toBeNull();
});

it('finds a category or tag by WordPress’s term ID or slug, only one this source made and still here', function (): void {
    $news = typesTerm($this->admin, 'category', 'News');
    $tides = typesTerm($this->admin, 'tag', 'Tides');
    $theirs = typesTerm($this->admin, 'category', 'Theirs');
    ImportRecords::entry($this->sourceId, $this->runId, 'term', '5', 'created', ['teme_type' => 'term', 'teme_id' => $news->id, 'facts' => json_encode(['term_id' => 4, 'taxonomy' => 'category', 'parent' => 0, 'path' => 'category/news'])]);
    ImportRecords::entry($this->sourceId, $this->runId, 'term', '9', 'created', ['teme_type' => 'term', 'teme_id' => $tides->id, 'facts' => json_encode(['term_id' => 8, 'taxonomy' => 'post_tag', 'parent' => 0, 'path' => 'tag/tides'])]);

    expect($this->targets->term('category', 4))->toBe($news->id)
        ->and($this->targets->term('post_tag', 8))->toBe($tides->id)
        ->and($this->targets->term('category', 8))->toBeNull()
        ->and($this->targets->termNamed('category', 'news'))->toBe($news->id)
        ->and($this->targets->termNamed('post_tag', 'tides'))->toBe($tides->id)
        ->and($this->targets->termNamed('category', $theirs->slug))->toBeNull()
        ->and($this->targets->termNamed('product_cat', 'news'))->toBeNull();
});

it('finds a person by WordPress’s number or nicename, only one still here', function (): void {
    $gone = User::factory()->create()->assignRole('author');
    ImportRecords::entry($this->sourceId, $this->runId, 'user', '3', 'created', ['teme_type' => 'user', 'teme_id' => (string) $this->author->id, 'facts' => json_encode(['nicename' => 'ada-lovelace'])]);
    ImportRecords::entry($this->sourceId, $this->runId, 'user', '4', 'created', ['teme_type' => 'user', 'teme_id' => (string) $gone->id, 'facts' => json_encode(['nicename' => 'gone'])]);
    $gone->delete();

    expect($this->targets->user(3))->toBe((string) $this->author->id)
        ->and($this->targets->userNamed('ada-lovelace'))->toBe((string) $this->author->id)
        ->and($this->targets->user(4))->toBeNull()
        ->and($this->targets->userNamed('gone'))->toBeNull();
});

it('finds outside images and a post’s attached files through the media import’s map', function (): void {
    $photo = mediaUpload($this->admin, mediaJpeg());
    ImportRecords::entry($this->sourceId, $this->runId, 'attachment', '41', 'created', ['teme_type' => 'media', 'teme_id' => $photo->id, 'facts' => json_encode(['parent' => 12, 'page_path' => null, 'source' => null])]);
    ImportRecords::entry($this->sourceId, $this->runId, 'outside-image', 'cdn.partner.example/photos/harbour.jpg', 'created', ['teme_type' => 'media', 'teme_id' => $photo->id]);

    expect($this->targets->attached(12, 'image'))->toBe([41])
        ->and($this->targets->attached(12, 'video'))->toBe([])
        ->and($this->targets->outsideImage('https://www.cdn.partner.example/photos/harbour.jpg'))->toBe($photo->id)
        ->and($this->targets->keepsOldSiteMedia())->toBeFalse()
        ->and((new MapTargets($this->sourceId, true))->keepsOldSiteMedia())->toBeTrue();
});

it('answers each lookup with at most two statements, never reading the whole map', function (): void {
    foreach (range(100, 400) as $id) {
        ImportRecords::entry($this->sourceId, $this->runId, 'post', (string) $id, 'skipped');
    }

    ctgImported($this->sourceId, $this->runId, $this->author, 12);

    expect(ctgStatements(fn () => $this->targets->page('/harbour-news')))->toBeLessThanOrEqual(2)
        ->and(ctgStatements(fn () => $this->targets->post(12)))->toBeLessThanOrEqual(2)
        ->and(ctgStatements(fn () => $this->targets->post(399)))->toBeLessThanOrEqual(2)
        ->and(ctgStatements(fn () => $this->targets->userNamed('nobody')))->toBeLessThanOrEqual(2);
});

it('gives the converter the old site’s home, its uploads folder and its registered shortcodes, asking once in six hours', function (): void {
    $companion = importFake();
    $companion->items('analyze', ['api' => 1, 'report' => ['sections' => [
        'media' => ['uploads' => ['baseurl_path' => '/files', 'uploads_host' => 'cdn.old.example', 'on_other_host' => true]],
        'content' => ['shortcodes' => ['tags' => ['contact-form-7' => ['registered' => true, 'posts' => 3], 'vc_row' => ['registered' => false, 'posts' => 9]]]],
    ]]]);
    $source = importSource();

    $site = app(ContentSite::class)->for($source->id);
    app(ContentSite::class)->for($source->id);

    expect($site->home)->toBe('https://old.example')
        ->and($site->uploadsKey('https://cdn.old.example/files/2024/05/Harbour.jpg'))->toBe('2024/05/harbour.jpg')
        ->and($site->shortcodes)->toBe(['contact-form-7'])
        ->and($companion->requestsTo('analyze'))->toHaveCount(1)
        ->and(Cache::get(ContentSite::key($source->id)))->toBe(['uploads' => 'https://cdn.old.example/files', 'shortcodes' => ['contact-form-7']]);
});

it('gives the defaults for a site with no report, and asks again after a site that couldn’t be read', function (): void {
    $companion = importFake();
    $source = importSource();
    $companion->answer('analyze', 503, ['code' => 'busy']);

    $refused = app(ContentSite::class)->for($source->id);
    $companion->items('analyze', ['api' => 1, 'report' => null]);
    $unreported = app(ContentSite::class)->for($source->id);

    expect($refused->uploadsKey('https://old.example/wp-content/uploads/2024/05/harbour.jpg'))->toBe('2024/05/harbour.jpg')
        ->and($refused->shortcodes)->toBe([])
        ->and($unreported->shortcodes)->toBe([])
        ->and($companion->requestsTo('analyze'))->toHaveCount(2)
        ->and(Cache::get(ContentSite::key($source->id)))->toBe(['uploads' => null, 'shortcodes' => []]);
});
