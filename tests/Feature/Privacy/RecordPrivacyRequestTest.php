<?php

declare(strict_types=1);

use App\Domain\Hooks\HookDispatcher;
use App\Domain\Privacy\Models\PrivacyRequest;
use App\Domain\Privacy\Operations\Input\RecordPrivacyRequestInput;
use App\Domain\Privacy\Operations\RecordPrivacyRequest;
use App\Domain\Privacy\PersonalDataHolders;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Privacy\CollectPersonalDataHolders;
use Tests\Fixtures\Privacy\RecordingHolder;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    // Core's holders aside: the test's own, through the hook, as a plugin's.
    app()->instance(PersonalDataHolders::class, new PersonalDataHolders(app(HookDispatcher::class), []));
    app(HookDispatcher::class)->subscriber('acme/test', [], ['CollectPersonalDataHolders'])
        ->on(CollectPersonalDataHolders::class, fn (): array => [new RecordingHolder('acme.first', 'First'), new RecordingHolder('acme.second', 'Second')]);

    $this->record = fn (array $changes = []): string => app(RecordPrivacyRequest::class)->execute(new RecordPrivacyRequestInput(...[
        'kind' => 'export',
        'email' => 'ada@example.com',
        'note' => 'Asked by email on 12 Nov; replied from her address.',
        'reply' => true,
        'actorId' => null,
        ...$changes,
    ]));
});

it('records an export for the operator, with the address lower-cased, its hash, the account and one entry per holder', function (): void {
    $ada = User::factory()->create(['email' => 'Ada@Example.com']);

    $id = ($this->record)(['email' => '  ADA@example.com ']);
    $request = PrivacyRequest::query()->findOrFail($id);

    expect($request->kind)->toBe('export')
        ->and($request->status)->toBe('pending')
        ->and($request->email)->toBe('ada@example.com')
        ->and($request->email_hash)->toBe(hash_hmac('sha256', 'ada@example.com', (string) config('app.key')))
        ->and($request->user_id)->toBe($ada->id)
        ->and($request->requested_by)->toBeNull()
        ->and($request->reply)->toBeTrue()
        ->and($request->note)->toBe('Asked by email on 12 Nov; replied from her address.')
        ->and($request->results)->toEqual([
            ['key' => 'acme.first', 'label' => 'First', 'page' => 0, 'done' => false, 'found' => 0],
            ['key' => 'acme.second', 'label' => 'Second', 'page' => 0, 'done' => false, 'found' => 0],
        ]);
});

it('records an erasure for a person who holds privacy.manage, as them, with no account when none has the address', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    app()->instance(Elevation::class, new DeniedElevation);

    $id = ($this->record)(['kind' => 'erase', 'actorId' => (string) $admin->id, 'reply' => false]);
    $request = PrivacyRequest::query()->findOrFail($id);

    expect($request->kind)->toBe('erase')
        ->and($request->requested_by)->toBe($admin->id)
        ->and($request->user_id)->toBeNull()
        ->and($request->reply)->toBeFalse()
        ->and($request->results[0])->toEqual(['key' => 'acme.first', 'label' => 'First', 'page' => 0, 'done' => false, 'removed' => 0, 'retained' => 0, 'reasons' => []]);
});

it('refuses a second open request of the same kind for the same address, naming the first, and writes nothing', function (): void {
    $first = ($this->record)();

    expect(fn () => ($this->record)(['email' => 'ADA@example.com']))
        ->toThrow(ValidationException::class, "There is already an open request of this kind for this address: {$first}.");
    expect(PrivacyRequest::query()->count())->toBe(1);
});

it('accepts an erasure while an export for the address is open', function (): void {
    ($this->record)();
    ($this->record)(['kind' => 'erase']);

    expect(PrivacyRequest::query()->count())->toBe(2);
});

it('keeps one open request of a kind per address in the database too', function (): void {
    $row = fn (string $status): array => [
        'id' => strtolower((string) Str::ulid()), 'kind' => 'export', 'email' => 'ada@example.com', 'email_hash' => str_repeat('b', 64),
        'note' => 'A note.', 'status' => $status, 'completed_at' => $status === 'done' ? now() : null, 'created_at' => now(), 'updated_at' => now(),
    ];

    DB::table('privacy_requests')->insert($row('done'));
    DB::table('privacy_requests')->insert($row('running'));

    expect(cmRefusal(fn () => DB::table('privacy_requests')->insert($row('pending'))))->toBe('23505')
        ->and(DB::table('privacy_requests')->count())->toBe(2);
});

it('refuses a bad address, an empty note and a note over 2,000 characters', function (array $changes, string $field): void {
    try {
        ($this->record)($changes);
        $this->fail('It recorded the request.');
    } catch (ValidationException $refused) {
        expect($refused->errors())->toHaveKey($field);
    }

    expect(PrivacyRequest::query()->count())->toBe(0);
})->with([
    'not an address' => [['email' => 'ada at example'], 'email'],
    'an empty note' => [['note' => '   '], 'note'],
    'a long note' => [['note' => str_repeat('a', 2001)], 'note'],
    'a kind it doesn’t know' => [['kind' => 'delete'], 'kind'],
]);

it('refuses two holders with one key, and writes nothing', function (): void {
    app(HookDispatcher::class)->subscriber('acme/other', [], ['CollectPersonalDataHolders'])
        ->on(CollectPersonalDataHolders::class, fn (): array => [new RecordingHolder('acme.first', 'Again')]);

    expect(fn () => ($this->record)())->toThrow(ValidationException::class, 'Two holders of personal data share the key acme.first.');
    expect(PrivacyRequest::query()->count())->toBe(0);
});

it('writes nothing when an editor asks', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => ($this->record)(['actorId' => (string) $editor->id]))->toThrow(AuthorizationException::class, 'Not permitted to handle privacy requests.');
    expect(PrivacyRequest::query()->count())->toBe(0);
});

it('writes nothing when a suspended administrator asks', function (): void {
    $admin = User::factory()->create(['suspended_at' => now()])->assignRole('administrator');

    expect(fn () => ($this->record)(['actorId' => (string) $admin->id]))->toThrow(AuthorizationException::class);
    expect(PrivacyRequest::query()->count())->toBe(0);
});

it('writes nothing when no operator is present', function (): void {
    app()->instance(Elevation::class, new DeniedElevation);

    expect(fn () => ($this->record)())->toThrow(AuthorizationException::class, 'Not permitted to handle privacy requests.');
    expect(PrivacyRequest::query()->count())->toBe(0);
});
