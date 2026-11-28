<?php

/**
 * Tests the rest of a plugin script's serving rules: a disabled plugin's script, a link on its path, an
 * encoded slash, the cache headers, the theme's switch, the budget fault, the insertion output, and the
 * refusals a manifest's scripts get at enable.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use App\Domain\Hooks\ListenerFaults;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Scripts\DeclaredScripts;
use App\Domain\Plugins\Scripts\PluginScriptFile;
use App\Domain\Plugins\Scripts\PluginScripts;
use App\Domain\Plugins\Styles\PluginStyleUsage;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Domain\Themes\ManifestValidator;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    $this->editor = User::factory()->create()->assignRole('editor');

    $this->restart = function (): void {
        app()->instance(LoadedPlugins::class, new LoadedPlugins);
        app(PluginLoader::class)->load();
    };

    // The cardbox fixture, enabled from a copy with a script for its note card and the given files.
    $this->enable = function (array $files = [], array $scripts = [['output' => 'card:note', 'file' => 'dist/note.js']], string $theme = 'open'): string {
        config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/'.$theme)]]);
        themeActivate($theme);

        $cardbox = json_decode((string) file_get_contents(PluginFixtures::path('cardbox').'/teme-plugin.json'), true, flags: JSON_THROW_ON_ERROR);
        $copy = PluginFixtures::copy('cardbox', [
            'capabilities' => ['pages.insert', 'site.scripts'],
            'contributes' => [...$cardbox['contributes'], 'scripts' => $scripts],
        ], [], $files + ['dist/note.js' => 'document.documentElement.dataset.note = "1";']);

        PluginFixtures::use($copy);
        $manifest = app(PluginRegistry::class)->all()['tests/cardbox']->manifest;
        app(EnablePlugin::class)->execute(new EnablePluginInput('tests/cardbox', $manifest->version(), $manifest->capabilities(), $manifest->hosts(), [], null));
        ($this->restart)();

        return $copy;
    };

    // A fault recorder that keeps what it was told, as the styles route test does.
    $this->faults = function (): object {
        $faults = new class implements ListenerFaults
        {
            /** @var list<array{0: string, 1: string}> */
            public array $recorded = [];

            public function record(string $owner, string $id, string $hook, Throwable $failure): void
            {
                $this->recorded[] = [$owner, $id];
            }
        };
        app()->instance(ListenerFaults::class, $faults);

        return $faults;
    };

    // A card drawn on a public page, so its plugin's outputs are recorded.
    $this->drawCard = function (string $theme = 'open'): void {
        $region = regionCreate($this->editor, $theme, 'sidebar');
        regionSave($region, $this->editor, [['type' => 'plugin-card', 'attrs' => ['id' => (string) Str::ulid(), 'card' => 'cardbox:note', 'values' => ['body' => 'Hi']]]]);
        listsPublish($region, $this->editor);
        pageArticle($this->editor, 'A story');
    };
});

afterEach(fn () => PluginFixtures::forget());

it('answers 404 for a declared script whose plugin is disabled', function (): void {
    ($this->enable)();
    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/cardbox', null));
    ($this->restart)();

    $this->get('/_teme/plugin-scripts/cardbox/dist/note.js')->assertNotFound();
});

it('answers 404 and records one fault for a script that is a link, however often it is asked for', function (): void {
    $copy = ($this->enable)(['dist/outside.js' => 'x']);
    // A link on the script's own path: the declared file is a symbolic link to a file outside the package.
    File::delete($copy.'/dist/note.js');
    symlink($copy.'/dist/outside.js', $copy.'/dist/note.js');
    ($this->restart)();
    $faults = ($this->faults)();

    $this->get('/_teme/plugin-scripts/cardbox/dist/note.js')->assertNotFound();
    $this->get('/_teme/plugin-scripts/cardbox/dist/note.js')->assertNotFound();

    expect($faults->recorded)->toBe([['tests/cardbox', 'cardbox#scripts']]);
});

it('answers 404 for an encoded slash in the script’s address', function (): void {
    ($this->enable)();

    $this->get('/_teme/plugin-scripts/cardbox/dist%2Fnote.js')->assertNotFound();
});

it('sends a script with its hash as the version and long cache headers, and nosniff', function (): void {
    ($this->enable)();

    $response = $this->get('/_teme/plugin-scripts/cardbox/dist/note.js')->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('max-age=31536000')->toContain('immutable')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Type'))->toStartWith('text/javascript');
});

it('links a script on the insertion output it declares', function (): void {
    ($this->enable)(scripts: [['output' => 'insertion:top', 'file' => 'dist/note.js']]);
    app(PluginStyleUsage::class)->record('cardbox', 'insertion:top');

    expect(app(PluginScripts::class)->links())->toHaveCount(1)
        ->and(app(PluginScripts::class)->links()[0]['key'])->toBe('cardbox:insertion:top');
});

it('switches a plugin’s script off under a theme that names it, while the card itself is drawn', function (): void {
    ($this->enable)(theme: 'styles-off');
    ($this->drawCard)('styles-off');

    // The card itself is drawn, so the missing link is the theme's switch, not a card that never drew.
    addrGet('/a-story/')->assertOk()
        ->assertSee('data-test="note"', false)
        ->assertDontSee('data-teme-plugin-script');
});

it('records one budget fault for a plugin whose scripts pass 20 KB on a page, however many pages it is on', function (): void {
    // Random text compresses poorly, so this file is over the budget once compressed.
    ($this->enable)(['dist/note.js' => base64_encode(random_bytes(60 * 1024))]);
    ($this->drawCard)();
    $faults = ($this->faults)();

    addrGet('/a-story/')->assertOk();
    addrGet('/a-story/')->assertOk();

    expect(collect($faults->recorded)->filter(fn (array $fault): bool => $fault[1] === 'scripts#budget'))->toHaveCount(1);
});

it('refuses at enable the scripts a manifest gets wrong', function (array $scripts, string $why): void {
    $errors = DeclaredScripts::errors(['scripts' => $scripts, 'cards' => [['id' => 'note']]], ['site.scripts']);

    expect($errors)->not->toBe([], $why);
})->with([
    'a duplicate output' => [[['output' => 'card:note', 'file' => 'a.js'], ['output' => 'card:note', 'file' => 'b.js']], 'one script per output'],
    'an undeclared card' => [[['output' => 'card:ghost', 'file' => 'a.js']], 'the card must be declared'],
    'a file that is not JavaScript' => [[['output' => 'card:note', 'file' => 'a.css']], 'only .js'],
    'a parent segment' => [[['output' => 'card:note', 'file' => '../a.js']], 'no ..'],
    'more than forty entries' => [array_map(fn (int $i): array => ['output' => 'insertion:i'.$i, 'file' => 'a.js'], range(1, 41)), 'at most 40'],
]);

it('refuses scripts that are not a list, and scripts without the capability', function (): void {
    expect(DeclaredScripts::errors(['scripts' => ['output' => 'card:note']], ['site.scripts']))->not->toBe([])
        ->and(DeclaredScripts::errors(['scripts' => [['output' => 'card:note', 'file' => 'a.js']]], ['pages.insert']))->not->toBe([])
        ->and(DeclaredScripts::errors(['scripts' => [['output' => 'card:note', 'file' => 'a.js']], 'cards' => [['id' => 'note']]], ['site.scripts']))->toBe([]);
});

it('refuses a theme’s plugin_scripts.off that does not name each script as code:card or code:insertion', function (): void {
    $errors = app(ManifestValidator::class)->errors(['plugin_scripts' => ['off' => ['bad key']]], base_path('tests/Fixtures/themes/open'));

    expect($errors)->toContain('plugin_scripts.off must name each script as code:card:<id> or code:insertion:<id>.')
        ->and(app(ManifestValidator::class)->errors(['plugin_scripts' => ['off' => 'cardbox:card:note']], base_path('tests/Fixtures/themes/open')))->toContain('plugin_scripts.off must list the plugin scripts the theme switches off.');
});

it('says in plain words, shared with the stylesheets, why a plugin file is refused', function (): void {
    $copy = ($this->enable)(['dist/outside.js' => 'x']);
    File::delete($copy.'/dist/note.js');
    symlink($copy.'/dist/outside.js', $copy.'/dist/note.js');
    ($this->restart)();

    expect(app(PluginScriptFile::class)->verdict('cardbox', 'dist/note.js')['refused'])->toBe('A plugin file may not be a link.');
});

it('gives a changed script a new hash in its address, once its plugin has a new version', function (): void {
    ($this->enable)(['dist/note.js' => 'a = 1;']);
    $before = app(PluginScriptFile::class)->verdict('cardbox', 'dist/note.js')['hash'];

    $cardbox = json_decode((string) file_get_contents(PluginFixtures::path('cardbox').'/teme-plugin.json'), true, flags: JSON_THROW_ON_ERROR);
    PluginFixtures::use(PluginFixtures::copy('cardbox', [
        'version' => '1.0.1',
        'capabilities' => ['pages.insert', 'site.scripts'],
        'contributes' => [...$cardbox['contributes'], 'scripts' => [['output' => 'card:note', 'file' => 'dist/note.js']]],
    ], [], ['dist/note.js' => 'a = 2;']));
    ($this->restart)();

    expect(app(PluginScriptFile::class)->verdict('cardbox', 'dist/note.js')['hash'])->not->toBe($before)
        ->and($before)->toBe(substr(hash('sha256', 'a = 1;'), 0, 12));
});

it('answers 404 for a declared script whose owner never agreed to site.scripts', function (): void {
    ($this->enable)();
    $record = PluginRecord::query()->where('name', 'tests/cardbox')->firstOrFail();
    $consent = (array) $record->consent;
    $consent['capabilities'] = array_values(array_diff((array) $consent['capabilities'], ['site.scripts']));
    $record->forceFill(['consent' => $consent])->save();
    app(PluginRecords::class)->changed();
    ($this->restart)();

    $this->get('/_teme/plugin-scripts/cardbox/dist/note.js')->assertNotFound();
    expect(app(PluginScriptFile::class)->verdict('cardbox', 'dist/note.js'))->toBeNull();
});
