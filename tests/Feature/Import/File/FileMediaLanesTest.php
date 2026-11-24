<?php

declare(strict_types=1);

use App\Domain\Import\File\ContentBlocks;
use App\Domain\Import\ImportEngine;
use App\Domain\Import\Jobs\ImportListPage;
use App\Domain\Import\Source\FileReader;
use App\Domain\Support\Security\JobActor;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Import\ExportFiles;
use Tests\Fixtures\Import\FileImports;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    Storage::fake('local');
    app()->instance(ContentBlocks::class, ExportFiles::blocks());
    Bus::fake();
    $this->file = FileImports::ready('companion-site.xml', $this->admin);
    // The file's own attachments are replaced by 25 numbered ones, so only their IDs matter.
    DB::table('import_file_rows')->where('file_id', $this->file->id)->where('list', 'media')->delete();
    DB::table('import_file_rows')->insert(array_map(fn (int $id): array => [
        'file_id' => $this->file->id, 'list' => 'media', 'object_id' => $id, 'payload' => json_encode(['id' => $id]),
    ], range(1, 25)));
    $this->reader = new FileReader($this->file->id, (string) $this->file->source_id);
});

it('splits the media list into lanes that together read every attachment once, each in ID order (#701)', function (): void {
    $read = function (int $lane): array {
        $ids = [];

        for ($after = 0, $page = null; $page === null || $page->nextAfterId !== null; $after = (int) $page->nextAfterId) {
            $page = $this->reader->page('media', ['lane' => $lane, 'lanes' => 3], $after, 2);
            array_push($ids, ...array_column($page->items, 'id'));
        }

        return $ids;
    };

    $lanes = [$read(0), $read(1), $read(2)];

    expect($lanes[0])->toBe([3, 6, 9, 12, 15, 18, 21, 24])
        ->and($lanes[1])->toBe([1, 4, 7, 10, 13, 16, 19, 22, 25])
        ->and($lanes[2])->toBe([2, 5, 8, 11, 14, 17, 20, 23])
        ->and(collect($lanes)->flatten()->sort()->values()->all())->toBe(range(1, 25));
});

it('reads the whole media list when no lane is asked for (#701)', function (): void {
    expect(array_column($this->reader->page('media', [], 0, 100)->items, 'id'))->toBe(range(1, 25));
});

it('refuses a lane that is not one of the lanes (#701)', function (array $query): void {
    $this->reader->page('media', $query, 0, 20);
})->throws(InvalidArgumentException::class)->with([
    'past the last' => [['lane' => 3, 'lanes' => 3]],
    'below the first' => [['lane' => -1, 'lanes' => 3]],
    'no lanes' => [['lane' => 0, 'lanes' => 0]],
]);

it('starts a file run’s media phase as one chain per lane, each from the start (#701)', function (): void {
    $run = FileImports::run($this->file, [], ['media_lanes' => 3]);
    $run->forceFill(['phase' => 'media'])->save();

    app(ImportEngine::class)->begin($run->id, (string) $run->phase_token, JobActor::person($this->admin->id));

    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->name === "import:{$run->id}:media"
        && collect($batch->jobs)->map(fn (ImportListPage $page): array => [$page->phase, $page->list, $page->afterId])->all() === [['media', 0, 0], ['media', 1, 0], ['media', 2, 0]]);
});

it('stores the downloads setting as a file run’s lanes (#701)', function (): void {
    $run = FileImports::run($this->file);

    expect($run->options)->toBe(['pause_ms' => 0, 'media_lanes' => 4]);
});
