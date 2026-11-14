<?php

declare(strict_types=1);

use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Phases\Content\ContentPhase;
use App\Domain\Import\Phases\Content\PageParentsStep;
use App\Domain\Support\Operations\BaseOperation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Import\ContentImports;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->author = User::factory()->create()->assignRole('author');
    $this->companion = importFake();
    $this->source = importSource();
    ContentImports::person($this->source->id, $this->author, 3);
    // The person's row comes from a run that stopped: this is the site's first import.
    DB::table('import_runs')->where('source_id', $this->source->id)->update(['state' => ImportRunState::Stopped->value, 'stop_reason' => 'cancelled']);
    importPhaseAlone(app(ContentPhase::class), app(PageParentsStep::class));
});

/**
 * WordPress's page $id, under $parent, at $path.
 *
 * @param  array<string, mixed>  $fields
 *
 * @return array<string, mixed>
 */
function ploPage(int $id, string $slug, int $parent, string $path, array $fields = []): array
{
    return ContentImports::wpItem($id, ['type' => 'page', 'slug' => $slug, 'title' => ucfirst($slug), 'parent' => $parent, 'permalink' => "https://old.example/{$path}/", ...$fields]);
}

it('takes a page\'s object before its tree, in an import\'s writes and in placing a page alike', function (): void {
    $this->companion->items('content', [ploPage(13, 'about', 0, 'about'), ploPage(14, 'team', 13, 'about/team'), ploPage(16, 'other', 0, 'other')]);
    importRun($this->source, null);

    // Each transaction an Operation began, by the Operation's name, with the locks it holds, those
    // its savepoints took included, in the order taken: object or tree. In production each is its
    // own transaction, since a queued job runs its Operations one after another.
    $locks = [];
    $open = [];
    Event::listen(TransactionBeginning::class, function () use (&$locks, &$open): void {
        $frame = collect(debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT))->first(fn (array $frame): bool => ($frame['object'] ?? null) instanceof BaseOperation);
        $locks[] = ['by' => isset($frame['object']) ? class_basename($frame['object']) : null, 'took' => []];
        $open[] = array_key_last($locks);
    });
    Event::listen([TransactionCommitted::class, TransactionRolledBack::class], function () use (&$open): void {
        array_pop($open);
    });
    DB::listen(function (QueryExecuted $query) use (&$locks, &$open): void {
        if (str_contains($query->sql, 'pg_advisory_xact_lock')) {
            $key = (string) ($query->bindings[0] ?? '');
            $kind = str_starts_with($key, 'teme.tree.') ? 'tree' : (str_starts_with($key, 'import:') ? 'object' : 'other');

            foreach ($open as $index) {
                $locks[$index]['took'][] = $kind;
            }
        }
    });

    // A catch-up that changes a page (the import's own write) and moves it (the page-parents step).
    $this->companion->items('content', [
        ploPage(13, 'about', 0, 'about'),
        ploPage(14, 'team', 16, 'other/team', ['title' => 'Our team', 'modified_gmt' => now()->utc()->format('Y-m-d H:i:s')]),
        ploPage(16, 'other', 0, 'other'),
    ]);
    importRun($this->source, null, ImportRunKind::CatchUp);

    // The import's own write, and the placing of a page, as each first takes the two.
    $orders = collect($locks)
        ->filter(fn (array $tx): bool => in_array($tx['by'], ['ImportObject', 'PlaceImportedPage'], true) && count(array_unique(array_intersect($tx['took'], ['object', 'tree']))) === 2)
        ->map(fn (array $tx): string => $tx['by'].': '.implode(' then ', array_values(array_unique(array_intersect($tx['took'], ['object', 'tree'])))))
        ->unique()->sort()->values()->all();

    expect($orders)->toBe(['ImportObject: object then tree', 'PlaceImportedPage: object then tree']);
});
