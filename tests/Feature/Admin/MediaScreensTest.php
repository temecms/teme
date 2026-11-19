<?php

declare(strict_types=1);

use App\Domain\Media\Models\Media;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->author = User::factory()->create()->assignRole('author');
});

function libraryFile(string $path, string $name): UploadedFile
{
    return new UploadedFile($path, $name, null, null, true);
}

it('shows the library to anyone who uploads, and to no one else', function (): void {
    mediaUpload($this->author, mediaJpeg(), 'harbour.jpg');

    $this->actingAs($this->author)->get(route('media.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/index')
            ->where('rows.0.title', 'harbour')
            ->where('tabs.0', ['key' => 'all', 'label' => 'All', 'count' => 1])
            ->missing('total'));

    $this->actingAs(User::factory()->create()->assignRole('contributor'))->get(route('media.index'))->assertForbidden();
});

it('uploads a file and answers with its ID', function (): void {
    $response = $this->actingAs($this->author)
        ->postJson(route('media.store'), ['file' => libraryFile(mediaJpeg(), 'harbour.jpg')])
        ->assertCreated();

    expect(Media::query()->sole()->id)->toBe($response->json('id'));
});

it('answers a duplicate with the item the library already has', function (): void {
    $path = mediaJpeg();
    $first = mediaUpload($this->author, $path, 'harbour.jpg');

    $this->actingAs($this->author)
        ->postJson(route('media.store'), ['file' => libraryFile($path, 'again.jpg')])
        ->assertStatus(409)
        ->assertJsonPath('duplicate.id', $first->id);

    $this->actingAs($this->author)
        ->postJson(route('media.store'), ['file' => libraryFile($path, 'again.jpg'), 'allow_duplicate' => '1'])
        ->assertCreated();
});

it('answers a refused file with the reason, beside the file', function (): void {
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-notes.jpg';
    file_put_contents($path, '<!DOCTYPE html><html><body>Not a picture</body></html>');

    $this->actingAs($this->author)
        ->postJson(route('media.store'), ['file' => libraryFile($path, 'notes.jpg')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');
});

it('saves details from the drawer and answers with them', function (): void {
    $media = mediaUpload($this->author, mediaJpeg(), 'harbour.jpg');

    $this->actingAs($this->author)
        ->patchJson(route('media.update', ['media' => $media->id]), ['title' => 'Harbour', 'alt' => 'Boats at dawn', 'caption' => '', 'credit' => '', 'focal_x' => 30, 'focal_y' => 60])
        ->assertOk()
        ->assertJsonPath('alt', 'Boats at dawn')
        ->assertJsonPath('focalX', 30);
});

it('refuses to save without a focal point, and does not change the stored one', function (): void {
    $media = mediaUpload($this->author, mediaJpeg(), 'harbour.jpg');
    $media->update(['focal_x' => 30, 'focal_y' => 60]);

    $this->actingAs($this->author)
        ->patchJson(route('media.update', ['media' => $media->id]), ['title' => 'Harbour', 'alt' => '', 'caption' => '', 'credit' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['focal_x', 'focal_y']);

    expect($media->refresh())->focal_x->toBe(30)->focal_y->toBe(60);
});

it('trashes, restores and deletes for good from the drawer', function (): void {
    $media = mediaUpload($this->author, mediaJpeg(), 'harbour.jpg');
    $as = $this->actingAs($this->author);

    $as->postJson(route('media.action', ['media' => $media->id, 'action' => 'trash']))->assertOk()->assertJsonPath('trashed', true);
    $as->postJson(route('media.action', ['media' => $media->id, 'action' => 'restore']))->assertOk()->assertJsonPath('trashed', false);
    $as->postJson(route('media.action', ['media' => $media->id, 'action' => 'trash']))->assertOk();
    $as->deleteJson(route('media.destroy', ['media' => $media->id]))->assertNoContent();

    expect(Media::withTrashed()->find($media->id))->toBeNull();
});

it('refuses another author\'s changes', function (): void {
    $media = mediaUpload($this->author, mediaJpeg(), 'harbour.jpg');
    $other = User::factory()->create()->assignRole('author');

    $this->actingAs($other)
        ->patchJson(route('media.update', ['media' => $media->id]), ['title' => 'Mine now'])
        ->assertForbidden();

    expect($media->refresh()->title)->toBe('harbour');
});

it('downloads an image original from the private disk', function (): void {
    $media = mediaUpload($this->author, mediaJpeg(), 'harbour.jpg');

    $this->actingAs($this->author)->get(route('media.original', ['media' => $media->id]))
        ->assertOk()
        ->assertDownload('harbour.jpg');
});

it('answers a damaged image with the reason beside the file, never a server error', function (): void {
    $this->actingAs($this->author)
        ->postJson(route('media.store'), ['file' => libraryFile(mediaDamagedGif(), 'Quick-Money1.gif')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file' => 'This file looks like an image but can’t be read. It may be damaged. Open it in an image editor, save it again and upload it.']);

    expect(Media::query()->count())->toBe(0);
});

it('answers Show more with the next files after the last one shown, in the same order, under the same filters', function (): void {
    $first = mediaUpload($this->author, mediaJpeg(), 'harbour.jpg');
    $row = (array) DB::table('media')->where('id', $first->id)->first();

    foreach (range(1, 64) as $n) {
        DB::table('media')->insert([...$row, 'id' => (string) Str::ulid(), 'title' => "copy {$n}", 'sha256' => hash('sha256', "copy {$n}"), 'created_at' => now()->subMinutes($n)]);
    }

    $older = $this->actingAs($this->author)->get(route('media.index'))->inertiaProps('older');

    $this->actingAs($this->author)->getJson(route('media.more', ['after' => $older]))
        ->assertOk()
        ->assertJsonCount(5, 'rows')
        ->assertJsonPath('rows.0.title', 'copy 60')
        ->assertJsonPath('older', null);

    $this->actingAs($this->author)->getJson(route('media.more', ['after' => $older, 'search' => 'nothing like it']))
        ->assertOk()
        ->assertJsonCount(0, 'rows');

    $this->actingAs(User::factory()->create()->assignRole('contributor'))->getJson(route('media.more', ['after' => $older]))->assertForbidden();
});

it('opens a picture in Edit image with its place among the library’s images, and nothing that isn’t a live image', function (): void {
    $this->travelTo(now()->subMinutes(3));
    $older = mediaUpload($this->author, mediaJpeg(500, 300), 'older.jpg');
    $this->travelBack();
    $this->travelTo(now()->subMinutes(2));
    $document = mediaUpload($this->author, mediaPdf(), 'fares.pdf');
    $this->travelBack();
    $this->travelTo(now()->subMinute());
    $middle = mediaUpload($this->author, mediaJpeg(600, 300), 'middle.jpg');
    $this->travelBack();
    $newest = mediaUpload($this->author, mediaJpeg(700, 300), 'newest.jpg');

    $this->actingAs($this->author)->get(route('media.edit-image', ['media' => $middle->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/image')
            ->where('item.id', $middle->id)
            ->where('place', ['number' => 2, 'total' => 3, 'previous' => $newest->id, 'next' => $older->id]));

    $this->actingAs($this->author)->get(route('media.edit-image', ['media' => $document->id]))->assertNotFound();
    $this->actingAs(User::factory()->create()->assignRole('contributor'))->get(route('media.edit-image', ['media' => $middle->id]))->assertForbidden();
});
