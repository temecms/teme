<?php

declare(strict_types=1);

use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Helpers for the admin side of the plugin and theme installer's tests (docs/plans/plugin-installer.md).

if (! function_exists('extAdmin')) {
    /**
     * An administrator, with the password taken as given.
     */
    function extAdmin(): User
    {
        $admin = User::factory()->create()->assignRole('administrator');
        app()->instance(Elevation::class, new OperatorElevation);

        return $admin;
    }

    /**
     * An extensions folder whose current release holds $packages (name => type, version and code,
     * asked for by name unless `dependency` is true), with $earlier releases kept before it. Returns the
     * folder, set as the configured path.
     *
     * @param  array<string, array{type: string, version?: string, code?: string, source?: string, dependency?: bool}>  $packages
     */
    function extFolder(array $packages = [], int $earlier = 0, ?array $repositories = null): string
    {
        $root = sys_get_temp_dir().'/teme-ext-admin-'.Str::random(12);
        $releases = [];

        for ($index = 0; $index <= $earlier; $index++) {
            $releases[] = strtolower((string) Str::ulid());
            usleep(2000);
        }

        $current = end($releases);

        foreach ($releases as $id) {
            mkdir("{$root}/releases/{$id}/vendor/composer", 0755, true);
        }

        $installed = [];
        $require = [];
        $sources = [];

        foreach ($packages as $name => $package) {
            $installed[] = ['name' => $name, 'type' => $package['type'], 'version' => $package['version'] ?? '1.0.0'];

            if (! ($package['dependency'] ?? false)) {
                $require[$name] = '^'.($package['version'] ?? '1.0.0');
                $sources[$name] = $package['source'] ?? 'packagist';
            }

            mkdir("{$root}/releases/{$current}/vendor/{$name}", 0755, true);
            $manifest = $package['type'] === 'teme-plugin' ? 'teme-plugin.json' : 'teme-theme.json';
            file_put_contents("{$root}/releases/{$current}/vendor/{$name}/{$manifest}", json_encode(['code' => $package['code'] ?? Str::after($name, '/')]));
        }

        file_put_contents("{$root}/releases/{$current}/composer.json", json_encode([
            'name' => 'teme/site-extensions',
            'require' => $require === [] ? new stdClass : $require,
            'repositories' => $repositories ?? [],
            'extra' => ['teme' => ['sources' => $sources === [] ? new stdClass : $sources]],
        ]));
        file_put_contents("{$root}/releases/{$current}/vendor/composer/installed.json", json_encode(['packages' => $installed]));
        symlink("releases/{$current}", "{$root}/current");

        config(['teme.extensions.path' => $root]);

        return $root;
    }

    /**
     * Writes an extension request row as the installer would leave it, and returns its ID.
     *
     * @param  array<string, mixed>  $values
     */
    function extRequestRow(string $status = 'requested', array $values = []): string
    {
        $id = strtolower((string) Str::ulid());
        DB::table('extension_requests')->insert([
            'id' => $id, 'action' => 'install', 'kind' => 'plugin', 'package' => 'acme/hello', 'constraint' => '^1.0',
            'source_kind' => 'packagist', 'status' => $status, 'requested_at' => now(),
            'finished_at' => in_array($status, ['requested', 'running', 'waiting'], true) ? null : now(),
            ...$values,
        ]);

        return $id;
    }

    /**
     * Writes an open update of Teme, so extension requests wait for it.
     */
    function extOpenTemeUpdate(): void
    {
        DB::table('teme_updates')->insert([
            'id' => strtolower((string) Str::ulid()), 'kind' => 'update', 'from_version' => '0.1.0', 'to_version' => '0.1.1',
            'status' => 'requested', 'steps' => '[]', 'requested_at' => now(),
        ]);
    }
}
