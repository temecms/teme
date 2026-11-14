<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\Input\UploadMediaInput;
use App\Domain\Media\Operations\TrashMedia;
use App\Domain\Media\Operations\UploadMedia;
use App\Domain\Media\Queries\MediaLibrary;
use App\Domain\Media\Queries\MediaLibraryRequest;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Contents\Visibilities;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->author = User::factory()->create()->assignRole('author');
    $this->draft = lifecycleDraft($this->author, 'Harbour news');

    // One file a minute, each image a new width, so the list's order is the order they came in and none is a duplicate.
    $this->width = 400;
    $this->upload = function (string $name, ?string $item, ?string $path = null): Media {
        $this->travel(1)->minutes();

        return app(UploadMedia::class)->execute(new UploadMediaInput($path ?? mediaJpeg($this->width++, 300), $name, (string) $this->author->id, uploadedFor: $item));
    };
});

it('narrows either tab to the files uploaded for an item, and ignores what isn’t an item’s ID (I-553)', function (): void {
    ($this->upload)('harbour.jpg', $this->draft->id);
    ($this->upload)('quay.jpg', null);
    $gone = ($this->upload)('gone.jpg', $this->draft->id);
    app(TrashMedia::class)->execute(new MediaActionInput($gone->id, (string) $this->author->id));
    $page = fn (array $arguments): array => app(MediaLibrary::class)->page(new MediaLibraryRequest(...$arguments));

    expect(array_column($page(['uploadedFor' => $this->draft->id])['rows'], 'title'))->toBe(['harbour'])
        ->and($page(['uploadedFor' => $this->draft->id])['filtered'])->toBeTrue()
        ->and(array_column($page(['tab' => 'trash', 'uploadedFor' => $this->draft->id])['rows'], 'title'))->toBe(['gone'])
        ->and(array_column($page(['uploadedFor' => 'harbour-news'])['rows'], 'title'))->toBe(['quay', 'harbour'])
        ->and($page(['uploadedFor' => 'harbour-news'])['filtered'])->toBeFalse()
        // The tabs count the whole library, whatever narrows the list.
        ->and(array_column($page(['uploadedFor' => $this->draft->id])['tabs'], 'count'))->toBe([2, 1]);
});

it('narrows the media modal to the files uploaded for the item open in the editor, beside its other filters', function (): void {
    ($this->upload)('harbour.jpg', $this->draft->id);
    ($this->upload)('fares.pdf', $this->draft->id, mediaPdf());
    ($this->upload)('quay.jpg', null);
    $this->travelBack();
    $titles = fn (array $query): array => array_column($this->actingAs($this->author)->getJson(route('media.picker', $query))->assertOk()->json('rows'), 'title');

    expect($titles(['kinds' => ['image'], 'uploadedFor' => $this->draft->id]))->toBe(['harbour'])
        ->and($titles(['kinds' => ['image', 'document'], 'uploadedFor' => $this->draft->id]))->toBe(['fares', 'harbour'])
        ->and($titles(['kinds' => ['image', 'document'], 'uploadedFor' => $this->draft->id, 'search' => 'fares']))->toBe(['fares'])
        ->and($titles(['kinds' => ['image']]))->toBe(['quay', 'harbour']);
});

it('sends the Media screen the item it is narrowed to, a trashed one too, and drops an address that names no item', function (): void {
    ($this->upload)('harbour.jpg', $this->draft->id);
    ($this->upload)('quay.jpg', null);
    $this->travelBack();
    $screen = fn (array $query) => $this->actingAs($this->author)->get(route('media.index', $query))->assertOk();

    $screen(['uploaded_for' => $this->draft->id, 'view' => 'list'])
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/index')
            ->where('filters', ['view' => 'list', 'uploaded_for' => $this->draft->id])
            ->where('uploadedFor', ['id' => $this->draft->id, 'title' => 'Harbour news'])
            ->where('filtered', true)
            ->has('rows', 1)
            ->where('rows.0.title', 'harbour'));

    app(TrashContent::class)->execute(new ContentActionInput($this->draft->id, (string) $this->author->id));

    $screen(['uploaded_for' => $this->draft->id])
        ->assertInertia(fn (Assert $page) => $page
            ->where('uploadedFor', ['id' => $this->draft->id, 'title' => 'Harbour news'])
            ->has('rows', 1));

    $screen(['uploaded_for' => strtolower((string) Str::ulid())])
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters', [])
            ->where('uploadedFor', null)
            ->where('filtered', false)
            ->has('rows', 2));
});

it('tells the drawer what a file was uploaded for: the item, its status, whether it is in the trash, or nothing (I-553)', function (): void {
    $harbour = ($this->upload)('harbour.jpg', $this->draft->id);
    $quay = ($this->upload)('quay.jpg', null);
    $this->travelBack();
    $shown = fn (Media $media): mixed => $this->actingAs($this->author)->getJson(route('media.show', ['media' => $media->id]))->assertOk()->json('uploadedFor');

    expect($shown($harbour))->toBe(['id' => $this->draft->id, 'title' => 'Harbour news', 'status' => 'draft', 'trashed' => false])
        ->and($shown($quay))->toBeNull();

    listsPublish($this->draft, $this->author);

    expect($shown($harbour)['status'])->toBe('published');

    app(TrashContent::class)->execute(new ContentActionInput($this->draft->id, (string) $this->author->id));

    expect($shown($harbour)['trashed'])->toBeTrue();
});

it('names a private item a file was uploaded for only to whoever may edit it (I-1700)', function (): void {
    $harbour = ($this->upload)('harbour.jpg', $this->draft->id);
    $this->travelBack();
    Visibilities::change($this->draft, 'private', $this->author);
    $other = User::factory()->create()->assignRole('author');
    $title = fn (User $viewer): mixed => $this->actingAs($viewer)->getJson(route('media.show', ['media' => $harbour->id]))->assertOk()->json('uploadedFor.title');

    expect($title($this->author))->toBe('Harbour news')
        ->and($title($other))->toBe('A private item');
});
