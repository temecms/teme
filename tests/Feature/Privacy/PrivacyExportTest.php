<?php

declare(strict_types=1);

use App\Domain\Hooks\HookDispatcher;
use App\Domain\Hooks\ListenerFailed;
use App\Domain\Privacy\Models\PrivacyRequest;
use App\Domain\Privacy\Operations\Input\RecordPrivacyRequestInput;
use App\Domain\Privacy\Operations\Input\RunPrivacyExportInput;
use App\Domain\Privacy\Operations\RecordPrivacyRequest;
use App\Domain\Privacy\Operations\RunPrivacyExport;
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
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Privacy\CollectPersonalDataHolders;
use Teme\Contracts\Privacy\ExportGroup;
use Teme\Contracts\Privacy\ExportPage;
use Tests\Fixtures\Privacy\FailingHolder;
use Tests\Fixtures\Privacy\RecordingHolder;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');
    cmSettings(['site_title' => 'Plant Notes'], 'general');
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(PersonalDataHolders::class, new PersonalDataHolders(app(HookDispatcher::class), []));

    $this->page = fn (string $title, bool $done = true): ExportPage => new ExportPage([new ExportGroup($title, ['Name' => 'Ada', 'Comment' => $title])], $done);
    $this->first = new RecordingHolder('acme.first', 'First', [($this->page)('One', false), ($this->page)('Two', false), ($this->page)('Three')]);
    $this->second = new RecordingHolder('acme.second', 'Second', [new ExportPage([new ExportGroup('Four', ['Name' => 'Ada'])], true)]);
    $this->offered = [$this->first, $this->second];
    app(HookDispatcher::class)->subscriber('acme/test', [], ['CollectPersonalDataHolders'])
        ->on(CollectPersonalDataHolders::class, fn (): array => $this->offered);

    $this->record = fn (): string => app(RecordPrivacyRequest::class)->execute(new RecordPrivacyRequestInput('export', 'ada@example.com', 'Asked by email.', false));
    $this->step = fn (string $id, ?string $actorId = null): PrivacyStep => app(RunPrivacyExport::class)->execute(new RunPrivacyExportInput($id, $actorId));
    $this->files = fn (): array => Storage::disk('local')->allFiles();
    $this->untilDone = function (string $id): int {
        $steps = 0;

        do {
            $steps++;
        } while (! ($this->step)($id)->done && $steps < 20);

        return $steps;
    };
});

it('asks the first unfinished holder for its next page, writes the page and records how far it got', function (): void {
    $id = ($this->record)();

    $step = ($this->step)($id);
    $request = PrivacyRequest::query()->findOrFail($id);

    expect($step->done)->toBeFalse()
        ->and($step->holder)->toBe('acme.first')
        ->and($request->status)->toBe('running')
        ->and($request->results[0])->toMatchArray(['key' => 'acme.first', 'page' => 1, 'done' => false, 'found' => 1])
        ->and($request->results[1])->toMatchArray(['key' => 'acme.second', 'page' => 0, 'done' => false])
        ->and($this->first->calls)->toBe([['export', 'ada@example.com', null, 1]])
        ->and(($this->files)())->toBe(["privacy/{$id}-acme.first-1.json"]);
});

it('gives the holder the account’s ID when an account has the address', function (): void {
    $ada = User::factory()->create(['email' => 'ada@example.com']);
    $id = ($this->record)();

    ($this->step)($id);

    expect($this->first->calls)->toBe([['export', 'ada@example.com', (string) $ada->id, 1]]);
});

it('steps through a holder’s pages, then the next, and writes one file in order with the page files gone', function (): void {
    $id = ($this->record)();

    expect(($this->untilDone)($id))->toBe(5);

    $request = PrivacyRequest::query()->findOrFail($id);
    $file = json_decode((string) Storage::disk('local')->get("privacy/{$id}.json"), true, flags: JSON_THROW_ON_ERROR);

    expect($request->status)->toBe('done')
        ->and($request->completed_at)->not->toBeNull()
        ->and($request->export_path)->toBe("privacy/{$id}.json")
        ->and($request->results[0])->toMatchArray(['page' => 3, 'done' => true, 'found' => 3])
        ->and($request->results[1])->toMatchArray(['page' => 1, 'done' => true, 'found' => 1])
        ->and(($this->files)())->toBe(["privacy/{$id}.json"])
        ->and($file['site'])->toBe('Plant Notes')
        ->and($file['email'])->toBe('ada@example.com')
        ->and($file['made_at'])->toBeString()
        ->and($file['copies'])->toBe("The site's backups may still hold this data until they are replaced.")
        ->and(array_column($file['holders'], 'key'))->toBe(['acme.first', 'acme.second'])
        ->and(array_column($file['holders'][0]['groups'], 'title'))->toBe(['One', 'Two', 'Three'])
        ->and($file['holders'][0]['label'])->toBe('First')
        ->and($file['holders'][1]['groups'][0])->toBe(['title' => 'Four', 'values' => ['Name' => 'Ada']]);
});

it('writes every export file readable by its owner alone', function (): void {
    $id = ($this->record)();
    $mode = fn (string $path): int => fileperms(Storage::disk('local')->path($path)) & 0777;

    ($this->step)($id);

    expect($mode("privacy/{$id}-acme.first-1.json"))->toBe(0600);

    ($this->untilDone)($id);

    expect($mode("privacy/{$id}.json"))->toBe(0600);
});

it('lists a holder with nothing as an empty list of groups', function (): void {
    $this->offered = [new RecordingHolder('acme.empty', 'Empty')];
    $id = ($this->record)();

    ($this->untilDone)($id);
    $file = json_decode((string) Storage::disk('local')->get("privacy/{$id}.json"), true, flags: JSON_THROW_ON_ERROR);

    expect($file['holders'])->toBe([['key' => 'acme.empty', 'label' => 'Empty', 'groups' => []]]);
});

it('asks a holder that joined after the request was recorded, in its turn', function (): void {
    $id = ($this->record)();
    $late = new RecordingHolder('acme.late', 'Late', [($this->page)('Late one')]);
    $this->offered = [$this->first, $this->second, $late];

    ($this->untilDone)($id);
    $file = json_decode((string) Storage::disk('local')->get("privacy/{$id}.json"), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column(PrivacyRequest::query()->findOrFail($id)->results, 'key'))->toBe(['acme.first', 'acme.second', 'acme.late'])
        ->and($late->calls)->toHaveCount(1)
        ->and(array_column($file['holders'], 'key'))->toBe(['acme.first', 'acme.second', 'acme.late']);
});

it('fails the step when the disk refuses a page, recording nothing', function (): void {
    $id = ($this->record)();
    // The local disk doesn't throw: a write it can't make only returns false.
    Storage::set('local', Mockery::mock(Storage::disk('local'))->shouldReceive('put')->andReturn(false)->getMock());

    expect(fn () => ($this->step)($id))->toThrow(RuntimeException::class, "Couldn't write the export file privacy/{$id}-acme.first-1.json.");
    expect(PrivacyRequest::query()->findOrFail($id)->results[0])->toMatchArray(['page' => 0, 'done' => false]);
});

it('fails the last step when the disk refuses the file the person gets, leaving no path to it', function (): void {
    $id = ($this->record)();

    for ($step = 1; $step <= 4; $step++) {
        ($this->step)($id);
    }

    // A folder where the file must go.
    Storage::disk('local')->makeDirectory("privacy/{$id}.json");

    expect(fn () => ($this->step)($id))->toThrow(RuntimeException::class, "Couldn't write the export file privacy/{$id}.json.");
    expect(PrivacyRequest::query()->findOrFail($id))->status->toBe('running')->export_path->toBeNull();
});

it('fails the last step when a page file is gone, and emails nothing', function (): void {
    Notification::fake();
    $id = app(RecordPrivacyRequest::class)->execute(new RecordPrivacyRequestInput('export', 'ada@example.com', 'Asked by email.', true));

    for ($step = 1; $step <= 4; $step++) {
        ($this->step)($id);
    }

    Storage::disk('local')->delete("privacy/{$id}-acme.first-2.json");

    expect(fn () => ($this->step)($id))->toThrow(RuntimeException::class, "A page of the export is missing: privacy/{$id}-acme.first-2.json.");
    expect(PrivacyRequest::query()->findOrFail($id))->status->toBe('running')->export_path->toBeNull()
        ->and(Storage::disk('local')->exists("privacy/{$id}.json"))->toBeFalse();
    Notification::assertNothingSent();
});

it('fails the step, naming the holder, after three empty pages that never say they are done', function (): void {
    $empty = new ExportPage([], false);
    $this->offered = [new RecordingHolder('acme.endless', 'Endless', [$empty, $empty, $empty, $empty])];
    $id = ($this->record)();

    ($this->step)($id);
    ($this->step)($id);

    expect(fn () => ($this->step)($id))->toThrow(RuntimeException::class, 'The holder Endless answered three pages with nothing in them and never said it was done.');
    expect(PrivacyRequest::query()->findOrFail($id)->results[0])->toMatchArray(['page' => 2, 'idle' => 2, 'done' => false]);
});

it('refuses a step on a request whose address is gone, and writes nothing', function (): void {
    $id = ($this->record)();
    DB::table('privacy_requests')->where('id', $id)->update(['email' => null]);

    expect(fn () => ($this->step)($id))->toThrow(ValidationException::class, 'This request is too old to run. Record a new one.');
    expect(PrivacyRequest::query()->findOrFail($id)->status)->toBe('pending')
        ->and($this->first->calls)->toBe([])
        ->and(($this->files)())->toBe([]);
});

it('leaves one copy of a page when a step is retried after a rollback', function (): void {
    $flaky = new FailingHolder($this->first, [2]);
    $this->offered = [$flaky, $this->second];
    $id = ($this->record)();

    ($this->step)($id);
    expect(fn () => ($this->step)($id))->toThrow(RuntimeException::class, 'The holder broke.');
    ($this->untilDone)($id);

    $file = json_decode((string) Storage::disk('local')->get("privacy/{$id}.json"), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($file['holders'][0]['groups'], 'title'))->toBe(['One', 'Two', 'Three'])
        ->and(PrivacyRequest::query()->findOrFail($id)->results[0])->toMatchArray(['page' => 3, 'found' => 3]);
});

it('marks a holder no longer offered as done and lists it as missing', function (): void {
    $id = ($this->record)();
    $this->offered = [$this->second];

    $step = ($this->step)($id);
    ($this->untilDone)($id);
    $file = json_decode((string) Storage::disk('local')->get("privacy/{$id}.json"), true, flags: JSON_THROW_ON_ERROR);

    expect($step->holder)->toBe('acme.first')
        ->and(PrivacyRequest::query()->findOrFail($id)->results[0])->toMatchArray(['done' => true, 'missing' => true, 'page' => 0])
        ->and($file['holders'][0])->toBe(['key' => 'acme.first', 'label' => 'First', 'groups' => [], 'missing' => true])
        ->and($this->first->calls)->toBe([]);
});

it('refuses a step on an ended request, and writes nothing', function (string $status): void {
    $id = ($this->record)();
    DB::table('privacy_requests')->where('id', $id)->update(['status' => $status, 'completed_at' => now()]);

    expect(fn () => ($this->step)($id))->toThrow(ValidationException::class, 'This request has already ended.');
    expect(PrivacyRequest::query()->findOrFail($id)->status)->toBe($status)
        ->and($this->first->calls)->toBe([])
        ->and(($this->files)())->toBe([]);
})->with(['done', 'failed']);

it('refuses a step on an erasure', function (): void {
    $id = app(RecordPrivacyRequest::class)->execute(new RecordPrivacyRequestInput('erase', 'ada@example.com', 'Asked by email.'));

    expect(fn () => ($this->step)($id))->toThrow(ValidationException::class);
    expect(PrivacyRequest::query()->findOrFail($id)->status)->toBe('pending');
});

it('fails the step when a plugin’s listener throws, leaving the row and the files as they were', function (): void {
    $id = ($this->record)();
    ($this->step)($id);
    $before = DB::table('privacy_requests')->where('id', $id)->first();
    app(HookDispatcher::class)->subscriber('acme/broken', [], ['CollectPersonalDataHolders'])
        ->on(CollectPersonalDataHolders::class, fn (): array => throw new RuntimeException('Broken.'));

    expect(fn () => ($this->step)($id))->toThrow(ListenerFailed::class);
    expect(DB::table('privacy_requests')->where('id', $id)->first())->toEqual($before)
        ->and(($this->files)())->toBe(["privacy/{$id}-acme.first-1.json"]);
});

it('writes nothing when an editor tries', function (): void {
    $id = ($this->record)();
    $editor = User::factory()->create()->assignRole('editor');
    $before = DB::table('privacy_requests')->where('id', $id)->first();

    expect(fn () => ($this->step)($id, (string) $editor->id))->toThrow(AuthorizationException::class);
    expect(DB::table('privacy_requests')->where('id', $id)->first())->toEqual($before)
        ->and($this->first->calls)->toBe([])
        ->and(($this->files)())->toBe([]);
});

it('writes nothing without the operator', function (): void {
    $id = ($this->record)();
    $before = DB::table('privacy_requests')->where('id', $id)->first();
    app()->instance(Elevation::class, new DeniedElevation);

    expect(fn () => ($this->step)($id))->toThrow(AuthorizationException::class);
    expect(DB::table('privacy_requests')->where('id', $id)->first())->toEqual($before)
        ->and($this->first->calls)->toBe([])
        ->and(($this->files)())->toBe([]);
});
