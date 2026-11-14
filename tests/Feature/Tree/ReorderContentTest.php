<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Input\ReorderContentInput;
use App\Domain\Contents\Operations\ReorderContent;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Tree\PageTree;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->tree = PageTree::build();
});

function reorder(Content $item, string $direction, User $actor): void
{
    app(ReorderContent::class)->execute(new ReorderContentInput($item->id, $direction, (string) $actor->id));
}

/**
 * The positions of these items, in the order given.
 *
 * @return list<int>
 */
function reorderPositions(Content ...$items): array
{
    return array_map(fn (Content $item): int => (int) DB::table('contents')->where('id', $item->id)->value('position'), $items);
}

it('moves a page up among its siblings and numbers them 0, 1, 2', function (): void {
    // About's live children: team 0, history 1, plans 2, secret 3 (archived is in the trash).
    reorder($this->tree->plans, 'up', $this->tree->editor);

    expect(reorderPositions($this->tree->team, $this->tree->plans, $this->tree->history, $this->tree->secret))->toBe([0, 1, 2, 3]);
});

it('moves a page down', function (): void {
    reorder($this->tree->team, 'down', $this->tree->editor);

    expect(reorderPositions($this->tree->history, $this->tree->team, $this->tree->plans, $this->tree->secret))->toBe([0, 1, 2, 3]);
});

it('refuses the first page up and the last down, in v9\'s words, writing nothing', function (string $page, string $direction, string $words): void {
    $before = DB::table('contents')->orderBy('id')->get(['id', 'position', 'updated_at'])->all();

    expect(fn () => reorder($this->tree->{$page}, $direction, $this->tree->editor))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['direction'])->toBe([$words]));

    expect(DB::table('contents')->orderBy('id')->get(['id', 'position', 'updated_at'])->all())->toEqual($before);
})->with([
    'first, up' => ['team', 'up', 'It is already first.'],
    'last, down' => ['secret', 'down', 'It is already last.'],
]);

it('reorders the top level', function (): void {
    // At the top: about and services, both at 0, ordered by ID.
    [$first, $second] = collect([$this->tree->about, $this->tree->services])->sortBy('id')->values()->all();

    reorder($second, 'up', $this->tree->editor);

    expect(reorderPositions($second, $first))->toBe([0, 1]);
});

it('leaves the order of pages in the trash alone', function (): void {
    reorder($this->tree->plans, 'up', $this->tree->editor);

    expect(reorderPositions($this->tree->archived))->toBe([4]);
});

it('touches no updated_at', function (): void {
    $before = DB::table('contents')->orderBy('id')->pluck('updated_at', 'id')->all();

    reorder($this->tree->plans, 'up', $this->tree->editor);

    expect(DB::table('contents')->orderBy('id')->pluck('updated_at', 'id')->all())->toBe($before);
});

it('refuses a list longer than 1,000, in these words', function (): void {
    $template = (array) DB::table('contents')->where('id', $this->tree->plans->id)->first();
    $rows = [];

    foreach (range(1, 1001) as $n) {
        $rows[] = ['id' => strtolower((string) Str::ulid()), 'slug' => "many-{$n}", 'position' => $n, 'working_revision_id' => null, 'published_revision_id' => null] + $template;
    }

    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('contents')->insert($chunk);
    }

    expect(fn () => reorder($this->tree->plans, 'up', $this->tree->editor))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['direction'])->toBe(['There are too many pages here to move one step at a time. Set its Order in Quick edit instead.']));
});

it('refuses an author and writes nothing', function (): void {
    $before = DB::table('contents')->orderBy('id')->get(['id', 'position'])->all();

    expect(fn () => reorder($this->tree->plans, 'up', $this->tree->author))->toThrow(AuthorizationException::class);

    expect(DB::table('contents')->orderBy('id')->get(['id', 'position'])->all())->toEqual($before);
});

it('refuses a contributor and writes nothing', function (): void {
    $contributor = User::factory()->create()->assignRole('contributor');
    $before = DB::table('contents')->orderBy('id')->get(['id', 'position'])->all();

    expect(fn () => reorder($this->tree->plans, 'up', $contributor))->toThrow(AuthorizationException::class);

    expect(DB::table('contents')->orderBy('id')->get(['id', 'position'])->all())->toEqual($before);
});

it('sets aside the parent\'s page', function (): void {
    $folder = cacheOn();
    cacheShown(['/about/' => ['children:'.$this->tree->about->id], '/elsewhere/' => ['content:unrelated']]);

    reorder($this->tree->plans, 'up', $this->tree->editor);

    expect(cacheKept('/about/', '/elsewhere/'))->toBe(['/elsewhere/'])
        ->and(cacheSetAside('/about/'))->not->toBeNull();

    cacheOff($folder);
});

it('moves no address', function (): void {
    $before = DB::table('paths')->orderBy('path')->get()->all();

    reorder($this->tree->team, 'down', $this->tree->editor);

    expect(DB::table('paths')->orderBy('path')->get()->all())->toEqual($before);
});
