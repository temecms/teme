<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Input\UpdateContentDetailsInput;
use App\Domain\Contents\Operations\UpdateContentDetails;
use App\Domain\Contents\Tree\TreePlace;
use App\Domain\Contents\Tree\TreeRules;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\Fixtures\Tree\PageTree;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->tree = PageTree::build();
});

/**
 * Puts the item under the parent, or at the top, through the real Operation.
 */
function placeMove(Content $item, ?Content $parent, User $actor, ?int $position = null, ?string $slug = null): void
{
    $item = Content::withTrashed()->findOrFail($item->id);

    app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput(
        contentId: $item->id,
        expectedRevisionId: (string) $item->working_revision_id,
        actorId: (string) $actor->id,
        slug: $slug,
        place: new TreePlace($parent?->id, $position),
    ));
}

/**
 * What a refused move must leave as it was: every item's place, every
 * address and every revision.
 *
 * @return array<string, mixed>
 */
function placeState(): array
{
    return [
        'contents' => DB::table('contents')->orderBy('id')->get(['id', 'parent_id', 'position', 'slug', 'working_revision_id', 'updated_at'])->map(fn (object $row): array => (array) $row)->all(),
        'paths' => DB::table('paths')->orderBy('path')->get(['path', 'target_id', 'is_current'])->map(fn (object $row): array => (array) $row)->all(),
        'revisions' => DB::table('content_revisions')->count(),
    ];
}

it('puts a page under another, at its new address, the old one answering 301', function (): void {
    placeMove($this->tree->history, $this->tree->team, $this->tree->editor);

    $response = addrGet('/about/history/');

    expect(addrCurrent('content', $this->tree->history->id))->toBe('about/team/history')
        ->and($response->getStatusCode())->toBe(301)
        ->and(addrLocation($response))->toBe('/about/team/history/');
});

it('gives a page moved under a draft its address without the draft\'s slug', function (): void {
    placeMove($this->tree->history, $this->tree->services, $this->tree->editor);

    expect(addrCurrent('content', $this->tree->history->id))->toBe('history')
        ->and($this->tree->history->refresh()->parent_id)->toBe($this->tree->services->id);
});

it('puts a moved page last among its new siblings, and keeps its place when only the order changes', function (): void {
    placeMove($this->tree->history, $this->tree->team, $this->tree->editor);

    expect($this->tree->history->refresh()->position)->toBe(1);

    placeMove($this->tree->history, $this->tree->team, $this->tree->editor);

    expect($this->tree->history->refresh()->position)->toBe(1)
        ->and($this->tree->history->parent_id)->toBe($this->tree->team->id);
});

it('sets the order it is given', function (): void {
    placeMove($this->tree->history, $this->tree->about, $this->tree->editor, 7);

    expect($this->tree->history->refresh()->position)->toBe(7)
        ->and(addrCurrent('content', $this->tree->history->id))->toBe('about/history');
});

it('moves the pages under a moved page with it', function (): void {
    placeMove($this->tree->team, $this->tree->history, $this->tree->editor);

    expect(addrCurrent('content', $this->tree->team->id))->toBe('about/history/team')
        ->and(addrCurrent('content', $this->tree->people->id))->toBe('about/history/team/people')
        ->and(addrGet('/about/team/people/')->getStatusCode())->toBe(301);
});

it('writes no revision and keeps the draft waiting for a place alone', function (): void {
    $working = $this->tree->plans->working_revision_id;
    $revisions = DB::table('content_revisions')->count();

    placeMove($this->tree->plans, $this->tree->team, $this->tree->editor);

    expect($this->tree->plans->refresh()->working_revision_id)->toBe($working)
        ->and($this->tree->plans->status)->toBe('draft')
        ->and(DB::table('content_revisions')->count())->toBe($revisions);
});

it('refuses each of the tree\'s rules and writes nothing', function (Closure $case, string $words): void {
    [$item, $parent] = $case($this->tree);
    $before = placeState();

    expect(fn () => placeMove($item, $parent, $this->tree->editor))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['parent'])->toBe([$words]));

    expect(placeState())->toBe($before);
})->with([
    'a type that doesn\'t nest' => [fn (PageTree $tree): array => [$tree->teamPost, $tree->about], 'Posts cannot have a parent.'],
    'a parent of another type' => [fn (PageTree $tree): array => [$tree->history, $tree->teamPost], 'The parent must be another page.'],
    'a parent in the trash' => [fn (PageTree $tree): array => [$tree->history, $tree->archived], 'That page is in the trash. Restore it, or choose another parent.'],
    'a parent that doesn\'t exist' => [function (PageTree $tree): array {
        $gone = $tree->plans->replicate();
        $gone->id = '01j0000000000000000000000z';

        return [$tree->history, $gone];
    }, 'That page no longer exists. Choose another parent.'],
    'itself' => [fn (PageTree $tree): array => [$tree->history, $tree->history], "A page can't sit under itself or under a page beneath it."],
    'a page under it' => [fn (PageTree $tree): array => [$tree->about, $tree->people], "A page can't sit under itself or under a page beneath it."],
    'a ninth level' => [function (PageTree $tree): array {
        $parent = $tree->people;

        foreach (range(4, 8) as $level) {
            $page = addrPublished($tree->editor, "Level {$level}", 'page');
            PageTree::place($page, $parent);
            $parent = $page;
        }

        return [$tree->history, $parent];
    }, 'Pages go eight levels deep at most.'],
]);

it('refuses an order outside 0 to 99,999 and writes nothing', function (int $position): void {
    $before = placeState();

    expect(fn () => placeMove($this->tree->history, $this->tree->about, $this->tree->editor, $position))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['position'])->toBe(['Order must be a whole number from 0 to 99,999.']));

    expect(placeState())->toBe($before);
})->with([-1, 100000]);

it('refuses a move that would put a page under it at an address something else holds, naming both, and writes nothing', function (): void {
    // The post "Team" holds the address people would move to.
    DB::table('paths')->where('target_id', $this->tree->teamPost->id)->where('is_current', true)->update(['path' => 'about/history/team/people']);
    $before = placeState();

    expect(fn () => placeMove($this->tree->team, $this->tree->history, $this->tree->editor))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['slug'])->toBe(['Its page “People” would move to /about/history/team/people/, which is used by the post "Team".']));

    expect(placeState())->toBe($before);
});

it('refuses an author and a contributor who may edit the page but not everyone\'s, and writes nothing', function (string $role): void {
    Role::findByName($role)->givePermissionTo(['page.create', 'page.edit.own']);
    $person = User::factory()->create()->assignRole($role);
    $mine = lifecycleDraft($person, 'Mine', 'page');
    $before = placeState();

    expect(fn () => placeMove($mine, $this->tree->about, $person))->toThrow(AuthorizationException::class);

    expect(placeState())->toBe($before);
})->with(['author', 'contributor']);

it('refuses a move that a move just made into a loop, under the lock', function (): void {
    $tree = $this->tree;
    $real = app(TreeRules::class);
    app()->instance(TreeRules::class, new class($real, $tree)
    {
        private bool $raced = false;

        public function __construct(private readonly TreeRules $real, private readonly PageTree $tree) {}

        public function refusal(Content $item, ?string $parentId): ?string
        {
            $answer = $this->real->refusal($item, $parentId);

            if (! $this->raced) {
                $this->raced = true;
                // The first move commits between the second's validate() and perform().
                placeMove($this->tree->team, $this->tree->history, $this->tree->editor);
            }

            return $answer;
        }
    });

    $team = DB::table('contents')->where('id', $tree->team->id)->first(['parent_id', 'position']);

    expect(fn () => placeMove($tree->history, $tree->team, $tree->editor))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['parent'])->toBe(["A page can't sit under itself or under a page beneath it."]));

    expect($tree->history->refresh()->parent_id)->toBe($tree->about->id)
        ->and($tree->team->refresh()->parent_id)->toBe($tree->history->id)
        ->and($team->parent_id)->toBe($tree->about->id)
        ->and(addrCurrent('content', $tree->history->id))->toBe('about/history');
});

it('sets aside the old parent\'s and the new parent\'s pages', function (): void {
    $folder = cacheOn();
    cacheShown([
        '/about/' => ['children:'.$this->tree->about->id],
        '/about/team/' => ['children:'.$this->tree->team->id],
        '/elsewhere/' => ['content:unrelated'],
    ]);

    placeMove($this->tree->history, $this->tree->team, $this->tree->editor);

    expect(cacheKept('/about/', '/about/team/', '/elsewhere/'))->toBe(['/elsewhere/'])
        ->and(cacheSetAside('/about/'))->not->toBeNull()
        ->and(cacheSetAside('/about/team/'))->not->toBeNull();

    cacheOff($folder);
});

it('refuses moving a draft whose published pages would clash, and writes nothing', function (): void {
    DB::table('paths')->where('target_id', $this->tree->teamPost->id)->where('is_current', true)->update(['path' => 'about/web']);
    $before = placeState();

    expect(fn () => placeMove($this->tree->services, $this->tree->about, $this->tree->editor))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['slug'])->toBe(['Its page “Web” would move to /about/web/, which is used by the post "Team".']));

    expect(placeState())->toBe($before);
});

it('refuses a slug that is a feed\'s name for a page with a parent', function (): void {
    $before = placeState();

    expect(fn () => addrRename($this->tree->history, 'rss', $this->tree->editor))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['slug'])->toBe(["A page under another page can't be called rss: that address is kept for feeds. Choose another."]));

    expect(placeState())->toBe($before);
});

it('refuses a publish whose pending address is a feed\'s name for a child page, and changes nothing', function (): void {
    $history = $this->tree->history;
    $before = placeState();
    $live = addrCurrent('content', $history->id);
    $slug = $history->slug;

    $this->actingAs($this->tree->editor)->postJson("/admin/content/{$history->id}/publish", [
        'revision_id' => $history->working_revision_id,
        'slug' => 'rss',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.slug.0', "A page under another page can't be called rss: that address is kept for feeds. Choose another.");

    expect(placeState())->toBe($before)
        ->and(addrCurrent('content', $history->id))->toBe($live)
        ->and($history->refresh()->slug)->toBe($slug)
        ->and($history->status)->toBe('published');
});
