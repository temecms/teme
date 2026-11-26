<?php

declare(strict_types=1);

use App\Domain\Hooks\HookDispatcher;
use App\Domain\Taxonomy\Operations\CreateTerm;
use App\Domain\Taxonomy\Operations\DeleteTerm;
use App\Domain\Taxonomy\Operations\Input\DeleteTermInput;
use App\Domain\Taxonomy\Operations\Input\SaveTermInput;
use App\Domain\Taxonomy\Operations\UpdateTerm;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Taxonomy\TermCreated;
use Teme\Contracts\Taxonomy\TermDeleted;
use Teme\Contracts\Taxonomy\TermUpdated;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->nobody = User::factory()->create();
});

/**
 * Records each term hook's payload and the open transaction depth when a
 * listener runs, the same way the media hook tests do.
 *
 * @return ArrayObject<int, array{hook: string, payload: object, depth: int}>
 */
function termHookRecord(string ...$hooks): ArrayObject
{
    $heard = new ArrayObject;
    $core = app(HookDispatcher::class)->subscriber('core');

    foreach ($hooks as $hook) {
        $core->on($hook, function (object $payload) use ($heard, $hook): void {
            $heard[] = ['hook' => $hook, 'payload' => $payload, 'depth' => DB::transactionLevel()];
        });
    }

    return $heard;
}

it('sends TermCreated once, after commit, with the term and who made it', function (): void {
    $base = DB::transactionLevel();
    $heard = termHookRecord(TermCreated::class);

    $term = app(CreateTerm::class)->execute(new SaveTermInput('category', 'Harbour news', null, null, null, (string) $this->editor->id));

    expect($heard)->toHaveCount(1)
        ->and($heard[0]['depth'])->toBe($base)
        ->and($heard[0]['payload']->term->id)->toBe((string) $term->id)
        ->and($heard[0]['payload']->term->taxonomy)->toBe('category')
        ->and($heard[0]['payload']->term->name)->toBe('Harbour news')
        ->and($heard[0]['payload']->term->slug)->toBe('harbour-news')
        ->and($heard[0]['payload']->term->parentId)->toBeNull()
        ->and($heard[0]['payload']->actorId)->toBe((string) $this->editor->id);
});

it('sends TermUpdated once, after commit, with the term as it is now', function (): void {
    $term = app(CreateTerm::class)->execute(new SaveTermInput('category', 'Harbour news', null, null, null, (string) $this->editor->id));
    $base = DB::transactionLevel();
    $heard = termHookRecord(TermUpdated::class);

    app(UpdateTerm::class)->execute(new SaveTermInput('category', 'Harbour stories', null, null, null, (string) $this->editor->id, (string) $term->id));

    expect($heard)->toHaveCount(1)
        ->and($heard[0]['depth'])->toBe($base)
        ->and($heard[0]['payload']->term->id)->toBe((string) $term->id)
        ->and($heard[0]['payload']->term->name)->toBe('Harbour stories')
        ->and($heard[0]['payload']->actorId)->toBe((string) $this->editor->id);
});

it('sends TermDeleted with the term as it was before its row went, after commit', function (): void {
    $term = app(CreateTerm::class)->execute(new SaveTermInput('category', 'Harbour news', null, null, null, (string) $this->editor->id));
    $base = DB::transactionLevel();
    $heard = termHookRecord(TermDeleted::class);

    app(DeleteTerm::class)->execute(new DeleteTermInput((string) $term->id, (string) $this->editor->id));

    expect($heard)->toHaveCount(1)
        ->and($heard[0]['depth'])->toBe($base)
        ->and($heard[0]['payload']->term->id)->toBe((string) $term->id)
        ->and($heard[0]['payload']->term->name)->toBe('Harbour news')
        ->and($heard[0]['payload']->term->taxonomy)->toBe('category')
        ->and($heard[0]['payload']->actorId)->toBe((string) $this->editor->id);
});

it('sends no term hook when a change is refused', function (): void {
    $term = app(CreateTerm::class)->execute(new SaveTermInput('category', 'Harbour news', null, null, null, (string) $this->editor->id));
    $heard = termHookRecord(TermCreated::class, TermUpdated::class, TermDeleted::class);

    expect(fn () => app(CreateTerm::class)->execute(new SaveTermInput('category', 'Nope', null, null, null, (string) $this->nobody->id)))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateTerm::class)->execute(new SaveTermInput('category', 'Nope', null, null, null, (string) $this->nobody->id, (string) $term->id)))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteTerm::class)->execute(new DeleteTermInput((string) $term->id, (string) $this->nobody->id)))->toThrow(AuthorizationException::class)
        ->and($heard->getArrayCopy())->toBe([]);
});
