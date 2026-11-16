<?php

declare(strict_types=1);

use App\Domain\Ai\AiMessage;
use App\Domain\Ai\AiRequest;
use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Jobs\Operations\CancelJob;
use App\Domain\Jobs\Operations\FailJob;
use App\Domain\Jobs\Operations\FinishJob;
use App\Domain\Jobs\Operations\ReportJobProgress;
use App\Domain\Plugins\Jobs\RunPluginJob;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\SafeMode;
use App\Domain\Support\Security\JobActor;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Teme\Contracts\Ai\AiAsk;
use Teme\Contracts\Ai\AiRefused;
use Tests\Fixtures\Plugins\PluginFixtures;
use Tests\Fixtures\Plugins\Scribe\AskHandler;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../../../Fixtures/plugins/scribe/src/ScribePlugin.php';
require_once __DIR__.'/../../../Fixtures/plugins/scribe/src/AskHandler.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->fake = aiFake();
    scribeEnable();
    scribeChoose('article', 'fake:fake-text');
    AskHandler::$last = null;

    // Queues one of scribe's jobs as the administrator and runs it as the queue would.
    $this->run = function (string $job, array $payload): string {
        Queue::fake();
        Auth::login($this->admin);
        app()->instance(LoadedPlugins::class, new LoadedPlugins);
        app(PluginLoader::class)->load();
        $plugin = app(LoadedPlugins::class)->running()['tests/scribe'] ?? throw new RuntimeException('Scribe did not start.');
        $id = $plugin->jobs->queue($job, ['person' => (string) $this->admin->id, ...$payload]);
        Auth::logout();

        (new RunPluginJob('scribe', $job, $id, JobActor::person($this->admin->id)))->handle(
            app(PluginRegistry::class), app(PluginLoader::class), app(SafeMode::class),
            app(CancelJob::class), app(FailJob::class), app(FinishJob::class), app(ReportJobProgress::class),
        );

        return $id;
    };
});

afterEach(fn () => PluginFixtures::forget());

it('sends a 600-second request inside a long job, with that timeout', function (): void {
    $this->fake->answer(aiText('A long article.'));

    $id = ($this->run)('write', ['seconds' => 600]);

    expect($this->fake->requests[0]->timeoutSeconds)->toBe(600)
        ->and(AskHandler::$last?->text)->toBe('A long article.')
        ->and(TrackedJob::query()->findOrFail($id)->state->value)->toBe('finished');
});

it('refuses more than 55 seconds in a job that isn’t long, sending nothing', function (): void {
    $this->fake->answer(aiText('Never sent.'));
    scribeChoose('titles', 'fake:fake-text');

    scribeRunAs($this->admin);

    expect(fn () => scribeDoor()->ask(new AiAsk('article', 'Write.', [], timeoutSeconds: 56), (string) $this->admin->id))
        ->toThrow(AiRefused::class, 'A request longer than 55 seconds runs only inside a long job.')
        ->and($this->fake->requests)->toBe([]);
});

it('takes 1 to 600 seconds for a long request, and 1 to 55 for any other', function (): void {
    $messages = [AiMessage::system('Write.'), AiMessage::user('<input name="brief">Ferries</input>')];

    expect((new AiRequest('fake-text', $messages, 100, 600, long: true))->timeoutSeconds)->toBe(600)
        ->and(fn () => new AiRequest('fake-text', $messages, 100, 601, long: true))->toThrow(InvalidArgumentException::class, 'between 1 and 600 seconds')
        ->and(fn () => new AiRequest('fake-text', $messages, 100, 56))->toThrow(InvalidArgumentException::class, 'between 1 and 55 seconds');
});
