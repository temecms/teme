<?php

declare(strict_types=1);

use App\Domain\Import\Models\ImportUpload;
use App\Domain\Import\Operations\AttachUploadsCopy;
use App\Domain\Import\Operations\Input\AttachUploadsCopyInput;
use App\Domain\Import\Uploads\SourceUploads;
use App\Domain\Import\Uploads\UploadsCopy;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    importFake();
    $this->source = importSource();
    $this->folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-copy-test-'.Str::random(10);
    mkdir($this->folder.'/2024/05', recursive: true);
    file_put_contents($this->folder.'/2024/05/Harbour One.jpg', dlJpeg(640, 480));
});

afterEach(function (): void {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->folder, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }

    rmdir($this->folder);
});

it('reads a picture by its path under uploads/, and refuses any path that could leave the copy', function (): void {
    expect(UploadsCopy::relativePath('https://old.example/wp-content/uploads/2024/05/Harbour%20One.jpg'))->toBe('2024/05/Harbour One.jpg')
        ->and(UploadsCopy::relativePath('https://old.example/wp-content/uploads/../../secrets.txt'))->toBeNull()
        ->and(UploadsCopy::relativePath('https://old.example/wp-content/uploads/2024/%2e%2e/%2e%2e/secrets.txt'))->toBeNull()
        ->and(UploadsCopy::relativePath('https://old.example/wp-content/uploads/2024//a.jpg'))->toBeNull()
        ->and(UploadsCopy::relativePath('https://old.example/wp-content/uploads/2024\\a.jpg'))->toBeNull()
        ->and(UploadsCopy::relativePath('https://old.example/about/'))->toBeNull();
});

it('copies a picture out of a folder into a temporary file, and never deletes the person’s own file', function (): void {
    $copy = new UploadsCopy(UploadsCopy::FOLDER, $this->folder);
    $file = $copy->download('https://old.example/wp-content/uploads/2024/05/Harbour%20One.jpg', 1024 * 1024, sys_get_temp_dir());

    expect($file)->not->toBeNull()
        ->and($file->path)->not->toBe($this->folder.'/2024/05/Harbour One.jpg')
        ->and($file->name)->toBe('Harbour One.jpg')
        ->and($file->kind)->toBe('image');

    $file->release();

    expect(is_file($this->folder.'/2024/05/Harbour One.jpg'))->toBeTrue();
});

it('reads a picture out of a zip entry by entry, and lacks what the zip lacks', function (): void {
    $zipPath = $this->folder.'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('uploads/2024/05/Harbour One.jpg', dlJpeg(640, 480));
    $zip->close();

    $copy = new UploadsCopy(UploadsCopy::ZIP, $zipPath, 'uploads/');

    expect($copy->download('https://old.example/wp-content/uploads/2024/05/Harbour%20One.jpg', 1024 * 1024, sys_get_temp_dir()))->not->toBeNull()
        ->and($copy->download('https://old.example/wp-content/uploads/2024/05/Missing.jpg', 1024 * 1024, sys_get_temp_dir()))->toBeNull();

    unlink($zipPath);
});

it('attaches a copy to the site, and refuses one the server can’t read', function (): void {
    $attached = scElevated(fn (): array => app(AttachUploadsCopy::class)->execute(new AttachUploadsCopyInput($this->source->id, $this->folder, null)));

    expect($attached['kind'])->toBe(UploadsCopy::FOLDER)
        ->and(ImportUpload::query()->where('source_id', $this->source->id)->value('path'))->toBe(realpath($this->folder))
        ->and(fn () => scElevated(fn (): array => app(AttachUploadsCopy::class)->execute(new AttachUploadsCopyInput($this->source->id, $this->folder.'/missing', null))))
        ->toThrow(ValidationException::class);
});

it('writes no copy when an editor attaches one', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $before = DB::table('import_uploads')->count();

    expect(fn () => app(AttachUploadsCopy::class)->execute(new AttachUploadsCopyInput($this->source->id, $this->folder, (string) $editor->id)))
        ->toThrow(AuthorizationException::class, 'Not permitted to import a site.')
        ->and(DB::table('import_uploads')->count())->toBe($before);
});

it('brings a picture the copy has without asking the site, and asks the site for one it lacks', function (): void {
    scElevated(fn (): array => app(AttachUploadsCopy::class)->execute(new AttachUploadsCopyInput($this->source->id, $this->folder, null)));

    $sourceUploads = app(SourceUploads::class);
    $copied = $sourceUploads->download($this->source->id, 'https://old.example/wp-content/uploads/2024/05/Harbour%20One.jpg', 1024 * 1024);
    $missing = $sourceUploads->download($this->source->id, 'https://old.example/wp-content/uploads/2024/05/Gone.jpg', 1024 * 1024);
    $otherHost = $sourceUploads->download($this->source->id, 'https://elsewhere.example/wp-content/uploads/2024/05/Harbour%20One.jpg', 1024 * 1024);

    expect($copied)->not->toBeNull()
        ->and($missing)->toBeNull()
        ->and($otherHost)->toBeNull();

    $copied?->release();
});

it('refuses to attach the application, its storage or public folder, or any folder above them', function (): void {
    $this->source = importSource();

    foreach ([base_path(), storage_path(), storage_path('app'), public_path(), dirname(base_path())] as $path) {
        expect(fn () => scElevated(fn (): array => app(AttachUploadsCopy::class)->execute(new AttachUploadsCopyInput($this->source->id, $path, null))))
            ->toThrow(ValidationException::class);
    }

    expect(ImportUpload::query()->where('source_id', $this->source->id)->exists())->toBeFalse();
});

it('sees a folder inside or above the application whichever separator its path uses', function (): void {
    $inside = realpath(storage_path('app')) ?: storage_path('app');

    expect(UploadsCopy::reachesApplication($inside))->toBeTrue()
        ->and(UploadsCopy::reachesApplication(str_replace('\\', '/', $inside)))->toBeTrue()
        ->and(UploadsCopy::reachesApplication(dirname(base_path())))->toBeTrue()
        ->and(UploadsCopy::reachesApplication($this->folder))->toBeFalse();
});

it('refuses a folder that is neither an uploads folder nor holds one, and writes no copy', function (): void {
    $broad = $this->folder.DIRECTORY_SEPARATOR.'site-root';
    mkdir($broad.DIRECTORY_SEPARATOR.'logs', recursive: true);
    file_put_contents($broad.DIRECTORY_SEPARATOR.'backup.sql', 'INSERT INTO users VALUES (1);');

    expect(fn () => scElevated(fn (): array => app(AttachUploadsCopy::class)->execute(new AttachUploadsCopyInput($this->source->id, $broad, null))))
        ->toThrow(ValidationException::class, 'That folder doesn’t look like an uploads folder')
        ->and(ImportUpload::query()->where('source_id', $this->source->id)->exists())->toBeFalse()
        ->and(UploadsCopy::prefixOfFolder($this->folder))->toBe('')
        ->and(UploadsCopy::prefixOfFolder($broad))->toBeNull();

    mkdir($broad.DIRECTORY_SEPARATOR.'uploads');

    expect(UploadsCopy::prefixOfFolder($broad))->toBe('uploads/');
});

it('never reads a dot-file or a dot-folder from a copy', function (): void {
    expect(UploadsCopy::safe('2024/.env'))->toBeNull()
        ->and(UploadsCopy::safe('.htaccess'))->toBeNull()
        ->and(UploadsCopy::safe('.hidden/a.jpg'))->toBeNull()
        ->and(UploadsCopy::safe('2024/05/a.jpg'))->toBe('2024/05/a.jpg');
});

it('never brings a protected plugin’s folder from a copy, and says why', function (): void {
    $folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-protected-'.uniqid();
    mkdir($folder.'/woocommerce_uploads', recursive: true);
    file_put_contents($folder.'/woocommerce_uploads/Harbour One.jpg', dlJpeg(640, 480));
    $copy = new UploadsCopy(UploadsCopy::FOLDER, $folder);

    expect($copy->download('https://old.example/wp-content/uploads/woocommerce_uploads/Harbour%20One.jpg', 1024 * 1024, sys_get_temp_dir()))->toBeNull()
        ->and(UploadsCopy::protectedReason('https://old.example/wp-content/uploads/woocommerce_uploads/a.jpg'))->toContain('protected plugin')
        ->and(UploadsCopy::protectedReason('https://old.example/wp-content/uploads/2024/a.jpg'))->toBeNull();

    unlink($folder.'/woocommerce_uploads/Harbour One.jpg');
    rmdir($folder.'/woocommerce_uploads');
    rmdir($folder);
});

it('refuses a symlink in a folder copy that leads out of the copy', function (): void {
    $outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-outside-'.uniqid().'.jpg';
    file_put_contents($outside, dlJpeg(640, 480));
    symlink($outside, $this->folder.'/2024/05/Link Out.jpg');

    $copy = new UploadsCopy(UploadsCopy::FOLDER, $this->folder);

    expect($copy->download('https://old.example/wp-content/uploads/2024/05/Link%20Out.jpg', 1024 * 1024, sys_get_temp_dir()))->toBeNull();

    unlink($this->folder.'/2024/05/Link Out.jpg');
    unlink($outside);
});

it('refuses a picture bigger than the limit, from a folder and from a zip entry', function (): void {
    file_put_contents($this->folder.'/2024/05/Big One.jpg', dlJpeg(640, 480));
    $folder = new UploadsCopy(UploadsCopy::FOLDER, $this->folder);

    expect($folder->download('https://old.example/wp-content/uploads/2024/05/Big%20One.jpg', 10, sys_get_temp_dir()))->toBeNull();

    $zipPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-big-'.uniqid().'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('uploads/2024/05/Big One.jpg', dlJpeg(640, 480));
    $zip->close();

    $archive = new ZipArchive;
    $archive->open($zipPath);
    $fromZip = new UploadsCopy(UploadsCopy::ZIP, $zipPath);

    expect($fromZip->download('https://old.example/wp-content/uploads/2024/05/Big%20One.jpg', 10, sys_get_temp_dir(), $archive))->toBeNull();

    $archive->close();
    unlink($zipPath);
    unlink($this->folder.'/2024/05/Big One.jpg');
});

it('counts the files a folder copy leaves out, by the protected folder each is in', function (): void {
    $folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-left-'.uniqid();
    mkdir($folder.'/uploads/woocommerce_uploads', recursive: true);
    mkdir($folder.'/uploads/gravity_forms');
    mkdir($folder.'/uploads/2024/05', recursive: true);
    file_put_contents($folder.'/uploads/woocommerce_uploads/a.jpg', 'a');
    file_put_contents($folder.'/uploads/woocommerce_uploads/b.jpg', 'b');
    file_put_contents($folder.'/uploads/gravity_forms/c.jpg', 'c');
    file_put_contents($folder.'/uploads/2024/05/Harbour One.jpg', dlJpeg(640, 480));

    $copy = new UploadsCopy(UploadsCopy::FOLDER, $folder, 'uploads/');

    expect($copy->leftOut())->toBe(['files' => 3, 'folders' => ['gravity_forms' => 1, 'woocommerce_uploads' => 2]]);

    foreach (['woocommerce_uploads/a.jpg', 'woocommerce_uploads/b.jpg', 'gravity_forms/c.jpg', '2024/05/Harbour One.jpg'] as $file) {
        unlink($folder.'/uploads/'.$file);
    }
    rmdir($folder.'/uploads/woocommerce_uploads');
    rmdir($folder.'/uploads/gravity_forms');
    rmdir($folder.'/uploads/2024/05');
    rmdir($folder.'/uploads/2024');
    rmdir($folder.'/uploads');
    rmdir($folder);
});
