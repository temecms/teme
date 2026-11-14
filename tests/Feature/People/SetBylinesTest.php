<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Contents\Operations\Input\UpdateContentDetailsInput;
use App\Domain\Contents\Operations\UpdateContentDetails;
use App\Domain\People\Credit;
use App\Domain\People\Credits;
use App\Domain\People\Operations\Input\SetBylinesInput;
use App\Domain\People\Operations\SetBylines;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\People\Credited;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->ada = User::factory()->create(['name' => 'Ada Lovelace'])->assignRole('editor');
    $this->grace = User::factory()->create(['name' => 'Grace Hopper'])->assignRole('editor');
    $this->author = User::factory()->create(['name' => 'Alan Author'])->assignRole('author');
    app(PathProjection::class)->syncUser($this->ada);
    app(PathProjection::class)->syncUser($this->grace);
    $this->reuters = Credited::guest('Reuters', 'reuters', 'News agency');
    $this->article = addrPublished($this->ada, 'Harbour news');
    $this->set = fn (array $keys, ?User $actor = null, ?string $contentId = null): mixed => app(SetBylines::class)->execute(new SetBylinesInput((string) ($actor ?? $this->ada)->id, $contentId ?? $this->article->id, $keys));
    $this->credits = fn (): array => app(Credits::class)->forItems([$this->article->id => (int) $this->article->author_id])[$this->article->id];
    $this->refusal = function (Closure $run): ?string {
        try {
            $run();
        } catch (ValidationException $refused) {
            return $refused->errors()['people'][0] ?? null;
        }

        return null;
    };
    $this->user = fn (User $user): string => Credit::account((int) $user->id)->key();
    $this->guest = fn (string $id): string => Credit::person($id)->key();
});

it('sets an item\'s credits in order', function (): void {
    ($this->set)([($this->guest)($this->reuters), ($this->user)($this->grace)]);

    expect(($this->credits)())->toEqual([Credit::person($this->reuters), Credit::account((int) $this->grace->id)])
        ->and(DB::table('content_bylines')->where('content_id', $this->article->id)->orderBy('position')->pluck('position')->all())->toBe([0, 1]);
});

it('clears them, so the item credits its owner', function (): void {
    ($this->set)([($this->guest)($this->reuters)]);
    ($this->set)([]);

    expect(($this->credits)())->toEqual([Credit::account((int) $this->ada->id)])
        ->and(DB::table('content_bylines')->count())->toBe(0);
});

it('refuses more than twenty, the same person twice, or someone who doesn\'t exist', function (): void {
    $many = array_map(fn (int $i): string => ($this->guest)(Credited::guest("Agency {$i}", "agency-{$i}")), range(1, 21));
    $adaPerson = (function (): string {
        ($this->set)([($this->user)($this->ada)]);
        ($this->set)([]);

        return (string) DB::table('people')->where('user_id', $this->ada->id)->value('id');
    })();

    expect(($this->refusal)(fn () => ($this->set)($many)))->toBe('An item can credit at most 20 people.')
        ->and(($this->refusal)(fn () => ($this->set)([($this->guest)($this->reuters), ($this->guest)($this->reuters)])))->toBe('Each person can be credited once.')
        ->and(($this->refusal)(fn () => ($this->set)(['user:999999'])))->toBe("There's no such person: user:999999.")
        ->and(($this->refusal)(fn () => ($this->set)(['person:01kv7m3q9x2c5r8t1w4y6z0b3d'])))->toBe("There's no such person: person:01kv7m3q9x2c5r8t1w4y6z0b3d.")
        ->and(($this->refusal)(fn () => ($this->set)(['user:abc'])))->toBe("There's no such person: user:abc.")
        ->and(($this->refusal)(fn () => ($this->set)([($this->guest)($adaPerson)])))->toBe("There's no such person: person:{$adaPerson}.")
        ->and(DB::table('content_bylines')->count())->toBe(0);
});

it('makes an account\'s person the first time a byline names the account, and only then', function (): void {
    expect(DB::table('people')->where('user_id', $this->grace->id)->count())->toBe(0);

    ($this->set)([($this->user)($this->grace)]);
    $person = DB::table('people')->where('user_id', $this->grace->id)->value('id');
    $second = addrPublished($this->ada, 'Ferry times');
    ($this->set)([($this->user)($this->grace)], contentId: $second->id);

    expect(DB::table('people')->where('user_id', $this->grace->id)->pluck('id')->all())->toBe([$person])
        ->and(DB::table('people')->where('id', $person)->first(['name', 'slug', 'bio']))->toEqual((object) ['name' => null, 'slug' => null, 'bio' => null]);
});

it('takes key-share locks on the credited accounts before the item\'s row', function (): void {
    $statements = array_column(planStatements(fn () => ($this->set)([($this->user)($this->grace), ($this->user)($this->ada)])), 'query');
    $accounts = collect($statements)->search(fn (string $sql): bool => str_contains($sql, 'FROM users') && str_contains($sql, 'FOR KEY SHARE'));
    $item = collect($statements)->search(fn (string $sql): bool => str_contains($sql, 'from "contents"') && str_contains($sql, 'for update'));
    $people = collect($statements)->search(fn (string $sql): bool => str_contains($sql, 'INSERT INTO people'));

    expect($accounts)->toBeInt()
        ->and($item)->toBeInt()
        ->and($people)->toBeInt()
        ->and($accounts)->toBeLessThan($item)
        ->and($item)->toBeLessThan($people)
        ->and($statements[$accounts])->toContain('ORDER BY id');
});

it('lets an author credit a guest on their own item', function (): void {
    $own = addrPublished($this->author, 'My story');

    ($this->set)([($this->guest)($this->reuters), ($this->user)($this->author)], $this->author, $own->id);

    expect(DB::table('content_bylines')->where('content_id', $own->id)->count())->toBe(2);
});

it('refuses an author crediting another account, and allows an editor', function (): void {
    $own = addrPublished($this->author, 'My story');

    expect(($this->refusal)(fn () => ($this->set)([($this->user)($this->grace)], $this->author, $own->id)))->toBe("Only someone who may edit anyone's posts may credit another person with an account.")
        ->and(DB::table('content_bylines')->count())->toBe(0);

    ($this->set)([($this->user)($this->grace)], $this->ada, $own->id);

    expect(DB::table('content_bylines')->where('content_id', $own->id)->count())->toBe(1);
});

it('writes nothing when someone who may not edit the item asks', function (): void {
    $before = [DB::table('people')->get()->all(), DB::table('content_bylines')->get()->all(), DB::table('paths')->get()->all()];

    expect(fn () => ($this->set)([($this->user)($this->grace)], $this->author))->toThrow(AuthorizationException::class);

    expect([DB::table('people')->get()->all(), DB::table('content_bylines')->get()->all(), DB::table('paths')->get()->all()])->toEqual($before);
});

it('moves a live item\'s {author} address when its first credit changes, keeping the old one', function (): void {
    addrChange($this->admin, ['patterns' => ['post' => '/{author}/{slug}/']]);
    $this->article->refresh();
    app(PathProjection::class)->syncContent($this->article);
    $before = addrCurrent('content', $this->article->id);

    ($this->set)([($this->guest)($this->reuters), ($this->user)($this->ada)]);

    expect($before)->toBe($this->ada->slug.'/harbour-news')
        ->and(addrCurrent('content', $this->article->id))->toBe('reuters/harbour-news')
        ->and(addrLocation(addrGet('/'.$before.'/')->assertStatus(301)))->toBe('/reuters/harbour-news/');

    $rows = DB::table('paths')->where('target_id', $this->article->id)->count();
    ($this->set)([($this->guest)($this->reuters)]);

    // The same first credit: nothing moves.
    expect(DB::table('paths')->where('target_id', $this->article->id)->count())->toBe($rows);
});

it('refuses a first credit whose address another item holds', function (): void {
    addrChange($this->admin, ['patterns' => ['post' => '/{author}/{slug}/']]);
    DB::table('paths')->insert(['path' => 'reuters/harbour-news', 'target_type' => 'content', 'target_id' => '01kv7m3q9x2c5r8t1w4y6z0b3d', 'is_current' => true]);

    expect(($this->refusal)(fn () => ($this->set)([($this->guest)($this->reuters)])))->toStartWith('The address /reuters/harbour-news/ is used by')
        ->and(DB::table('content_bylines')->count())->toBe(0);
});

it('takes the tree lock before the item\'s row for a page', function (): void {
    $page = addrPublished($this->ada, 'About', 'page');
    $statements = array_column(planStatements(fn () => ($this->set)([($this->guest)($this->reuters)], contentId: $page->id)), 'query');
    $lock = collect($statements)->search(fn (string $sql): bool => str_contains($sql, 'pg_advisory_xact_lock'));
    $row = collect($statements)->search(fn (string $sql): bool => str_contains($sql, 'for update'));

    expect($lock)->toBeInt()
        ->and($row)->toBeInt()
        ->and($lock)->toBeLessThan($row);
});

it('purges both people\'s lists after commit, and nothing on a rollback', function (): void {
    $folder = cacheOn();
    $pages = ['/author/'.$this->ada->slug.'/', '/author/reuters/', '/harbour-news/', '/author/'.$this->grace->slug.'/'];

    foreach ($pages as $page) {
        cacheGet($page)->assertOk();
    }

    expect(cacheKept(...$pages))->toBe($pages);

    cacheRolledBack(fn () => ($this->set)([($this->guest)($this->reuters)]));

    expect(cacheKept(...$pages))->toBe($pages);

    ($this->set)([($this->guest)($this->reuters)]);

    // The old credit's list and the new one's go, with the article; a list the change doesn't touch stays.
    expect(cacheKept(...$pages))->toBe(['/author/'.$this->grace->slug.'/']);

    cacheOff($folder);
});

it('refuses, writing nothing, when a person it names is deleted between its check and its write', function (): void {
    $removed = false;

    // Another request deletes Reuters right after this one locks the item's row.
    DB::listen(function (QueryExecuted $query) use (&$removed): void {
        if (! $removed && str_contains($query->sql, 'from "contents"') && str_contains($query->sql, 'for update')) {
            $removed = true;
            DB::table('people')->where('id', $this->reuters)->delete();
        }
    });

    expect(($this->refusal)(fn () => ($this->set)([($this->guest)($this->reuters), ($this->user)($this->ada)])))->toBe("There's no such person: person:{$this->reuters}.")
        ->and($removed)->toBeTrue()
        ->and(DB::table('content_bylines')->count())->toBe(0)
        ->and(DB::table('people')->whereNotNull('user_id')->count())->toBe(0);
});

it('locks the people it names by ID after the item\'s row', function (): void {
    $wire = Credited::guest('Wire', 'wire');
    $statements = array_column(planStatements(fn () => ($this->set)([($this->guest)($wire), ($this->guest)($this->reuters)])), 'query');
    $row = collect($statements)->search(fn (string $sql): bool => str_contains($sql, 'from "contents"') && str_contains($sql, 'for update'));
    $people = collect($statements)->search(fn (string $sql): bool => str_contains($sql, 'FROM people') && str_contains($sql, 'ORDER BY id FOR KEY SHARE'));

    expect($row)->toBeInt()
        ->and($people)->toBeInt()
        ->and($row)->toBeLessThan($people);
});

it('changes the credit of an item without bylines when its owner changes, and not of one with them', function (): void {
    $plain = addrPublished($this->ada, 'Plain');
    $credited = addrPublished($this->ada, 'Credited');
    ($this->set)([($this->guest)($this->reuters)], contentId: $credited->id);

    foreach ([$plain, $credited] as $item) {
        $item->refresh();
        app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput(contentId: $item->id, expectedRevisionId: $item->working_revision_id, actorId: (string) $this->admin->id, authorId: (int) $this->grace->id));
    }

    $credits = app(Credits::class)->forItems([$plain->id => (int) $this->grace->id, $credited->id => (int) $this->grace->id]);

    expect($credits[$plain->id])->toEqual([Credit::account((int) $this->grace->id)])
        ->and($credits[$credited->id])->toEqual([Credit::person($this->reuters)]);
});
