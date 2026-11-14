<?php

declare(strict_types=1);

use App\Domain\Hooks\HookDispatcher;
use App\Domain\Privacy\Models\PrivacyRequest;
use App\Domain\Privacy\Notifications\PersonalDataErased;
use App\Domain\Privacy\Notifications\PersonalDataExported;
use App\Domain\Privacy\Operations\Input\RecordPrivacyRequestInput;
use App\Domain\Privacy\Operations\Input\RunPrivacyExportInput;
use App\Domain\Privacy\Operations\RecordPrivacyRequest;
use App\Domain\Privacy\Operations\RunPrivacyExport;
use App\Domain\Privacy\PersonalDataHolders;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Teme\Contracts\Privacy\CollectPersonalDataHolders;
use Teme\Contracts\Privacy\ExportGroup;
use Teme\Contracts\Privacy\ExportPage;
use Tests\Fixtures\Privacy\FailingHolder;
use Tests\Fixtures\Privacy\RecordingHolder;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');
    Notification::fake();
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->item = cmItem($this->editor);
    $this->comment = cmComment($this->item);
});

it('exports from the console: records, runs to done, says what each holder found, where the file is and that it was emailed', function (): void {
    $this->artisan('teme:privacy:export', ['email' => 'Ada@Example.com', '--note' => 'Asked by email; replied from her address.'])
        ->expectsOutputToContain('Exporting the personal data held for request')
        ->expectsOutputToContain('Comments: 1 item found.')
        ->expectsOutputToContain('Account: 0 items found.')
        ->expectsOutputToContain('Written to')
        ->expectsOutputToContain('Emailed to the address.')
        ->assertExitCode(0);

    $request = PrivacyRequest::query()->sole();

    expect($request->status)->toBe('done')
        ->and($request->note)->toBe('Asked by email; replied from her address.')
        ->and(Storage::disk('local')->exists((string) $request->export_path))->toBeTrue();
    Notification::assertSentOnDemand(PersonalDataExported::class);
});

it('refuses an export with no note, writing nothing', function (): void {
    $this->artisan('teme:privacy:export', ['email' => 'ada@example.com'])
        ->expectsOutputToContain('Give a note: why, and how you checked who asked. Never the address itself.')
        ->assertExitCode(1);

    expect(PrivacyRequest::query()->count())->toBe(0);
});

it('emails nothing with --no-reply, and says so', function (): void {
    $this->artisan('teme:privacy:export', ['email' => 'ada@example.com', '--note' => 'A test.', '--no-reply' => true])
        ->expectsOutputToContain('Not emailed (--no-reply).')
        ->assertExitCode(0);

    Notification::assertNothingSent();
});

it('erases nothing without --force in a run that can’t ask', function (): void {
    $this->artisan('teme:privacy:erase', ['email' => 'ada@example.com', '--note' => 'A test.', '--no-interaction' => true])
        ->expectsOutputToContain('Nothing was erased.')
        ->assertExitCode(1);

    expect($this->comment->refresh()->author_email)->toBe('ada@example.com')
        ->and(PrivacyRequest::query()->count())->toBe(0);
});

it('erases after the operator agrees', function (): void {
    $this->artisan('teme:privacy:erase', ['email' => 'ada@example.com', '--note' => 'A test.'])
        ->expectsConfirmation('Erase every piece of personal data this site holds for this address? Comments keep their words. This can\'t be undone.', 'yes')
        ->assertExitCode(0);

    expect($this->comment->refresh()->author_name)->toBe('Anonymous');
});

it('erases with --force, saying what each holder removed and kept, why, and that backups may hold it', function (): void {
    User::factory()->create(['email' => 'ada@example.com']);

    $this->artisan('teme:privacy:erase', ['email' => 'ada@example.com', '--note' => 'A test.', '--force' => true])
        ->expectsOutputToContain('Comments: 1 removed, 0 kept.')
        ->expectsOutputToContain('Account: 0 removed, 1 kept.')
        ->expectsOutputToContain('The account is kept. To remove it, delete it under Users.')
        ->expectsOutputToContain("The site's backups may still hold this data until they are replaced.")
        ->assertExitCode(0);

    expect($this->comment->refresh()->author_name)->toBe('Anonymous')
        ->and(PrivacyRequest::query()->sole()->status)->toBe('done');
    Notification::assertSentOnDemand(PersonalDataErased::class);
});

it('fails the request when a step throws, says so, emails nothing, and a second run finishes', function (): void {
    app()->instance(PersonalDataHolders::class, new PersonalDataHolders(app(HookDispatcher::class), []));
    $flaky = new FailingHolder(new RecordingHolder('acme.flaky', 'Flaky'), [1], 'The holder broke.');
    app(HookDispatcher::class)->subscriber('acme/test', [], ['CollectPersonalDataHolders'])
        ->on(CollectPersonalDataHolders::class, fn (): array => [$flaky]);

    $this->artisan('teme:privacy:erase', ['email' => 'ada@example.com', '--note' => 'A test.', '--force' => true])
        ->expectsOutputToContain('The request stopped: The holder broke.')
        ->assertExitCode(1);

    $failed = PrivacyRequest::query()->sole();

    expect($failed->status)->toBe('failed')
        ->and($failed->failure)->toContain('The holder broke.');
    Notification::assertNothingSent();

    $this->artisan('teme:privacy:erase', ['email' => 'ada@example.com', '--note' => 'Again.', '--force' => true])->assertExitCode(0);

    expect(PrivacyRequest::query()->where('status', 'done')->count())->toBe(1)
        ->and(PrivacyRequest::query()->count())->toBe(2);
});

it('stores no part of the address when a long failure is cut across it', function (): void {
    app()->instance(PersonalDataHolders::class, new PersonalDataHolders(app(HookDispatcher::class), []));
    // "RuntimeException: " is 18 characters, so the address starts at character 496.
    $broken = new FailingHolder(new RecordingHolder('acme.broken', 'Broken'), [1], str_repeat('x', 477).'ada@example.com was refused.');
    app(HookDispatcher::class)->subscriber('acme/test', [], ['CollectPersonalDataHolders'])
        ->on(CollectPersonalDataHolders::class, fn (): array => [$broken]);

    $this->artisan('teme:privacy:export', ['email' => 'ada@example.com', '--note' => 'A test.', '--no-reply' => true])->assertExitCode(1);

    expect(mb_strtolower((string) PrivacyRequest::query()->sole()->failure))->not->toContain('ada@');
});

it('fails the request when the disk refuses the export, says so and emails nothing', function (): void {
    // The local disk doesn't throw: a write it can't make only returns false.
    Storage::set('local', Mockery::mock(Storage::disk('local'))->shouldReceive('put')->andReturn(false)->getMock());

    $this->artisan('teme:privacy:export', ['email' => 'ada@example.com', '--note' => 'A test.'])
        ->expectsOutputToContain("The request stopped: Couldn't write the export file privacy/")
        ->assertExitCode(1);

    $failed = PrivacyRequest::query()->sole();

    expect($failed->status)->toBe('failed')
        ->and($failed->failure)->toContain("Couldn't write the export file privacy/{$failed->id}-comments-1.json.")
        ->and($failed->export_path)->toBeNull();
    Notification::assertNothingSent();
});

it('continues a request a killed run left running, asking no holder again for a page it recorded', function (): void {
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(PersonalDataHolders::class, new PersonalDataHolders(app(HookDispatcher::class), []));
    $page = fn (string $title, bool $done): ExportPage => new ExportPage([new ExportGroup($title, ['Name' => 'Ada'])], $done);
    $holder = new RecordingHolder('acme.pages', 'Pages', [$page('One', false), $page('Two', false), $page('Three', true)]);
    app(HookDispatcher::class)->subscriber('acme/test', [], ['CollectPersonalDataHolders'])
        ->on(CollectPersonalDataHolders::class, fn (): array => [$holder]);
    $id = app(RecordPrivacyRequest::class)->execute(new RecordPrivacyRequestInput('export', 'ada@example.com', 'The first note.', false));
    app(RunPrivacyExport::class)->execute(new RunPrivacyExportInput($id));
    DB::table('privacy_requests')->where('id', $id)->update(['status' => 'running']);

    $this->artisan('teme:privacy:export', ['email' => 'ada@example.com', '--note' => 'A new note.'])
        ->expectsOutputToContain("Continuing request {$id}, recorded")
        ->expectsOutputToContain('Pages: 3 items found.')
        ->assertExitCode(0);

    $request = PrivacyRequest::query()->sole();

    expect($request->status)->toBe('done')
        ->and($request->note)->toBe('The first note.')
        ->and(array_column($holder->calls, 3))->toBe([1, 2, 3]);
});
