<?php

declare(strict_types=1);

use App\Domain\Hooks\HookDispatcher;
use App\Domain\Privacy\Models\PrivacyRequest;
use App\Domain\Privacy\Operations\Input\RecordPrivacyRequestInput;
use App\Domain\Privacy\Operations\Input\RunPrivacyErasureInput;
use App\Domain\Privacy\Operations\Input\RunPrivacyExportInput;
use App\Domain\Privacy\Operations\RecordPrivacyRequest;
use App\Domain\Privacy\Operations\RunPrivacyErasure;
use App\Domain\Privacy\Operations\RunPrivacyExport;
use App\Domain\Privacy\PersonalDataHolders;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Teme\Contracts\Privacy\CollectPersonalDataHolders;
use Tests\Fixtures\Privacy\PageBlindHolder;
use Tests\Fixtures\Privacy\RecordingHolder;
use Tests\Fixtures\Privacy\RepeatingHolder;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');
    Notification::fake();
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(PersonalDataHolders::class, new PersonalDataHolders(app(HookDispatcher::class), []));
    $this->offered = [];
    app(HookDispatcher::class)->subscriber('acme/test', [], ['CollectPersonalDataHolders'])
        ->on(CollectPersonalDataHolders::class, fn (): array => $this->offered);

    $this->record = fn (string $kind): string => app(RecordPrivacyRequest::class)->execute(new RecordPrivacyRequestInput($kind, 'ada@example.com', 'Asked by email.', true));
    // Moves the request's first holder on, as if its earlier pages had been asked.
    $this->advance = function (string $id, array $values): void {
        $request = PrivacyRequest::query()->findOrFail($id);
        $results = $request->results;
        $results[0] = [...$results[0], ...$values];
        $request->results = $results;
        $request->save();
    };
});

it('stops an erasure whose holder answers its first page again for ever, at the page limit, naming the holder', function (): void {
    $this->offered = [$holder = new RepeatingHolder];
    $id = ($this->record)('erase');
    $step = fn () => app(RunPrivacyErasure::class)->execute(new RunPrivacyErasureInput($id));

    $step();
    ($this->advance)($id, ['page' => RunPrivacyErasure::MOST_PAGES - 1]);
    $step();

    expect(PrivacyRequest::query()->findOrFail($id)->results[0])->toMatchArray(['page' => 10_000, 'done' => false])
        ->and(fn () => $step())->toThrow(RuntimeException::class, 'The holder Repeating was asked for 10,000 pages and never said it was done.')
        ->and($holder->calls)->toBe(2)
        ->and(PrivacyRequest::query()->findOrFail($id)->results[0])->toMatchArray(['page' => 10_000]);
});

it('stops an export whose holder ignores the page it is asked for, at the size limit, writing no more', function (): void {
    $this->offered = [new PageBlindHolder(4096)];
    $id = ($this->record)('export');
    $step = fn () => app(RunPrivacyExport::class)->execute(new RunPrivacyExportInput($id));

    $step();
    ($this->advance)($id, ['bytes' => RunPrivacyExport::MOST_BYTES - 1000]);

    expect(fn () => $step())->toThrow(RuntimeException::class, 'The holder Blind took the export past 100 MB.')
        ->and(Storage::disk('local')->allFiles())->toBe(["privacy/{$id}-acme.blind-1.json"])
        ->and(PrivacyRequest::query()->findOrFail($id)->results[0])->toMatchArray(['page' => 1]);
});

it('fails the request from the console at a limit, emails nothing, and a run after the holder is fixed finishes', function (): void {
    $this->offered = [new RepeatingHolder];
    $id = ($this->record)('erase');
    ($this->advance)($id, ['page' => RunPrivacyErasure::MOST_PAGES]);

    $this->artisan('teme:privacy:erase', ['email' => 'ada@example.com', '--force' => true])
        ->expectsOutputToContain('The request stopped: The holder Repeating was asked for 10,000 pages and never said it was done.')
        ->assertExitCode(1);

    expect(PrivacyRequest::query()->findOrFail($id))->status->toBe('failed')
        ->failure->toContain('The holder Repeating was asked for 10,000 pages');
    Notification::assertNothingSent();

    $this->offered = [new RecordingHolder('acme.repeating', 'Repeating')];

    $this->artisan('teme:privacy:erase', ['email' => 'ada@example.com', '--note' => 'Again, the plugin fixed.', '--force' => true])->assertExitCode(0);

    expect(PrivacyRequest::query()->where('status', 'done')->count())->toBe(1);
});
