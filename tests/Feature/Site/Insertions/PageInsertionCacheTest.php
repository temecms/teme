<?php

declare(strict_types=1);

use App\Domain\Cache\PageTags;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Site\Insertions\InsertionPoints;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Insertions\ArticleFacts;
use Teme\Contracts\Insertions\InsertionContributor;
use Teme\Contracts\Insertions\InsertionsChanged;
use Teme\Contracts\Operations\OperationInput;
use Tests\Fixtures\Plugins\Billboard\Billboard;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

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

    $this->author = User::factory()->create()->assignRole('author');
    $this->folder = cacheOn();
    pageArticle($this->author, 'Harbour story');
});

afterEach(function (): void {
    Billboard::reset();
    PluginFixtures::forget();

    if (isset($this->folder)) {
        cacheOff($this->folder);
    }
});

it('tags every page that asked page contributors, and only those', function (): void {
    addrGet('/')->assertOk();
    addrGet('/harbour-story/')->assertOk();
    addrGet('/feed/')->assertOk();

    expect(cacheEntry('/')?->tags)->toContain(PageTags::PAGE_INSERTIONS)
        ->and(cacheEntry('/harbour-story/')?->tags)->toContain(PageTags::PAGE_INSERTIONS)
        ->and(cacheEntry('/feed/')?->tags ?? [])->not->toContain(PageTags::PAGE_INSERTIONS);
});

it('purges those pages after the plugin\'s settings are saved, and not when the save is refused', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    addrGet('/')->assertOk();

    expect(fn () => app(UpdateSettings::class)->execute(new UpdateSettingsInput('plugin.billboard', ['label' => 'Ad'], [], [], 0, (string) $this->author->id)))
        ->toThrow(AuthorizationException::class)
        ->and(cacheEntry('/'))->not->toBeNull();

    app(UpdateSettings::class)->execute(new UpdateSettingsInput('plugin.billboard', ['label' => 'Ad'], [], [], 0, (string) $admin->id));

    expect(cacheEntry('/'))->toBeNull();
});

it('purges those pages after a plugin granted pages.insert is disabled, and again after it is enabled', function (): void {
    addrGet('/')->assertOk();

    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/billboard', null));

    expect(cacheEntry('/'))->toBeNull();

    addrGet('/')->assertOk();
    $manifest = app(PluginRegistry::class)->all()['tests/billboard']->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/billboard', $manifest->version(), $manifest->capabilities(), $manifest->hosts(), $manifest->hooks(), null));

    expect(cacheEntry('/'))->toBeNull();
});

it('purges those pages after InsertionsChanged::notify() commits, and not on rollback', function (): void {
    addrGet('/')->assertOk();
    $notifying = fn (bool $fails): BaseOperation => new class($fails) extends BaseOperation
    {
        public function __construct(private bool $fails) {}

        protected function authorize(OperationInput $input): void {}

        protected function validate(OperationInput $input): void {}

        protected function perform(OperationInput $input): mixed
        {
            app(InsertionsChanged::class)->notify();

            return $this->fails ? throw new RuntimeException('perform() failed on purpose.') : null;
        }
    };

    expect(fn () => $notifying(true)->execute(schedProbeInput()))->toThrow(RuntimeException::class)
        ->and(cacheEntry('/'))->not->toBeNull();

    $notifying(false)->execute(schedProbeInput());

    expect(cacheEntry('/'))->toBeNull();
});

it('gives article contributors the article\'s address', function (): void {
    $seen = new ArrayObject;
    app()->instance('test.insertions.path', new readonly class($seen) implements InsertionContributor
    {
        public function __construct(private ArrayObject $seen) {}

        public function precedence(): string
        {
            return 'core';
        }

        public function insertions(ArticleFacts $article): array
        {
            $this->seen[] = $article->path;

            return [];
        }
    });
    app()->tag(['test.insertions.path'], InsertionPoints::CONTRIBUTORS);

    addrGet('/harbour-story/')->assertOk();

    expect($seen->getArrayCopy())->toBe(['/harbour-story/']);
});
