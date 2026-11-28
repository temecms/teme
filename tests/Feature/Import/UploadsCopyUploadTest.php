<?php

declare(strict_types=1);

use App\Domain\Import\Models\ImportUpload;
use App\Domain\Import\Operations\AttachUploadsCopy;
use App\Domain\Import\Operations\CancelUploadsCopy;
use App\Domain\Import\Operations\FinishUploadsCopy;
use App\Domain\Import\Operations\Input\AttachUploadsCopyInput;
use App\Domain\Import\Operations\Input\ReceiveUploadsCopyPieceInput;
use App\Domain\Import\Operations\Input\StartUploadsCopyInput;
use App\Domain\Import\Operations\Input\UploadsCopyUploadInput;
use App\Domain\Import\Operations\ReceiveUploadsCopyPiece;
use App\Domain\Import\Operations\StartUploadsCopy;
use App\Domain\Import\Queries\ImportUploadCopies;
use App\Domain\Import\Uploads\SourceUploads;
use App\Domain\Import\Uploads\UploadsCopy;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');
    importFake();
    $this->source = importSource();
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');
});

/** A zip of a picture at uploads/2024/05/Harbour One.jpg, as bytes. */
function copyZipBytes(): string
{
    $path = tempnam(sys_get_temp_dir(), 'teme-zip-test-');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('uploads/2024/05/Harbour One.jpg', dlJpeg(640, 480));
    $zip->close();
    $bytes = (string) file_get_contents($path);
    unlink($path);

    return $bytes;
}

/** Uploads these bytes in pieces of $pieceSize as the admin, and returns the upload's ID. */
function uploadCopy(string $sourceId, string $bytes, string $actor, int $pieceSize = 1024): string
{
    $started = app(StartUploadsCopy::class)->execute(new StartUploadsCopyInput($sourceId, 'uploads.zip', strlen($bytes), $actor));

    for ($offset = 0; $offset < strlen($bytes); $offset += $pieceSize) {
        app(ReceiveUploadsCopyPiece::class)->execute(new ReceiveUploadsCopyPieceInput($started['uploadId'], $offset, substr($bytes, $offset, $pieceSize), $actor));
    }

    return $started['uploadId'];
}

it('uploads a copy in pieces and makes it the site’s copy once it is complete and a zip', function (): void {
    $bytes = copyZipBytes();
    $uploadId = uploadCopy($this->source->id, $bytes, (string) $this->admin->id);

    expect(ImportUpload::query()->whereKey($uploadId)->value('state'))->toBe('uploading');
    app(FinishUploadsCopy::class)->execute(new UploadsCopyUploadInput($uploadId, (string) $this->admin->id));

    expect(ImportUpload::query()->where('source_id', $this->source->id)->where('state', 'ready')->value('id'))->toBe($uploadId)
        ->and(file_get_contents((string) ImportUpload::query()->whereKey($uploadId)->value('path')))->toBe($bytes)
        ->and(app(ImportUploadCopies::class)->forSource($this->source->id)['name'])->toBe('uploads.zip');
});

it('refuses a piece past the end, a short copy and a file that isn’t a zip, leaving no ready copy', function (): void {
    $bytes = copyZipBytes();
    $started = app(StartUploadsCopy::class)->execute(new StartUploadsCopyInput($this->source->id, 'uploads.zip', strlen($bytes), (string) $this->admin->id));

    expect(fn () => app(ReceiveUploadsCopyPiece::class)->execute(new ReceiveUploadsCopyPieceInput($started['uploadId'], 0, str_repeat('x', strlen($bytes) + 1), (string) $this->admin->id)))
        ->toThrow(ValidationException::class);

    expect(fn () => app(FinishUploadsCopy::class)->execute(new UploadsCopyUploadInput($started['uploadId'], (string) $this->admin->id)))
        ->toThrow(ValidationException::class, 'The upload isn’t complete yet. Send the rest of the file.');

    $notZip = 'not a zip at all';
    $other = app(StartUploadsCopy::class)->execute(new StartUploadsCopyInput($this->source->id, 'bad.zip', strlen($notZip), (string) $this->admin->id));
    app(ReceiveUploadsCopyPiece::class)->execute(new ReceiveUploadsCopyPieceInput($other['uploadId'], 0, $notZip, (string) $this->admin->id));

    $answer = app(FinishUploadsCopy::class)->execute(new UploadsCopyUploadInput($other['uploadId'], (string) $this->admin->id));

    expect($answer['refused'])->toContain('isn’t a zip');

    expect(ImportUpload::query()->whereKey($other['uploadId'])->exists())->toBeFalse()
        ->and(app(ImportUploadCopies::class)->forSource($this->source->id))->toBeNull();
});

it('replaces the copy a site had, deleting the file Teme kept and never a folder the operator named', function (): void {
    $first = uploadCopy($this->source->id, copyZipBytes(), (string) $this->admin->id);
    app(FinishUploadsCopy::class)->execute(new UploadsCopyUploadInput($first, (string) $this->admin->id));
    $firstPath = (string) ImportUpload::query()->whereKey($first)->value('path');

    $second = uploadCopy($this->source->id, copyZipBytes(), (string) $this->admin->id);
    app(FinishUploadsCopy::class)->execute(new UploadsCopyUploadInput($second, (string) $this->admin->id));

    expect(is_file($firstPath))->toBeFalse()
        ->and(ImportUpload::query()->where('source_id', $this->source->id)->where('state', 'ready')->count())->toBe(1);

    $folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-operator-copy-'.uniqid();
    mkdir($folder.'/2024', recursive: true);
    file_put_contents($folder.'/2024/Harbour One.jpg', dlJpeg(640, 480));
    scElevated(fn (): array => app(AttachUploadsCopy::class)->execute(new AttachUploadsCopyInput($this->source->id, $folder, null)));

    expect(is_dir($folder))->toBeTrue();

    unlink($folder.'/2024/Harbour One.jpg');
    rmdir($folder.'/2024');
    rmdir($folder);
});

it('deletes the file of a cancelled upload and leaves the ready copy alone', function (): void {
    $ready = uploadCopy($this->source->id, copyZipBytes(), (string) $this->admin->id);
    app(FinishUploadsCopy::class)->execute(new UploadsCopyUploadInput($ready, (string) $this->admin->id));
    $readyPath = (string) ImportUpload::query()->whereKey($ready)->value('path');

    $started = app(StartUploadsCopy::class)->execute(new StartUploadsCopyInput($this->source->id, 'more.zip', 10, (string) $this->admin->id));
    app(ReceiveUploadsCopyPiece::class)->execute(new ReceiveUploadsCopyPieceInput($started['uploadId'], 0, '0123456789', (string) $this->admin->id));
    $unfinishedPath = (string) ImportUpload::query()->whereKey($started['uploadId'])->value('path');

    app(CancelUploadsCopy::class)->execute(new UploadsCopyUploadInput($started['uploadId'], (string) $this->admin->id));

    expect(is_file($unfinishedPath))->toBeFalse()
        ->and(is_file($readyPath))->toBeTrue()
        ->and(ImportUpload::query()->where('source_id', $this->source->id)->where('state', 'ready')->exists())->toBeTrue();
});

it('writes no copy when an editor uploads', function (): void {
    $before = DB::table('import_uploads')->count();

    expect(fn () => app(StartUploadsCopy::class)->execute(new StartUploadsCopyInput($this->source->id, 'uploads.zip', 10, (string) $this->editor->id)))
        ->toThrow(AuthorizationException::class, 'Not permitted to import a site.')
        ->and(DB::table('import_uploads')->count())->toBe($before);

    $ready = uploadCopy($this->source->id, copyZipBytes(), (string) $this->admin->id);
    app(FinishUploadsCopy::class)->execute(new UploadsCopyUploadInput($ready, (string) $this->admin->id));

});

it('asks the site for nothing the copy has, through the HTTP routes as an administrator', function (): void {
    $this->actingAs($this->admin)
        ->postJson("/admin/tools/import/{$this->source->id}/uploads/start", ['name' => 'uploads.zip', 'size' => 10])
        ->assertOk()
        ->assertJsonStructure(['fileId', 'receivedBytes', 'sizeBytes']);

    $this->actingAs($this->editor)
        ->postJson("/admin/tools/import/{$this->source->id}/uploads/start", ['name' => 'uploads.zip', 'size' => 10])
        ->assertForbidden();

});

it('finds the uploads folder inside a zip whose top folder is another, and refuses a zip with none', function (): void {
    $zipPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-prefix-'.uniqid().'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('site-backup/uploads/2024/05/Harbour One.jpg', dlJpeg(640, 480));
    $zip->close();
    $bytes = (string) file_get_contents($zipPath);
    $uploadId = uploadCopy($this->source->id, $bytes, (string) $this->admin->id, 4096);

    expect(app(FinishUploadsCopy::class)->execute(new UploadsCopyUploadInput($uploadId, (string) $this->admin->id))['refused'])->toBeNull()
        ->and(ImportUpload::query()->whereKey($uploadId)->value('prefix'))->toBe('site-backup/uploads/')
        ->and(app(SourceUploads::class)->downloadFile($this->source->id, '2024/05/Harbour One.jpg', 'https://old.example/wp-content/uploads/2024/05/Harbour%20One.jpg', 1024 * 1024))->not->toBeNull();

    $empty = new ZipArchive;
    $empty->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $empty->addFromString('readme.txt', 'nothing here');
    $empty->close();
    $bytes = (string) file_get_contents($zipPath);
    $noUploads = uploadCopy($this->source->id, $bytes, (string) $this->admin->id, 4096);

    expect(app(FinishUploadsCopy::class)->execute(new UploadsCopyUploadInput($noUploads, (string) $this->admin->id))['refused'])->toContain('uploads folder');

    unlink($zipPath);
});

it('refuses a zip that holds more entries than the copy may', function (): void {
    expect(UploadsCopy::holdsTooMany(UploadsCopy::MOST_ENTRIES + 1))->toBeTrue()
        ->and(UploadsCopy::holdsTooMany(UploadsCopy::MOST_ENTRIES))->toBeFalse();
});
