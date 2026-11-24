<?php

declare(strict_types=1);

use App\Domain\Cache\PublicChanges;
use App\Domain\Import\ImportMode;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $source = ImportSource::query()->create([
        'address' => 'https://old.example', 'api_root' => 'https://old.example/wp-json/', 'user_name' => 'ada',
        'credential' => 'abcd EFGH 1234 ijkl MNOP 5678', 'plugin_version' => '0.2.0', 'api_version' => 1, 'probed_at' => now(),
    ]);

    $this->runIn = fn (ImportRunState $state): ImportRun => ImportRun::query()->create([
        'source_id' => $source->id,
        'kind' => ImportRunKind::Full,
        'state' => $state,
        'phase' => 'users',
        'phase_token' => $state === ImportRunState::Running ? strtolower((string) Str::ulid()) : null,
        'stop_reason' => $state === ImportRunState::Stopped ? 'by-hand' : null,
        'high_water_at' => now(),
        'started_at' => now(),
        'ended_at' => $state === ImportRunState::Running ? null : now(),
    ]);

    $this->mode = app(ImportMode::class);
});

it('holds an Operation\'s purge while a run\'s work is in hand, and lets it through outside one (I-332)', function (): void {
    $folder = cacheOn();
    cacheShown(['/ferry/' => ['content:ferry'], '/harbour/' => ['content:harbour']]);
    $run = ($this->runIn)(ImportRunState::Running);

    $this->mode->during($run->id, fn () => cacheProbe(new CacheKeysFor(CacheKeysFor::CONTENT, 'ferry', ['content:ferry']))->execute(schedProbeInput()));

    expect(cacheKept('/ferry/', '/harbour/'))->toBe(['/ferry/', '/harbour/']);

    cacheProbe(new CacheKeysFor(CacheKeysFor::CONTENT, 'harbour', ['content:harbour']))->execute(schedProbeInput());

    expect(cacheKept('/ferry/', '/harbour/'))->toBe(['/ferry/']);
    cacheOff($folder);
});

it('keeps the tags of the purges it held, for a picture pass to purge its page of posts after (#716)', function (): void {
    $folder = cacheOn();
    cacheShown(['/ferry/' => ['content:ferry'], '/harbour/' => ['content:harbour']]);
    $run = ($this->runIn)(ImportRunState::Running);

    $this->mode->during($run->id, fn () => cacheProbe(new CacheKeysFor(CacheKeysFor::CONTENT, 'ferry', ['content:ferry']))->execute(schedProbeInput()));
    $held = $this->mode->takeHeld();
    app(PublicChanges::class)->purge($held);

    expect($held)->toContain('content:ferry')
        ->and($this->mode->takeHeld())->toBe([])
        ->and(cacheKept('/ferry/', '/harbour/'))->toBe(['/harbour/']);
    cacheOff($folder);
});

it('still runs an Operation\'s own work after each commit inside a run, which the next object reads', function (): void {
    $run = ($this->runIn)(ImportRunState::Running);
    $operation = new class extends BaseOperation
    {
        protected function authorize(OperationInput $input): void {}

        protected function validate(OperationInput $input): void {}

        protected function perform(OperationInput $input): mixed
        {
            return null;
        }

        protected function afterCommit(mixed $result): void
        {
            Cache::put('import-mode-hooks', 'ran');
        }

        protected function invalidateCache(mixed $result): void
        {
            Cache::put('import-mode-invalidation', 'ran');
        }
    };

    $this->mode->during($run->id, fn () => $operation->execute(schedProbeInput()));

    expect(Cache::get('import-mode-hooks'))->toBe('ran')
        ->and(Cache::get('import-mode-invalidation'))->toBe('ran');
});

it('can be entered only for a run that is running', function (string $case): void {
    $runId = match ($case) {
        'missing' => strtolower((string) Str::ulid()),
        'stopped' => ($this->runIn)(ImportRunState::Stopped)->id,
        default => ($this->runIn)(ImportRunState::Finished)->id,
    };

    expect(fn () => $this->mode->during($runId, fn () => null))->toThrow(LogicException::class, 'Only a running import can enter import mode.')
        ->and($this->mode->active())->toBeFalse();
})->with([
    'a run that isn\'t there' => ['missing'],
    'a stopped run' => ['stopped'],
    'a finished run' => ['finished'],
]);

it('refuses to be entered twice', function (): void {
    $run = ($this->runIn)(ImportRunState::Running);

    expect(fn () => $this->mode->during($run->id, fn () => $this->mode->during($run->id, fn () => null)))
        ->toThrow(LogicException::class, "Import mode is already on, for run [{$run->id}].");
});

it('leaves the mode when the work throws', function (): void {
    $run = ($this->runIn)(ImportRunState::Running);

    expect(fn () => $this->mode->during($run->id, function (): void {
        expect($this->mode->active())->toBeTrue()->and($this->mode->runId())->toBe(ImportRun::query()->value('id'));

        throw new RuntimeException('An object failed.');
    }))->toThrow(RuntimeException::class, 'An object failed.');

    expect($this->mode->active())->toBeFalse()
        ->and($this->mode->runId())->toBeNull();
});

it('can\'t be entered from the HTTP layer: nothing there names it', function (): void {
    $naming = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Http'), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->isFile() && str_contains((string) file_get_contents($file->getPathname()), 'ImportMode')) {
            $naming[] = $file->getPathname();
        }
    }

    expect($naming)->toBe([]);
});

it('belongs to one request or job: a new scope starts without it', function (): void {
    $first = app(ImportMode::class);

    expect(app(ImportMode::class))->toBe($first);

    app()->forgetScopedInstances();

    expect(app(ImportMode::class))->not->toBe($first);
});
