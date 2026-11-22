<?php

declare(strict_types=1);

use App\Domain\Import\File\ContentBlocks;
use App\Domain\Import\File\Jobs\ReadImportFile;
use App\Domain\Import\Models\ImportFile;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Operations\FinishReadingImportFile;
use App\Domain\Import\Operations\Input\FinishReadingImportFileInput;
use App\Domain\Import\Operations\Input\RecordImportFileRowsInput;
use App\Domain\Import\Operations\Input\RefuseImportFileInput;
use App\Domain\Import\Operations\RecordImportFileRows;
use App\Domain\Import\Operations\RefuseImportFile;
use App\Domain\Import\Planning\SiteAnalysis;
use App\Domain\Support\Security\JobActor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Import\ExportFiles;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');
    Storage::fake('local');
    app()->instance(ContentBlocks::class, ExportFiles::blocks());
});

/**
 * An uploaded file being read, holding one of the reader's fixtures.
 */
function wxrUploaded(string $fixture, User $by): ImportFile
{
    $bytes = (string) file_get_contents(ExportFiles::path($fixture));
    $file = ImportFile::query()->create([
        'uploaded_by' => $by->id, 'name' => $fixture, 'size_bytes' => strlen($bytes), 'received_bytes' => strlen($bytes),
        'sha256' => hash('sha256', $bytes), 'state' => 'reading',
    ]);
    Storage::disk('local')->put($file->path(), $bytes);

    return $file;
}

/**
 * Reads the file to its end as its uploader, running each part the read queues.
 */
function wxrReadToEnd(ImportFile $file): int
{
    $parts = 0;
    $job = new ReadImportFile($file->id, JobActor::person($file->uploaded_by));

    while ($job !== null) {
        Queue::fake();
        $job->handle();
        $parts++;
        $queued = Queue::pushedJobs()[ReadImportFile::class] ?? [];
        $job = $queued === [] ? null : $queued[0]['job'];
    }

    return $parts;
}

/**
 * The file's rows, by list and ID, decoded.
 *
 * @return array<string, array<int, array<string, mixed>>>
 */
function wxrStoredRows(ImportFile $file): array
{
    $rows = [];

    foreach (DB::table('import_file_rows')->where('file_id', $file->id)->orderBy('list')->orderBy('object_id')->get() as $row) {
        $rows[$row->list][(int) $row->object_id] = json_decode($row->payload, true);
    }

    return $rows;
}

it('reads a file into its rows, keeps its report, and makes it a new site', function (): void {
    $file = wxrUploaded('companion-site.xml', $this->admin);

    expect(wxrReadToEnd($file))->toBe(1);

    $file->refresh();
    $rows = wxrStoredRows($file);
    $source = ImportSource::query()->sole();

    expect($file->state)->toBe('ready')
        ->and($file->source_id)->toBe($source->id)
        ->and($source->address)->toBe('https://old.example')
        ->and($source->created_by)->toBe($this->admin->id)
        ->and($file->site_address)->toBe('https://old.example')
        ->and($file->wxr_version)->toBe('1.2')
        ->and($file->made_at?->toIso8601String())->toBe('2026-10-01T09:30:00+00:00')
        ->and($file->read_items)->toBe(20)
        ->and(array_keys($rows['menus']))->toBe([3, 4])
        ->and(array_column($rows['menus'][3]['items'], 'label'))->toBe(['Home', 'About', 'Team', 'News', 'Ferries', 'Harbour Trust'])
        ->and($rows['users'][3]['published_posts'])->toBe(1)
        ->and($rows['terms'][102]['count'])->toBe(1)
        ->and($rows['content'][12]['blocks'][0]['autop'])->toStartWith('<p>');
});

it('gives SiteAnalysis the counts the file has', function (): void {
    $file = wxrUploaded('companion-site.xml', $this->admin);
    wxrReadToEnd($file);

    $analysis = SiteAnalysis::fromReport($file->refresh()->report ?? [])->toArray();

    expect(array_column($analysis['comes'], 'count', 'key'))->toBe([
        'posts' => 1, 'pages' => 6, 'media' => 2, 'categories' => 6, 'tags' => 4, 'people' => 5, 'comments' => 4, 'menus' => 2,
    ])
        ->and($analysis['seo'])->toBe('Yoast SEO')
        ->and($analysis['reportedAt'])->not->toBeNull()
        ->and($analysis['templates'])->toBe(['template-full-width.php' => 1])
        ->and(array_column($analysis['stays'], 'count', 'key'))->toBe(['taxonomy.genre' => 1]);
});

it('counts outside images, finds Rank Math and Polylang, and names the objects it dropped', function (): void {
    $file = wxrUploaded('edge-cases.xml', $this->admin);
    wxrReadToEnd($file);
    $report = $file->refresh()->report ?? [];

    expect(SiteAnalysis::fromReport($report)->outsideHosts())->toBe(['photos.friend.example' => 2])
        ->and(SiteAnalysis::fromReport($report)->toArray()['seo'])->toBe('Rank Math')
        ->and(SiteAnalysis::polylangLanguages($report))->toBe(2)
        ->and($report['errors'])->toEqual(['serialized' => ['dropped' => 1, 'items' => [['post_id' => 60, 'key' => '_wp_attachment_metadata']]]]);
});

it('attaches a file to the site Teme already knows at the same address', function (): void {
    importFake();
    $source = importSource($this->admin);
    $file = wxrUploaded('companion-site.xml', $this->admin);

    wxrReadToEnd($file);

    expect($file->refresh()->source_id)->toBe($source->id)
        ->and(ImportSource::query()->count())->toBe(1);
});

it('keeps what it read when it stops at its time, and the next part writes each row once', function (): void {
    $whole = wxrUploaded('companion-site.xml', $this->admin);
    wxrReadToEnd($whole);

    // Each item's blocks take half a minute, so a part reads two items and stops.
    $clock = CarbonImmutable::parse('2026-11-22 10:00:00');
    CarbonImmutable::setTestNow($clock);
    app()->instance(ContentBlocks::class, ContentBlocks::using(function (string $html) use (&$clock): array {
        $clock = $clock->addSeconds(30);
        CarbonImmutable::setTestNow($clock);

        return ExportFiles::blocks()->parsed($html);
    }, fn (string $html): string => '<p>'.trim($html).'</p>'));

    $file = wxrUploaded('companion-site.xml', $this->admin);
    Queue::fake();
    (new ReadImportFile($file->id, JobActor::person($this->admin->id)))->handle();

    expect($file->refresh()->read_items)->toBe(2)
        ->and($file->state)->toBe('reading')
        ->and(Queue::pushedJobs()[ReadImportFile::class])->toHaveCount(1);

    $parts = wxrReadToEnd($file);
    CarbonImmutable::setTestNow();

    expect($parts)->toBeGreaterThan(3)
        ->and($file->refresh()->state)->toBe('ready')
        ->and(wxrStoredRows($file))->toBe(wxrStoredRows($whole))
        ->and(DB::table('import_file_rows')->where('file_id', $file->id)->count())->toBe(DB::table('import_file_rows')->where('file_id', $whole->id)->count());
});

it('refuses a file that isn’t an export, with its sentence', function (string $fixture, string $sentence): void {
    $file = wxrUploaded($fixture, $this->admin);

    wxrReadToEnd($file);

    expect($file->refresh()->state)->toBe('refused')
        ->and($file->refused)->toBe($sentence)
        ->and(DB::table('import_file_rows')->where('file_id', $file->id)->count())->toBe(0)
        ->and(ImportSource::query()->count())->toBe(0);
})->with([
    'a document type' => ['doctype.xml', 'This file has a document type declaration, which an export file never has. Teme doesn’t read it.'],
    'a feed' => ['rss-feed.xml', 'That isn’t a WordPress export file.'],
]);

it('writes nothing for anyone who may not import', function (string $case): void {
    $file = wxrUploaded('companion-site.xml', $this->admin);
    $before = DB::table('import_files')->get()->toJson();
    $editor = (string) $this->editor->id;

    $act = match ($case) {
        'keeping rows' => fn () => app(RecordImportFileRows::class)->execute(new RecordImportFileRowsInput(
            $file->id, 0, 1, [['list' => 'users', 'id' => 1, 'payload' => ['id' => 1]]],
            ['seo' => [], 'images' => ['total' => 0, 'own' => 0, 'hosts' => []], 'dropped' => []],
            ['siteAddress' => 'https://old.example', 'version' => '1.2', 'madeAt' => null], $editor,
        )),
        'refusing' => fn () => app(RefuseImportFile::class)->execute(new RefuseImportFileInput($file->id, 'No.', $editor)),
        'finishing' => fn () => app(FinishReadingImportFile::class)->execute(new FinishReadingImportFileInput($file->id, $editor)),
        default => fn () => (new ReadImportFile($file->id, JobActor::person($this->editor->id)))->handle(),
    };

    expect($act)->toThrow(AuthorizationException::class, 'Not permitted to import a site.')
        ->and(DB::table('import_files')->get()->toJson())->toBe($before)
        ->and(DB::table('import_file_rows')->count())->toBe(0)
        ->and(DB::table('import_sources')->count())->toBe(0);
})->with([
    'keeping rows' => ['keeping rows'],
    'refusing' => ['refusing'],
    'finishing' => ['finishing'],
    'reading as an editor' => ['reading'],
]);
