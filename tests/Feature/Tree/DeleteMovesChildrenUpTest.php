<?php

declare(strict_types=1);

use App\Domain\Cache\PageTags;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\DeleteContentPermanently;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\RestoreContent;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Contents\Tree\SubtreeAddresses;
use App\Domain\Contents\Tree\SubtreeJobKind;
use App\Domain\Jobs\Operations\Input\StartJobInput;
use App\Domain\Jobs\Operations\StartJob;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Tree\PageTree;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->tree = PageTree::build();
});

function upDelete(Content $item, ?string $actorId): void
{
    app(DeleteContentPermanently::class)->execute(new ContentActionInput($item->id, $actorId));
}

/**
 * Each item's parent and order.
 *
 * @return array<string, array{parent_id: string|null, position: int}>
 */
function upPlaces(Content ...$items): array
{
    $places = [];

    foreach ($items as $item) {
        $row = DB::table('contents')->where('id', $item->id)->first(['parent_id', 'position']);
        $places[$item->id] = ['parent_id' => $row?->parent_id, 'position' => (int) $row?->position];
    }

    return $places;
}

/**
 * Every address but the deleted item's own.
 *
 * @return list<array<string, mixed>>
 */
function upPaths(Content $deleted): array
{
    return DB::table('paths')->where('target_id', '<>', $deleted->id)->orderBy('path')->get(['path', 'target_id', 'is_current'])->map(fn (object $row): array => (array) $row)->all();
}

it('moves the children of a page deleted for good up to its parent, keeping their order', function (): void {
    $second = addrPublished($this->tree->editor, 'Second child', 'page');
    // Under a page in the trash, where no Operation moves a page: written to the row, its address already the flat one.
    DB::table('contents')->where('id', $second->id)->update(['parent_id' => $this->tree->archived->id, 'position' => 1]);

    upDelete($this->tree->archived, (string) $this->tree->editor->id);

    expect(upPlaces($this->tree->archivedChild, $second))->toBe([
        $this->tree->archivedChild->id => ['parent_id' => $this->tree->about->id, 'position' => 0],
        $second->id => ['parent_id' => $this->tree->about->id, 'position' => 1],
    ]);
});

it('sets aside the new parent\'s page and each moved page\'s at once', function (): void {
    $folder = cacheOn();
    $pages = ['/about/', '/about/archived-child/', '/about/team/'];
    addrGet('/about/archived-child/')->assertOk();
    addrGet('/about/team/')->assertOk();
    // About's page as a theme that lists its children stores it.
    cacheShown(['/about/' => [PageTags::childrenOf($this->tree->about->id)]]);

    expect(cacheKept(...$pages))->toBe($pages);

    upDelete($this->tree->archived, (string) $this->tree->editor->id);

    // About now lists Archived child, whose own page names a new parent; Team's page lists its siblings, Archived child among them now.
    expect(cacheKept(...$pages))->toBe([]);
    cacheOff($folder);
});

it('sets aside nothing when the change rolls back', function (): void {
    $folder = cacheOn();
    $pages = ['/about/', '/about/archived-child/'];
    addrGet('/about/archived-child/')->assertOk();
    cacheShown(['/about/' => [PageTags::childrenOf($this->tree->about->id)]]);

    cacheRolledBack(fn () => upDelete($this->tree->archived, (string) $this->tree->editor->id));

    expect(cacheKept(...$pages))->toBe($pages);
    cacheOff($folder);
});

it('moves them to the top when it had none', function (): void {
    app(TrashContent::class)->execute(new ContentActionInput($this->tree->services->id, (string) $this->tree->editor->id));

    upDelete($this->tree->services, (string) $this->tree->editor->id);

    expect(upPlaces($this->tree->web))->toBe([$this->tree->web->id => ['parent_id' => null, 'position' => 0]]);
});

it('moves no address', function (): void {
    $before = upPaths($this->tree->archived);

    upDelete($this->tree->archived, (string) $this->tree->editor->id);

    expect(upPaths($this->tree->archived))->toBe($before)
        ->and(addrCurrent('content', $this->tree->archivedChild->id))->toBe('about/archived-child');
});

it('lets the operator\'s purge do the same', function (): void {
    DB::table('contents')->where('id', $this->tree->archived->id)->update(['deleted_at' => now()->subDays(90)]);

    Artisan::call('teme:trash:purge');

    expect(Content::withTrashed()->find($this->tree->archived->id))->toBeNull()
        ->and(upPlaces($this->tree->archivedChild))->toBe([$this->tree->archivedChild->id => ['parent_id' => $this->tree->about->id, 'position' => 0]]);
});

it('refuses to delete a page restored after validate(), writing nothing', function (): void {
    $tree = $this->tree;
    $real = app(SubtreeAddresses::class);
    // Restored between the delete's validate() and perform(), as the delete takes the tree.
    app()->instance(SubtreeAddresses::class, new class($real, $tree)
    {
        private bool $raced = false;

        public function __construct(private readonly SubtreeAddresses $real, private readonly PageTree $tree) {}

        public function lock(?string $type): bool
        {
            if (! $this->raced) {
                $this->raced = true;
                app(RestoreContent::class)->execute(new ContentActionInput($this->tree->archived->id, (string) $this->tree->editor->id));
            }

            return $this->real->lock($type);
        }

        /**
         * @param  list<mixed>  $arguments
         */
        public function __call(string $method, array $arguments): mixed
        {
            return $this->real->{$method}(...$arguments);
        }
    });
    $places = upPlaces($this->tree->archived, $this->tree->archivedChild);

    expect(fn () => upDelete($tree->archived, (string) $tree->editor->id))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['content'])->toBe(['Move it to the trash first.']));

    // The restore ran inside the refused delete's transaction, so it went back with it.
    expect(Content::withTrashed()->find($this->tree->archived->id))->not->toBeNull()
        ->and(upPlaces($this->tree->archived, $this->tree->archivedChild))->toBe($places);
});

it('refuses to delete a page whose branch is still moving, and the purge skips it', function (): void {
    app(StartJob::class)->execute(new StartJobInput(kind: SubtreeJobKind::KEY, label: 'Moving', runType: SubtreeJobKind::RUN_TYPE, runId: $this->tree->archived->id, actorId: (string) $this->tree->editor->id));
    $places = upPlaces($this->tree->archivedChild);

    expect(fn () => upDelete($this->tree->archived, (string) $this->tree->editor->id))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['content'])->toBe(["The pages under it are still moving. Delete it once the jobs indicator says they've moved."]));

    DB::table('contents')->where('id', $this->tree->archived->id)->update(['deleted_at' => now()->subDays(90)]);
    Artisan::call('teme:trash:purge');

    expect(Content::withTrashed()->find($this->tree->archived->id))->not->toBeNull()
        ->and(upPlaces($this->tree->archivedChild))->toBe($places);
});
