<?php

declare(strict_types=1);

namespace App\Domain\PublicRoutes;

use App\Domain\Plugins\PluginRegistry;
use App\Domain\Settings\SettingsCheck;
use Illuminate\Contracts\Container\Container;

/**
 * The check on a plugin's settings group when the plugin names a root file
 * from a setting (docs/guides/developers/plugins/root-files.md): a value whose filled name another
 * plugin answers now is refused when it is saved, so two plugins never
 * claim one address. Registered for each such group as its plugin starts.
 */
final readonly class RootFileNames implements SettingsCheck
{
    public function __construct(
        private string $owner,
        private string $code,
        private PluginRegistry $plugins,
        private RootFiles $rootFiles,
    ) {}

    /**
     * Registers the check for this plugin's settings group, under
     * SettingsCheck::TAG, once a process.
     */
    public static function register(Container $container, string $owner, string $code): void
    {
        $abstract = self::class.':'.$code;

        if ($container->bound($abstract)) {
            return;
        }

        $container->bind($abstract, fn (Container $app): self => new self($owner, $code, $app->make(PluginRegistry::class), $app->make(RootFiles::class)));
        $container->tag([$abstract], SettingsCheck::TAG);
    }

    public function group(): string
    {
        return "plugin.{$this->code}";
    }

    public function problems(array $values): array
    {
        $manifest = $this->plugins->find($this->owner)?->manifest;
        $problems = [];

        foreach ($manifest === null ? [] : RootFile::declaredIn($manifest) as $file) {
            $key = $file->nameSetting();

            if ($key === null || ! array_key_exists($key, $values)) {
                continue;
            }

            $name = $file->filledName($values);

            if ($name !== null && $this->rootFiles->ownerOf($name, $this->owner) !== null) {
                $problems["values.{$key}"] = __('Another plugin already answers /:name.', ['name' => $name]);
            }
        }

        return $problems;
    }
}
