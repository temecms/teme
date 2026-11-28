<?php

declare(strict_types=1);

use App\Domain\Contents\Exceptions\StaleRevisionException;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Plugins\Jobs\RunPluginJob;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\RunEditorPanelActionInput;
use App\Domain\Plugins\Operations\RunEditorPanelAction;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Plugins\Linkfinder\Linkfinder;
use Tests\Fixtures\Plugins\PluginFixtures;

require_once __DIR__.'/Linkfinder.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    linkfinderEnabled();
    Queue::fake();
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->post = lifecycleDraft($this->editor, 'Pruning roses');

    $this->run = fn (string $action = 'find', array $values = [], ?User $person = null, ?string $revision = null): array => app(RunEditorPanelAction::class)->execute(new RunEditorPanelActionInput(
        code: 'linkfinder',
        panel: 'links',
        action: $action,
        contentId: $this->post->id,
        revisionId: $revision ?? (string) $this->post->working_revision_id,
        values: $values,
        actorId: (string) ($person ?? $this->editor)->id,
    ));
});

afterEach(function (): void {
    Linkfinder::reset();
    PluginFixtures::forget();
});

it('queues the action’s job as the person, with the item, the revision and the values, once while one waits', function (): void {
    $first = ($this->run)('rescan', ['focus' => 'pruning', 'reach' => 'all']);
    $second = ($this->run)('rescan', ['focus' => 'pruning', 'reach' => 'all']);

    $job = DB::table('tracked_jobs')->where('id', $first['job'])->first();
    // The payload is stored as JSONB, which keeps keys in its own order.
    $payload = json_decode((string) $job->payload, true);
    ksort($payload);
    ksort($payload['values']);

    expect($second['job'])->toBe($first['job'])
        ->and(DB::table('tracked_jobs')->count())->toBe(1)
        ->and($job->owner)->toBe('linkfinder')
        ->and($job->run_id)->toBe('find-links')
        ->and($job->starter)->toBe('person')
        ->and((string) $job->started_by)->toBe((string) $this->editor->id)
        ->and($job->label)->toBe('Look further: Pruning roses')
        ->and($job->unique_key)->toBe(RunEditorPanelAction::uniqueKey('links', 'rescan', $this->post->id))
        ->and($payload)->toBe([
            'action' => 'rescan',
            'itemId' => $this->post->id,
            'panel' => 'links',
            'revisionId' => $this->post->working_revision_id,
            'values' => ['focus' => 'pruning', 'fresh' => null, 'most' => 5, 'reach' => 'all'],
        ]);

    // A panel action is a person's own click, so its closing line reaches their inbox.
    Queue::assertPushed(RunPluginJob::class, fn (RunPluginJob $pushed): bool => $pushed->actor->userId === (string) $this->editor->id
        && $pushed->notifyOnFinish === true);
});

it('makes a unique key that fits, lower case, however long the ids', function (): void {
    expect(RunEditorPanelAction::uniqueKey('links', 'find', '01JD2K8Y8T4V6W9X0Y1Z2A3B4C'))->toBe('panel:links:find:01jd2k8y8t4v6w9x0y1z2a3b4c')
        ->and(strlen(RunEditorPanelAction::uniqueKey(str_repeat('p', 24), str_repeat('a', 24), '01JD2K8Y8T4V6W9X0Y1Z2A3B4C')))->toBeLessThanOrEqual(64)
        ->and(RunEditorPanelAction::uniqueKey(str_repeat('p', 24), str_repeat('a', 24), '01JD2K8Y8T4V6W9X0Y1Z2A3B4C'))->toMatch('/^[a-z0-9][a-z0-9:._-]{0,63}$/');
});

it('refuses, writing nothing', function (Closure $call, string $exception): void {
    expect(fn () => $call($this))->toThrow($exception);

    expect(DB::table('tracked_jobs')->count())->toBe(0);
})->with([
    'a person who can’t update the item' => [fn ($test) => ($test->run)(person: User::factory()->create()->assignRole('author')->givePermissionTo('plugin.linkfinder.suggest')), AuthorizationException::class],
    'a person without the panel’s permission' => [function ($test): array {
        $author = User::factory()->create()->assignRole('author');
        $test->post = lifecycleDraft($author, 'My own roses');

        return ($test->run)(person: $author);
    }, AuthorizationException::class],
    'an undeclared action' => [fn ($test) => ($test->run)('publish'), ValidationException::class],
    'a value the field refuses' => [fn ($test) => ($test->run)('rescan', ['reach' => 'forever']), ValidationException::class],
    'a required value missing' => [fn ($test) => ($test->run)('rescan', ['focus' => 'pruning']), ValidationException::class],
    'a number out of bounds' => [fn ($test) => ($test->run)('rescan', ['reach' => 'all', 'most' => 21]), ValidationException::class],
    'a field it doesn’t declare' => [fn ($test) => ($test->run)('find', ['tone' => 'warm']), ValidationException::class],
    'a stale revision' => [function ($test): array {
        $old = (string) $test->post->working_revision_id;
        app(SaveDraft::class)->execute(new SaveDraftInput($test->post->id, 'Pruning roses well', ['type' => 'doc', 'content' => []], [], $old, (string) $test->editor->id));

        return ($test->run)(revision: $old);
    }, StaleRevisionException::class],
    'a disabled plugin' => [function ($test): array {
        app()->instance(Elevation::class, new OperatorElevation);
        app(DisablePlugin::class)->execute(new DisablePluginInput('tests/linkfinder', null));
        app()->forgetInstance(Elevation::class);

        return ($test->run)();
    }, AuthorizationException::class],
]);

it('answers the action with the panel again, and a stale revision with 409', function (): void {
    $url = route('content.panels.actions', ['content' => $this->post->id, 'code' => 'linkfinder', 'panel' => 'links', 'action' => 'find']);

    $this->actingAs($this->editor)->postJson($url, ['revision_id' => $this->post->working_revision_id])
        ->assertOk()
        ->assertJsonPath('status', 'No links suggested yet.')
        ->assertJsonPath('jobs.0.label', 'Find links: Pruning roses')
        ->assertJsonPath('busy', true);

    $this->actingAs($this->editor)->postJson($url, ['revision_id' => 'older'])->assertStatus(409)->assertJsonPath('stale', true);
});
