<?php

declare(strict_types=1);

use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Domain\Updates\Notifications\TemeUpdateNotice;
use App\Domain\Updates\Operations\BeginTemeUpdate;
use App\Domain\Updates\Operations\CancelTemeUpdate;
use App\Domain\Updates\Operations\EndTemeUpdate;
use App\Domain\Updates\Operations\Input\BeginTemeUpdateInput;
use App\Domain\Updates\Operations\Input\CancelTemeUpdateInput;
use App\Domain\Updates\Operations\Input\EndTemeUpdateInput;
use App\Domain\Updates\Operations\Input\RequestTemeUpdateInput;
use App\Domain\Updates\Operations\RequestTemeUpdate;
use App\Domain\Updates\Requests\UpdateKind;
use App\Domain\Updates\Requests\UpdateStatus;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Notification::fake();
    updSite('0.1.0-rc.14', ['0.1.0-rc.15', '0.1.0-rc.14']);
    $this->first = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->second = User::factory()->create()->assignRole('administrator');
    $this->unverified = User::factory()->unverified()->create()->assignRole('administrator');
    $this->suspended = User::factory()->create(['suspended_at' => now()])->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->requestAs = function (?User $person = null): string {
        $person ??= $this->first;
        app()->instance(Elevation::class, new OperatorElevation);

        return (string) app(RequestTemeUpdate::class)->execute(new RequestTemeUpdateInput('0.1.0-rc.15', UpdateKind::Update, (string) $person->id));
    };
    $this->open = function (string $status = 'requested'): string {
        $id = strtolower((string) Str::ulid());
        DB::table('teme_updates')->insert(['id' => $id, 'kind' => 'update', 'from_version' => '0.1.0-rc.14', 'to_version' => '0.1.0-rc.15', 'status' => $status, 'steps' => '[]', 'requested_at' => now(), 'requested_by' => $this->first->id]);

        return $id;
    };
});

it('emails every administrator, verified and not suspended, when an update is requested', function (): void {
    ($this->requestAs)();

    foreach ([$this->first, $this->second] as $person) {
        Notification::assertSentTo($person, TemeUpdateNotice::class, fn (TemeUpdateNotice $notice): bool => $notice->kind === 'requested'
            && $notice->who === 'Ada Admin' && $notice->from === '0.1.0-rc.14' && $notice->to === '0.1.0-rc.15');
    }

    Notification::assertNotSentTo($this->unverified, TemeUpdateNotice::class);
    Notification::assertNotSentTo($this->suspended, TemeUpdateNotice::class);
    Notification::assertNotSentTo($this->editor, TemeUpdateNotice::class);
    Notification::assertCount(2);
});

it('sends nothing when the request is refused', function (): void {
    app()->instance(Elevation::class, new OperatorElevation);

    expect(fn () => app(RequestTemeUpdate::class)->execute(new RequestTemeUpdateInput('0.1.0-rc.99', UpdateKind::Update, (string) $this->first->id)))
        ->toThrow(ValidationException::class);
    Notification::assertNothingSent();
});

it('emails every administrator when the updater begins an update, and none when the begin found it cancelled', function (): void {
    updOperator();
    $id = ($this->open)();
    $cancelled = ($this->open)('cancelled');

    app(BeginTemeUpdate::class)->execute(new BeginTemeUpdateInput($cancelled));
    Notification::assertNothingSent();

    app(BeginTemeUpdate::class)->execute(new BeginTemeUpdateInput($id));

    Notification::assertSentTo($this->first, TemeUpdateNotice::class, fn (TemeUpdateNotice $notice): bool => $notice->kind === 'started');
    Notification::assertSentTo($this->second, TemeUpdateNotice::class);
    Notification::assertCount(2);
});

it('emails every administrator when an update ends done, switched back or failed', function (UpdateStatus $status, string $kind): void {
    updOperator();
    $id = ($this->open)();

    app(EndTemeUpdate::class)->execute(new EndTemeUpdateInput($id, $status, 'It stopped, said the test.'));

    Notification::assertSentTo($this->first, TemeUpdateNotice::class, fn (TemeUpdateNotice $notice): bool => $notice->kind === $kind
        && ($status === UpdateStatus::Done || $notice->sentence === 'It stopped, said the test.'));
    Notification::assertCount(2);
})->with([
    'done' => [UpdateStatus::Done, 'done'],
    'switched back' => [UpdateStatus::SwitchedBack, 'switched-back'],
    'failed' => [UpdateStatus::Failed, 'failed'],
]);

it('sends nothing for a cancel', function (): void {
    app()->instance(Elevation::class, new OperatorElevation);
    $id = ($this->open)();

    app(CancelTemeUpdate::class)->execute(new CancelTemeUpdateInput($id, (string) $this->first->id));

    Notification::assertNothingSent();
});

it('names the versions and the person in the email, and never a token', function (string $kind, bool $rollback): void {
    dpKey('dokploy-secret-token');
    $notice = new TemeUpdateNotice($kind, '0.1.0-rc.14', '0.1.0-rc.15', $kind === 'requested' ? 'Ada Admin' : null, 'It stopped.', 'Ledger *Weekly*', $rollback);

    $mail = $notice->toMail($this->first);
    $text = $mail->subject.' '.implode(' ', $mail->introLines).' '.$mail->actionText;

    expect($text)->toContain('0.1.0-rc.15')
        ->and($text)->not->toContain('dokploy-secret-token')
        ->and($mail->actionUrl)->toBe(route('tools.updates.index'))
        ->and($mail->subject)->toContain('Ledger *Weekly*');

    if ($kind === 'requested') {
        expect($text)->toContain('Ada Admin')->toContain('0.1.0-rc.14');
    }
})->with(fn () => collect(['requested', 'started', 'done', 'switched-back', 'failed'])->flatMap(fn (string $kind): array => [[$kind, false], [$kind, true]])->all());

it('says someone at the server asked, when the operator requested', function (): void {
    $notice = new TemeUpdateNotice('requested', '0.1.0-rc.14', '0.1.0-rc.15', null, null, 'Ledger', false);

    expect(implode(' ', $notice->toMail($this->first)->introLines))->toContain('Someone at the server asked for Ledger to be updated from Teme 0.1.0-rc.14 to 0.1.0-rc.15.');
});
