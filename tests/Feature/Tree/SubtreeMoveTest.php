<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Tree\SubtreeAddresses;
use App\Domain\Support\Operations\BaseOperation;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Operations\OperationInput;
use Tests\Fixtures\Tree\PageTree;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->tree = PageTree::build();
});

/**
 * An Operation that renames the item straight on its row, syncs its own
 * address and moves the pages under it, as the real Operations do.
 */
function subtreeRename(Content $item, string $slug): BaseOperation
{
    return new class($item, $slug) extends BaseOperation
    {
        public function __construct(private readonly Content $item, private readonly string $slug) {}

        protected function authorize(OperationInput $input): void {}

        protected function validate(OperationInput $input): void {}

        protected function perform(OperationInput $input): mixed
        {
            $subtree = app(SubtreeAddresses::class);
            $item = Content::query()->lockForUpdate()->findOrFail($this->item->id);
            $before = $subtree->childChain($item);
            $item->slug = $this->slug;
            $item->save();
            app(PathProjection::class)->syncContent($item);

            foreach ($subtree->follow($item, $before, null)->purges as $purge) {
                $this->purgeAfterCommit($purge);
            }

            return null;
        }
    };
}

it('moves every page under a renamed page in the same transaction, each old address answering 301', function (): void {
    subtreeRename($this->tree->about, 'about-us')->execute(new ContentActionInput($this->tree->about->id, null));

    expect(addrCurrent('content', $this->tree->about->id))->toBe('about-us')
        ->and(addrCurrent('content', $this->tree->team->id))->toBe('about-us/team')
        ->and(addrCurrent('content', $this->tree->people->id))->toBe('about-us/team/people')
        ->and(addrCurrent('content', $this->tree->history->id))->toBe('about-us/history')
        ->and(addrCurrent('content', $this->tree->secretChild->id))->toBe('about-us/secret-child')
        ->and(addrCurrent('content', $this->tree->archivedChild->id))->toBe('about-us/archived-child');

    $response = addrGet('/about/team/people/');

    expect($response->getStatusCode())->toBe(301)
        ->and(addrLocation($response))->toBe('/about-us/team/people/');
});

it('rolls the moves back with the change', function (): void {
    $before = DB::table('paths')->orderBy('path')->get(['path', 'target_id', 'is_current'])->all();

    cacheRolledBack(fn () => subtreeRename($this->tree->about, 'about-us')->execute(new ContentActionInput($this->tree->about->id, null)));

    expect(DB::table('paths')->orderBy('path')->get(['path', 'target_id', 'is_current'])->all())->toEqual($before)
        ->and(addrCurrent('content', $this->tree->people->id))->toBe('about/team/people');
});
