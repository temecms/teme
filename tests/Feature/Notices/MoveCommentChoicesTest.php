<?php

declare(strict_types=1);

use App\Domain\Preferences\Queries\PreferencesFor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function mccStore(User $person, array $preferences): void
{
    DB::table('users')->where('id', $person->id)->update(['preferences' => json_encode($preferences), 'updated_at' => '2027-01-01 00:00:00']);
}

function mccStored(User $person): array
{
    return json_decode((string) DB::table('users')->where('id', $person->id)->value('preferences'), true);
}

beforeEach(function (): void {
    $this->migration = require database_path('migrations/2027_05_02_100000_move_comment_email_choices.php');
    $this->off = User::factory()->create();
    $this->on = User::factory()->create();
    $this->none = User::factory()->create();
    $this->already = User::factory()->create();
    mccStore($this->off, ['notifications.comment_waiting' => false, 'notifications.comment_approved' => true]);
    mccStore($this->on, ['notifications.comment_waiting' => true]);
    mccStore($this->already, ['notifications.comment_waiting' => false, 'notifications.comments.waiting' => ['mail']]);
});

it('turns a choice that was off into an empty list under the kind’s key, and keeps the old key', function (): void {
    $this->migration->up();

    expect(mccStored($this->off))->toEqualCanonicalizing([
        'notifications.comment_waiting' => false,
        'notifications.comment_approved' => true,
        'notifications.comments.waiting' => [],
    ]);
});

it('adds nothing for a choice that was on or never made, and keeps a new key already there', function (): void {
    $this->migration->up();

    expect(mccStored($this->on))->toEqualCanonicalizing(['notifications.comment_waiting' => true])
        ->and(mccStored($this->none))->toBe([])
        ->and(mccStored($this->already)['notifications.comments.waiting'])->toBe(['mail']);
});

it('moves nobody’s updated_at', function (): void {
    $this->migration->up();

    expect(DB::table('users')->where('id', $this->off->id)->value('updated_at'))->toStartWith('2027-01-01 00:00:00');
});

it('removes only the new keys when rolled back', function (): void {
    $this->migration->up();
    $this->migration->down();

    expect(mccStored($this->off))->toEqualCanonicalizing(['notifications.comment_waiting' => false, 'notifications.comment_approved' => true]);
});

it('gives the moved person an empty choice and the other the default', function (): void {
    $this->migration->up();

    expect(PreferencesFor::user($this->off->refresh())['notifications.comments.waiting'])->toBe([])
        ->and(PreferencesFor::user($this->on->refresh())['notifications.comments.waiting'])->toBe(['mail']);
});
