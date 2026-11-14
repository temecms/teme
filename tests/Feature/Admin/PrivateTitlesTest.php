<?php

declare(strict_types=1);

use App\Domain\Contents\Queries\PrivateTitles;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Contents\Visibilities;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->author = User::factory()->create()->assignRole('author');
    $this->other = User::factory()->create()->assignRole('author');
    $this->contributor = User::factory()->create()->assignRole('contributor');
    $this->item = Visibilities::change(cmItem($this->author, 'Staff notes'), 'private', $this->author);
});

it('shows a private item\'s title only to whoever may edit it, and “A private item” to anyone else (I-1700)', function (string $who, string $seen): void {
    $viewer = match ($who) {
        'an editor' => $this->editor,
        'its author' => $this->author,
        'another author' => $this->other,
        'a contributor' => $this->contributor,
        'nobody' => null,
    };

    expect(PrivateTitles::of($this->item->id, 'Staff notes', $viewer))->toBe($seen);
})->with([
    ['an editor', 'Staff notes'],
    ['its author', 'Staff notes'],
    ['another author', 'A private item'],
    ['a contributor', 'A private item'],
    ['nobody', 'A private item'],
]);

it('leaves a public or protected item\'s title as it is, for anyone (I-1700)', function (): void {
    $protected = Visibilities::change(cmItem($this->author, 'Members'), 'protected', $this->author, 'Harbour 2024');

    expect(PrivateTitles::of(cmItem($this->author, 'Harbour news')->id, 'Harbour news', $this->other))->toBe('Harbour news')
        ->and(PrivateTitles::of($protected->id, 'Members', $this->other))->toBe('Members');
});

it('says “A private item” in a file\'s Used in to someone who may not edit it, and its title to someone who may (I-1700)', function (): void {
    $media = mediaUpload($this->admin, mediaJpeg());
    DB::table('media_usages')->insert(['content_id' => $this->item->id, 'media_id' => $media->id, 'scope' => 'working', 'ref' => 'featured']);

    $this->actingAs($this->other)->getJson(route('media.usages', ['media' => $media->id]))
        ->assertOk()
        ->assertJsonPath('rows.0.title', 'A private item');
    $this->actingAs($this->author)->getJson(route('media.usages', ['media' => $media->id]))
        ->assertOk()
        ->assertJsonPath('rows.0.title', 'Staff notes');
});
