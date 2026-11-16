<?php

declare(strict_types=1);

use App\Domain\Jobs\JobState;
use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Jobs\Operations\CancelJob;
use App\Domain\Jobs\Operations\FailJob;
use App\Domain\Jobs\Operations\FinishJob;
use App\Domain\Jobs\Operations\ReportJobProgress;
use App\Domain\Plugins\Doors\HostResolver;
use App\Domain\Plugins\Doors\PluginDoors;
use App\Domain\Plugins\Doors\PluginHttp;
use App\Domain\Plugins\Jobs\RunPluginJob;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\SafeMode;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\JobActor;
use App\Domain\Support\Security\OperatorElevation;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Teme\Contracts\Http\AnswerTooLarge;
use Teme\Contracts\Http\OutboundHttp;
use Teme\Contracts\Http\RequestFailed;
use Tests\Fixtures\Plugins\Lists\FakeResolver;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    app()->instance(HostResolver::class, FakeResolver::answering(['api.example.com' => ['93.184.215.14']]));

    $this->door = fn (bool $long): OutboundHttp => app(PluginDoors::class)->for(
        'tests/jobs',
        'jobs',
        ['http'],
        ['api.example.com'],
        httpSeconds: $long ? 300 : null,
        answerBytes: $long ? PluginHttp::LONG_ANSWER_BYTES : null,
    )[OutboundHttp::class];

    // The path names the answer's size in bytes; the jobs fixture's long call asks for /long, answered with 5 MB.
    Http::fake(function (Request $request) {
        $size = explode('/', $request->url())[3] ?? '0';

        return Http::response(str_repeat('a', $size === 'long' ? 5 * 1024 * 1024 : (int) $size));
    });
});

it('lets an answer of up to 16 MB arrive inside a long job', function (): void {
    $answer = ($this->door)(true)->get('https://api.example.com/'.(5 * 1024 * 1024));

    expect(strlen($answer->body))->toBe(5 * 1024 * 1024)
        ->and(PluginHttp::LONG_ANSWER_BYTES)->toBe(16 * 1024 * 1024);
});

it('refuses an answer past 16 MB inside a long job, saying so', function (): void {
    expect(fn () => ($this->door)(true)->get('https://api.example.com/'.(17 * 1024 * 1024)))
        ->toThrow(AnswerTooLarge::class, 'The answer from api.example.com was larger than 16 MB.');
});

it('stops an answer as it arrives, the moment it passes 16 MB, never reading on', function (): void {
    $options = PluginHttp::transport('api.example.com', '93.184.215.14', PluginHttp::LONG_ANSWER_BYTES);

    expect($options['progress'](0, PluginHttp::LONG_ANSWER_BYTES, 0, 0))->toBeFalse()
        ->and(fn () => $options['progress'](0, PluginHttp::LONG_ANSWER_BYTES + 1, 0, 0))->toThrow(AnswerTooLarge::class, 'larger than 16 MB');
});

it('keeps answers outside a long job at 1 MB', function (): void {
    expect(fn () => ($this->door)(false)->get('https://api.example.com/'.(2 * 1024 * 1024)))
        ->toThrow(AnswerTooLarge::class, 'The answer from api.example.com was larger than 1 MB.');
});

it('keeps requests at 1 MB inside a long job', function (): void {
    expect(fn () => ($this->door)(true)->postJson('https://api.example.com/0', ['text' => str_repeat('a', PluginHttp::MOST_BYTES)]))
        ->toThrow(RequestFailed::class, 'larger than 1 MB');
});

it('gives a long job’s run the larger answer, so a 5 MB answer finishes it', function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    PluginFixtures::use('jobs');
    PluginFixtures::installed(['tests/jobs' => 'jobs']);
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/jobs', '1.0.0', ['jobs', 'http'], ['api.example.com'], [], null));
    Queue::fake();
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    app(PluginLoader::class)->load();
    $id = app(LoadedPlugins::class)->running()['tests/jobs']->jobs->queue('long-call');

    try {
        (new RunPluginJob('jobs', 'long-call', $id, JobActor::operator()))->handle(
            app(PluginRegistry::class),
            app(PluginLoader::class),
            app(SafeMode::class),
            app(CancelJob::class),
            app(FailJob::class),
            app(FinishJob::class),
            app(ReportJobProgress::class),
        );
    } finally {
        PluginFixtures::forget();
    }

    expect(TrackedJob::query()->findOrFail($id)->state)->toBe(JobState::Finished);
});
