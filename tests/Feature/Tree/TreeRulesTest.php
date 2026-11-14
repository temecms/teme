<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Contents\Tree\TreeLock;
use App\Domain\Contents\Tree\TreeRules;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
});

/**
 * A chain of published pages, each under the one before, written straight to the rows.
 *
 * @return list<Content>
 */
function rulesChain(User $editor, string $name, int $length): array
{
    $pages = [];

    foreach (range(1, $length) as $n) {
        $page = addrPublished($editor, "{$name} {$n}", 'page');

        if ($pages !== []) {
            DB::table('contents')->where('id', $page->id)->update(['parent_id' => $pages[array_key_last($pages)]->id]);
        }

        $pages[] = $page->refresh();
    }

    return $pages;
}

it('refuses a parent the tree\'s rules don\'t allow, in these words', function (Closure $case, string $words): void {
    [$item, $parentId] = $case($this->editor);

    expect(app(TreeRules::class)->refusal($item, $parentId))->toBe($words);
})->with([
    'a type that doesn\'t nest' => [fn (User $editor): array => [addrPublished($editor, 'A post'), addrPublished($editor, 'Another post')->id], 'Posts cannot have a parent.'],
    'a parent of another type' => [fn (User $editor): array => [addrPublished($editor, 'Team', 'page'), addrPublished($editor, 'A post')->id], 'The parent must be another page.'],
    'a parent in the trash' => [function (User $editor): array {
        $parent = addrPublished($editor, 'Old', 'page');
        app(TrashContent::class)->execute(new ContentActionInput($parent->id, (string) $editor->id));

        return [addrPublished($editor, 'Team', 'page'), $parent->id];
    }, 'That page is in the trash. Restore it, or choose another parent.'],
    'a parent that doesn\'t exist' => [fn (User $editor): array => [addrPublished($editor, 'Team', 'page'), '01j0000000000000000000000z'], 'That page no longer exists. Choose another parent.'],
    'the item itself' => [function (User $editor): array {
        $page = addrPublished($editor, 'Team', 'page');

        return [$page, $page->id];
    }, "A page can't sit under itself or under a page beneath it."],
    'a page under it' => [function (User $editor): array {
        [$top, , $bottom] = rulesChain($editor, 'Level', 3);

        return [$top, $bottom->id];
    }, "A page can't sit under itself or under a page beneath it."],
    'a ninth level' => [function (User $editor): array {
        $branch = rulesChain($editor, 'Branch', 5);
        $stem = rulesChain($editor, 'Stem', 4);

        return [$branch[0], $stem[3]->id];
    }, 'Pages go eight levels deep at most.'],
    'a feed\'s name' => [function (User $editor): array {
        $page = lifecycleDraft($editor, 'Rss', 'page');
        DB::table('contents')->where('id', $page->id)->update(['slug' => 'rss']);

        return [$page->refresh(), addrPublished($editor, 'About', 'page')->id];
    }, "A page under another page can't be called rss: that address is kept for feeds. Choose another."],
]);

it('allows a draft parent', function (): void {
    expect(app(TreeRules::class)->refusal(addrPublished($this->editor, 'Team', 'page'), lifecycleDraft($this->editor, 'Plans', 'page')->id))->toBeNull();
});

it('allows the top level', function (): void {
    expect(app(TreeRules::class)->refusal(addrPublished($this->editor, 'Team', 'page'), null))->toBeNull()
        ->and(app(TreeRules::class)->refusal(addrPublished($this->editor, 'A post'), null))->toBeNull();
});

it('lets one transaction at a time hold a type\'s tree', function (): void {
    TreeLock::take('page');

    config(['database.connections.tree_probe' => config('database.connections.'.config('database.default'))]);
    $probe = DB::connection('tree_probe');

    expect((bool) $probe->scalar("SELECT pg_try_advisory_xact_lock(hashtext('teme.tree.page'))"))->toBeFalse()
        ->and((bool) $probe->scalar("SELECT pg_try_advisory_xact_lock(hashtext('teme.tree.post'))"))->toBeTrue();

    $probe->disconnect();
});
