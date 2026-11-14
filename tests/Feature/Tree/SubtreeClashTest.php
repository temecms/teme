<?php

declare(strict_types=1);

use App\Domain\Contents\Jobs\MoveSubtreeAddresses;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Tree\SubtreeAddresses;
use App\Domain\Contents\Tree\SubtreeJobKind;
use App\Domain\Contents\Tree\SubtreeMoves;
use App\Domain\Contents\Tree\SubtreeOutcome;
use App\Domain\Contents\Tree\TreeLock;
use App\Domain\Support\Operations\BaseOperation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Operations\OperationInput;
use Tests\Fixtures\Tree\PageTree;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->tree = PageTree::build();
    app()->forgetScopedInstances();
});

/**
 * An Operation that changes the item straight on its row, as the real
 * Operations do once they follow the tree, and moves the pages under it.
 *
 * @param  Closure(Content): void  $change
 */
function clashChange(Content $item, Closure $change): BaseOperation
{
    return new class($item, $change) extends BaseOperation
    {
        public ?SubtreeOutcome $outcome = null;

        public function __construct(private readonly Content $item, private readonly Closure $change) {}

        protected function authorize(OperationInput $input): void {}

        protected function validate(OperationInput $input): void {}

        protected function perform(OperationInput $input): mixed
        {
            $subtree = app(SubtreeAddresses::class);
            TreeLock::take('page');
            $item = Content::withTrashed()->lockForUpdate()->findOrFail($this->item->id);
            $before = $subtree->childChain($item);
            ($this->change)($item);
            $this->outcome = $subtree->follow($item, $before, $input->actorId());

            foreach ($this->outcome->purges as $purge) {
                $this->purgeAfterCommit($purge);
            }

            return null;
        }
    };
}

function clashRun(Content $item, Closure $change, ?User $actor = null): SubtreeOutcome
{
    $operation = clashChange($item, $change);
    $operation->execute(new ContentActionInput($item->id, $actor === null ? null : (string) $actor->id));

    return $operation->outcome ?? new SubtreeOutcome;
}

/**
 * Counts the statements the work reads, and those that match a pattern.
 *
 * @return list<string>
 */
function clashStatements(Closure $work): array
{
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });
    $work();

    return $statements;
}

it('finds the first page under it whose address another target holds, set-wise', function (): void {
    $ids = PageTree::many($this->tree->about, 60);
    DB::table('contents')->where('id', $ids[41])->update(['slug' => 'x', 'title' => 'X']);
    $term = typesTerm($this->tree->admin, 'category', 'Ex');
    DB::table('paths')->where('target_type', 'term')->where('target_id', $term->id)->where('is_current', true)->update(['path' => 'about/x']);
    $about = Content::query()->findOrFail($this->tree->about->id);
    $answer = null;

    $statements = clashStatements(function () use ($about, &$answer): void {
        $answer = app(SubtreeAddresses::class)->clash($about, 'about');
    });

    expect($answer)->toBe('Its page “X” would move to /about/x/, which is used by the category "Ex".')
        ->and(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'WITH RECURSIVE')))->toHaveCount(1)
        ->and(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'from "paths"')))->toHaveCount(1);
});

it('finds none for a page with no children, reading one statement', function (): void {
    $answer = 'unread';

    $statements = clashStatements(function () use (&$answer): void {
        $answer = app(SubtreeAddresses::class)->clash($this->tree->people, 'elsewhere/people');
    });

    expect($answer)->toBeNull()
        ->and($statements)->toHaveCount(1);
});

it('keeps a page whose new address another target holds at its old one, and names it', function (): void {
    // Trashing About takes its slug out of the chain: Team would be at team, the post's.
    $outcome = clashRun($this->tree->about, fn (Content $about) => $about->delete());

    expect(addrCurrent('content', $this->tree->team->id))->toBe('about/team')
        ->and(addrCurrent('content', $this->tree->history->id))->toBe('history')
        ->and(addrCurrent('content', $this->tree->people->id))->toBe('team/people')
        ->and($outcome->kept)->toBe([['title' => 'Team', 'path' => '/about/team/', 'address' => '/team/', 'holder' => 'the post "Team"']])
        ->and($outcome->moved)->toBe(4);
});

it('collects every outcome of the request in SubtreeMoves', function (): void {
    clashRun($this->tree->about, function (Content $about): void {
        $about->slug = 'about-us';
        $about->save();
    });
    clashRun($this->tree->about, fn (Content $about) => $about->delete());

    $moves = app(SubtreeMoves::class);

    expect(array_map(fn (SubtreeOutcome $outcome): int => $outcome->moved, $moves->all()))->toBe([5, 4])
        ->and(array_column($moves->kept(), 'title'))->toBe(['Team']);
});

it('moves 50 pages in the transaction and queues all of 51', function (): void {
    Bus::fake([MoveSubtreeAddresses::class]);
    // Five of About's pages have an address; 45 more make 50.
    PageTree::many($this->tree->about, 45);

    $outcome = clashRun($this->tree->about, function (Content $about): void {
        $about->slug = 'about-us';
        $about->save();
    });

    expect($outcome->moved)->toBe(50)
        ->and($outcome->queued)->toBe(0)
        ->and(DB::table('paths')->where('is_current', true)->where('path', 'like', 'about-us/%')->count())->toBe(50);
    Bus::assertNotDispatched(MoveSubtreeAddresses::class);

    PageTree::many($this->tree->about, 1, 'one-more');

    $outcome = clashRun($this->tree->about, function (Content $about): void {
        $about->slug = 'about-again';
        $about->save();
    }, $this->tree->editor);

    expect($outcome->moved)->toBe(0)
        ->and($outcome->queued)->toBe(51)
        ->and(DB::table('paths')->where('is_current', true)->where('path', 'like', 'about-again/%')->count())->toBe(0)
        ->and(DB::table('tracked_jobs')->where('kind', SubtreeJobKind::KEY)->where('run_id', $this->tree->about->id)->pluck('id')->all())->toBe([$outcome->jobId]);
    Bus::assertDispatchedTimes(MoveSubtreeAddresses::class, 1);
});

it('names an address of a kind this release doesn’t know in words when a page is kept at its old one', function (): void {
    // A kind a later release adds, with a ULID, holds the post's address.
    DB::table('paths')->where('target_type', 'content')->where('target_id', $this->tree->teamPost->id)->where('is_current', true)
        ->update(['target_type' => 'gadget', 'target_id' => '01kv7m3q9x2c5r8t1w4y6z0b3d']);

    $outcome = clashRun($this->tree->about, fn (Content $about) => $about->delete());

    expect(addrCurrent('content', $this->tree->team->id))->toBe('about/team')
        ->and($outcome->kept)->toBe([['title' => 'Team', 'path' => '/about/team/', 'address' => '/team/', 'holder' => 'something this version of Teme doesn’t know (gadget)']]);
});
