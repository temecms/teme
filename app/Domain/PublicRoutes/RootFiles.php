<?php

declare(strict_types=1);

namespace App\Domain\PublicRoutes;

use App\Domain\Hooks\ListenerFaults;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\SafeMode;
use Illuminate\Contracts\Container\Container;
use RuntimeException;
use Teme\Contracts\Plugins\Capability;
use Teme\Contracts\Settings\SettingsReader;
use Throwable;

/**
 * The files enabled plugins answer at the site's root (docs/guides/developers/plugins/root-files.md):
 * each plugin granted routes.public that may load now, by the one rule that
 * decides whether it starts, its names and bodies filled from its settings
 * as they are now. Nothing is kept between calls.
 */
final readonly class RootFiles
{
    public function __construct(
        private PluginRegistry $plugins,
        private PluginRecords $records,
        private SafeMode $safeMode,
        private PluginLoader $loader,
        private SettingsReader $settings,
        private Container $container,
    ) {}

    /**
     * The body answered at /{name}, or null when no plugin answers it. When
     * two plugins answer one name, the one enabled first does, and the
     * other's is recorded as its fault.
     */
    public function find(string $name): ?string
    {
        $answer = null;

        foreach ($this->answering() as [$owner, $filledName, $body]) {
            if ($filledName !== $name) {
                continue;
            }

            if ($answer === null) {
                $answer = $body;

                continue;
            }

            $this->container->make(ListenerFaults::class)->record($owner, $owner.'#root-files', RootFiles::class, new RuntimeException("Another plugin already answers /{$name}."));
        }

        return $answer;
    }

    /**
     * The plugin that answers /{name} now, leaving $except out, or null.
     */
    public function ownerOf(string $name, string $except = ''): ?string
    {
        foreach ($this->answering() as [$owner, $filledName]) {
            if ($filledName === $name && $owner !== $except) {
                return $owner;
            }
        }

        return null;
    }

    /**
     * Every file answered now, as [owner, name, body], the plugin enabled
     * first first: none on a safe page or with every plugin disabled on the
     * server.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function answering(): array
    {
        if ($this->safeMode->active()) {
            return [];
        }

        $disabledOnServer = $this->loader->disabledOnServer();

        if ($disabledOnServer === true) {
            return [];
        }

        $records = $this->records->enabled();
        usort($records, fn (PluginRecord $a, PluginRecord $b): int => [$a->enabled_at?->getTimestamp() ?? 0, $a->name] <=> [$b->enabled_at?->getTimestamp() ?? 0, $b->name]);
        $files = [];

        foreach ($records as $record) {
            if (! in_array(Capability::RoutesPublic->value, $record->consent['capabilities'] ?? [], true)) {
                continue;
            }

            $found = $this->plugins->find($record->name);
            $manifest = $found?->manifest;

            // The one rule that decides whether a plugin starts decides whether its files answer.
            if ($manifest === null || $this->loader->skipReason($record, $found, $disabledOnServer) !== null) {
                continue;
            }

            $declared = RootFile::declaredIn($manifest);

            if ($declared === []) {
                continue;
            }

            $values = $this->values($record->code);

            foreach ($declared as $file) {
                $name = $file->filledName($values);
                $body = $file->filledBody($values);

                if ($name !== null && $body !== null) {
                    $files[] = [$record->name, $name, $body];
                }
            }
        }

        return $files;
    }

    /**
     * The plugin's settings as they are now, or none when its group isn't
     * registered, as when it failed to start.
     *
     * @return array<string, mixed>
     */
    private function values(string $code): array
    {
        try {
            return $this->settings->group("plugin.{$code}");
        } catch (Throwable) {
            return [];
        }
    }
}
