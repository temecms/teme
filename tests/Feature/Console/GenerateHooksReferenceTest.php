<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Teme\Contracts\Hooks\Collect;
use Teme\Contracts\Hooks\Gate;
use Teme\Contracts\Hooks\Notification;
use Teme\Contracts\Hooks\Resolve;
use Teme\Contracts\Hooks\Transform;

/**
 * How many hook classes live under the contracts source: each class that
 * implements one of the five verbs.
 */
function hooksReferenceVerbClasses(): array
{
    $verbs = [Notification::class, Transform::class, Gate::class, Resolve::class, Collect::class];
    $root = base_path('packages/teme-contracts/src');
    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($root) + 1, -4);
        $class = 'Teme\\Contracts\\'.str_replace(['/', '\\'], '\\', $relative);

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isInterface() || $reflection->isTrait() || $reflection->isAbstract()) {
            continue;
        }

        foreach ($verbs as $verb) {
            if ($reflection->implementsInterface($verb)) {
                $found[] = $class;

                break;
            }
        }
    }

    sort($found);

    return $found;
}

it('lists every hook class that implements a verb', function (): void {
    $path = storage_path('framework/testing/hooks-reference.md');

    $this->artisan('teme:docs:hooks', ['--output' => $path])->assertSuccessful();

    $markdown = (string) file_get_contents($path);
    $classes = hooksReferenceVerbClasses();

    expect($classes)->not->toBeEmpty();

    foreach ($classes as $class) {
        expect($markdown)->toContain('## '.class_basename($class));
    }

    expect(substr_count($markdown, "\n## "))->toBe(count($classes));
});

it('fails naming a hook that has no @since', function (): void {
    $source = storage_path('framework/testing/hooks-nosince-src');
    File::deleteDirectory($source);
    File::ensureDirectoryExists($source);

    file_put_contents($source.'/NoSinceHook.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace HooksNosince;

use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\Notification;

/**
 * A fixture hook with no @since, for the generator's refusal.
 */
#[HookContract]
final readonly class NoSinceHook implements Notification
{
    public function __construct(public string $id) {}
}
PHP);

    $this->artisan('teme:docs:hooks', [
        '--source' => $source,
        '--namespace' => 'HooksNosince',
        '--output' => storage_path('framework/testing/hooks-nosince.md'),
    ])->expectsOutputToContain('NoSinceHook')->assertFailed();
});

it('keeps the committed hooks reference in step with the command', function (): void {
    $path = storage_path('framework/testing/hooks-reference-drift.md');

    $this->artisan('teme:docs:hooks', ['--output' => $path])->assertSuccessful();

    expect(file_get_contents($path))->toBe(file_get_contents(base_path('docs/reference/hooks.md')));
});
