<?php

declare(strict_types=1);

use App\Domain\Cache\PageTags;
use App\Domain\Cache\PageWarmer;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Api\Switches;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    PluginFixtures::use('inserts');
    app()->instance(Elevation::class, new OperatorElevation);

    $manifest = app(PluginRegistry::class)->all()['tests/inserts']->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput(
        'tests/inserts',
        $manifest->version(),
        $manifest->capabilities(),
        $manifest->hosts(),
        $manifest->hooks(),
        null,
    ));
    app(PluginLoader::class)->load();

    $this->author = User::factory()->create()->assignRole('author');
    $this->folder = cacheOn();
    $this->post = pageArticleFromDocument($this->author, 'Insertion story', [
        docText('Paragraph one.'),
        docText('Paragraph two.'),
        docText('Paragraph three.'),
    ]);
});

afterEach(function (): void {
    PluginFixtures::forget();
    if (isset($this->folder)) {
        cacheOff($this->folder);
    }
});

it('draws the fixture plugin\'s insertion after paragraph 2 on a published post', function (): void {
    $html = (string) addrGet('/insertion-story/')->assertOk()->getContent();

    expect($html)->toContain('<div data-test="insertion">Inserted</div>')
        ->and((int) strpos($html, 'Paragraph two.'))->toBeLessThan((int) strpos($html, 'data-test="insertion"'))
        ->and((int) strpos($html, 'data-test="insertion"'))->toBeLessThan((int) strpos($html, 'Paragraph three.'));
});

it('does not draw insertions on a draft preview, the feed, the .md version or the API', function (): void {
    Switches::llmsOn();
    Switches::apiOn();

    $draft = lifecycleDraft($this->author, 'Draft only');
    app(SaveDraft::class)->execute(new SaveDraftInput(
        $draft->id,
        'Draft only',
        ['type' => 'doc', 'content' => [docText('One.'), docText('Two.'), docText('Three.')]],
        [],
        $draft->working_revision_id,
        (string) $this->author->id,
        [],
    ));
    $preview = histPreview($draft->refresh(), $this->author);

    expect((string) $this->get("/preview/{$preview->token}")->assertOk()->getContent())
        ->not->toContain('data-test="insertion"')
        ->and((string) addrGet('/feed/')->assertOk()->getContent())->not->toContain('data-test="insertion"')
        ->and((string) addrGet('/insertion-story.md')->assertOk()->getContent())->not->toContain('data-test="insertion"')
        ->and(json_encode($this->getJson('/api/v1/items/'.$this->post->id)->assertOk()->json()))
        ->not->toContain('data-test="insertion"');
});

it('draws nothing when insertions_off is on', function (): void {
    app(SaveDraft::class)->execute(new SaveDraftInput(
        $this->post->id,
        'Insertion story',
        ['type' => 'doc', 'content' => [docText('Paragraph one.'), docText('Paragraph two.'), docText('Paragraph three.')]],
        [BuiltInFields::INSERTIONS_OFF => true],
        $this->post->working_revision_id,
        (string) $this->author->id,
        [],
    ));
    listsPublish($this->post->refresh(), $this->author);

    expect((string) addrGet('/insertion-story/')->assertOk()->getContent())
        ->not->toContain('data-test="insertion"');
});

it('records the insertions tag on the page when anything was drawn', function (): void {
    addrGet('/insertion-story/')->assertOk();

    expect(cacheEntry('/insertion-story/')?->tags)->toContain(PageTags::INSERTIONS);
});

it('draws the fixture plugin\'s insertion on a page warmed through PageWarmer, which starts a fresh scope', function (): void {
    // Core's theme accepts the fixtures' Teme version, which the fresh scope reads again; the good theme asks for ^0.1.
    config(['teme.theme_paths' => []]);
    themeActivate('default');

    expect(app(PageWarmer::class)->warm('http://localhost/insertion-story/'))->toBe('MISS')
        ->and(cacheEntry('/insertion-story/')?->body)->toContain('<div data-test="insertion">Inserted</div>');
});

it('purges insertions pages after a plugin\'s settings are saved, and not when the save is refused', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    addrGet('/insertion-story/')->assertOk();
    expect(cacheEntry('/insertion-story/'))->not->toBeNull();

    expect(fn () => app(UpdateSettings::class)->execute(new UpdateSettingsInput('plugin.inserts', ['label' => 'Ad'], [], [], 0, (string) $this->author->id)))
        ->toThrow(AuthorizationException::class)
        ->and(cacheEntry('/insertion-story/'))->not->toBeNull();

    app(UpdateSettings::class)->execute(new UpdateSettingsInput('plugin.inserts', ['label' => 'Ad'], [], [], 0, (string) $admin->id));

    expect(cacheEntry('/insertion-story/'))->toBeNull();
});
