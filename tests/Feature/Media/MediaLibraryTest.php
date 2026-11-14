<?php

declare(strict_types=1);

use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\Input\UpdateMediaDetailsInput;
use App\Domain\Media\Operations\TrashMedia;
use App\Domain\Media\Operations\UpdateMediaDetails;
use App\Domain\Media\Queries\MediaLibrary;
use App\Domain\Media\Queries\MediaLibraryRequest;
use App\Domain\Media\Queries\MediaUsageList;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->author = User::factory()->create()->assignRole('author');
});

function libraryPage(array $arguments = []): array
{
    return app(MediaLibrary::class)->page(new MediaLibraryRequest(...$arguments));
}

function libraryTitles(array $page): array
{
    return array_column($page['rows'], 'title');
}

it('lists newest first, with the All and Trash tabs and their exact counts', function (): void {
    mediaUpload($this->author, mediaJpeg(400, 300), 'first.jpg');
    // Timestamps are stored to the second; move the clock so the order is certain.
    $this->travel(1)->minutes();
    mediaUpload($this->author, mediaPdf(), 'second.pdf');

    $page = libraryPage();

    expect(libraryTitles($page))->toBe(['second', 'first'])
        ->and($page['tabs'])->toBe([
            ['key' => 'all', 'label' => 'All', 'count' => 2],
            ['key' => 'trash', 'label' => 'Trash', 'count' => 0],
        ])
        ->and($page['filtered'])->toBeFalse();
});

it('filters by type, unused, missing alt text, uploader and search, and shows no total', function (): void {
    $described = mediaUpload($this->author, mediaJpeg(400, 300), 'harbour.jpg');
    app(UpdateMediaDetails::class)->execute(new UpdateMediaDetailsInput($described->id, (string) $this->author->id, 'harbour', 'Boats at dawn', null, null));
    $this->travel(1)->minutes();
    mediaUpload($this->author, mediaJpeg(401, 300), 'market.jpg');
    $this->travel(1)->minutes();
    $used = mediaUpload($this->author, mediaJpeg(402, 300), 'used.jpg');
    refsDraft($this->author, refsFeatured($used));
    $this->travel(1)->minutes();
    $editor = User::factory()->create()->assignRole('editor');
    mediaUpload($editor, mediaPdf(), 'theirs.pdf');

    expect(libraryTitles(libraryPage(['type' => 'document'])))->toBe(['theirs'])
        ->and(libraryTitles(libraryPage(['missingAlt' => true])))->toBe(['used', 'market'])
        ->and(libraryTitles(libraryPage(['unused' => true])))->toBe(['theirs', 'market', 'harbour'])
        ->and(libraryTitles(libraryPage(['mine' => true, 'viewerId' => $editor->id])))->toBe(['theirs'])
        ->and(libraryTitles(libraryPage(['search' => 'DAWN'])))->toBe(['harbour'])
        ->and(libraryPage(['search' => 'dawn'])['filtered'])->toBeTrue();
});

it('shows the trash on its own tab, most recently trashed first', function (): void {
    $image = mediaUpload($this->author, mediaJpeg(), 'gone.jpg');
    app(TrashMedia::class)->execute(new MediaActionInput($image->id, (string) $this->author->id));

    expect(libraryTitles(libraryPage()))->toBe([])
        ->and(libraryTitles(libraryPage(['tab' => 'trash'])))->toBe(['gone']);
});

it('pages by keyset in both directions', function (): void {
    foreach (range(1, 65) as $n) {
        mediaUpload($this->author, mediaPdf(), "doc-{$n}.pdf", allowDuplicate: true);
    }

    $first = libraryPage();
    $second = libraryPage(['after' => $first['older']]);
    $back = libraryPage(['before' => $second['newer']]);

    expect(count($first['rows']))->toBe(60)
        ->and(count($second['rows']))->toBe(5)
        ->and($second['older'])->toBeNull()
        ->and(libraryTitles($back))->toBe(libraryTitles($first));
});

it('lists the first page for a before cursor it cannot decode, not the oldest rows (a hand-edited link)', function (): void {
    foreach (range(1, 65) as $n) {
        mediaUpload($this->author, mediaPdf(), "doc-{$n}.pdf", allowDuplicate: true);
    }

    $first = libraryPage();
    $edited = libraryPage(['before' => 'not-a-cursor']);

    expect(libraryTitles($edited))->toBe(libraryTitles($first))
        ->and($edited['newer'])->toBeNull()
        ->and($edited['older'])->toBe($first['older']);
});

it('makes the same number of queries on every page', function (): void {
    foreach (range(1, 65) as $n) {
        mediaUpload($this->author, mediaPdf(), "doc-{$n}.pdf", allowDuplicate: true);
    }

    $count = function (array $arguments): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        libraryPage($arguments);

        return count(DB::getQueryLog());
    };

    expect($count(['after' => libraryPage()['older']]))->toBe($count([]));
});

it('lists where an item is used, one row per content item', function (): void {
    $image = mediaUpload($this->author, mediaJpeg(), 'used.jpg');
    $post = refsDraft($this->author, refsFeatured($image));
    listsPublish($post, $this->author);

    $page = app(MediaUsageList::class)->page($image->id, null);

    expect($page['rows'])->toBe([['id' => $post->id, 'title' => 'Pictured', 'type' => 'post', 'status' => 'published', 'published' => true]])
        ->and($page['older'])->toBeNull();
});
