<?php

declare(strict_types=1);

use App\Domain\Users\Operations\DeleteUser;
use App\Domain\Users\Operations\Input\DeleteUserInput;
use App\Domain\Users\Operations\Input\UpdateProfileInput;
use App\Domain\Users\Operations\UpdateProfile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->zainab = User::factory()->create(['name' => 'Zainab'])->assignRole('author');
    $this->amaka = User::factory()->create(['name' => '  amaka '])->assignRole('author');
    $this->chidi = User::factory()->create(['name' => 'Chidi'])->assignRole('author');
});

it('sorts by author name, and ties are broken by id', function (): void {
    lifecycleDraft($this->zainab, 'By Zainab');
    $first = lifecycleDraft($this->amaka, 'By amaka, one');
    $second = lifecycleDraft($this->amaka, 'By amaka, two');
    lifecycleDraft($this->chidi, 'By Chidi');

    $amaka = collect([$first, $second])->sortBy('id')->pluck('title')->all();

    expect(array_column(lscrPage(['sort' => 'author', 'direction' => 'asc'])['rows'], 'title'))
        ->toBe([...$amaka, 'By Chidi', 'By Zainab'])
        ->and(array_column(lscrPage(['sort' => 'author', 'direction' => 'desc'])['rows'], 'title'))
        ->toBe(['By Zainab', 'By Chidi', ...array_reverse($amaka)])
        ->and(DB::table('contents')->where('id', $first->id)->value('author_sort'))->toBe('amaka');
});

it('keeps the author sort right when an author is renamed', function (): void {
    lifecycleDraft($this->amaka, 'By amaka');
    $chidis = lifecycleDraft($this->chidi, 'By Chidi');

    app(UpdateProfile::class)->execute(new UpdateProfileInput((string) $this->chidi->id, 'Ada', $this->chidi->email, (string) $this->chidi->id));

    expect(DB::table('contents')->where('id', $chidis->id)->value('author_sort'))->toBe('ada')
        ->and(array_column(lscrPage(['sort' => 'author', 'direction' => 'asc'])['rows'], 'title'))->toBe(['By Chidi', 'By amaka']);
});

it('keeps the author sort right when content is reassigned on deleting a person', function (): void {
    $this->withElevation();
    $admin = User::factory()->create(['name' => 'Admin'])->assignRole('administrator');
    $zainabs = lifecycleDraft($this->zainab, 'By Zainab');
    lifecycleDraft($this->chidi, 'By Chidi');

    app(DeleteUser::class)->execute(new DeleteUserInput((string) $this->zainab->id, (string) $this->amaka->id, (string) $admin->id));

    expect(DB::table('contents')->where('id', $zainabs->id)->value('author_sort'))->toBe('amaka')
        ->and(array_column(lscrPage(['sort' => 'author', 'direction' => 'asc'])['rows'], 'title'))->toBe(['By Zainab', 'By Chidi']);
});

it('pages the author sort by keyset in both directions without OFFSET', function (): void {
    foreach (range(1, 60) as $n) {
        lifecycleDraft([$this->zainab, $this->amaka, $this->chidi][$n % 3], "Post {$n}");
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $first = lscrPage(['sort' => 'author', 'direction' => 'asc']);
    $second = lscrPage(['sort' => 'author', 'direction' => 'asc', 'after' => $first['older']]);
    $back = lscrPage(['sort' => 'author', 'direction' => 'asc', 'before' => $second['newer']]);

    $statements = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    expect($first['rows'])->toHaveCount(50)
        ->and($second['rows'])->toHaveCount(10)
        ->and(array_column($back['rows'], 'id'))->toBe(array_column($first['rows'], 'id'))
        ->and(collect($statements)->filter(fn (string $sql): bool => str_contains(strtolower($sql), 'offset')))->toBeEmpty();
});

it('walks an index for the author sort, never sorting the table', function (string $tab): void {
    lifecycleDraft($this->amaka, 'By amaka');
    DB::statement('ANALYZE contents');

    $plan = lscrPlan(['tab' => $tab, 'sort' => 'author', 'direction' => 'asc']);

    // Either author_sort index answers the order; which one the planner
    // picks on a one-row table is its business. What matters is no sort.
    expect($plan)->toMatch('/Index (Only )?Scan using contents_list_(status_)?author_sort/')
        ->and($plan)->not->toContain('Sort Key');
})->with(['all', 'drafts']);

it('offers no author sort on the Mine tab, and keeps its own order there', function (): void {
    $first = lifecycleDraft($this->zainab, 'Created first');
    $second = lifecycleDraft($this->zainab, 'Created second');
    // Changed last, so the updated order is the opposite of the id order an
    // author sort would fall back on when every row has the same author.
    DB::table('contents')->where('id', $first->id)->update(['updated_at' => now()->addMinute()]);

    $rows = lscrPage(['tab' => 'mine', 'sort' => 'author', 'direction' => 'asc', 'viewerId' => $this->zainab->id])['rows'];

    expect(array_column($rows, 'id'))->toBe([$second->id, $first->id]);
});

it('falls back from the author sort while the Author filter is set', function (): void {
    $first = lifecycleDraft($this->amaka, 'Created first');
    $second = lifecycleDraft($this->amaka, 'Created second');
    DB::table('contents')->where('id', $first->id)->update(['updated_at' => now()->addMinute()]);

    $rows = lscrPage(['sort' => 'author', 'direction' => 'asc', 'authorId' => $this->amaka->id])['rows'];

    expect(array_column($rows, 'id'))->toBe([$second->id, $first->id]);
});
