<?php

declare(strict_types=1);

use App\Domain\Cards\Exceptions\InvalidDocumentException;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Hooks\ListenerFaults;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Regions\Queries\RegionScreens;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fixtures\Plugins\Cardbox\NoteCard;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

/*
 * A plugin's cards in a theme's regions (docs/guides/developers/plugins/cards.md):
 * one node type, "plugin-card", naming the card as code:id; drawn by the
 * plugin, cleaned by core, kept and drawn as nothing while it is disabled.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/open')]]);
    themeActivate('open');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->admin = User::factory()->create()->assignRole('administrator');
    app()->instance(Elevation::class, new OperatorElevation);

    $this->restart = function (): void {
        app()->instance(LoadedPlugins::class, new LoadedPlugins);
        app(PluginLoader::class)->load();
    };
    $this->enable = function (): void {
        PluginFixtures::use('cardbox');
        $manifest = app(PluginRegistry::class)->all()['tests/cardbox']->manifest;
        app(EnablePlugin::class)->execute(new EnablePluginInput('tests/cardbox', $manifest->version(), $manifest->capabilities(), $manifest->hosts(), [], null));
        ($this->restart)();
    };
    $this->note = fn (array $values = ['body' => 'Hi']): array => ['type' => 'plugin-card', 'attrs' => ['id' => (string) Str::ulid(), 'card' => 'cardbox:note', 'values' => $values]];
});

afterEach(fn () => PluginFixtures::forget());

it('draws a plugin card, cleaned, with the region size', function (): void {
    ($this->enable)();
    $region = regionCreate($this->editor, 'open', 'sidebar');
    regionSave($region, $this->editor, [($this->note)()]);
    listsPublish($region, $this->editor);
    pageArticle($this->editor, 'A story');

    expect(addrGet('/a-story/')->assertOk()->getContent())
        ->toContain('data-plugin-card="cardbox:note"')
        ->toContain('class="card-plugin card-plugin-cardbox-note"')
        ->toContain('Hi 300x600')
        ->not->toContain('alert(1)');
});

it('keeps a disabled plugin’s card, draws nothing, and draws it again when re-enabled', function (): void {
    ($this->enable)();
    $region = regionCreate($this->editor, 'open', 'sidebar');
    regionSave($region, $this->editor, [($this->note)()]);
    listsPublish($region, $this->editor);
    pageArticle($this->editor, 'A story');
    expect(addrGet('/a-story/')->getContent())->toContain('Hi 300x600');

    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/cardbox', (string) $this->admin->id));
    ($this->restart)();

    expect(addrGet('/a-story/')->assertOk()->getContent())->not->toContain('data-plugin-card')
        ->and(json_encode(ContentRevision::query()->findOrFail(Content::query()->findOrFail($region->id)->working_revision_id)->document))->toContain('cardbox:note');

    // A disabled plugin's card is kept as it is when the region is saved again.
    regionSave($region, $this->editor, [($this->note)(['body' => 'Kept'])]);

    ($this->enable)();
    listsPublish($region, $this->editor);

    expect(addrGet('/a-story/')->getContent())->toContain('Kept 300x600');
});

it('takes every card in a region that names none, and only the named in a narrowed one', function (): void {
    ($this->enable)();
    $sidebar = regionCreate($this->editor, 'open', 'sidebar');
    $narrow = regionCreate($this->editor, 'open', 'narrow');

    regionSave($sidebar, $this->editor, [regionCardNode('snippet'), ($this->note)()]);

    expect(fn () => regionSave($narrow, $this->editor, [regionCardNode('snippet')]))->toThrow(InvalidDocumentException::class, "can't be used in this region")
        ->and(fn () => regionSave($narrow, $this->editor, [($this->note)()]))->toThrow(InvalidDocumentException::class, 'The card "cardbox:note" can\'t be used in this region.');
});

it('offers the plugin’s cards in a region that takes every card, by their labels', function (): void {
    ($this->enable)();
    $region = regionCreate($this->editor, 'open', 'sidebar');
    $mode = app(RegionScreens::class)->editorMode($region->id);

    expect($mode['cards'])->toContain('callout', 'cardbox:note')->not->toContain('plugin-card', 'imported-content')
        ->and($mode['cardLabels'])->toContain('Note')
        ->and($mode['size'])->toBe('300x600')
        ->and($mode['takes'])->toBe('Takes text and any card. At most 50 blocks.');
});

it('refuses a value the card doesn’t declare, and one that breaks its rules', function (array $values): void {
    ($this->enable)();
    $region = regionCreate($this->editor, 'open', 'sidebar');

    expect(fn () => regionSave($region, $this->editor, [($this->note)($values)]))->toThrow(InvalidDocumentException::class);
})->with([
    'unknown' => [['nope' => 1]],
    'too long' => [['body' => str_repeat('a', 121)]],
    'a choice too long' => [['pick' => str_repeat('a', 192)]],
]);

it('refuses a plugin card outside a region', function (): void {
    ($this->enable)();
    $post = lifecycleDraft($this->editor, 'A post');

    expect(fn () => docSave($post, $this->editor, docWith(($this->note)())))->toThrow(InvalidDocumentException::class, "theme's region only");
});

it('writes nothing when the person may not manage regions', function (): void {
    ($this->enable)();
    $region = regionCreate($this->editor, 'open', 'sidebar');
    $author = User::factory()->create()->assignRole('author');
    $before = DB::table('content_revisions')->count();

    expect(fn () => regionSave($region, $author, [($this->note)()]))->toThrow(AuthorizationException::class)
        ->and(DB::table('content_revisions')->count())->toBe($before);
});

it('gives the plugin’s options for a choice field, told the region’s size, only to people who manage regions', function (): void {
    ($this->enable)();
    $region = regionCreate($this->editor, 'open', 'sidebar');
    $author = User::factory()->create()->assignRole('author');

    $this->actingAs($author)->getJson("/admin/content/{$region->id}/card-choices/cardbox:note/pick")->assertForbidden();
    $this->actingAs($this->editor)->getJson("/admin/content/{$region->id}/card-choices/cardbox:note/pick?q=ta")
        ->assertOk()
        ->assertExactJson(['choices' => [['value' => 'tall', 'label' => 'Tall', 'hint' => '300x600', 'fits' => true]]]);
    $this->actingAs($this->editor)->getJson("/admin/content/{$region->id}/card-choices/cardbox:gone/pick")->assertNotFound();
});

it('gives no options, and records the plugin’s fault, when its choices throw', function (): void {
    ($this->enable)();
    $region = regionCreate($this->editor, 'open', 'sidebar');
    $faults = new class implements ListenerFaults
    {
        /** @var list<string> */
        public array $recorded = [];

        public function record(string $owner, string $id, string $hook, Throwable $failure): void
        {
            $this->recorded[] = "{$owner} {$id}: {$failure->getMessage()}";
        }
    };
    app()->instance(ListenerFaults::class, $faults);
    NoteCard::$fails = true;

    try {
        $this->actingAs($this->editor)->getJson("/admin/content/{$region->id}/card-choices/cardbox:note/pick")->assertOk()->assertExactJson(['choices' => []]);
    } finally {
        NoteCard::$fails = false;
    }

    expect($faults->recorded)->toBe(['tests/cardbox tests/cardbox#card: The picks are gone.']);
});

it('sends the editor the plugin’s cards with their fields, a choice as a choice', function (): void {
    ($this->enable)();
    $region = regionCreate($this->editor, 'open', 'sidebar');

    $this->actingAs($this->editor)->get("/admin/content/{$region->id}/edit")->assertInertia(fn ($page) => $page
        ->where('manifest.pluginCards.0.key', 'cardbox:note')
        ->where('manifest.pluginCards.0.plugin', 'Cardbox')
        ->where('manifest.pluginCards.0.fields.0.key', 'body')
        ->where('manifest.pluginCards.0.fields.1.type', 'choice')
        ->where('themeRegion.size', '300x600'));
});
