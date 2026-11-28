<?php

declare(strict_types=1);

use App\Domain\Hooks\HookDispatcher;
use App\Domain\Plugins\Seams\DispatcherSubscriptions;
use App\Domain\Users\Operations\AcceptInvitation;
use App\Domain\Users\Operations\Input\AcceptInvitationInput;
use App\Domain\Users\Operations\Input\InviteUserInput;
use App\Domain\Users\Operations\InviteUser;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Hooks\HookRefused;
use Teme\Contracts\People\PersonInvited;
use Teme\Contracts\People\PersonJoined;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
});

/**
 * @return object{items: list<array{hook: string, payload: object, depth: int}>}
 */
function personHookRecord(string ...$hooks): object
{
    $heard = (object) ['items' => []];
    $core = app(HookDispatcher::class)->subscriber('core');

    foreach ($hooks as $hook) {
        $core->on($hook, function (object $payload) use ($heard, $hook): void {
            $heard->items[] = ['hook' => $hook, 'payload' => $payload, 'depth' => DB::transactionLevel()];
        });
    }

    return $heard;
}

it('sends PersonInvited after commit with id and name only, never a role or email', function (): void {
    $heard = personHookRecord(PersonInvited::class);
    $base = DB::transactionLevel();
    $this->withElevation();

    $result = app(InviteUser::class)->execute(new InviteUserInput(
        name: 'Ada Harbour',
        email: 'ada@harbour.example',
        role: 'editor',
        actorId: (string) $this->admin->id,
        deliver: false,
    ));

    expect($heard->items)->toHaveCount(1)
        ->and($heard->items[0]['depth'])->toBe($base)
        ->and($heard->items[0]['payload'])->toBeInstanceOf(PersonInvited::class)
        ->and($heard->items[0]['payload']->personId)->toBe((string) $result['userId'])
        ->and($heard->items[0]['payload']->name)->toBe('Ada Harbour')
        ->and($heard->items[0]['payload']->actorId)->toBe((string) $this->admin->id)
        ->and(json_encode($heard->items[0]['payload']))->not->toContain('ada@harbour.example')
        ->and(json_encode($heard->items[0]['payload']))->not->toContain('editor');
});

it('sends PersonJoined after commit when the invitation is accepted', function (): void {
    $this->withElevation();
    $result = app(InviteUser::class)->execute(new InviteUserInput(
        name: 'Ada Harbour',
        email: 'ada@harbour.example',
        role: 'author',
        actorId: (string) $this->admin->id,
        deliver: false,
    ));
    $heard = personHookRecord(PersonJoined::class);
    $base = DB::transactionLevel();

    app(AcceptInvitation::class)->execute(new AcceptInvitationInput(
        'ada@harbour.example',
        $result['token'],
        'a-brand-new-long-passphrase',
        'a-brand-new-long-passphrase',
    ));

    expect($heard->items)->toHaveCount(1)
        ->and($heard->items[0]['depth'])->toBe($base)
        ->and($heard->items[0]['payload']->personId)->toBe((string) $result['userId'])
        ->and($heard->items[0]['payload']->name)->toBe('Ada Harbour')
        ->and(json_encode($heard->items[0]['payload']))->not->toContain('ada@harbour.example')
        ->and(json_encode($heard->items[0]['payload']))->not->toContain('author');
});

it('refuses a PersonInvited subscription without people.read', function (): void {
    $hooks = app(DispatcherSubscriptions::class)->for('tests/hello', ['content.read'], ['PersonInvited'], []);

    expect(fn () => $hooks->on(PersonInvited::class, function (): void {}))
        ->toThrow(HookRefused::class, 'It listens for PersonInvited without asking for people.read in its teme-plugin.json.');
});

it('refuses a PersonJoined subscription without people.read', function (): void {
    $hooks = app(DispatcherSubscriptions::class)->for('tests/hello', ['content.read'], ['PersonJoined'], []);

    expect(fn () => $hooks->on(PersonJoined::class, function (): void {}))
        ->toThrow(HookRefused::class, 'It listens for PersonJoined without asking for people.read in its teme-plugin.json.');
});

it('sends no PersonInvited when the invite is refused, and writes nothing', function (): void {
    $heard = personHookRecord(PersonInvited::class);
    $this->withElevation();
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => app(InviteUser::class)->execute(new InviteUserInput(
        name: 'Ada Harbour',
        email: 'ada@harbour.example',
        role: 'editor',
        actorId: (string) $editor->id,
        deliver: false,
    )))->toThrow(AuthorizationException::class);

    expect($heard->items)->toHaveCount(0)
        ->and(User::query()->where('email', 'ada@harbour.example')->exists())->toBeFalse();
});

it('sends no PersonJoined when accepting the invitation is refused', function (): void {
    $this->withElevation();
    $result = app(InviteUser::class)->execute(new InviteUserInput(
        name: 'Ada Harbour',
        email: 'ada@harbour.example',
        role: 'author',
        actorId: (string) $this->admin->id,
        deliver: false,
    ));
    $heard = personHookRecord(PersonJoined::class);

    expect(fn () => app(AcceptInvitation::class)->execute(new AcceptInvitationInput(
        'ada@harbour.example',
        'not-the-token',
        'a-brand-new-long-passphrase',
        'a-brand-new-long-passphrase',
    )))->toThrow(AuthorizationException::class);

    expect($heard->items)->toHaveCount(0)
        ->and(User::query()->find($result['userId'])?->email_verified_at)->toBeNull();
});

it('sends no PersonInvited when the invite’s transaction rolls back', function (): void {
    $heard = personHookRecord(PersonInvited::class);
    $this->withElevation();

    try {
        DB::transaction(function (): void {
            app(InviteUser::class)->execute(new InviteUserInput(
                name: 'Ada Harbour',
                email: 'ada@harbour.example',
                role: 'editor',
                actorId: (string) $this->admin->id,
                deliver: false,
            ));

            throw new RuntimeException('Undo.');
        });
    } catch (RuntimeException) {
    }

    expect($heard->items)->toHaveCount(0)
        ->and(User::query()->where('email', 'ada@harbour.example')->exists())->toBeFalse();
});
