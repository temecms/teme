<?php

declare(strict_types=1);

use App\Domain\Hooks\HookDispatcher;
use App\Domain\Privacy\Models\PrivacyRequest;
use App\Domain\Privacy\Operations\FailPrivacyRequest;
use App\Domain\Privacy\Operations\Input\FailPrivacyRequestInput;
use App\Domain\Privacy\Operations\Input\RecordPrivacyRequestInput;
use App\Domain\Privacy\Operations\Input\RunPrivacyExportInput;
use App\Domain\Privacy\Operations\RecordPrivacyRequest;
use App\Domain\Privacy\Operations\RunPrivacyExport;
use App\Domain\Privacy\PersonalDataHolders;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Privacy\CollectPersonalDataHolders;
use Teme\Contracts\Privacy\ExportGroup;
use Teme\Contracts\Privacy\ExportPage;
use Tests\Fixtures\Privacy\RecordingHolder;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(PersonalDataHolders::class, new PersonalDataHolders(app(HookDispatcher::class), []));
    $page = new ExportPage([new ExportGroup('One', ['Name' => 'Ada'])], false);
    app(HookDispatcher::class)->subscriber('acme/test', [], ['CollectPersonalDataHolders'])
        ->on(CollectPersonalDataHolders::class, fn (): array => [new RecordingHolder('acme.first', 'First', [$page, $page, $page])]);

    $this->id = app(RecordPrivacyRequest::class)->execute(new RecordPrivacyRequestInput('export', 'ada@example.com', 'Asked by email.', false));
    $this->fail = fn (string $failure = 'RuntimeException: It broke.', ?string $actorId = null): mixed => app(FailPrivacyRequest::class)->execute(new FailPrivacyRequestInput($this->id, $failure, $actorId));
    $this->row = fn (): PrivacyRequest => PrivacyRequest::query()->findOrFail($this->id);
});

it('marks a running request failed, with its reason and when it ended', function (): void {
    app(RunPrivacyExport::class)->execute(new RunPrivacyExportInput($this->id));

    ($this->fail)();

    expect(($this->row)()->status)->toBe('failed')
        ->and(($this->row)()->failure)->toBe('RuntimeException: It broke.')
        ->and(($this->row)()->completed_at)->not->toBeNull();
});

it('leaves a request that already ended alone', function (): void {
    DB::table('privacy_requests')->where('id', $this->id)->update(['status' => 'done', 'completed_at' => now()->subDay()]);
    $before = DB::table('privacy_requests')->where('id', $this->id)->first();

    ($this->fail)();

    expect(DB::table('privacy_requests')->where('id', $this->id)->first())->toEqual($before);
});

it('refuses an empty reason, and writes nothing', function (): void {
    expect(fn () => ($this->fail)(''))->toThrow(ValidationException::class);
    expect(($this->row)()->status)->toBe('pending');
});

it('cuts a reason over 500 characters to 500, after the address is taken out', function (): void {
    ($this->fail)(str_repeat('a', 480).'ada@example.com'.str_repeat('b', 100));

    expect(($this->row)()->failure)->toBe(str_repeat('a', 480).'[the address]'.str_repeat('b', 7));
});

it('stores no part of an address that straddles the cut', function (): void {
    ($this->fail)(str_repeat('x', 495).'ADA@example.com was refused.');

    $failure = (string) ($this->row)()->failure;

    expect(mb_strlen($failure))->toBeLessThanOrEqual(500)
        ->and(mb_strtolower($failure))->not->toContain('ada@')
        ->and($failure)->toEndWith('[the address]');
});

it('takes out the start of the address that ends a reason cut before it came', function (): void {
    ($this->fail)(str_repeat('x', 100).'Ada@exam');

    expect(($this->row)()->failure)->toBe(str_repeat('x', 100).'[the address]');
});

it('stores the reason with the request’s address taken out, whatever its letter case', function (): void {
    ($this->fail)('SQLSTATE[23514]: Failing row contains (ADA@Example.com, x) and ada@example.com.');

    expect(($this->row)()->failure)->toBe('SQLSTATE[23514]: Failing row contains ([the address], x) and [the address].');
});

it('deletes a failed export’s page files after commit, and keeps them on a rollback', function (): void {
    app(RunPrivacyExport::class)->execute(new RunPrivacyExportInput($this->id));
    app(RunPrivacyExport::class)->execute(new RunPrivacyExportInput($this->id));
    $pages = ["privacy/{$this->id}-acme.first-1.json", "privacy/{$this->id}-acme.first-2.json"];

    expect(Storage::disk('local')->allFiles())->toEqualCanonicalizing($pages);

    try {
        DB::transaction(function (): void {
            ($this->fail)();

            throw new RuntimeException('Rolled back.');
        });
    } catch (RuntimeException) {
    }

    expect(Storage::disk('local')->allFiles())->toEqualCanonicalizing($pages)
        ->and(($this->row)()->status)->toBe('running');

    ($this->fail)();

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('deletes a failed export’s own file too, one a rolled-back last step left', function (): void {
    app(RunPrivacyExport::class)->execute(new RunPrivacyExportInput($this->id));
    Storage::disk('local')->put("privacy/{$this->id}.json", '{}');

    ($this->fail)();

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('writes nothing when an editor tries', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => ($this->fail)('It broke.', (string) $editor->id))->toThrow(AuthorizationException::class);
    expect(($this->row)()->status)->toBe('pending');
});

it('writes nothing without the operator', function (): void {
    app()->instance(Elevation::class, new DeniedElevation);

    expect(fn () => ($this->fail)())->toThrow(AuthorizationException::class);
    expect(($this->row)()->status)->toBe('pending');
});
