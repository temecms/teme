<?php

declare(strict_types=1);

use App\Domain\Media\Models\Media;
use App\Domain\Media\Operations\Input\UpdateMediaDetailsInput;
use App\Domain\Media\Operations\UpdateMediaDetails;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->author = User::factory()->create()->assignRole('author');

    // An image the library credits, through the real Operation.
    $this->credited = function (string $credit): Media {
        $media = pageImage($this->author, 800, 600);
        app(UpdateMediaDetails::class)->execute(new UpdateMediaDetailsInput($media->id, (string) $this->author->id, $media->title, $media->alt, $media->caption, $credit));

        return $media->refresh();
    };
});

it('gives the editor each image\'s credit with the media it opens on', function (): void {
    $featured = ($this->credited)('Photo: Ada Okafor');
    $content = lifecycleDraft($this->author, 'Harbour festival');
    docSave($content, $this->author, docWith(docText('Three days of music.')), refsFeatured($featured));

    $this->actingAs($this->author)->get("/admin/content/{$content->id}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where("session.media.{$featured->id}.credit", 'Photo: Ada Okafor'));
});

it('gives an image chosen later its credit through the card media the editor asks for, and null for one with none', function (): void {
    $credited = ($this->credited)('Photo: Ada Okafor');
    $plain = pageImage($this->author, 640, 480);

    $this->actingAs($this->author)
        ->getJson('/admin/content/card-media?'.http_build_query(['ids' => [$credited->id, $plain->id]]))
        ->assertOk()
        ->assertJsonPath("{$credited->id}.credit", 'Photo: Ada Okafor')
        ->assertJson([$plain->id => ['credit' => null]]);
});

it('leaves the server renderer\'s media lookup as it was, without the credit', function (): void {
    expect((string) file_get_contents(app_path('Domain/Cards/CardMedia.php')))->not->toContain('credit');
});

it('gives the editor each image\'s licence beside its credit, and null where there is none (I-236)', function (?string $credit, ?string $licence): void {
    $media = pageImage($this->author, 800, 600);
    app(UpdateMediaDetails::class)->execute(new UpdateMediaDetailsInput($media->id, (string) $this->author->id, $media->title, $media->alt, $media->caption, $credit, licence: $licence));
    $content = lifecycleDraft($this->author, 'Harbour festival');
    docSave($content, $this->author, docWith(docText('Three days of music.')), refsFeatured($media));

    $this->actingAs($this->author)->get("/admin/content/{$content->id}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where("session.media.{$media->id}.credit", $credit)
            ->where("session.media.{$media->id}.licence", $licence));

    $this->actingAs($this->author)
        ->getJson('/admin/content/card-media?'.http_build_query(['ids' => [$media->id]]))
        ->assertOk()
        ->assertJson([$media->id => ['credit' => $credit, 'licence' => $licence]]);
})->with([
    'a credit and a licence' => ['Photo: Ada Okafor', 'CC BY 4.0'],
    'a licence alone' => [null, 'CC BY 4.0'],
    'neither' => [null, null],
]);
