<?php

declare(strict_types=1);

use App\Domain\Redirects\Queries\SiteAnswers;
use App\Domain\Redirects\RedirectLookup;
use App\Domain\Redirects\SiteAnswer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Import\ImportRecords;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost']);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->admin = User::factory()->create()->assignRole('administrator');
    $source = ImportRecords::source();
    $run = ImportRecords::run($source);
    $this->media = mediaUpload($this->admin, mediaJpeg(), 'photo.jpg');
    ImportRecords::entry($source, $run, 'file', '2024/05/photo.jpg', 'created', ['teme_type' => 'media', 'teme_id' => $this->media->id, 'run_id' => null]);
});

it('answers an old WordPress file address of any size, edited copy or case with a 301 to the file (I-1851)', function (): void {
    $file = app(RedirectLookup::class)->oldFile('/wp-content/uploads/2024/05/photo.jpg');

    expect($file)->toBeString()
        ->and(addrGet('/wp-content/uploads/2024/05/photo.jpg')->assertStatus(301)->headers->get('Location'))->toBe($file)
        ->and(addrGet('/wp-content/uploads/2024/05/Photo-300x200.JPG')->assertStatus(301)->headers->get('Location'))->toBe($file)
        ->and(addrGet('/WP-Content/Uploads/2024/05/photo-scaled.jpg')->assertStatus(301)->headers->get('Location'))->toBe($file)
        ->and(app(SiteAnswers::class)->at('/wp-content/uploads/2024/05/photo-1024x768.jpg'))->toEqual(new SiteAnswer(301, $file, 'old file'));
});

it('answers nothing for a file no import brought, one in the trash, or any other address', function (): void {
    expect(addrGet('/wp-content/uploads/2024/05/other.jpg')->getStatusCode())->toBe(404)
        ->and(app(RedirectLookup::class)->oldFile('/wp-content/uploads/'))->toBeNull()
        ->and(app(RedirectLookup::class)->oldFile('/media/2024/05/photo.jpg'))->toBeNull();

    DB::table('media')->where('id', $this->media->id)->update(['deleted_at' => now()]);

    expect(addrGet('/wp-content/uploads/2024/05/photo.jpg')->getStatusCode())->toBe(404);
});

it('lets a person’s own redirect from such an address win', function (): void {
    rdSaved('/wp-content/uploads/2024/05/photo.jpg', '/gallery/');

    expect(addrLocation(addrGet('/wp-content/uploads/2024/05/photo.jpg')))->toBe('/gallery/');
});
