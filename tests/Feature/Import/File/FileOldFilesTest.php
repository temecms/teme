<?php

declare(strict_types=1);

use App\Domain\Import\ImportRunState;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Fixtures\Import\FakeCompanion;
use Tests\Fixtures\Import\FileImports;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost']);
    Storage::fake('local');
    Storage::fake('media');
    Storage::fake('media-originals');
    Sleep::fake();
    importResolve([FakeCompanion::HOST => [FakeCompanion::IP]]);
    config(['teme.import.local_sources' => false]);
    Http::preventStrayRequests();
    $this->admin = User::factory()->create()->assignRole('administrator');
});

/**
 * The address check's messages of one run, by code.
 *
 * @return array<string, list<string>>
 */
function ofCheck(string $runId): array
{
    return DB::table('import_messages')->where('run_id', $runId)->where('code', 'like', 'check.%')->orderBy('id')->get(['code', 'message'])
        ->groupBy('code')->map(fn ($rows): array => $rows->pluck('message')->all())->all();
}

it('counts the old site’s file addresses as redirected to the media library, in one line, not as errors', function (): void {
    Http::fake(fn (Request $request) => str_ends_with($request->url(), '.pdf')
        ? Http::response("%PDF-1.4\n%%EOF\n", 200, ['Content-Type' => 'application/pdf'])
        : Http::response(dlJpeg(), 200, ['Content-Type' => 'image/jpeg']));
    $run = FileImports::run(FileImports::ready('companion-site.xml', $this->admin));
    $check = ofCheck($run->id);

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and(array_filter($check['check.not-found'] ?? [], fn (string $message): bool => str_contains($message, '/wp-content/uploads/')))->toBe([])
        ->and($check['check.old-files'] ?? [])->toBe(['4 old file addresses now redirect to the media library.']);
});

it('keeps a file the import didn’t bring an error', function (): void {
    Http::fake(fn (Request $request) => str_ends_with($request->url(), '.pdf')
        ? Http::response('Not here', 404)
        : Http::response(dlJpeg(), 200, ['Content-Type' => 'image/jpeg']));
    $run = FileImports::run(FileImports::ready('companion-site.xml', $this->admin));
    $check = ofCheck($run->id);

    expect(array_values(array_filter($check['check.not-found'] ?? [], fn (string $message): bool => str_contains($message, '/wp-content/uploads/'))))
        ->toBe(['/wp-content/uploads/2024/05/menu.pdf, a file of the media library on the old site, answers “not found” here. Bring what it showed, or add a redirect for it in Tools › Redirects.'])
        ->and($check['check.old-files'] ?? [])->toBe(['3 old file addresses now redirect to the media library.']);
});
