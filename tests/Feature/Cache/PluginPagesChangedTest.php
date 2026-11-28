<?php

declare(strict_types=1);

use App\Domain\Cache\Operations\Input\PurgePublicTagsInput;
use App\Domain\Cache\Operations\PurgePublicTags;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\PurgeDeferredPages;
use App\Domain\Cards\Plugin\CoreCardsChanged;
use App\Domain\Import\ImportMode;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Plugins\Doors\CurrentJobActor;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Site\Insertions\CoreInsertionsChanged;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\JobActor;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Tests\Fixtures\Plugins\Billboard\Billboard;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

/*
 * A plugin's job tells the page cache its insertions or cards changed: the
 * pages of the named items are purged after the job's work commits, or the
 * door's own tags at most once a minute with the rest coalesced into one
 * later purge; nothing is purged outside a job, after a rollback, or while an
 * import holds the purges.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    PluginFixtures::use('billboard');
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    app()->instance(Elevation::class, new OperatorElevation);

    $manifest = app(PluginRegistry::class)->all()['tests/billboard']->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/billboard', $manifest->version(), $manifest->capabilities(), $manifest->hosts(), $manifest->hooks(), null));
    app(PluginLoader::class)->load();
    Billboard::reset();
    Cache::flush();

    $this->author = User::factory()->create()->assignRole('author');
    $this->story = pageArticle($this->author, 'Harbour story');
    $this->other = pageArticle($this->author, 'Other story');
    $this->folder = cacheOn();
    $this->asJob = function (Closure $work): mixed {
        app(CurrentJobActor::class)->set(JobActor::operator());

        try {
            return $work();
        } finally {
            app(CurrentJobActor::class)->clear();
        }
    };
});

afterEach(function (): void {
    Billboard::reset();
    PluginFixtures::forget();
    Cache::flush();

    if (isset($this->folder)) {
        cacheOff($this->folder);
    }
});

function insertionsDoor(): CoreInsertionsChanged
{
    return new CoreInsertionsChanged('tests/billboard');
}

it('purges the pages of the named content when a job names it, and no other cached page', function (): void {
    addrGet('/harbour-story/')->assertOk();
    addrGet('/other-story/')->assertOk();

    ($this->asJob)(fn () => insertionsDoor()->notify([(string) $this->story->id]));

    expect(cacheEntry('/harbour-story/'))->toBeNull()
        ->and(cacheEntry('/other-story/'))->not->toBeNull();
});

it('purges only the insertion tags for a whole-site call from a job, not the sitemap', function (): void {
    addrGet('/')->assertOk();
    addrGet('/sitemap.xml')->assertOk();

    ($this->asJob)(fn () => insertionsDoor()->notify());

    expect(cacheEntry('/'))->toBeNull()
        ->and(cacheEntry('/sitemap.xml'))->not->toBeNull();
});

it('purges a whole-site call once a minute, and coalesces the rest into one purge when the minute ends', function (): void {
    Queue::fake();
    addrGet('/')->assertOk();

    ($this->asJob)(fn () => insertionsDoor()->notify());

    expect(cacheEntry('/'))->toBeNull();

    addrGet('/')->assertOk();
    ($this->asJob)(fn () => insertionsDoor()->notify());

    expect(cacheEntry('/'))->not->toBeNull();
    Queue::assertPushed(PurgeDeferredPages::class, 1);

    (new PurgeDeferredPages)->handle();

    expect(cacheEntry('/'))->toBeNull();
});

it('writes nothing and purges nothing when the call comes from outside a job', function (): void {
    addrGet('/harbour-story/')->assertOk();

    expect(fn () => insertionsDoor()->notify([(string) $this->story->id]))->toThrow(AuthorizationException::class)
        ->and(fn () => insertionsDoor()->notify())->toThrow(AuthorizationException::class)
        ->and(cacheEntry('/harbour-story/'))->not->toBeNull();
});

it('purges nothing when the job\'s own transaction rolls back', function (): void {
    addrGet('/harbour-story/')->assertOk();

    try {
        ($this->asJob)(fn () => DB::transaction(function (): void {
            insertionsDoor()->notify([(string) $this->story->id]);

            throw new RuntimeException('The job failed after noting its change.');
        }));
    } catch (RuntimeException) {
        // The rollback is the point of the test.
    }

    expect(cacheEntry('/harbour-story/'))->not->toBeNull();
});

it('holds the purge of a job run during an import, as core holds its own', function (): void {
    addrGet('/harbour-story/')->assertOk();
    $source = ImportSource::query()->create([
        'address' => 'https://old.example', 'api_root' => 'https://old.example/wp-json/', 'user_name' => 'ada',
        'credential' => 'abcd EFGH 1234 ijkl MNOP 5678', 'plugin_version' => '0.2.0', 'api_version' => 1, 'probed_at' => now(),
    ]);
    $run = ImportRun::query()->create([
        'source_id' => $source->id, 'kind' => ImportRunKind::Full, 'state' => ImportRunState::Running, 'phase' => 'users',
        'phase_token' => strtolower((string) Str::ulid()), 'high_water_at' => now(), 'started_at' => now(),
    ]);

    app(ImportMode::class)->during($run->id, fn () => ($this->asJob)(fn () => insertionsDoor()->notify([(string) $this->story->id])));

    expect(cacheEntry('/harbour-story/'))->not->toBeNull()
        ->and(app(ImportMode::class)->takeHeld())->toContain(PageTags::content((string) $this->story->id));
});

it('purges the named pages from inside an Operation that passes ids, and no other page', function (): void {
    addrGet('/harbour-story/')->assertOk();
    addrGet('/other-story/')->assertOk();

    $operation = new class extends BaseOperation
    {
        protected function authorize(OperationInput $input): void {}

        protected function validate(OperationInput $input): void {}

        protected function perform(OperationInput $input): mixed
        {
            (new CoreInsertionsChanged('tests/billboard'))->notify([(string) test()->story->id]);

            return true;
        }
    };
    $operation->execute(new class implements OperationInput
    {
        public function actorId(): ?string
        {
            return null;
        }
    });

    expect(cacheEntry('/harbour-story/'))->toBeNull()
        ->and(cacheEntry('/other-story/'))->not->toBeNull();
});

it('refuses more ids than the cap, and an id that is not a content ID, with nothing purged', function (): void {
    addrGet('/harbour-story/')->assertOk();

    expect(fn () => ($this->asJob)(fn () => insertionsDoor()->notify(array_fill(0, 501, (string) $this->story->id))))->toThrow(ValidationException::class)
        ->and(fn () => ($this->asJob)(fn () => insertionsDoor()->notify(['not-a-content-id'])))->toThrow(ValidationException::class)
        ->and(fn () => ($this->asJob)(fn () => insertionsDoor()->notify([12345])))->toThrow(ValidationException::class)
        ->and(cacheEntry('/harbour-story/'))->not->toBeNull();
});

it('purges the card pages of the plugin from a job without the whole site', function (): void {
    addrGet('/sitemap.xml')->assertOk();

    ($this->asJob)(fn () => (new CoreCardsChanged('billboard', 'tests/billboard'))->notify());

    expect(cacheEntry('/sitemap.xml'))->not->toBeNull();
});

it('refuses a deferred purge of a tag core does not name for a plugin, such as every page, with nothing purged', function (): void {
    addrGet('/')->assertOk();

    expect(fn () => app(PurgePublicTags::class)->execute(new PurgePublicTagsInput([PageTags::EVERYTHING])))
        ->toThrow(AuthorizationException::class)
        ->and(cacheEntry('/'))->not->toBeNull();
});
