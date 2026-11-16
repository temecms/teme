<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionAction;
use App\Domain\Extensions\ExtensionKind;
use App\Domain\Extensions\Installer\ComposerRunner;
use App\Domain\Extensions\Installer\StageValidator;
use App\Domain\Extensions\Sources\ExtensionRequest;
use Tests\Fixtures\Extensions\ExtensionsFolder;

beforeEach(function (): void {
    config(['teme.version' => '0.5.0']);
    $this->folder = ExtensionsFolder::make();
    $this->imageCodes = ['plugins' => [], 'themes' => []];

    $composer = new class((string) config('teme.extensions.composer'), $this->folder->root) extends ComposerRunner
    {
        /** @var array<string, list<array<string, mixed>>>|null */
        public ?array $found = [];

        public function audit(string $release): ?array
        {
            return $this->found;
        }
    };
    $this->composer = $composer;

    $this->validator = fn (): StageValidator => app()->make(StageValidator::class, [
        'composer' => $this->composer,
        'imageVendor' => base_path('vendor'),
        'imageCodes' => fn (): array => $this->imageCodes,
    ]);

    /** Stages a release on the current one, with this package required, and returns its folder. */
    $this->staged = function (string $package, string $constraint = '1.0.0', array $extra = []): string {
        $id = $this->folder->stage($this->folder->store->current());
        $release = $this->folder->store->path($id);
        $result = $this->composer->run(['require', "{$package}:{$constraint}", '--update-with-dependencies', '--update-no-dev', ...$extra], $release);

        expect($result->ok())->toBeTrue($result->output);

        return $release;
    };

    $this->request = fn (string $package, ExtensionKind $kind = ExtensionKind::Plugin, string $source = 'packagist'): ExtensionRequest => new ExtensionRequest(
        ...[...get_object_vars(ExtensionsFolder::request(ExtensionAction::Install, $package, '1.0.0', $kind)), 'sourceKind' => $source],
    );

    $this->validate = fn (ExtensionRequest $request, string $release, ?string $previous = null) => ($this->validator)()->validate($request, $release, ExtensionsFolder::pins(), $previous);
});

afterEach(function (): void {
    $this->folder->delete();
});

it('takes a valid plugin with nothing to agree to', function (): void {
    $outcome = ($this->validate)(($this->request)('fixtures/hello-extension'), ($this->staged)('fixtures/hello-extension'));

    expect($outcome->refusals)->toBe([])
        ->and($outcome->needs)->toBe([])
        ->and($outcome->warnings)->toBe([]);
})->group('composer');

it('verifies a release whose lock, composer.json and vendor agree', function (): void {
    expect(($this->validator)()->verify(($this->staged)('fixtures/hello-extension')))->toBe([]);
})->group('composer');

it('refuses a release whose composer.json changed after the lock', function (): void {
    $release = ($this->staged)('fixtures/hello-extension');
    $project = json_decode((string) file_get_contents($release.'/composer.json'), true);
    $project['require']['fixtures/hello-theme'] = '1.0.0';
    file_put_contents($release.'/composer.json', json_encode($project));

    expect(implode(' ', ($this->validator)()->verify($release)))->toContain('The lock doesn’t match composer.json');
})->group('composer');

it('refuses a release where what was installed isn’t what was locked', function (): void {
    $release = ($this->staged)('fixtures/hello-extension');
    $installed = json_decode((string) file_get_contents($release.'/vendor/composer/installed.json'), true);
    $installed['packages'][0]['dist']['reference'] = 'something-else';
    file_put_contents($release.'/vendor/composer/installed.json', json_encode($installed));

    expect(($this->validator)()->verify($release))->toContain('What Composer installed isn’t exactly what it locked.');
})->group('composer');

it('refuses a package of the wrong type', function (): void {
    $outcome = ($this->validate)(($this->request)('fixtures/wrong-type'), ($this->staged)('fixtures/wrong-type'));

    expect(implode(' ', $outcome->refusals))->toContain('fixtures/wrong-type isn’t a plugin: its type is library, not teme-plugin.');
})->group('composer');

it('refuses a theme request for a plugin', function (): void {
    $outcome = ($this->validate)(($this->request)('fixtures/hello-extension', ExtensionKind::Theme), ($this->staged)('fixtures/hello-extension'));

    expect(implode(' ', $outcome->refusals))->toContain('isn’t a theme');
})->group('composer');

it('refuses a package that is part of Teme’s build', function (): void {
    $outcome = ($this->validate)(($this->request)('laravel/framework'), ($this->staged)('fixtures/hello-extension'));

    expect(implode(' ', $outcome->refusals))->toContain('laravel/framework is part of Teme’s build');
})->group('composer');

it('refuses a plugin whose Teme range leaves this Teme out', function (): void {
    config(['teme.version' => '0.4.0']);

    $outcome = ($this->validate)(($this->request)('fixtures/hello-extension'), ($this->staged)('fixtures/hello-extension'));

    expect(implode(' ', $outcome->refusals))->toContain('requires Teme ^0.5, but this is Teme 0.4.0');
})->group('composer');

it('refuses a plugin whose code another plugin uses, in the release or the image', function (): void {
    $this->folder->install('fixtures/hello-extension', '1.0.0');
    $outcome = ($this->validate)(($this->request)('fixtures/duplicate-code'), ($this->staged)('fixtures/duplicate-code'));

    expect(implode(' ', $outcome->refusals))->toContain('The plugin code "hello-extension" is already used by another plugin');

    $this->folder->delete();
    $this->folder = ExtensionsFolder::make();
    $this->imageCodes = ['plugins' => ['hello-extension'], 'themes' => []];

    $outcome = ($this->validate)(($this->request)('fixtures/hello-extension'), ($this->staged)('fixtures/hello-extension'));

    expect(implode(' ', $outcome->refusals))->toContain('The plugin code "hello-extension" is already used');
})->group('composer');

it('refuses a plugin that declares autoload.files itself', function (): void {
    $own = $this->folder->root.'/own-files';
    mkdir($own.'/src', 0755, true);
    copy(ExtensionsFolder::PACKAGES.'/hello-extension/src/HelloExtension.php', $own.'/src/HelloExtension.php');
    file_put_contents($own.'/boot.php', "<?php\n");
    file_put_contents($own.'/teme-plugin.json', str_replace('fixtures/hello-extension', 'fixtures/own-files', (string) file_get_contents(ExtensionsFolder::PACKAGES.'/hello-extension/teme-plugin.json')));
    file_put_contents($own.'/composer.json', json_encode([
        'name' => 'fixtures/own-files', 'type' => 'teme-plugin', 'version' => '1.0.0', 'require' => ['teme/contracts' => '*'],
        'autoload' => ['psr-4' => ['Fixtures\\HelloExtension\\' => 'src/'], 'files' => ['boot.php']],
    ]));

    $release = $this->folder->store->path($this->folder->stage(repositories: [['type' => 'path', 'url' => $own, 'options' => ['symlink' => false]]]));
    $result = $this->composer->run(['require', 'fixtures/own-files:1.0.0'], $release);
    expect($result->ok())->toBeTrue($result->output);

    $outcome = ($this->validate)(($this->request)('fixtures/own-files'), $release);

    expect(implode(' ', $outcome->refusals))->toContain('fixtures/own-files declares autoload.files');
})->group('composer');

it('refuses a release that declares a namespace of Teme’s own', function (): void {
    $outcome = ($this->validate)(($this->request)('fixtures/autoloads-app'), ($this->staged)('fixtures/autoloads-app'));

    expect(implode(' ', $outcome->refusals))->toContain('App\\Extra\\')
        ->and(implode(' ', $outcome->refusals))->toContain('core’s code always wins');
})->group('composer');

it('refuses a release this server’s PHP can’t run', function (): void {
    $release = ($this->staged)('fixtures/needs-ext-nonexistent', '1.0.0', ['--ignore-platform-reqs']);

    $outcome = ($this->validate)(($this->request)('fixtures/needs-ext-nonexistent'), $release);

    expect(implode(' ', $outcome->refusals))->toContain('ext-nonexistent');
})->group('composer');

it('refuses a package with a known security advisory, and goes on when the database can’t be reached', function (): void {
    $release = ($this->staged)('fixtures/hello-extension');
    $this->composer->found = ['fixtures/hello-extension' => [['title' => 'Remote code execution', 'advisoryId' => 'PKSA-test']]];

    expect(implode(' ', ($this->validate)(($this->request)('fixtures/hello-extension'), $release)->refusals))
        ->toContain('fixtures/hello-extension has a known security advisory: Remote code execution.');

    $this->composer->found = null;
    $outcome = ($this->validate)(($this->request)('fixtures/hello-extension'), $release);

    expect($outcome->refusals)->toBe([])
        ->and($outcome->warnings)->toBe(['Teme couldn’t reach the security advisory database, so the packages weren’t checked against it.']);
})->group('composer');

it('waits for agreement to a dependency whose files run on every request', function (): void {
    $outcome = ($this->validate)(($this->request)('fixtures/files-dependency'), ($this->staged)('fixtures/files-dependency'));

    expect($outcome->refusals)->toBe([])
        ->and($outcome->needs)->toBe(['files' => ['fixtures/with-files' => ['helpers.php']]])
        ->and($outcome->coveredBy([]))->toBeFalse()
        ->and($outcome->coveredBy(['files' => ['fixtures/with-files' => ['helpers.php']]]))->toBeTrue();
})->group('composer');

it('waits for agreement to a Composer plugin that won’t run', function (): void {
    $outcome = ($this->validate)(($this->request)('fixtures/composer-plugin-dependency'), ($this->staged)('fixtures/composer-plugin-dependency'));

    expect($outcome->refusals)->toBe([])
        ->and($outcome->needs)->toBe(['composer_plugins' => ['fixtures/marker-plugin']]);
})->group('composer');

it('waits for agreement when a package comes from another source, or an upload replaces the version installed', function (): void {
    $first = $this->folder->install('fixtures/hello-extension', '1.0.0');
    $previous = $this->folder->store->path($first);
    $project = json_decode((string) file_get_contents($previous.'/composer.json'), true);
    $project['extra']['teme']['sources'] = ['fixtures/hello-extension' => 'git'];
    file_put_contents($previous.'/composer.json', json_encode($project));

    $release = ($this->staged)('fixtures/hello-extension', '1.1.0');

    expect(($this->validate)(($this->request)('fixtures/hello-extension'), $release, $previous)->needs)
        ->toBe(['source' => ['package' => 'fixtures/hello-extension', 'from' => 'git', 'to' => 'packagist']])
        ->and(($this->validate)(($this->request)('fixtures/hello-extension', source: 'upload'), $release, $previous)->needs)
        ->toBe([
            'source' => ['package' => 'fixtures/hello-extension', 'from' => 'git', 'to' => 'upload'],
            'replace' => ['package' => 'fixtures/hello-extension', 'from' => '1.0.0', 'to' => '1.1.0'],
        ]);
})->group('composer');

it('refuses a package holding a link that points outside it, and takes one that stays inside', function (): void {
    $release = ($this->staged)('fixtures/hello-theme');
    $public = "{$release}/vendor/fixtures/hello-theme/public";
    symlink('../teme-theme.json', "{$public}/manifest.json");

    expect(($this->validate)(($this->request)('fixtures/hello-theme', ExtensionKind::Theme), $release)->refusals)->toBe([]);

    symlink(base_path('.env'), "{$public}/build/settings.txt");
    symlink('../../../../../../outside', "{$public}/up");

    $refusals = ($this->validate)(($this->request)('fixtures/hello-theme', ExtensionKind::Theme), $release)->refusals;

    expect($refusals)->toHaveCount(2)
        ->and($refusals[0])->toContain('fixtures/hello-theme')->toContain('public/build/settings.txt')
        ->and($refusals[1])->toContain('public/up');
})->group('composer');

it('asks again only about packages the release that runs doesn’t already hold', function (): void {
    $this->folder->install('fixtures/files-dependency', '1.0.0');
    $previous = $this->folder->store->path($this->folder->store->current());

    expect(($this->validate)(($this->request)('fixtures/hello-extension'), ($this->staged)('fixtures/hello-extension'), $previous)->needs)->toBe([]);
})->group('composer');

it('asks again about a package the running release holds at the same reference but another version', function (): void {
    $lock = fn (string $version): array => ['packages' => [[
        'name' => 'fixtures/with-files', 'version' => $version, 'type' => 'library',
        'dist' => ['type' => 'path', 'url' => 'packages/with-files', 'reference' => 'same-reference'],
        'autoload' => ['files' => ['helpers.php']],
    ]]];
    $previous = $this->folder->store->path($this->folder->stage());
    $release = $this->folder->store->path($this->folder->stage());
    file_put_contents("{$previous}/composer.lock", json_encode($lock('1.0.0')));
    file_put_contents("{$release}/composer.lock", json_encode($lock('1.1.0')));

    $outcome = ($this->validate)(($this->request)('fixtures/hello-extension'), $release, $previous);

    expect($outcome->needs)->toBe(['files' => ['fixtures/with-files' => ['helpers.php']]]);

    file_put_contents("{$release}/composer.lock", json_encode($lock('1.0.0')));

    expect(($this->validate)(($this->request)('fixtures/hello-extension'), $release, $previous)->needs)->toBe([]);
});
