<?php

declare(strict_types=1);

use App\Domain\Import\File\Jobs\ReadImportFile;
use App\Domain\Import\Models\ImportFile;
use App\Domain\Import\Operations\CancelImportUpload;
use App\Domain\Import\Operations\DeleteImportFile;
use App\Domain\Import\Operations\FinishImportUpload;
use App\Domain\Import\Operations\Input\CancelImportUploadInput;
use App\Domain\Import\Operations\Input\DeleteImportFileInput;
use App\Domain\Import\Operations\Input\FinishImportUploadInput;
use App\Domain\Import\Operations\Input\ReceiveImportPieceInput;
use App\Domain\Import\Operations\Input\StartImportUploadInput;
use App\Domain\Import\Operations\ReceiveImportPiece;
use App\Domain\Import\Operations\StartImportUpload;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');
    Storage::disk('local')->deleteDirectory('imports');
    Storage::disk('local')->makeDirectory('imports');

    $this->start = fn (?User $actor, string $name = 'site.xml', int $size = 100): array => app(StartImportUpload::class)
        ->execute(new StartImportUploadInput($name, $size, $actor === null ? null : (string) $actor->id));

    $this->receive = fn (string $fileId, int $offset, string $bytes, ?User $actor = null): array => app(ReceiveImportPiece::class)
        ->execute(new ReceiveImportPieceInput($fileId, $offset, $bytes, $actor === null ? (string) $this->admin->id : (string) $actor->id));

    $this->finish = fn (string $fileId, ?User $actor = null): array => app(FinishImportUpload::class)
        ->execute(new FinishImportUploadInput($fileId, $actor === null ? (string) $this->admin->id : (string) $actor->id));

    $this->wxr = fn (string $version = '1.2'): string => <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
	xmlns:excerpt="http://wordpress.org/export/{$version}/excerpt/"
	xmlns:content="http://purl.org/rss/1.0/modules/content/"
	xmlns:wfw="http://wellformedweb.org/CommentAPI/"
	xmlns:dc="http://purl.org/dc/elements/1.1/"
	xmlns:wp="http://wordpress.org/export/{$version}/">
<channel>
	<title>Example</title>
	<wp:wxr_version>{$version}</wp:wxr_version>
</channel>
</rss>
XML;
});

afterEach(function (): void {
    Storage::disk('local')->deleteDirectory('imports');
});

it('starts an upload and carries on the same unfinished one within a day', function (): void {
    $first = ($this->start)($this->admin, 'blog.xml', 500);
    $again = ($this->start)($this->admin, 'blog.xml', 500);

    expect($again['fileId'])->toBe($first['fileId'])
        ->and($again['carriedOn'])->toBeTrue()
        ->and($first['carriedOn'])->toBeFalse()
        ->and(ImportFile::query()->count())->toBe(1)
        ->and(ImportFile::query()->sole()->state)->toBe('uploading');
});

it('refuses a file over the size limit before any byte is stored', function (): void {
    config(['teme.import.max_file_mb' => 1024]);
    $limit = 1024 * 1024 * 1024;
    $before = Storage::disk('local')->allFiles('imports');

    expect(fn () => ($this->start)($this->admin, 'huge.xml', $limit + 1))
        ->toThrow(function (ValidationException $refusal): void {
            expect($refusal->errors()['size'][0] ?? null)->toBe(
                'This file is larger than 1 GB. Export a part of the site at a time: WordPress’s Tools › Export can export one type, or a range of dates.',
            );
        });

    expect(ImportFile::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('imports'))->toBe($before);
});

it('refuses a name that is not an xml export', function (): void {
    expect(fn () => ($this->start)($this->admin, 'site.zip', 100))
        ->toThrow(ValidationException::class);
    expect(ImportFile::query()->count())->toBe(0);
});

it('writes a piece, and a retry at an earlier offset leaves the file identical to one sent once', function (): void {
    $started = ($this->start)($this->admin, 'once.xml', 12);
    ($this->receive)($started['fileId'], 0, 'Hello ');
    ($this->receive)($started['fileId'], 6, 'world!');

    $path = ImportFile::query()->findOrFail($started['fileId'])->path();
    $once = Storage::disk('local')->get($path);

    $retry = ($this->start)($this->admin, 'retry.xml', 12);
    ($this->receive)($retry['fileId'], 0, 'Hello ');
    ($this->receive)($retry['fileId'], 6, 'XXXXXX');
    ($this->receive)($retry['fileId'], 6, 'world!');

    expect(Storage::disk('local')->get(ImportFile::query()->findOrFail($retry['fileId'])->path()))->toBe($once)
        ->and($once)->toBe('Hello world!')
        ->and(ImportFile::query()->findOrFail($retry['fileId'])->received_bytes)->toBe(12);
});

it('refuses a piece past the bytes already received, naming the offset it wants', function (): void {
    $started = ($this->start)($this->admin, 'gap.xml', 20);
    ($this->receive)($started['fileId'], 0, '0123456789');

    expect(fn () => ($this->receive)($started['fileId'], 15, 'xxxxx'))
        ->toThrow(function (ValidationException $refusal): void {
            expect($refusal->errors()['offset'][0] ?? null)->toBe('Send the next piece at byte 10.');
        });

    expect(ImportFile::query()->findOrFail($started['fileId'])->received_bytes)->toBe(10);
});

it('serializes two pieces at once under the row’s lock', function (): void {
    $started = ($this->start)($this->admin, 'lock.xml', 10);

    DB::flushQueryLog();
    DB::enableQueryLog();
    ($this->receive)($started['fileId'], 0, '0123456789');
    $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");
    DB::disableQueryLog();

    expect($sql)->toContain('for update')
        ->and(ImportFile::query()->findOrFail($started['fileId'])->received_bytes)->toBe(10)
        ->and(Storage::disk('local')->get(ImportFile::query()->findOrFail($started['fileId'])->path()))->toBe('0123456789');
});

it('finishes a complete upload, refuses a DOCTYPE, and dispatches the reading job', function (): void {
    Queue::fake();
    $body = ($this->wxr)();
    $started = ($this->start)($this->admin, 'good.xml', strlen($body));
    ($this->receive)($started['fileId'], 0, $body);

    $done = ($this->finish)($started['fileId']);
    $file = ImportFile::query()->findOrFail($started['fileId']);

    expect($done['fileId'])->toBe($started['fileId'])
        ->and($done['refused'] ?? false)->toBeFalse()
        ->and($file->state)->toBe('reading')
        ->and($file->sha256)->toBe(hash('sha256', $body))
        ->and($file->refused)->toBeNull();

    Queue::assertPushed(ReadImportFile::class, fn (ReadImportFile $job): bool => $job->fileId === $started['fileId']
        && $job->actor->userId === (string) $this->admin->id);

    $bad = ($this->start)($this->admin, 'bad.xml', 80);
    $doctype = "<!DOCTYPE rss [<!ENTITY xxe SYSTEM \"file:///etc/passwd\">]>\n<rss xmlns:wp=\"http://wordpress.org/export/1.2/\"></rss>";
    // Pad/trim to declared size.
    $doctype = str_pad(substr($doctype, 0, 80), 80, ' ');
    ($this->receive)($bad['fileId'], 0, $doctype);

    $refused = ($this->finish)($bad['fileId']);
    $row = ImportFile::query()->findOrFail($bad['fileId']);

    expect($refused['refused'])->toBeTrue()
        ->and($row->state)->toBe('refused')
        ->and((string) $row->refused)->toContain('WordPress export');
});

it('cancels an upload and deletes a finished file’s bytes and row mark', function (): void {
    $started = ($this->start)($this->admin, 'cancel.xml', 5);
    ($this->receive)($started['fileId'], 0, 'hello');
    $path = ImportFile::query()->findOrFail($started['fileId'])->path();

    app(CancelImportUpload::class)->execute(new CancelImportUploadInput($started['fileId'], (string) $this->admin->id));

    expect(ImportFile::query()->whereKey($started['fileId'])->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($path))->toBeFalse();

    Queue::fake();
    $body = ($this->wxr)();
    $kept = ($this->start)($this->admin, 'keep.xml', strlen($body));
    ($this->receive)($kept['fileId'], 0, $body);
    ($this->finish)($kept['fileId']);
    $filePath = ImportFile::query()->findOrFail($kept['fileId'])->path();

    app(DeleteImportFile::class)->execute(new DeleteImportFileInput($kept['fileId'], (string) $this->admin->id));

    $row = ImportFile::query()->findOrFail($kept['fileId']);
    expect($row->deleted_at)->not->toBeNull()
        ->and(Storage::disk('local')->exists($filePath))->toBeFalse();
});

it('writes nothing when authorization fails for each upload operation', function (string $op): void {
    Queue::fake();
    $body = ($this->wxr)();
    $started = ($this->start)($this->admin, "auth-{$op}.xml", strlen($body));
    ($this->receive)($started['fileId'], 0, $body);
    if (in_array($op, ['finish', 'delete'], true)) {
        ($this->finish)($started['fileId']);
    }

    $before = ImportFile::query()->orderBy('id')->get()->toJson();
    $files = Storage::disk('local')->allFiles('imports');

    $call = match ($op) {
        'start' => fn () => ($this->start)($this->editor, 'nope.xml', 10),
        'receive' => fn () => ($this->receive)($started['fileId'], 0, 'x', $this->editor),
        'finish' => fn () => ($this->finish)($started['fileId'], $this->editor),
        'cancel' => fn () => app(CancelImportUpload::class)->execute(new CancelImportUploadInput($started['fileId'], (string) $this->editor->id)),
        'delete' => fn () => app(DeleteImportFile::class)->execute(new DeleteImportFileInput($started['fileId'], (string) $this->editor->id)),
    };

    expect($call)->toThrow(AuthorizationException::class);
    expect(ImportFile::query()->orderBy('id')->get()->toJson())->toBe($before)
        ->and(Storage::disk('local')->allFiles('imports'))->toBe($files);
})->with(['start', 'receive', 'finish', 'cancel', 'delete']);
