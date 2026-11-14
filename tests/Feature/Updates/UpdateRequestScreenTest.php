<?php

declare(strict_types=1);

use App\Domain\Deployment\Health\Heartbeats;
use App\Domain\Support\Security\Elevation;
use App\Domain\Updates\Jobs\SendTemeUpdateEmails;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    updSite('0.1.0-rc.14', ['0.1.0-rc.15', '0.1.0-rc.14', '0.1.0-rc.13']);
    updDeployed('0.1.0-rc.13', 600);
    app()->forgetInstance(Elevation::class);
    $this->confirmed = scConfirmed();
    $this->open = fn (string $status, array $overrides = []): string => tap(strtolower((string) Str::ulid()), fn (string $id) => DB::table('teme_updates')->insert([
        'id' => $id, 'kind' => 'update', 'from_version' => '0.1.0-rc.14', 'to_version' => '0.1.0-rc.15', 'status' => $status,
        'steps' => '[]', 'requested_by' => $this->admin->id, 'requested_at' => now()->subMinute(),
        'finished_at' => in_array($status, ['requested', 'running'], true) ? null : now(),
        ...$overrides,
    ]));
    $this->steps = fn (array $states, array $extra = []): string => json_encode(array_map(fn (string $step, string $state): array => [
        'step' => $step, 'state' => $state, 'startedAt' => null, 'finishedAt' => null, 'detail' => null,
        'asks' => 1, 'runsAfter' => '2026-11-10T09:00:00.000+00:00', 'mark' => 'deployment-77',
        ...($extra[$step] ?? []),
    ], ['deploy', 'web', 'worker', 'worker-long', 'scheduler', 'check', 'updater'], $states), JSON_THROW_ON_ERROR);
});

it('asks for the password before recording an update from the screen', function (): void {
    Queue::fake();

    $this->actingAs($this->admin)->from(route('tools.updates.index'))
        ->post('/admin/tools/updates/requests', ['version' => '0.1.0-rc.15', 'kind' => 'update'])
        ->assertRedirect(route('password.confirm'));

    expect(DB::table('teme_updates')->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('answers 423 and records nothing when the form asks in place and the password was not confirmed lately', function (): void {
    Queue::fake();

    $this->actingAs($this->admin)->withHeader('X-Teme-Ask-In-Place', '1')
        ->post('/admin/tools/updates/requests', ['version' => '0.1.0-rc.15', 'kind' => 'update'])
        ->assertStatus(423);

    expect(DB::table('teme_updates')->count())->toBe(0);
});

it('records the update and says so', function (): void {
    Queue::fake();

    $this->actingAs($this->admin)->withSession($this->confirmed)
        ->post('/admin/tools/updates/requests', ['version' => '0.1.0-rc.15', 'kind' => 'update'])
        ->assertRedirect(route('tools.updates.index'))
        ->assertInertiaFlash('toast.message', 'Update requested. The updater starts it within a minute.');

    $row = DB::table('teme_updates')->first();
    expect($row->to_version)->toBe('0.1.0-rc.15')->and($row->status)->toBe('requested')->and((int) $row->requested_by)->toBe($this->admin->id);
    Queue::assertPushed(SendTemeUpdateEmails::class);
});

it('shows a refusal as a toast and records nothing', function (): void {
    $this->actingAs($this->admin)->withSession($this->confirmed)
        ->post('/admin/tools/updates/requests', ['version' => '0.1.0-rc.99', 'kind' => 'update'])
        ->assertRedirect(route('tools.updates.index'))
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', 'Teme 0.1.0-rc.99 isn’t a release this site is offered.');

    expect(DB::table('teme_updates')->count())->toBe(0);
});

it('rolls back from the screen', function (): void {
    Queue::fake();

    $this->actingAs($this->admin)->withSession($this->confirmed)
        ->post('/admin/tools/updates/requests', ['version' => '0.1.0-rc.13', 'kind' => 'rollback'])
        ->assertInertiaFlash('toast.message', 'Roll back requested. The updater starts it within a minute.');

    $row = DB::table('teme_updates')->first();
    expect($row->kind)->toBe('rollback')->and($row->to_version)->toBe('0.1.0-rc.13');
});

it('cancels from the screen without the password', function (): void {
    $id = ($this->open)('requested');

    $this->actingAs($this->admin)->post("/admin/tools/updates/requests/{$id}/cancel")
        ->assertRedirect(route('tools.updates.index'))
        ->assertInertiaFlash('toast.message', 'The update was cancelled.');

    expect(DB::table('teme_updates')->where('id', $id)->value('status'))->toBe('cancelled');
});

it('says in a toast that a running update can’t be cancelled, changing nothing', function (): void {
    $id = ($this->open)('running');

    $this->actingAs($this->admin)->post("/admin/tools/updates/requests/{$id}/cancel")
        ->assertInertiaFlash('toast.type', 'error');

    expect(DB::table('teme_updates')->where('id', $id)->value('status'))->toBe('running');
});

it('refuses everyone without updates.manage, recording nothing', function (string $role): void {
    $id = ($this->open)('requested');
    $person = User::factory()->create()->assignRole($role);

    $this->actingAs($person)->withSession($this->confirmed)
        ->post('/admin/tools/updates/requests', ['version' => '0.1.0-rc.15', 'kind' => 'update'])->assertForbidden();
    $this->actingAs($person)->post("/admin/tools/updates/requests/{$id}/cancel")->assertForbidden();

    expect(DB::table('teme_updates')->count())->toBe(1)
        ->and(DB::table('teme_updates')->value('status'))->toBe('requested');
})->with(['editor', 'author', 'contributor']);

it('gives the page the open update with its steps, the roll back on offer, and when the updater last looked', function (): void {
    ($this->open)('running', ['started_at' => now()->subSeconds(40), 'step' => 'web', 'steps' => ($this->steps)(['done', 'started', 'pending', 'pending', 'pending', 'pending', 'pending'])]);
    app(Heartbeats::class)->beat(Heartbeats::UPDATER);

    $this->actingAs($this->admin)->get('/admin/tools/updates')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('update.status', 'running')
            ->where('update.kind', 'update')
            ->where('update.from', '0.1.0-rc.14')
            ->where('update.to', '0.1.0-rc.15')
            ->where('update.requestedBy', 'Ada Admin')
            ->where('update.steps.1.step', 'web')
            ->where('update.steps.1.state', 'started')
            ->where('update.canCancel', false)
            ->where('rollback.version', '0.1.0-rc.13')
            ->where('rollback.refusal', 'An update is already under way.')
            ->has('updaterSeenAt')
            ->where('canUpdate', 'An update is already under way.'));
});

it('offers the roll back once the update is done', function (): void {
    ($this->open)('done');

    $this->actingAs($this->admin)->get('/admin/tools/updates')
        ->assertInertia(fn (Assert $page) => $page->where('rollback.version', '0.1.0-rc.13')->where('rollback.refusal', null)->where('canUpdate', null));
});

it('lets the newest be requested when nothing is open, and says when the operator asked', function (): void {
    ($this->open)('requested', ['requested_by' => null]);
    $this->actingAs($this->admin)->get('/admin/tools/updates')
        ->assertInertia(fn (Assert $page) => $page->where('update.canCancel', true)->where('update.requestedBy', null)->where('updaterSeenAt', null));

    DB::table('teme_updates')->delete();
    $this->actingAs($this->admin)->get('/admin/tools/updates')
        ->assertInertia(fn (Assert $page) => $page->where('update', null)->where('canUpdate', null));
});

it('gives the page why the newest can’t be requested', function (): void {
    DB::table('teme_releases')->where('version', '0.1.0-rc.15')->update(['maintenance' => true]);

    $this->actingAs($this->admin)->get('/admin/tools/updates')
        ->assertInertia(fn (Assert $page) => $page->where('canUpdate', 'Teme 0.1.0-rc.15 needs maintenance mode while it deploys, so this update is done by hand: see How to update.'));
});

it('shows a finished update for seven days, and never a cancelled one', function (): void {
    $done = ($this->open)('done', ['finished_at' => now()->subDays(6)]);
    $this->actingAs($this->admin)->get('/admin/tools/updates')->assertInertia(fn (Assert $page) => $page->where('update.id', $done)->where('update.canCancel', false));

    DB::table('teme_updates')->where('id', $done)->update(['finished_at' => now()->subDays(8)]);
    $this->actingAs($this->admin)->get('/admin/tools/updates')->assertInertia(fn (Assert $page) => $page->where('update', null));

    ($this->open)('cancelled');
    $this->actingAs($this->admin)->get('/admin/tools/updates')->assertInertia(fn (Assert $page) => $page->where('update', null));
});

it('throttles the request route', function (): void {
    $this->actingAs($this->admin)->withSession($this->confirmed);

    foreach (range(1, 6) as $_) {
        $this->post('/admin/tools/updates/requests', ['version' => '0.1.0-rc.99', 'kind' => 'update'])->assertRedirect();
    }

    $this->post('/admin/tools/updates/requests', ['version' => '0.1.0-rc.99', 'kind' => 'update'])->assertTooManyRequests();
});

it('never sends a step’s mark or asks to the page', function (): void {
    ($this->open)('running', ['started_at' => now(), 'step' => 'web', 'steps' => ($this->steps)(['done', 'started', 'pending', 'pending', 'pending', 'pending', 'pending'])]);

    $this->actingAs($this->admin)->get('/admin/tools/updates')
        ->assertInertia(function (Assert $page): void {
            $steps = $page->toArray()['props']['update']['steps'];

            expect($steps)->toHaveCount(7);
            foreach ($steps as $step) {
                expect(array_keys($step))->toBe(['step', 'state', 'startedAt', 'finishedAt', 'detail']);
            }
        });
});

it('gives the page the updater’s wait for a quiet moment, under the deploy step', function (): void {
    // UpdateRunner::WAITING is private; the sentence is the runner's, written here as the page must show it.
    $waiting = 'Waiting for a backup or a job to finish first.';
    ($this->open)('running', ['started_at' => now(), 'step' => 'deploy', 'steps' => ($this->steps)(['pending', 'pending', 'pending', 'pending', 'pending', 'pending', 'pending'], ['deploy' => ['detail' => $waiting]])]);

    $this->actingAs($this->admin)->get('/admin/tools/updates')
        ->assertInertia(fn (Assert $page) => $page->where('update.steps.0.step', 'deploy')->where('update.steps.0.detail', $waiting));
});
