<?php

declare(strict_types=1);

use App\Domain\Plugins\Editor\EditorPanels;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Preferences\Operations\Input\UpdatePreferencesInput;
use App\Domain\Preferences\Operations\UpdatePreferences;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Plugins\Linkfinder\Linkfinder;
use Tests\Fixtures\Plugins\PluginFixtures;

require_once __DIR__.'/Linkfinder.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    linkfinderEnabled();
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->author = User::factory()->create()->assignRole('author');
    $this->post = lifecycleDraft($this->author, 'Pruning roses');

    $this->tools = fn (User $person, string $id) => $this->actingAs($person)->get(route('content.edit', ['content' => $id]));
    // Saves this person's pins as the editor does, signed in as them.
    $this->pin = function (User $person, array $pins): mixed {
        $this->actingAs($person);

        return app(UpdatePreferences::class)->execute(new UpdatePreferencesInput(['editor.pinned_tools' => $pins], (string) $person->id));
    };
});

afterEach(function (): void {
    Linkfinder::reset();
    PluginFixtures::forget();
});

it('gives the editor the panel on a listed type, for a person who may update the item and holds its permission', function (): void {
    ($this->tools)($this->editor, $this->post->id)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('panels', 1)
        ->where('panels.0.tool', 'plugin.linkfinder.links')
        ->where('panels.0.code', 'linkfinder')
        ->where('panels.0.id', 'links')
        ->where('panels.0.plugin', 'Link Finder')
        ->where('panels.0.label', 'Link suggestions')
        ->where('panels.0.icon', 'link')
        ->where('panels.0.actions.0', ['id' => 'find', 'label' => 'Find links', 'confirm' => null, 'fields' => []])
        ->where('panels.0.actions.1.confirm', 'This reads every published article again, which takes a minute.')
        ->where('panels.0.actions.1.fields.1', [
            'key' => 'reach', 'type' => 'select', 'label' => 'How far back', 'required' => true, 'default' => 'year', 'maxLength' => null,
            'options' => [['value' => 'year', 'label' => 'The last year'], ['value' => 'all', 'label' => 'Everything']], 'min' => null, 'max' => null,
        ]));

    expect(Linkfinder::$asked)->toBe([]);
});

it('gives no panel on a type it doesn’t list', function (): void {
    $page = lifecycleDraft($this->editor, 'About us', 'page');

    ($this->tools)($this->editor, $page->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('panels', []));
});

it('gives no panel to a person who may update the item but lacks its permission', function (): void {
    ($this->tools)($this->author, $this->post->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('panels', []));
});

it('gives no panel to a person who may not update the item', function (): void {
    $other = User::factory()->create()->assignRole('author');
    $other->givePermissionTo('plugin.linkfinder.suggest');

    ($this->tools)($other, $this->post->id)->assertForbidden();

    expect(app(EditorPanels::class)->on($this->post, (string) $other->id))->toBe([]);
});

it('gives no panel when the plugin is disabled', function (): void {
    app()->instance(Elevation::class, new OperatorElevation);
    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/linkfinder', null));

    ($this->tools)($this->editor, $this->post->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('panels', []));
});

it('gives no panel when the plugin isn’t granted content.read and jobs', function (string $kept): void {
    PluginRecord::query()->where('name', 'tests/linkfinder')->update(['consent' => ['capabilities' => [$kept], 'hosts' => [], 'hooks' => []]]);
    app(PluginRecords::class)->changed();

    ($this->tools)($this->editor, $this->post->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('panels', []));
})->with(['only content.read' => 'content.read', 'only jobs' => 'jobs']);

it('lets a person who sees the panel pin it, and refuses it for one who doesn’t', function (): void {
    ($this->pin)($this->editor, ['assistant', 'plugin.linkfinder.links']);

    expect($this->editor->refresh()->preferences['editor.pinned_tools'] ?? null)->toBe(['assistant', 'plugin.linkfinder.links'])
        ->and(fn () => ($this->pin)($this->author, ['plugin.linkfinder.links']))->toThrow(ValidationException::class)
        ->and($this->author->refresh()->preferences)->toBe([]);
});
