<?php

declare(strict_types=1);

use App\Domain\Plugins\CodeScan;
use Tests\Fixtures\Plugins\PluginFixtures;

afterEach(fn () => PluginFixtures::forget());

it('finds Teme’s own code however it is named', function (string $source, string $name, int $line): void {
    expect(app(CodeScan::class)->namesIn("<?php\n".$source))->toBe([[$name, $line]]);
})->with([
    'use' => ['use App\\Models\\User;', 'App\\Models\\User', 2],
    'fully qualified' => ['$x = \\App\\Domain\\X::class;', '\\App\\Domain\\X', 2],
    'group use' => ['use App\\{A, B};', 'App', 2],
    'namespace' => ['namespace App\\Plugin;', 'App\\Plugin', 2],
    'bare namespace' => ['namespace App;', 'App', 2],
    'string' => ["\$c = 'App\\\\Domain\\\\X';", 'App\\Domain\\X', 2],
    'lower case' => ['use app\\domain\\x;', 'app\\domain\\x', 2],
]);

it('lets Illuminate, the plugin’s own names and the word App alone pass', function (): void {
    $source = "<?php\nnamespace Acme\\Hello;\nuse Illuminate\\Support\\Str;\nuse Teme\\Contracts\\Hooks\\Hooks;\n\$app = 'Apple';\n// App\\Foo in a comment\n/** @see App\\Bar */\n";

    expect(app(CodeScan::class)->namesIn($source))->toBe([]);
});

it('says where the first name is, and how many more there are', function (): void {
    $path = PluginFixtures::path('reaching');

    expect(app(CodeScan::class)->problems($path, PluginFixtures::composer('reaching')))
        ->toBe(['It uses Teme’s own code, which plugins may not use: App\\Domain\\Contents\\Models\\Content in src/Listens.php, line 7. (and 2 more)']);
});

it('refuses a plugin that runs files on every request or asks Laravel to load it', function (): void {
    expect(app(CodeScan::class)->problems(PluginFixtures::path('autoloads'), PluginFixtures::composer('autoloads')))->toBe([
        'It asks Composer to run files on every request, so it would run even while disabled.',
        'It asks Laravel to load it, so it would run even while disabled.',
    ]);
});

it('skips only the top-level vendor, tests and node_modules folders, never autoload-dev’s, and reads the whole folder without a composer.json', function (): void {
    $reaches = "<?php\n\nnamespace Tests\\Fixtures\\Plugins\\Hello;\n\nuse App\\Models\\User;\n";
    $copy = PluginFixtures::copy('hello', composer: [
        'autoload' => ['psr-4' => ['Tests\\Fixtures\\Plugins\\Hello\\' => ['src/', 'src/tests/']]],
        'autoload-dev' => ['psr-4' => ['Tests\\Fixtures\\Plugins\\Hello\\Tests\\' => 'tests/', 'Elsewhere\\' => 'dev/']],
    ], files: [
        'tests/HelloTest.php' => $reaches,
        'src/tests/Helper.php' => $reaches,
        'src/vendor/x/y.php' => $reaches,
        'vendor/x/y.php' => $reaches,
        'node_modules/x/y.php' => $reaches,
        'dev/Dev.php' => $reaches,
    ]);

    expect(app(CodeScan::class)->problems($copy, json_decode((string) file_get_contents($copy.'/composer.json'), true)))
        ->toBe(['It uses Teme’s own code, which plugins may not use: App\\Models\\User in src/tests/Helper.php, line 5. (and 1 more)'])
        ->and(app(CodeScan::class)->sourceFiles($copy, null))->toBe([$copy.'/dev/Dev.php', $copy.'/src/Hello.php', $copy.'/src/tests/Helper.php', $copy.'/src/vendor/x/y.php']);
});

it('refuses a plugin with a file linked from outside its folder', function (): void {
    $outside = PluginFixtures::copy('hello');
    $copy = PluginFixtures::copy('hello');
    symlink($outside.'/src/Hello.php', $copy.'/src/Linked.php');

    expect(app(CodeScan::class)->problems($copy, PluginFixtures::composer('hello')))
        ->toBe(['It links to a file outside its own folder, so Teme can’t check it: src/Linked.php.'])
        ->and(app(CodeScan::class)->sourceFiles($copy, PluginFixtures::composer('hello')))->toBe([$copy.'/src/Hello.php']);
});

it('refuses a plugin with more files, or a larger file, than it scans', function (): void {
    $many = [];

    for ($i = 0; $i <= CodeScan::MOST_FILES; $i++) {
        $many["src/Many/F{$i}.php"] = "<?php\n";
    }

    $crowded = PluginFixtures::copy('hello', files: $many);
    $large = PluginFixtures::copy('hello', files: ['src/Large.php' => "<?php\n".str_repeat('//', CodeScan::MOST_BYTES)]);

    expect(app(CodeScan::class)->problems($crowded, PluginFixtures::composer('hello')))->toBe(['It has more than '.CodeScan::MOST_FILES.' PHP files, so Teme can’t check them all.'])
        ->and(app(CodeScan::class)->sourceFiles($crowded, PluginFixtures::composer('hello')))->toHaveCount(CodeScan::MOST_FILES)
        ->and(app(CodeScan::class)->problems($large, PluginFixtures::composer('hello')))->toBe(['src/Large.php is larger than 1 MB, so Teme can’t check it.'])
        ->and(app(CodeScan::class)->sourceFiles($large, PluginFixtures::composer('hello')))->toBe([$large.'/src/Hello.php']);
});
