<?php

declare(strict_types=1);

use App\Domain\Hooks\Reference\HookCatalogue;
use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\ManifestValidator;
use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Capability;
use Teme\Contracts\Plugins\Plugin;
use Tests\Fixtures\Plugins\PluginFixtures;

beforeEach(function (): void {
    config(['teme.version' => PluginFixtures::TEME]);
    $this->errors = fn (array $manifest, string $folder = 'hello', ?array $composer = null): array => app(ManifestValidator::class)->errors($manifest, PluginFixtures::path($folder), $composer);
});

it('publishes the Plugin interface with its @since, asking register() for Hooks alone', function (): void {
    $register = new ReflectionMethod(Plugin::class, 'register');

    expect((new ReflectionClass(Plugin::class))->getDocComment())->toContain('@since 0.5.0')
        ->and($register->getDocComment())->toContain('@since 0.5.0')
        ->and((string) $register->getParameters()[0]->getType())->toBe(Hooks::class)
        ->and((string) $register->getReturnType())->toBe('void');
});

it('accepts the hello fixture and finds nothing wrong', function (): void {
    expect(($this->errors)(PluginFixtures::manifest('hello'), 'hello', PluginFixtures::composer('hello')))->toBe([]);
});

it('refuses each broken manifest with its sentence', function (Closure $break, string $sentence, ?array $composer = null): void {
    $errors = ($this->errors)($break(PluginFixtures::manifest('hello')), 'hello', $composer);

    expect($errors)->toContain($sentence);
})->with([
    'manifest' => [fn (array $m): array => [...$m, 'manifest' => 2], 'The manifest must say "manifest": 1.'],
    'name' => [fn (array $m): array => [...$m, 'name' => 'tests/other'], 'The plugin’s name must be its Composer package’s name, "tests/hello".', ['name' => 'tests/hello']],
    'a package named outside the pattern, such as core' => [fn (array $m): array => [...$m, 'name' => 'core'], 'The manifest needs a name.', ['name' => 'core']],
    'code' => [fn (array $m): array => [...$m, 'code' => 'Hello!'], 'The plugin code must be 2 to 40 lower-case letters, digits and hyphens, starting with a letter or digit.'],
    'label' => [fn (array $m): array => [...$m, 'label' => ' '], 'The manifest needs a label.'],
    'version' => [fn (array $m): array => [...$m, 'version' => 'one'], 'version must be a version number, such as 1.2.0.'],
    'requires' => [fn (array $m): array => [...$m, 'requires' => []], 'The manifest must say which Teme versions the plugin works with, as requires.teme.'],
    'constraint' => [fn (array $m): array => [...$m, 'requires' => ['teme' => 'soon']], 'requires.teme "soon" is not a version constraint.'],
    'another plugin' => [fn (array $m): array => [...$m, 'requires' => ['teme' => '^0.5', 'acme/base' => '^1.0']], 'requires may name only teme and plugins.'],
    'plugin' => [fn (array $m): array => [...$m, 'plugin' => ''], 'plugin must name the plugin’s class.'],
    'class missing' => [fn (array $m): array => [...$m, 'plugin' => 'Tests\\Fixtures\\Plugins\\Hello\\Gone'], 'The class Tests\\Fixtures\\Plugins\\Hello\\Gone isn’t there.'],
    'hosts' => [fn (array $m): array => [...$m, 'capabilities' => ['http'], 'hosts' => ['10.0.0.1']], 'hosts must be a list of host names, such as api.example.com or *.example.com.'],
    'http without hosts' => [fn (array $m): array => [...$m, 'capabilities' => ['content.read', 'http']], 'It asks for http but names no hosts.'],
    'hosts without http' => [fn (array $m): array => [...$m, 'hosts' => ['api.example.com']], 'It names hosts but doesn’t ask for http.'],
    'owns not an object' => [fn (array $m): array => [...$m, 'owns' => []], 'owns must be an object whose only key is tables.'],
    'owns with another key' => [fn (array $m): array => [...$m, 'owns' => ['tables' => ['hello_links'], 'files' => []]], 'owns must be an object whose only key is tables.'],
    'owns.tables not a list' => [fn (array $m): array => [...$m, 'owns' => ['tables' => ['hello_links' => true]]], 'owns.tables must be a list of distinct table names.'],
    'owns.tables duplicate' => [fn (array $m): array => [...$m, 'owns' => ['tables' => ['hello_links', 'hello_links']]], 'owns.tables must be a list of distinct table names.'],
    'owns.tables pattern' => [fn (array $m): array => [...$m, 'owns' => ['tables' => ['Hello_links']]], 'owns.tables "Hello_links" must be a lower-case name of letters, digits and underscores, starting with a letter.'],
    'owns.tables prefix' => [fn (array $m): array => [...$m, 'owns' => ['tables' => ['other_links']]], 'owns.tables "other_links" must start with the plugin’s prefix "hello_".'],
    'owns.tables too long' => [fn (array $m): array => [...$m, 'owns' => ['tables' => ['hello_'.str_repeat('a', 58)]]], 'owns.tables "hello_'.str_repeat('a', 58).'" must be at most 63 characters.'],
]);

it('accepts a valid owns.tables list', function (): void {
    expect(($this->errors)(PluginFixtures::manifest('tables'), 'tables', PluginFixtures::composer('tables')))->toBe([]);
});

it('refuses a class that isn’t a plugin', function (): void {
    $manifest = [...PluginFixtures::manifest('greedy'), 'plugin' => 'Tests\\Fixtures\\Plugins\\Greedy\\NotAPlugin'];

    expect(($this->errors)($manifest, 'greedy'))->toContain('Tests\\Fixtures\\Plugins\\Greedy\\NotAPlugin doesn’t implement Teme\\Contracts\\Plugins\\Plugin.');
});

it('refuses a capability Teme doesn’t offer plugins yet, and one that doesn’t exist', function (): void {
    $errors = ($this->errors)([...PluginFixtures::manifest('hello'), 'capabilities' => ['content.read', 'mail.send', 'content.delete']]);

    expect($errors)->toContain('Teme doesn’t offer mail.send to plugins yet.')
        ->toContain('content.delete isn’t something a plugin can ask for.');
});

it('accepts content.write and media.write', function (): void {
    $errors = ($this->errors)([...PluginFixtures::manifest('hello'), 'capabilities' => ['content.read', 'content.write', 'media.write']]);

    expect($errors)->toBe([]);
});

it('refuses a hook that isn’t one, and one whose capability the plugin doesn’t ask for', function (): void {
    $errors = ($this->errors)([...PluginFixtures::manifest('hello'), 'capabilities' => [], 'hooks' => ['ContentPublished', 'PostSaved']]);

    expect($errors)->toContain('ContentPublished needs content.read, which it doesn’t ask for.')
        ->toContain('PostSaved isn’t a Teme hook.');
});

it('refuses a plugin class that asks for anything but what Teme offers plugins', function (): void {
    expect(($this->errors)(PluginFixtures::manifest('greedy'), 'greedy', PluginFixtures::composer('greedy')))
        ->toBe(['Tests\\Fixtures\\Plugins\\Greedy\\Greedy asks for Illuminate\\Contracts\\Container\\Container; a plugin may ask only for what Teme offers plugins.']);
});

it('refuses a plugin that asks for the outbound door without http', function (): void {
    $door = [...PluginFixtures::manifest('greedy'), 'plugin' => 'Tests\\Fixtures\\Plugins\\Greedy\\Door'];
    $granted = [...$door, 'capabilities' => ['http'], 'hosts' => ['api.example.com']];

    expect(($this->errors)($door, 'greedy'))->toBe(['Tests\\Fixtures\\Plugins\\Greedy\\Door asks for the outbound door, but the plugin doesn’t ask for http.'])
        ->and(($this->errors)($granted, 'greedy'))->toBe([]);
});

it('checks requires.teme against the Teme running now', function (): void {
    config(['teme.version' => '0.4.0']);

    expect(($this->errors)(PluginFixtures::manifest('hello')))->toBe(['The plugin requires Teme ^0.5, but this is Teme 0.4.0.']);
});

it('names every hook’s capability as one of the three', function (string $hook): void {
    $attribute = (new ReflectionClass($hook))->getAttributes(HookContract::class)[0] ?? null;
    $capability = $attribute?->newInstance()->capability;

    expect($capability === null || Capability::tryFrom($capability) !== null)->toBeTrue();
})->with(HookCatalogue::HOOKS);

it('refuses a class it can’t check without running code, with a plain reason', function (string $class, string $sentence): void {
    $manifest = [...PluginFixtures::manifest('greedy'), 'plugin' => 'Tests\\Fixtures\\Plugins\\Greedy\\'.$class];

    expect(($this->errors)($manifest, 'greedy'))->toBe([$sentence]);
})->with([
    'a parent outside its code' => ['Inherits', 'Tests\\Fixtures\\Plugins\\Greedy\\Inherits extends Acme\\Elsewhere\\BasePlugin, which isn’t in the plugin’s own code, so Teme can’t check it without running it.'],
    'an interface outside its code' => ['Foreign', 'Tests\\Fixtures\\Plugins\\Greedy\\Foreign implements Acme\\Elsewhere\\PluginLike, which isn’t in the plugin’s own code, so Teme can’t check it without running it.'],
    'a constructor from a trait' => ['Traited', 'Tests\\Fixtures\\Plugins\\Greedy\\Traited takes its constructor from Tests\\Fixtures\\Plugins\\Greedy\\BuildsItself, so Teme can’t check what it asks for without running it.'],
    'declared inside an if or a function' => ['Conditional', 'Tests\\Fixtures\\Plugins\\Greedy\\Conditional is declared inside an if or a function, so Teme can’t check it without running it.'],
]);

it('checks a plugin whose file would throw or exit when loaded, without loading it', function (string $class): void {
    $manifest = [...PluginFixtures::manifest('loud'), 'plugin' => 'Tests\\Fixtures\\Plugins\\Loud\\'.$class];
    $before = get_included_files();

    // What PHP included while checking, whichever test loaded what before in this process.
    expect(($this->errors)($manifest, 'loud', PluginFixtures::composer('loud')))->toBe([])
        ->and(array_filter(array_diff(get_included_files(), $before), fn (string $file): bool => str_contains($file, 'plugins/loud')))->toBe([]);
})->with(['Exits', 'Loud']);

it('refuses author_url and homepage that are not https or are longer than 2000 characters', function (string $key, mixed $value): void {
    $errors = ($this->errors)([...PluginFixtures::manifest('hello'), $key => $value]);

    expect($errors)->toContain("{$key} must be an https address of at most 2000 characters.");
})->with([
    'author_url http' => ['author_url', 'http://example.com'],
    'author_url not a url' => ['author_url', 'not-a-url'],
    'author_url too long' => ['author_url', 'https://example.com/'.str_repeat('a', 2000)],
    'author_url not a string' => ['author_url', 1],
    'homepage http' => ['homepage', 'http://example.com'],
    'homepage not a url' => ['homepage', 'ftp://example.com'],
    'homepage too long' => ['homepage', 'https://example.com/'.str_repeat('a', 2000)],
    'homepage not a string' => ['homepage', false],
]);

it('accepts https author_url and homepage, and leaves them out when absent', function (): void {
    $with = [...PluginFixtures::manifest('hello'), 'author_url' => 'https://example.com/author', 'homepage' => 'https://example.com'];
    $without = PluginFixtures::manifest('hello');

    expect(($this->errors)($with))->toBe([])
        ->and(($this->errors)($without))->toBe([]);
});

it('reads authorUrl from the manifest', function (): void {
    $with = new Manifest([...PluginFixtures::manifest('hello'), 'author_url' => 'https://example.com/author']);
    $without = new Manifest(PluginFixtures::manifest('hello'));

    expect($with->authorUrl())->toBe('https://example.com/author')
        ->and($without->authorUrl())->toBe('');
});

it('takes Teme’s own pre-releases in a range that names them, and says why ^0.5 stops at 0.6', function (string $range, string $teme, ?string $sentence): void {
    config(['teme.version' => $teme]);
    $errors = ($this->errors)([...PluginFixtures::manifest('hello'), 'requires' => ['teme' => $range]]);

    expect($errors)->toBe($sentence === null ? [] : [$sentence]);
})->with([
    'a pre-release, in a -dev range' => ['>=0.5.0-dev <1.0', '0.5.0-alpha.1', null],
    'the release, in a -dev range' => ['>=0.5.0-dev <1.0', '0.5.0', null],
    'a later release, in a -dev range' => ['>=0.5.0-dev <1.0', '0.9.2', null],
    'an untagged build today, outside it' => ['>=0.5.0-dev <1.0', '0.1.0', 'The plugin requires Teme >=0.5.0-dev <1.0, but this is Teme 0.1.0.'],
    'the next minor’s pre-release, in a -dev range' => ['>=0.5.0-dev <1.0', '0.6.0-alpha.1', null],
    'this minor’s pre-release, with ^0.5' => ['^0.5', '0.5.0-alpha.1', null],
    'the next minor’s pre-release, with ^0.5' => ['^0.5', '0.6.0-alpha.1', 'The plugin requires Teme ^0.5, but this is Teme 0.6.0-alpha.1.'],
]);

it('refuses a route handler and a custom verifier whose classes aren’t in the plugin, naming the manifest key', function (): void {
    $manifest = json_decode((string) file_get_contents(PluginFixtures::path('inbound').'/teme-plugin.json'), true);
    $manifest['routes'][0]['handler'] = 'Tests\\Fixtures\\Plugins\\Inbound\\Nowhere';
    $manifest['routes'][2]['verify'] = ['kind' => 'custom', 'class' => 'Tests\\Fixtures\\Plugins\\Inbound\\NoVerifier'];

    $errors = ($this->errors)($manifest, 'inbound');

    expect($errors)->toContain('routes.0.handler names Tests\\Fixtures\\Plugins\\Inbound\\Nowhere, which isn’t in the plugin’s code.')
        ->toContain('routes.2.verify.class names Tests\\Fixtures\\Plugins\\Inbound\\NoVerifier, which isn’t in the plugin’s code.');
});
