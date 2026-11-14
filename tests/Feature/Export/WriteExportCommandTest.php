<?php

declare(strict_types=1);

use App\Domain\Export\ExportKinds;
use App\Domain\Media\MediaDisks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Export\ExportAs;
use Tests\Fixtures\Export\ProbeKind;
use Tests\Fixtures\Export\SampleSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->folder = ExportAs::freshFolder();
});

afterEach(function (): void {
    ExportAs::remove($this->folder);
});

it('exports to the folder named, and says what it wrote and how to keep it', function (): void {
    SampleSite::make();

    $exit = Artisan::call('teme:export', ['folder' => $this->folder]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain("Exporting this site to {$this->folder} …")
        ->and($output)->toContain('  3 people')
        ->and($output)->toContain('  3 items')
        ->and($output)->toContain('  2 media items')
        ->and($output)->toContain('  1 menu'.PHP_EOL)
        ->and($output)->toContain('  4 menu links')
        ->and($output)->toContain('  2 originals copied, ')
        ->and($output)->toContain("Exported to {$this->folder}. Its manifest.json lists every file with its SHA-256, and what the export leaves out.")
        ->and($output)->toContain('It holds people’s email addresses and every draft: keep it as safely as the site’s database, and delete it from this server once it’s copied.')
        ->and($output)->toContain('Peak memory: ')
        ->and(is_file("{$this->folder}/manifest.json"))->toBeTrue();
});

it('refuses a folder that exists, with its words, and writes nothing', function (): void {
    mkdir($this->folder);

    $exit = Artisan::call('teme:export', ['folder' => $this->folder]);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain("{$this->folder} already exists. Name a folder that doesn’t exist yet: an export never writes into one.")
        ->and(ExportAs::leftovers($this->folder))->toBe([])
        ->and(DB::table('security_events')->where('type', 'export.made')->count())->toBe(0);
});

it('writes under storage/app/exports, named for the moment, when no folder is named', function (): void {
    Date::setTestNow('2026-10-20 14:03:07');
    $this->folder = str_replace('\\', '/', storage_path('app/exports/2026-10-20-140307'));

    $exit = Artisan::call('teme:export');

    expect($exit)->toBe(0)
        ->and(is_file("{$this->folder}/manifest.json"))->toBeTrue();

    if (PHP_OS_FAMILY !== 'Windows') {
        expect(fileperms($this->folder) & 0777)->toBe(0700);
    }
});

it('warns about originals missing or changed, and still finishes', function (): void {
    $site = SampleSite::make();
    MediaDisks::forOriginal($site->image->kind)->put($site->image->original_path, 'not the upload');

    $exit = Artisan::call('teme:export', ['folder' => $this->folder]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('1 original has changed since it was uploaded; it was exported as it is now, and its ID is under media.differs in manifest.json.')
        ->and($output)->not->toContain('couldn’t be read');
});

it('says the export stopped and that nothing was left behind', function (): void {
    SampleSite::make();
    app()->instance(ExportKinds::class, new ExportKinds([...(new ExportKinds)->all(), new ProbeKind('probes', [['n' => 1]], failAfter: 0)]));

    $exit = Artisan::call('teme:export', ['folder' => $this->folder]);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('The export stopped: A kind failed on purpose. Nothing was left behind.')
        ->and(file_exists($this->folder))->toBeFalse()
        ->and(ExportAs::leftovers($this->folder))->toBe([]);
});
