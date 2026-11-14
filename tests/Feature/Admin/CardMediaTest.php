<?php

declare(strict_types=1);

use App\Domain\Contents\Queries\EditorSession;
use App\Domain\Fields\BuiltInFields;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
});

it('gives a contributor the media a card needs, in the card shape', function (): void {
    $author = User::factory()->create()->assignRole('author');
    $media = mediaUpload($author, mediaJpeg(1200, 800), 'harbour.jpg');
    $contributor = User::factory()->create()->assignRole('contributor');

    $this->actingAs($contributor)
        ->getJson('/admin/content/card-media?'.http_build_query(['ids' => [$media->id]]))
        ->assertOk()
        ->assertJsonStructure([$media->id => ['variants', 'width', 'height', 'alt', 'title']])
        ->assertJsonPath("{$media->id}.width", $media->refresh()->width);
});

it('refuses the card media to someone who may not write', function (): void {
    $this->actingAs(User::factory()->create())
        ->getJson('/admin/content/card-media?'.http_build_query(['ids' => ['01K5Z9P2QH7N3X4C8V6B0M1T2A']]))
        ->assertForbidden();
});

it('opens the editor with the media its cards and its featured image show', function (): void {
    $author = User::factory()->create()->assignRole('author');
    $inBody = mediaUpload($author, mediaJpeg(1200, 800), 'harbour.jpg');
    $featured = mediaUpload($author, mediaJpeg(800, 600), 'quay.jpg');
    $content = lifecycleDraft($author, 'Harbour festival');
    docSave($content, $author, docWith(['type' => 'image', 'attrs' => ['id' => str_repeat('A', 26), 'mediaId' => $inBody->id]]), [
        BuiltInFields::FEATURED_IMAGE => ['id' => $featured->id, 'alt' => '', 'decorative' => false],
    ]);

    $session = EditorSession::for($content->id, (string) $author->id);

    expect(array_keys($session->media))->toEqualCanonicalizing([$inBody->id, $featured->id])
        ->and($session->media[$inBody->id]['variants'])->each->toHaveKey('url');
});
