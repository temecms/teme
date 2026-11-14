<?php

declare(strict_types=1);

use App\Domain\Media\Exceptions\DuplicateMediaException;
use App\Domain\Media\Jobs\GenerateMediaVariants;
use App\Domain\Media\MediaCounts;
use App\Domain\Media\Models\Media;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    Queue::fake();
    $this->author = User::factory()->create()->assignRole('author');
});

function mediaLimits(array $values): void
{
    $admin = User::factory()->create()->assignRole('administrator');
    app()->instance(Elevation::class, new OperatorElevation);
    app(UpdateSettings::class)->execute(new UpdateSettingsInput('media', $values, [], [], 0, (string) $admin->id));
}

it('keeps a long Chinese file name and title whole, cut only between characters', function (): void {
    $stem = 'a'.str_repeat('猫', 300);

    $media = mediaUpload($this->author, mediaJpeg(), $stem.'.jpg');

    expect(mb_strlen($media->title))->toBe(255)
        ->and(mb_check_encoding($media->title, 'UTF-8'))->toBeTrue()
        ->and(mb_strlen($media->original_filename))->toBe(255)
        ->and(mb_check_encoding($media->original_filename, 'UTF-8'))->toBeTrue();
});

it('stores an image original privately and queues its variants', function (): void {
    $media = mediaUpload($this->author, mediaJpeg(1200, 800));

    expect($media->kind)->toBe('image')
        ->and($media->extension)->toBe('jpg')
        ->and($media->title)->toBe('Holiday')
        ->and([$media->width, $media->height])->toBe([1200, 800])
        ->and($media->processing)->toBe('pending')
        ->and($media->original_path)->toBe("{$media->id}/v1/holiday.jpg")
        ->and($media->uploaded_by)->toBe($this->author->id)
        ->and(MediaCounts::all())->toBe(['image' => ['live' => 1]]);

    Storage::disk('media-originals')->assertExists($media->original_path);
    Storage::disk('media')->assertMissing($media->original_path);
    Queue::assertPushed(GenerateMediaVariants::class, fn (GenerateMediaVariants $job): bool => $job->mediaId === $media->id && $job->fileVersion === 1);
});

it('stores a document publicly, ready, with nothing queued', function (): void {
    $media = mediaUpload($this->author, mediaPdf(), 'Annual report.pdf');

    expect($media->kind)->toBe('document')
        ->and($media->processing)->toBe('ready')
        ->and($media->original_path)->toEndWith('/v1/annual-report.pdf');

    Storage::disk('media')->assertExists($media->original_path);
    Queue::assertNothingPushed();
});

it('names the stored file from its content, not its uploaded name', function (): void {
    $media = mediaUpload($this->author, mediaJpeg(), 'photo.png');

    expect($media->extension)->toBe('jpg')
        ->and($media->original_path)->toEndWith('/photo.jpg');
});

it('accepts documents, keeping a name\'s extension only among those its content allows', function (): void {
    $csv = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-data.csv';
    file_put_contents($csv, "name,count\nboats,12\n");
    $page = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-page.html';
    file_put_contents($page, "plain words, no markup\n");

    expect(mediaUpload($this->author, $csv, 'Figures.csv')->original_path)->toEndWith('/figures.csv')
        ->and(mediaUpload($this->author, $page, 'page.html')->original_path)->toEndWith('/page.txt');
});

it('refuses a file whose content is not an accepted type', function (): void {
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-shell.jpg';
    file_put_contents($path, '<?php echo "hello";');
    $html = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-page.txt';
    file_put_contents($html, '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>');

    expect(fn () => mediaUpload($this->author, $path, 'shell.jpg'))->toThrow(ValidationException::class)
        ->and(fn () => mediaUpload($this->author, $html, 'notes.txt'))->toThrow(ValidationException::class)
        ->and(Media::query()->count())->toBe(0);
});

it('refuses an image format this server cannot read', function (): void {
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-photo.heic';
    file_put_contents($path, "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic".str_repeat("\x00", 64));

    expect(fn () => mediaUpload($this->author, $path, 'photo.heic'))->toThrow(ValidationException::class)
        ->and(Media::query()->count())->toBe(0);
});

it('refuses files over the size limit and images over the pixel limit', function (): void {
    // One call: the group's version is 0 only until its first save.
    mediaLimits(['max_megapixels' => 1, 'max_upload_mb' => 1]);

    expect(fn () => mediaUpload($this->author, mediaJpeg(1200, 900)))->toThrow(ValidationException::class);

    $big = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-big.pdf';
    file_put_contents($big, "%PDF-1.4\n".str_repeat('0', 1_100_000));

    expect(fn () => mediaUpload($this->author, $big, 'big.pdf'))->toThrow(ValidationException::class)
        ->and(Media::query()->count())->toBe(0);
});

it('refuses a duplicate unless asked to upload it anyway', function (): void {
    $path = mediaJpeg();
    $first = mediaUpload($this->author, $path);

    try {
        mediaUpload($this->author, $path);
        $this->fail('A duplicate was accepted.');
    } catch (DuplicateMediaException $duplicate) {
        expect($duplicate->existingId)->toBe($first->id);
    }

    expect(mediaUpload($this->author, $path, allowDuplicate: true)->id)->not->toBe($first->id);
});

it('writes nothing when the uploader may not upload', function (): void {
    $contributor = User::factory()->create()->assignRole('contributor');

    expect(fn () => mediaUpload($contributor, mediaJpeg()))->toThrow(AuthorizationException::class)
        ->and(Media::query()->count())->toBe(0)
        ->and(Storage::disk('media-originals')->allFiles())->toBe([]);
});

it('lets the operator add files from the command line', function (): void {
    $this->artisan('teme:media:add', ['paths' => [mediaJpeg()]])->assertSuccessful();

    expect(Media::query()->sole()->uploaded_by)->toBeNull();
});

it('refuses an image it can’t read, such as a damaged GIF, saying what to do, and writes nothing', function (): void {
    expect(fn () => mediaUpload($this->author, mediaDamagedGif(), 'Quick-Money1.gif'))
        ->toThrow(ValidationException::class, 'This file looks like an image but can’t be read. It may be damaged. Open it in an image editor, save it again and upload it.')
        ->and(Media::query()->count())->toBe(0);
});
