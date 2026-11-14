<?php

declare(strict_types=1);

use App\Domain\Comments\Privacy\CommentsHolder;
use App\Domain\Hooks\HookDispatcher;
use App\Domain\Privacy\Models\PrivacyRequest;
use App\Domain\Privacy\Operations\Input\RecordPrivacyRequestInput;
use App\Domain\Privacy\Operations\Input\RunPrivacyErasureInput;
use App\Domain\Privacy\Operations\RecordPrivacyRequest;
use App\Domain\Privacy\Operations\RunPrivacyErasure;
use App\Domain\Privacy\PersonalDataHolders;
use App\Domain\Privacy\PrivacyStep;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Privacy\CollectPersonalDataHolders;
use Teme\Contracts\Privacy\ErasePage;
use Tests\Fixtures\Privacy\RecordingHolder;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->item = cmItem($this->editor);
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(PersonalDataHolders::class, new PersonalDataHolders(app(HookDispatcher::class), [CommentsHolder::class]));

    $this->keeper = new RecordingHolder('acme.keeper', 'Keeper', [], [
        new ErasePage(1, 2, ['Kept for the accounts.', 'Kept by law.'], false),
        new ErasePage(0, 1, ['Kept for the accounts.'], true),
    ]);
    app(HookDispatcher::class)->subscriber('acme/test', [], ['CollectPersonalDataHolders'])
        ->on(CollectPersonalDataHolders::class, fn (): array => [$this->keeper]);

    $this->id = app(RecordPrivacyRequest::class)->execute(new RecordPrivacyRequestInput('erase', 'ada@example.com', 'Asked by email.', false));
    $this->step = fn (?string $actorId = null): PrivacyStep => app(RunPrivacyErasure::class)->execute(new RunPrivacyErasureInput($this->id, $actorId));
});

it('steps through the holders and ends done, with what each removed and kept and why, each reason once', function (): void {
    cmComment($this->item);
    cmComment($this->item, 'pending');

    $steps = [];

    do {
        $step = ($this->step)();
        $steps[] = $step->holder;
    } while (! $step->done);

    $request = PrivacyRequest::query()->findOrFail($this->id);

    expect($steps)->toBe(['comments', 'acme.keeper', 'acme.keeper', null])
        ->and($request->status)->toBe('done')
        ->and($request->completed_at)->not->toBeNull()
        ->and($request->export_path)->toBeNull()
        ->and($request->results[0])->toMatchArray(['key' => 'comments', 'page' => 1, 'removed' => 2, 'retained' => 0, 'reasons' => [], 'done' => true])
        ->and($request->results[1])->toMatchArray(['key' => 'acme.keeper', 'page' => 2, 'removed' => 1, 'retained' => 3, 'reasons' => ['Kept for the accounts.', 'Kept by law.'], 'done' => true])
        ->and($this->keeper->calls)->toBe([['erase', 'ada@example.com', null, 1], ['erase', 'ada@example.com', null, 2]]);
});

it('says why when a plugin that held data is gone when its turn comes', function (): void {
    app(HookDispatcher::class)->forget('acme/test');

    while (! ($this->step)()->done);

    expect(PrivacyRequest::query()->findOrFail($this->id)->results[1])->toMatchArray([
        'key' => 'acme.keeper', 'done' => true, 'missing' => true,
        'reasons' => ['A plugin that held data when the request started was no longer installed: Keeper.'],
    ]);
});

it('writes nothing when an editor tries', function (): void {
    $comment = cmComment($this->item);
    $before = DB::table('comments')->where('id', $comment->id)->first();

    expect(fn () => ($this->step)((string) $this->editor->id))->toThrow(AuthorizationException::class);
    expect(DB::table('comments')->where('id', $comment->id)->first())->toEqual($before)
        ->and(PrivacyRequest::query()->findOrFail($this->id)->status)->toBe('pending');
});

it('writes nothing without the operator', function (): void {
    $comment = cmComment($this->item);
    $before = DB::table('comments')->where('id', $comment->id)->first();
    app()->instance(Elevation::class, new DeniedElevation);

    expect(fn () => ($this->step)())->toThrow(AuthorizationException::class);
    expect(DB::table('comments')->where('id', $comment->id)->first())->toEqual($before)
        ->and(PrivacyRequest::query()->findOrFail($this->id)->status)->toBe('pending')
        ->and($this->keeper->calls)->toBe([]);
});
