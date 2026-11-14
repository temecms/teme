<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\People\Credit;
use App\Domain\People\Credits;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\People\Credited;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->credits = app(Credits::class);
    $this->ada = User::factory()->create(['name' => 'Ada Lovelace'])->assignRole('editor');
    $this->grace = User::factory()->create(['name' => 'Grace Hopper'])->assignRole('editor');
    app(PathProjection::class)->syncUser($this->ada);
    app(PathProjection::class)->syncUser($this->grace);
    $this->reuters = Credited::guest('Reuters', 'reuters', 'News agency');
    $this->ids = function (Credit $credit): array {
        [$sql, $bindings] = $this->credits->itemIdsSql($credit);

        return collect(DB::select($sql, $bindings))->pluck('content_id')->map(fn (mixed $id): string => (string) $id)->sort()->values()->all();
    };
});

it('credits the owner of an item with no bylines', function (): void {
    $item = lifecycleDraft($this->ada, 'Article');

    expect($this->credits->forItems([$item->id => (int) $this->ada->id]))->toEqual([$item->id => [Credit::account((int) $this->ada->id)]])
        ->and(DB::table('people')->whereNotNull('user_id')->count())->toBe(0);
});

it('credits an item\'s bylines in their order, its owner not among them', function (): void {
    $item = lifecycleDraft($this->ada, 'Article');
    Credited::set($item->id, [Credit::person($this->reuters), Credit::account((int) $this->grace->id)]);

    expect($this->credits->forItems([$item->id => (int) $this->ada->id]))->toEqual([$item->id => [Credit::person($this->reuters), Credit::account((int) $this->grace->id)]]);
});

it('credits no one on an item with no owner and no bylines', function (): void {
    $item = lifecycleDraft($this->ada, 'Article');

    expect($this->credits->forItems([$item->id => null]))->toBe([$item->id => []])
        ->and($this->credits->forItems([]))->toBe([]);
});

it('reads the credits of 50 items in one statement', function (): void {
    $owners = [];

    foreach (range(1, 50) as $n) {
        $item = lifecycleDraft($n % 2 === 0 ? $this->ada : $this->grace, "Article {$n}");
        $owners[$item->id] = (int) $item->author_id;

        if ($n % 5 === 0) {
            Credited::set($item->id, [Credit::person($this->reuters)]);
        }
    }

    DB::enableQueryLog();
    $credits = $this->credits->forItems($owners);

    expect(DB::getQueryLog())->toHaveCount(1)
        ->and($credits)->toHaveCount(50);
});

it('lists an item for each person it credits, and for its owner only when it has no bylines', function (): void {
    $owned = lifecycleDraft($this->ada, 'Owned');
    $guested = lifecycleDraft($this->ada, 'Guested');
    $shared = lifecycleDraft($this->grace, 'Shared');
    Credited::set($guested->id, [Credit::person($this->reuters)]);
    Credited::set($shared->id, [Credit::person($this->reuters), Credit::account((int) $this->ada->id)]);

    expect(($this->ids)(Credit::account((int) $this->ada->id)))->toEqual(collect([$owned->id, $shared->id])->sort()->values()->all())
        ->and(($this->ids)(Credit::person($this->reuters)))->toEqual(collect([$guested->id, $shared->id])->sort()->values()->all())
        ->and(($this->ids)(Credit::account((int) $this->grace->id)))->toBe([]);
});

it('can answer each branch from its index', function (): void {
    foreach (range(1, 30) as $n) {
        lifecycleDraft($n % 3 === 0 ? $this->ada : $this->grace, "Article {$n}");
    }

    Credited::set(lifecycleDraft($this->grace, 'Shared')->id, [Credit::person($this->reuters), Credit::account((int) $this->ada->id)]);
    DB::statement('ANALYZE content_bylines');
    DB::statement('ANALYZE people');
    DB::statement('ANALYZE contents');

    $plan = DB::transaction(function (): string {
        DB::statement('SET LOCAL enable_seqscan = off');
        $lines = [];

        foreach ([Credit::account((int) $this->ada->id), Credit::person($this->reuters)] as $credit) {
            [$sql, $bindings] = $this->credits->itemIdsSql($credit);
            $lines = [...$lines, ...array_map(fn (object $row): string => (string) array_values((array) $row)[0], DB::select('EXPLAIN '.$sql, $bindings))];
        }

        return implode("\n", $lines);
    });

    expect($plan)->toContain('content_bylines_person_id_content_id_index')
        ->and($plan)->toContain('people_user_id_unique')
        ->and($plan)->toMatch('/contents_author_id_(index|id)\b/')
        ->and($plan)->not->toContain('Seq Scan');
});

it('gives the first credit\'s slug, a guest\'s or an account\'s', function (): void {
    $item = lifecycleDraft($this->ada, 'Article');

    DB::enableQueryLog();
    $owner = $this->credits->firstSlug($item->id);

    expect(DB::getQueryLog())->toHaveCount(1)
        ->and($owner)->toBe($this->ada->slug);

    Credited::set($item->id, [Credit::person($this->reuters), Credit::account((int) $this->grace->id)]);

    expect($this->credits->firstSlug($item->id))->toBe('reuters');

    Credited::set($item->id, [Credit::account((int) $this->grace->id)]);

    expect($this->credits->firstSlug($item->id))->toBe($this->grace->slug);

    // With no person row yet for the account named first.
    DB::table('content_bylines')->delete();
    DB::table('people')->whereNotNull('user_id')->delete();

    expect($this->credits->firstSlug($item->id))->toBe($this->ada->slug)
        ->and($this->credits->firstSlug(lifecycleDraft($this->ada, 'Other')->id))->toBe($this->ada->slug);
});

it('reads 50 items\' credits and their people in one statement', function (): void {
    $ids = [];

    foreach (range(1, 50) as $n) {
        $item = lifecycleDraft($n % 2 === 0 ? $this->ada : $this->grace, "Article {$n}");
        $ids[] = $item->id;

        if ($n % 5 === 0) {
            Credited::set($item->id, [Credit::person($this->reuters), Credit::account((int) $this->ada->id)]);
        }
    }

    DB::enableQueryLog();
    $shown = $this->credits->shown($ids);

    expect(DB::getQueryLog())->toHaveCount(1)
        ->and($shown)->toHaveCount(50)
        ->and(array_map(fn (object $person): string => $person->name, $shown[$ids[4]]))->toBe(['Reuters', 'Ada Lovelace'])
        ->and(array_map(fn (object $person): string => $person->kind.':'.$person->path, $shown[$ids[4]]))->toBe(['person:author/reuters', 'user:author/'.$this->ada->slug])
        ->and(array_map(fn (object $person): string => $person->name, $shown[$ids[0]]))->toBe(['Grace Hopper']);
});
