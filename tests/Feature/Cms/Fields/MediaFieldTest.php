<?php

declare(strict_types=1);

use App\Domain\Fields\BuiltInFields;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->author = User::factory()->create()->assignRole('author');
});

it('stores a featured image by ID, with its per-use alt text', function (): void {
    $image = mediaUpload($this->author, mediaJpeg());

    $content = refsDraft($this->author, [BuiltInFields::FEATURED_IMAGE => ['id' => $image->id, 'alt' => '  Boats  ', 'decorative' => '0']]);

    expect($content->workingRevision->fields[BuiltInFields::FEATURED_IMAGE])->toBe(['id' => $image->id, 'alt' => 'Boats', 'decorative' => false]);
});

it('refuses the decorative mark on the featured image', function (): void {
    $image = mediaUpload($this->author, mediaJpeg());

    expect(fn () => refsDraft($this->author, [BuiltInFields::FEATURED_IMAGE => ['id' => $image->id, 'alt' => null, 'decorative' => true]]))
        ->toThrow(ValidationException::class);
});

it('stores an empty choice as no image', function (): void {
    $content = refsDraft($this->author, [BuiltInFields::FEATURED_IMAGE => ['id' => '', 'alt' => '', 'decorative' => '0']]);

    expect($content->workingRevision->fields[BuiltInFields::FEATURED_IMAGE])->toBeNull();
});

it('refuses an ID that is not an image in the library', function (mixed $value): void {
    expect(fn () => refsDraft($this->author, [BuiltInFields::FEATURED_IMAGE => $value]))->toThrow(ValidationException::class);
})->with([
    'unknown ID' => [['id' => '01K5Z0F1ED00000000000000Z9', 'alt' => null, 'decorative' => false]],
    'not an ID' => [['id' => 'https://example.com/a.jpg', 'alt' => null, 'decorative' => false]],
    'a bare string' => ['01K5Z0F1ED00000000000000Z9'],
]);

it('refuses a document where an image is expected', function (): void {
    $pdf = mediaUpload($this->author, mediaPdf(), 'report.pdf');

    expect(fn () => refsDraft($this->author, [BuiltInFields::FEATURED_IMAGE => ['id' => $pdf->id, 'alt' => null, 'decorative' => false]]))
        ->toThrow(ValidationException::class);
});
