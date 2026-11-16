<?php

declare(strict_types=1);

use App\Domain\Plugins\Seams\PluginServices;
use Illuminate\Support\Facades\File;
use Teme\Contracts\Http\OutboundHttp;
use Teme\Contracts\Settings\SettingsReader;

/**
 * Every PHP file under the contracts source, as the class it declares.
 *
 * @return list<string>
 */
function contractsReferenceTypes(): array
{
    $root = base_path('packages/teme-contracts/src');
    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php') {
            $found[] = 'Teme\\Contracts\\'.str_replace('/', '\\', substr($file->getPathname(), strlen($root) + 1, -4));
        }
    }

    sort($found);

    return $found;
}

/**
 * A folder of fixture contracts holding this one file, emptied first.
 */
function contractsReferenceFixture(string $name, string $source): string
{
    $folder = storage_path('framework/testing/contracts-'.strtolower($name));
    File::deleteDirectory($folder);
    File::ensureDirectoryExists($folder);
    file_put_contents($folder.'/'.$name.'.php', $source);

    return $folder;
}

it('gives every interface, class and enum in teme/contracts a section', function (): void {
    $path = storage_path('framework/testing/contracts-reference.md');

    $this->artisan('teme:docs:contracts', ['--output' => $path])->assertSuccessful();

    $markdown = (string) file_get_contents($path);
    $types = contractsReferenceTypes();

    expect($types)->not->toBeEmpty()
        ->and(substr_count($markdown, "\n### "))->toBe(count($types));

    foreach ($types as $class) {
        expect($markdown)->toContain('`'.$class.'`, ');
    }
});

it('lists each contract a plugin’s constructor may ask for once, with the grant that gives it', function (): void {
    $path = storage_path('framework/testing/contracts-reference.md');

    $this->artisan('teme:docs:contracts', ['--output' => $path])->assertSuccessful();

    $markdown = (string) file_get_contents($path);

    foreach (PluginServices::OFFERED as $class) {
        expect(substr_count($markdown, '| `'.$class.'` |'))->toBe(1, "expected one row for {$class}");
    }

    expect($markdown)->toContain('| `'.SettingsReader::class.'` | always | 0.1.0 |')
        ->and($markdown)->toContain('| `'.OutboundHttp::class.'` | granted `http` | 0.5.0 |');
});

it('says the highest version this checkout’s contracts offer', function (): void {
    $path = storage_path('framework/testing/contracts-reference.md');

    $this->artisan('teme:docs:contracts', ['--output' => $path])->assertSuccessful();

    expect((string) file_get_contents($path))->toContain('This checkout offers contracts up to **0.9.0**.');
});

it('fails naming a type that has no @since', function (): void {
    $source = contractsReferenceFixture('NoSinceType', <<<'PHP'
<?php

declare(strict_types=1);

namespace ContractsNosinceType;

/**
 * A fixture interface with no version, for the generator's refusal.
 */
interface NoSinceType {}
PHP);

    $this->artisan('teme:docs:contracts', [
        '--source' => $source,
        '--namespace' => 'ContractsNosinceType',
        '--output' => storage_path('framework/testing/contracts-nosince-type.md'),
    ])->expectsOutputToContain('ContractsNosinceType\NoSinceType has no @since')->assertFailed();
});

it('fails naming a method that has no @since', function (): void {
    $source = contractsReferenceFixture('NoSinceMethod', <<<'PHP'
<?php

declare(strict_types=1);

namespace ContractsNosinceMethod;

/**
 * A fixture interface whose method has no version, for the generator's refusal.
 *
 * @since 0.9.0
 */
interface NoSinceMethod
{
    /** Answers nothing. */
    public function answer(): void;
}
PHP);

    $this->artisan('teme:docs:contracts', [
        '--source' => $source,
        '--namespace' => 'ContractsNosinceMethod',
        '--output' => storage_path('framework/testing/contracts-nosince-method.md'),
    ])->expectsOutputToContain('ContractsNosinceMethod\NoSinceMethod::answer() has no @since')->assertFailed();
});

it('keeps the committed contracts reference in step with the command', function (): void {
    $path = storage_path('framework/testing/contracts-reference-drift.md');

    $this->artisan('teme:docs:contracts', ['--output' => $path])->assertSuccessful();

    expect(file_get_contents($path))->toBe(file_get_contents(base_path('docs/reference/contracts.md')));
});
