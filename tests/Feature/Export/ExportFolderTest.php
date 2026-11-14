<?php

declare(strict_types=1);

use App\Domain\Export\ExportFolder;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Export\ExportAs;
use Tests\Fixtures\Export\ProbeKind;

beforeEach(function (): void {
    $this->target = ExportAs::freshFolder();
});

afterEach(function (): void {
    ExportAs::remove($this->target);
});

it('writes into a private folder beside the target, and moves it there only when finished', function (): void {
    $folder = ExportFolder::open($this->target);
    $file = $folder->ndjson(new ProbeKind);
    $file->write(['n' => 1]);
    $file->close();

    expect(is_dir($folder->staging))->toBeTrue()
        ->and(dirname($folder->staging))->toBe(dirname($this->target))
        ->and(basename($folder->staging))->toStartWith('.'.basename($this->target).'.partial-')
        ->and(file_exists($this->target))->toBeFalse();

    $folder->writeManifest(['format' => 'teme-export']);
    $folder->finish();

    expect(file_get_contents("{$this->target}/probes.ndjson"))->toBe('{"kind":"probes","version":1,"n":1}'."\n")
        ->and(json_decode((string) file_get_contents("{$this->target}/manifest.json"), true))->toBe(['format' => 'teme-export'])
        ->and(file_exists($folder->staging))->toBeFalse();
});

it('makes the folder readable by Teme’s user alone, and every file in it', function (): void {
    Storage::fake('media');
    Storage::disk('media')->put('2026/10/a.pdf', '%PDF-1.4');
    $folder = ExportFolder::open($this->target);
    $folder->ndjson(new ProbeKind)->close();
    $folder->copyOriginal('01JB2ZQ8V3K7N4W5X6Y7Z8A9BC', 'pdf', Storage::disk('media'), '2026/10/a.pdf');
    $folder->writeManifest([]);
    $folder->finish();

    if (PHP_OS_FAMILY !== 'Windows') {
        expect(fileperms($this->target) & 0777)->toBe(0700)
            ->and(fileperms("{$this->target}/media") & 0777)->toBe(0700)
            ->and(fileperms("{$this->target}/probes.ndjson") & 0777)->toBe(0600)
            ->and(fileperms("{$this->target}/media/01JB2ZQ8V3K7N4W5X6Y7Z8A9BC.pdf") & 0777)->toBe(0600)
            ->and(fileperms("{$this->target}/manifest.json") & 0777)->toBe(0600);
    }

    expect(is_file("{$this->target}/media/01JB2ZQ8V3K7N4W5X6Y7Z8A9BC.pdf"))->toBeTrue();
});

it('counts the lines and bytes it wrote, and hashes exactly those bytes', function (): void {
    $folder = ExportFolder::open($this->target);
    $file = $folder->ndjson(new ProbeKind);
    $file->write(['n' => 1]);
    $file->write(['n' => 2]);
    $written = $file->close();
    $bytes = (string) file_get_contents("{$folder->staging}/probes.ndjson");
    $folder->discard();

    expect([$written->kind, $written->file, $written->version, $written->noun, $written->lines])->toBe(['probes', 'probes.ndjson', 1, 'probe|probes', 2])
        ->and($written->bytes)->toBe(strlen($bytes))
        ->and($written->sha256)->toBe(hash('sha256', $bytes))
        ->and($written->toManifest())->toBe(['kind' => 'probes', 'file' => 'probes.ndjson', 'version' => 1, 'lines' => 2, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]);
});

it('refuses a line that would set its own kind or version', function (): void {
    $folder = ExportFolder::open($this->target);
    $file = $folder->ndjson(new ProbeKind);

    expect(fn () => $file->write(['kind' => 'other']))->toThrow(LogicException::class, 'A line of probes can’t set its own kind or version.')
        ->and(fn () => $file->write(['version' => 2]))->toThrow(LogicException::class, 'A line of probes can’t set its own kind or version.');

    $folder->discard();
});

it('copies an original by its recorded path, hashing it on the way, and answers null for a file that isn’t there', function (): void {
    Storage::fake('media-originals');
    $bytes = random_bytes(3 * 1024 * 1024 + 17);
    Storage::disk('media-originals')->put('2026/10/01JB2ZQ8V3K7N4W5X6Y7Z8A9BC/1/harbour.JPG', $bytes);
    $folder = ExportFolder::open($this->target);

    $disk = Storage::disk('media-originals');
    $copy = $folder->copyOriginal('01JB2ZQ8V3K7N4W5X6Y7Z8A9BC', 'JPG', $disk, '2026/10/01JB2ZQ8V3K7N4W5X6Y7Z8A9BC/1/harbour.JPG');
    $odd = $folder->copyOriginal('01JB2ZQ8V3K7N4W5X6Y7Z8A9BD', '../x', $disk, '2026/10/01JB2ZQ8V3K7N4W5X6Y7Z8A9BC/1/harbour.JPG');
    $missing = $folder->copyOriginal('01JB2ZQ8V3K7N4W5X6Y7Z8A9BE', 'jpg', $disk, '2026/10/gone.jpg');

    expect($copy?->path)->toBe('media/01JB2ZQ8V3K7N4W5X6Y7Z8A9BC.jpg')
        ->and($copy?->bytes)->toBe(strlen($bytes))
        ->and($copy?->sha256)->toBe(hash('sha256', $bytes))
        ->and(file_get_contents("{$folder->staging}/media/01JB2ZQ8V3K7N4W5X6Y7Z8A9BC.jpg"))->toBe($bytes)
        ->and($odd?->path)->toBe('media/01JB2ZQ8V3K7N4W5X6Y7Z8A9BD.bin')
        ->and($missing)->toBeNull();

    $folder->discard();
});

it('removes every file and folder it made when discarded, and nothing else beside them', function (): void {
    Storage::fake('media');
    Storage::disk('media')->put('a.pdf', '%PDF-1.4');
    $neighbour = dirname($this->target).'/'.basename($this->target).'-neighbour.txt';
    file_put_contents($neighbour, 'mine');
    $folder = ExportFolder::open($this->target);
    $open = $folder->ndjson(new ProbeKind('people'));
    $open->write(['n' => 1]);
    $folder->ndjson(new ProbeKind('terms'))->close();
    $folder->copyOriginal('01JB2ZQ8V3K7N4W5X6Y7Z8A9BC', 'pdf', Storage::disk('media'), 'a.pdf');

    // One file still open, as when a kind fails mid-way.
    expect($folder->discard())->toBeTrue()
        ->and(file_exists($folder->staging))->toBeFalse()
        ->and(file_exists($this->target))->toBeFalse()
        ->and(ExportAs::leftovers($this->target))->toBe([])
        ->and(file_get_contents($neighbour))->toBe('mine')
        ->and($folder->discard())->toBeTrue();

    unlink($neighbour);
});

it('does nothing when discarded after it has finished', function (): void {
    $folder = ExportFolder::open($this->target);
    $folder->writeManifest([]);
    $folder->finish();

    expect($folder->discard())->toBeTrue()
        ->and(is_file("{$this->target}/manifest.json"))->toBeTrue();
});

it('won’t move the export onto a folder that appeared meanwhile, and leaves that folder alone', function (): void {
    $folder = ExportFolder::open($this->target);
    $folder->writeManifest([]);
    mkdir($this->target);
    file_put_contents("{$this->target}/someone-elses.txt", 'theirs');

    expect(fn () => $folder->finish())->toThrow(RuntimeException::class, "{$this->target} appeared while the export was being written, so the export was not moved there.")
        ->and($folder->discard())->toBeTrue()
        ->and(file_get_contents("{$this->target}/someone-elses.txt"))->toBe('theirs')
        ->and(ExportAs::leftovers($this->target))->toBe([]);
});

it('says the disk may be full when a write can’t complete', function (): void {
    $readOnly = fopen('php://memory', 'rb');

    expect(fn () => ExportFolder::writeAll($readOnly, 'bytes', '/exports/people.ndjson'))
        ->toThrow(RuntimeException::class, 'Teme couldn’t write /exports/people.ndjson: the disk may be full.');
});
