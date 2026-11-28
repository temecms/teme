<?php

declare(strict_types=1);

use App\Domain\Cache\PageTags;
use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\ChooseSlotProvider;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\ChooseSlotProviderInput;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Slots\PluginSlotDeclaration;
use App\Domain\Plugins\Slots\PluginSlots;
use App\Domain\Plugins\Slots\SlotProviders;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;
use Tests\Fixtures\Plugins\PluginFixtures;
use Tests\Fixtures\Plugins\Slotted\Slotted;

require_once dirname(__DIR__, 2).'/Fixtures/plugins/slotted/src/Slotted.php';

uses(RefreshDatabase::class);

/**
 * Enables and loads the slotted fixture as the operator, which fills the image service slot.
 */
function slottedEnabled(): void
{
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);

    PluginFixtures::use('slotted');
    $manifest = app(PluginRegistry::class)->find('tests/slotted')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/slotted', '1.0.0', $manifest?->capabilities() ?? [], [], [], null));
    app(PluginLoader::class)->load();
    app()->forgetInstance(Elevation::class);
}

it('chooses the lone enabled provider of a slot by itself', function (): void {
    slottedEnabled();

    expect(app(SlotProviders::class)->chosenFor(PluginSlots::IMAGE_SERVICE))->toBe('tests/slotted');
});

it('records the administrator’s choice and clears it for none', function (): void {
    slottedEnabled();
    app()->instance(Elevation::class, new OperatorElevation);

    app(ChooseSlotProvider::class)->execute(new ChooseSlotProviderInput(PluginSlots::IMAGE_SERVICE, 'tests/slotted', null));
    expect(DB::table('plugin_slot_choices')->where('slot', PluginSlots::IMAGE_SERVICE)->value('plugin'))->toBe('tests/slotted');

    app(ChooseSlotProvider::class)->execute(new ChooseSlotProviderInput(PluginSlots::IMAGE_SERVICE, null, null));
    expect(DB::table('plugin_slot_choices')->where('slot', PluginSlots::IMAGE_SERVICE)->exists())->toBeFalse();
});

it('clears a choice when its plugin is disabled', function (): void {
    slottedEnabled();
    app()->instance(Elevation::class, new OperatorElevation);
    app(ChooseSlotProvider::class)->execute(new ChooseSlotProviderInput(PluginSlots::IMAGE_SERVICE, 'tests/slotted', null));

    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/slotted', null));

    expect(DB::table('plugin_slot_choices')->where('slot', PluginSlots::IMAGE_SERVICE)->value('plugin'))->toBe('')
        ->and(DB::table('plugin_slot_choices')->where('slot', PluginSlots::IMAGE_SERVICE)->value('notice'))->toBe('The Slotted plugin was turned off, so its choice was cleared.');
});

it('refuses a choice for a plugin that is not enabled, and writes nothing', function (): void {
    app()->instance(Elevation::class, new OperatorElevation);

    expect(fn () => app(ChooseSlotProvider::class)->execute(new ChooseSlotProviderInput(PluginSlots::IMAGE_SERVICE, 'tests/slotted', null)))
        ->toThrow(ValidationException::class);
    expect(DB::table('plugin_slot_choices')->count())->toBe(0);
});

it('refuses a person without plugins.manage, and writes nothing', function (): void {
    slottedEnabled();
    $person = User::factory()->create();

    expect(fn () => app(ChooseSlotProvider::class)->execute(new ChooseSlotProviderInput(PluginSlots::IMAGE_SERVICE, 'tests/slotted', (string) $person->id)))
        ->toThrow(AuthorizationException::class);
    expect(DB::table('plugin_slot_choices')->count())->toBe(0);
});

it('refuses a slot that core does not declare', function (): void {
    expect(PluginSlotDeclaration::errors(['nope']))->not->toBeEmpty()
        ->and(PluginSlotDeclaration::errors(['image-service', 'search', 'comment-check']))->toBe([]);
});

it('refuses a plugin that names a slot it does not implement', function (): void {
    $plugin = new class implements Plugin
    {
        public function register(Hooks $hooks): void {}
    };

    expect(PluginSlotDeclaration::startErrors(['provides' => ['image-service'], 'imageService' => ['label' => 'x', 'host' => 'x.example.com']], $plugin))->not->toBeEmpty();
});

it('validates provides through the contributions validator', function (): void {
    $validator = app(ContributionsValidator::class);

    expect($validator->errors(['provides' => ['image-service']], '1.0.0', ['media.read']))->toBe([])
        ->and($validator->errors(['provides' => 'image-service'], '1.0.0'))->not->toBeEmpty();
});

it('requires search.read of a plugin that fills the search slot', function (): void {
    $validator = app(ContributionsValidator::class);

    expect($validator->errors(['provides' => ['search']], '1.0.0', ['content.read']))->not->toBeEmpty()
        ->and($validator->errors(['provides' => ['search']], '1.0.0', ['search.read']))->toBe([]);
});

it('purges the cached search lists when the search engine changes', function (): void {
    $folder = cacheOn();

    try {
        PluginFixtures::use('searcher');
        $manifest = app(PluginRegistry::class)->find('tests/searcher')?->manifest;
        app()->instance(Elevation::class, new OperatorElevation);
        app(EnablePlugin::class)->execute(new EnablePluginInput('tests/searcher', '1.0.0', $manifest?->capabilities() ?? [], [], [], null));
        app(PluginLoader::class)->load();

        cacheShown(['/search-page/' => [PageTags::listOf('post')], '/elsewhere/' => ['some-other-tag']]);

        app(ChooseSlotProvider::class)->execute(new ChooseSlotProviderInput(slot: PluginSlots::SEARCH, plugin: 'tests/searcher', actorId: null));

        expect(cacheKept('/search-page/', '/elsewhere/'))->toBe(['/elsewhere/']);
    } finally {
        cacheOff($folder);
    }
});
