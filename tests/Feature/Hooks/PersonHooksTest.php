<?php

declare(strict_types=1);

use App\Domain\Hooks\HookDispatcher;
use App\Domain\Plugins\Seams\DispatcherSubscriptions;
use App\Domain\Users\Operations\AcceptInvitation;
use App\Domain\Users\Operations\Input\AcceptInvitationInput;
use App\Domain\Users\Operations\Input\InviteUserInput;
use App\Domain\Users\Operations\InviteUser;
use App\Models\User;
use ArrayObject;
use Database\Seeders\RoleSeeder;
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
 * @return ArrayObject<int, array{hook: string, payload: object, depth: int}>
 */
function personHookRecord(string ...$hooks): ArrayObject
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

    expect($heard)->toHaveCount(1)
        ->and($heard[0]['depth'])->toBe($base)
        ->and($heard[0]['payload'])->toBeInstanceOf(PersonInvited::class)
        ->and($heard[0]['payload']->personId)->toBe((string) $result['userId'])
        ->and($heard[0]['payload']->name)->toBe('Ada Harbour')
        ->and($heard[0]['payload']->actorId)->toBe((string) $this->admin->id)
        ->and(json_encode($heard[0]['payload']))->not->toContain('ada@harbour.example')
        ->and(json_encode($heard[0]['payload']))->not->toContain('editor');
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

    expect($heard)->toHaveCount(1)
        ->and($heard[0]['depth'])->toBe($base)
        ->and($heard[0]['payload']->personId)->toBe((string) $result['userId'])
        ->and($heard[0]['payload']->name)->toBe('Ada Harbour')
        ->and(json_encode($heard[0]['payload']))->not->toContain('ada@harbour.example')
        ->and(json_encode($heard[0]['payload']))->not->toContain('author');
});

it('refuses a PersonInvited subscription without people.read', function (): void {
    $hooks = app(DispatcherSubscriptions::class)->for('tests/hello', ['content.read'], ['PersonInvited'], []);

    expect(fn () => $hooks->on(PersonInvited::class, function (): void {}))
        ->toThrow(HookRefused::class, 'It listens for PersonInvited without asking for people.read in its teme-plugin.json.');
});
