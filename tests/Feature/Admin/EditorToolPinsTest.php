<?php

declare(strict_types=1);

use App\Domain\Preferences\Operations\Input\UpdatePreferencesInput;
use App\Domain\Preferences\Operations\UpdatePreferences;
use App\Domain\Preferences\Queries\PreferencesFor;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// The tools pinned to the editor's bar are a person's preference
// (docs/architecture/design-system.md, 4.39): up to three, in their order.

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');

    // Saves this person's pins as the editor does.
    $this->pinsSave = fn (User $person, mixed $pins): mixed => app(UpdatePreferences::class)
        ->execute(new UpdatePreferencesInput(['editor.pinned_tools' => $pins], (string) $person->id));
});

it('pins Assistant and Share for a person who has chosen nothing', function (): void {
    expect(PreferencesFor::user($this->author)['editor.pinned_tools'])->toBe(['assistant', 'share'])
        ->and($this->author->refresh()->preferences)->toBe([]);
});

it('keeps the pins in the order given', function (): void {
    ($this->pinsSave)($this->author, ['share', 'assistant']);

    expect(PreferencesFor::user($this->author->refresh())['editor.pinned_tools'])->toBe(['share', 'assistant']);
});

// The server's cap of three can't be reached on its own: only two tools may be pinned, so any list
// of four holds tools that can't be pinned, and that is what refuses it.
it('refuses four pins that include tools which can\'t be pinned, and writes nothing', function (): void {
    ($this->pinsSave)($this->author, ['share']);

    expect(fn () => ($this->pinsSave)($this->author, ['assistant', 'share', 'overview', 'history']))
        ->toThrow(ValidationException::class);

    expect($this->author->refresh()->preferences)->toBe(['editor.pinned_tools' => ['share']]);
});

it('refuses a tool that cannot be pinned, and writes nothing', function (array $pins): void {
    expect(fn () => ($this->pinsSave)($this->author, $pins))
        ->toThrow(ValidationException::class);

    expect($this->author->refresh()->preferences)->toBe([]);
})->with([
    'History, which is always on the bar' => [['history']],
    'a tool that does not exist' => [['made-up']],
]);

it('writes nothing when authorization fails', function (): void {
    // The input carries only the actor, so what can fail is the actor (PreferencesTest).
    $suspended = User::factory()->create(['suspended_at' => Date::now()])->assignRole('author');

    expect(fn () => ($this->pinsSave)($suspended, ['share']))
        ->toThrow(AuthorizationException::class);

    expect($suspended->refresh()->preferences)->toBe([])
        ->and(DB::table('users')->whereRaw("preferences <> '{}'::jsonb")->count())->toBe(0);
});
