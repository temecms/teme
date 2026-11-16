<?php

declare(strict_types=1);

use App\Domain\Plugins\Jobs\DeclaredJobs;
use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\ManifestValidator;
use Teme\Contracts\Plugins\Capability;
use Tests\Fixtures\Plugins\PluginFixtures;

beforeEach(function (): void {
    config(['teme.version' => PluginFixtures::TEME]);
    $this->errors = fn (array $manifest): array => app(ManifestValidator::class)->errors(
        $manifest,
        PluginFixtures::path('hello'),
        PluginFixtures::composer('hello'),
    );
    $this->base = fn (array $extra = []): array => [
        ...PluginFixtures::manifest('hello'),
        'capabilities' => ['content.read', 'jobs'],
        'jobs' => [
            ['key' => 'write-article', 'label' => 'Write an article', 'long' => true, 'tries' => 3, 'backoff' => [30, 120, 600]],
            ['key' => 'sync', 'label' => 'Sync with the service'],
        ],
        'schedules' => [
            ['job' => 'sync', 'every' => 'hour'],
        ],
        ...$extra,
    ];
});

it('accepts a valid jobs and schedules declaration', function (): void {
    expect(($this->errors)(($this->base)()))->toBe([]);
});

it('refuses each broken jobs or schedules declaration with its sentence', function (Closure $break, string $sentence): void {
    expect(($this->errors)($break(($this->base)())))->toContain($sentence);
})->with([
    'jobs not a list' => [
        fn (array $m): array => [...$m, 'jobs' => ['key' => 'sync']],
        'jobs must be a list of job declarations.',
    ],
    'jobs key pattern' => [
        fn (array $m): array => [...$m, 'jobs' => [['key' => 'Bad_Key', 'label' => 'Bad']]],
        'jobs "Bad_Key" must be a lower-case key of letters, digits and hyphens, at most 48 characters, starting with a letter.',
    ],
    'jobs key duplicate' => [
        fn (array $m): array => [...$m, 'jobs' => [
            ['key' => 'sync', 'label' => 'One'],
            ['key' => 'sync', 'label' => 'Two'],
        ]],
        'jobs key "sync" is listed more than once.',
    ],
    'jobs label empty' => [
        fn (array $m): array => [...$m, 'jobs' => [['key' => 'sync', 'label' => ' ']]],
        'jobs "sync" needs a label of 1 to 80 characters.',
    ],
    'jobs label too long' => [
        fn (array $m): array => [...$m, 'jobs' => [['key' => 'sync', 'label' => str_repeat('a', 81)]]],
        'jobs "sync" needs a label of 1 to 80 characters.',
    ],
    'jobs long not boolean' => [
        fn (array $m): array => [...$m, 'jobs' => [['key' => 'sync', 'label' => 'Sync', 'long' => 1]]],
        'jobs "sync" long must be true or false.',
    ],
    'jobs tries out of range' => [
        fn (array $m): array => [...$m, 'jobs' => [['key' => 'sync', 'label' => 'Sync', 'tries' => 6]]],
        'jobs "sync" tries must be a whole number from 1 to 5.',
    ],
    'jobs backoff wrong' => [
        fn (array $m): array => [...$m, 'jobs' => [['key' => 'sync', 'label' => 'Sync', 'backoff' => [0]]]],
        'jobs "sync" backoff must be a list of 1 to 5 whole numbers from 1 to 3600.',
    ],
    'schedules not a list' => [
        fn (array $m): array => [...$m, 'schedules' => ['job' => 'sync', 'every' => 'hour']],
        'schedules must be a list of schedule declarations.',
    ],
    'schedules unknown job' => [
        fn (array $m): array => [...$m, 'schedules' => [['job' => 'gone', 'every' => 'hour']]],
        'schedules names job "gone", which isn’t declared.',
    ],
    'schedules every unknown' => [
        fn (array $m): array => [...$m, 'schedules' => [['job' => 'sync', 'every' => 'cron']]],
        'schedules every must be one of minute, five-minutes, fifteen-minutes, hour, day, week.',
    ],
    'jobs without capability' => [
        fn (array $m): array => [...$m, 'capabilities' => ['content.read']],
        'It declares jobs but doesn’t ask for jobs.',
    ],
]);

it('reads declared jobs with defaults through DeclaredJobs', function (): void {
    $manifest = new Manifest(($this->base)());
    $declared = DeclaredJobs::of($manifest);

    expect($declared->job('write-article'))->toMatchArray([
        'key' => 'write-article',
        'label' => 'Write an article',
        'long' => true,
        'tries' => 3,
        'backoff' => [30, 120, 600],
    ])
        ->and($declared->job('sync'))->toMatchArray([
            'key' => 'sync',
            'label' => 'Sync with the service',
            'long' => false,
            'tries' => 3,
            'backoff' => [30, 120, 600],
        ])
        ->and($declared->schedules())->toBe([
            ['job' => 'sync', 'every' => 'hour'],
        ])
        ->and($declared->job('gone'))->toBeNull();
});

it('offers the jobs capability on Capability', function (): void {
    expect(Capability::Jobs->value)->toBe('jobs');
});
