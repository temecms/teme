<?php

/**
 * Tests that core serves a plugin's declared stylesheet at its route, wrapped in the
 * teme.plugins layer, and answers 404 for anything else: an undeclared file, a plugin
 * not enabled, a link on the path, an import, or braces that don't balance.
 *
 * Each test works on its own copy of the fixture, so no shared fixture file changes.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use App\Domain\Hooks\ListenerFaults;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Styles\PluginStylesheet;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(Elevation::class, new OperatorElevation);
    $this->restart = function (): void {
        app()->instance(LoadedPlugins::class, new LoadedPlugins);
        app(PluginLoader::class)->load();
    };
    $this->enable = function (string $copy): void {
        PluginFixtures::use($copy);
        $manifest = app(PluginRegistry::class)->all()['tests/cardbox']->manifest;
        app(EnablePlugin::class)->execute(new EnablePluginInput('tests/cardbox', $manifest->version(), $manifest->capabilities(), $manifest->hosts(), [], null));
        ($this->restart)();
    };
});

afterEach(fn () => PluginFixtures::forget());

it('serves a declared sheet wrapped in the teme.plugins layer, as text/css with long cache headers', function (): void {
    ($this->enable)(PluginFixtures::copy('cardbox'));

    $response = $this->get('/_teme/plugins/cardbox/dist/note.css')->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('text/css')
        ->and($response->headers->get('Cache-Control'))->toContain('immutable')
        ->and($response->getContent())->toContain('@layer teme.plugins {')
        ->and($response->getContent())->toContain('.card-plugin-cardbox-note');
});

it('answers 404 for a plugin that is not enabled', function (): void {
    PluginFixtures::use(PluginFixtures::copy('cardbox'));
    ($this->restart)();

    $this->get('/_teme/plugins/cardbox/dist/note.css')->assertNotFound();
});

it('answers 404 once the plugin is disabled', function (): void {
    ($this->enable)(PluginFixtures::copy('cardbox'));
    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/cardbox', null));
    ($this->restart)();

    $this->get('/_teme/plugins/cardbox/dist/note.css')->assertNotFound();
});

it('answers 404 for a file the manifest does not declare', function (): void {
    ($this->enable)(PluginFixtures::copy('cardbox', [], [], ['dist/secret.css' => '.a{color:red}']));

    $this->get('/_teme/plugins/cardbox/dist/secret.css')->assertNotFound();
});

it('answers 404 for a declared file that is a symbolic link', function (): void {
    $copy = PluginFixtures::copy('cardbox');
    $outside = sys_get_temp_dir().'/teme-sheet-'.uniqid().'.css';
    file_put_contents($outside, '.a{color:red}');
    unlink($copy.'/dist/note.css');
    symlink($outside, $copy.'/dist/note.css');
    ($this->enable)($copy);

    $this->get('/_teme/plugins/cardbox/dist/note.css')->assertNotFound();

    @unlink($outside);
});

it('answers 404 for a declared file behind a folder that is a symbolic link', function (): void {
    $copy = PluginFixtures::copy('cardbox');
    $outside = sys_get_temp_dir().'/teme-folder-'.uniqid();
    File::ensureDirectoryExists($outside);
    file_put_contents($outside.'/note.css', '.a{color:red}');
    rename($copy.'/dist', $copy.'/dist-kept');
    symlink($outside, $copy.'/dist');
    ($this->enable)($copy);

    $this->get('/_teme/plugins/cardbox/dist/note.css')->assertNotFound();

    File::deleteDirectory($outside);
});

it('answers 404 for a sheet that imports another, or whose braces do not balance', function (string $css): void {
    ($this->enable)(PluginFixtures::copy('cardbox', [], [], ['dist/note.css' => $css]));

    $this->get('/_teme/plugins/cardbox/dist/note.css')->assertNotFound();
})->with([
    'an import' => ['@import url("other.css");.a{color:red}'],
    'a missing closing brace' => ['.a{color:red'],
    'an extra closing brace' => ['.a{color:red}}'],
    'an import hidden after a comment' => ['/* hi */ @import url("x.css");'],
    'a namespace' => ['@namespace url(http://www.w3.org/1999/xhtml);.a{color:red}'],
    'a comment opener inside a string hides a brace' => ['a{content:"/*"} } .x{color:red; /* "*/ }'],
    'a brace inside an unquoted url' => ['a{b:url({)} } .x{}'],
    'an escaped brace' => ['a{} \\{ } .x{}'],
    'an import spelled with a hex escape' => ['@\\69 mport url("other.css");'],
    'a carriage return inside a string' => ["a{content:\"\r} } .x{color:red} b{content:\"}"],
    'a backslash and CRLF inside a string' => ["a{content:\"\\\r\n{\" } } .x{color:red} \"}"],
    'an escaped import followed by a carriage return' => ["@\\69\rmport url(\"other.css\");"],
    'an escaped import followed by a form feed' => ["@\\69\fmport url(\"other.css\");"],
    'a quoted hash url' => ['a{b:#url(x")") } } .x{color:red} "}'],
    'an escaped hash url' => ['a{b:#\\75 rl(x")") } } .x{color:red} "}'],
    'a NUL before url(' => ["a{b:\0url(x\")\") } } .x{color:red} \"}"],
    'a NUL between a hash and url(' => ["a{b:#\0url(x\")\") } } .x{color:red} \"}"],
    'a hex escape that takes the newline after it, inside a string' => ["a{content:\"\\41\n{\" } } .x{color:red} \"}}"],
    'a hex escape that takes a CRLF after it, inside a string' => ["a{content:\"\\41\r\n{\" } } .x{color:red} \"}}"],
    'a hex escape that takes a form feed after it, inside a string' => ["a{content:\"\\41\f{\" } } .x{color:red} \"}}"],
]);

it('answers 404 for a declared file that links to another file inside the package', function (): void {
    $copy = PluginFixtures::copy('cardbox');
    file_put_contents($copy.'/dist/real.css', '.a{color:red}');
    unlink($copy.'/dist/note.css');
    symlink('real.css', $copy.'/dist/note.css');
    ($this->enable)($copy);

    $this->get('/_teme/plugins/cardbox/dist/note.css')->assertNotFound();
});

it('answers 404 for a request whose file is not a plain path inside the package', function (string $path): void {
    ($this->enable)(PluginFixtures::copy('cardbox'));

    $this->get($path)->assertNotFound();
})->with([
    'a parent segment' => ['/_teme/plugins/cardbox/../../.env.css'],
    'an encoded parent segment' => ['/_teme/plugins/cardbox/%2e%2e/%2e%2e/.env.css'],
    'a backslash' => ['/_teme/plugins/cardbox/dist%5Cnote.css'],
    'a NUL byte' => ['/_teme/plugins/cardbox/dist/note.css%00'],
    'a leading slash' => ['/_teme/plugins/cardbox//dist/note.css'],
    'an encoded slash' => ['/_teme/plugins/cardbox/dist%2Fnote.css'],
]);

it('records one fault for a refused sheet, under its plugin’s Composer name, however often it is asked for', function (): void {
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
    ($this->enable)(PluginFixtures::copy('cardbox', [], [], ['dist/note.css' => '@import url("x.css");.a{color:red}']));

    $this->get('/_teme/plugins/cardbox/dist/note.css')->assertNotFound();
    $this->get('/_teme/plugins/cardbox/dist/note.css')->assertNotFound();

    expect($faults->recorded)->toBe([['tests/cardbox', 'cardbox#styles']]);
});

it('keeps a @charset inside the layer, so no rule can be moved above it', function (): void {
    expect(PluginStylesheet::wrap('@charset "utf-8";.a{color:red}'))->toStartWith('@layer teme.plugins {')
        ->and(PluginStylesheet::wrap("@charset \"\n; .x{color:red} \";"))->toStartWith('@layer teme.plugins {')
        ->and(PluginStylesheet::wrap('.a{color:red}@charset "utf-8";'))->not->toStartWith('@charset');
});

it('wraps a @charset inside the layer, and reads braces inside comments and strings as nothing', function (): void {
    expect(PluginStylesheet::wrap('@charset "utf-8";.a::before{content:"}";}'))
        ->toStartWith('@layer teme.plugins {')
        ->and(PluginStylesheet::wrap('/* } */.a{color:red}'))->toContain('@layer teme.plugins {');
});

it('changes the hash when the sheet changes', function (): void {
    $copy = PluginFixtures::copy('cardbox');
    ($this->enable)($copy);
    $first = app(PluginStylesheet::class)->hash('cardbox', 'dist/note.css');

    file_put_contents($copy.'/dist/note.css', '.b{color:blue}');
    ($this->restart)();

    expect($first)->toHaveLength(12)
        ->and(app(PluginStylesheet::class)->hash('cardbox', 'dist/note.css'))->not->toBe($first);
});
