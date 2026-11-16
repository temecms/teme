<?php

declare(strict_types=1);

namespace App\Domain\Extensions\Queries;

use App\Domain\Deployment\Health\Heartbeats;
use App\Domain\Extensions\ExtensionAction;
use App\Domain\Extensions\RequestStatus;
use App\Domain\Extensions\Sources\ExtensionRequest;
use App\Domain\Extensions\Sources\SourceKinds;
use Carbon\CarbonImmutable;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;

/**
 * What the admin shows of plugins and themes installed from it (docs/architecture/plugins.md): the
 * requests and the open one's steps, whether the installer is running, the commands that carry a request
 * out without it, the saved sources without their keys, the extensions folder's current release and the
 * packages in it, the releases kept before it, and which packages the image itself has. It reads
 * Composer's files in the folder as data and runs nothing.
 */
final readonly class ExtensionsScreen
{
    /** A release's folder name: a lowercase ULID. */
    private const string RELEASE_ID = '/^[0-9a-hjkmnp-tv-z]{26}$/';

    /** How many requests the list shows, newest first. */
    private const int SHOWN = 20;

    /** How recently the installer must have beaten to count as running. */
    private const int PRESENT_SECONDS = 120;

    public function __construct(private Heartbeats $heartbeats, private SourceKinds $kinds) {}

    /**
     * The screens' props: the newest requests, the open one with its steps and the commands that carry
     * it out, whether the installer is running, the folder, the held banner, the saved sources without
     * their keys, and what may be asked for.
     *
     * @return array{
     *     requests: list<array<string, mixed>>,
     *     open: array<string, mixed>|null,
     *     installerSeenAt: ?string,
     *     installerPresent: bool,
     *     folder: bool,
     *     held: array{release: string, reason: ?string}|null,
     *     sources: list<array{id: string, kind: string, label: string, url: ?string, username: ?string, hasKey: bool, createdAt: string}>,
     *     sourceKinds: list<string>,
     *     packagist: bool,
     *     canRollBack: bool,
     *     uploadMax: int,
     * }
     */
    public function data(): array
    {
        $rows = DB::table('extension_requests')->orderByDesc('requested_at')->orderByDesc('id')->limit(self::SHOWN)->get();
        $requests = array_map(fn (object $row): ExtensionRequest => ExtensionRequest::fromRow($row), $rows->all());
        $openRow = DB::table('extension_requests')->whereIn('status', ['requested', 'running', 'waiting'])->first();
        $seen = $this->heartbeats->lastBeat(Heartbeats::INSTALLER);

        return [
            'requests' => array_map(fn (ExtensionRequest $request): array => $this->summary($request), $requests),
            'open' => $openRow === null ? null : $this->request(ExtensionRequest::fromRow($openRow)),
            'installerSeenAt' => $seen?->toIso8601String(),
            'installerPresent' => $this->installerPresent(),
            'folder' => $this->root() !== null,
            'held' => $this->held(),
            'sources' => $this->sources(),
            'sourceKinds' => $this->kinds->keys(),
            'packagist' => (bool) config('teme.extensions.packagist'),
            'canRollBack' => $this->earlierReleases() !== [],
            'uploadMax' => (int) config('teme.extensions.upload_max'),
        ];
    }

    /**
     * Returns one request as the request sheet draws it, or null when there is no such request.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $row = DB::table('extension_requests')->where('id', $id)->first();

        return $row === null ? null : $this->request(ExtensionRequest::fromRow($row));
    }

    /**
     * Whether the installer beat in the last two minutes.
     */
    public function installerPresent(): bool
    {
        $seen = $this->heartbeats->lastBeat(Heartbeats::INSTALLER);

        return $seen !== null && $seen->greaterThan(CarbonImmutable::now()->subSeconds(self::PRESENT_SECONDS));
    }

    /**
     * Where each plugin or theme found on disk comes from, by its folder: `extensions` when the folder is
     * in the extensions folder, with its package and the source kind it was asked from, else `image`
     * (part of the site's build).
     *
     * @param  array<string, string>  $paths  each plugin's or theme's folder, by any key
     *
     * @return array<string, array{origin: 'image'|'extensions', source: ?string, package: ?string}>
     */
    public function origins(array $paths): array
    {
        $root = $this->root();
        $roots = $root === null ? [] : array_unique([$root, realpath($root) ?: $root]);
        $release = $this->currentRelease();
        $packages = $this->folderPackages();
        $origins = [];

        foreach ($paths as $key => $path) {
            $inFolder = array_filter($roots, fn (string $folder): bool => str_starts_with($path, $folder.'/releases/')) !== [];
            $source = null;
            $packageName = null;

            foreach ($inFolder && $release !== null ? $packages : [] as $name => $package) {
                if (str_ends_with(rtrim($path, '/'), '/releases/'.basename($release).'/vendor/'.$name)) {
                    $source = $package['source'];
                    $packageName = $name;
                }
            }

            $origins[$key] = ['origin' => $inFolder ? 'extensions' : 'image', 'source' => $source, 'package' => $packageName];
        }

        return $origins;
    }

    /**
     * The commands that carry out the open request on the server when no installer runs, filled with its
     * real values: with Docker Compose, on a server without Docker, and, as Statamic does, through the
     * site's own build. The first two only when the server has an extensions folder.
     *
     * @return array{docker: ?string, server: ?string, build: ?string}
     */
    public function commands(ExtensionRequest $request): array
    {
        $root = $this->root();
        $package = (string) $request->package;
        $owner = null;

        if ($root !== null && function_exists('posix_getpwuid')) {
            $user = @posix_getpwuid((int) @fileowner($root));
            $owner = is_array($user) ? $user['name'] : null;
        }

        return [
            'docker' => $root === null ? null : 'docker compose run --rm installer --once',
            'server' => $root === null ? null : sprintf(
                'cd %s && sudo -u %s TEME_EXTENSIONS_PATH=%s php artisan teme:installer --once',
                escapeshellarg(base_path()),
                escapeshellarg($owner ?? 'teme-installer'),
                escapeshellarg($root),
            ),
            'build' => match ($request->action) {
                ExtensionAction::Install, ExtensionAction::Update => 'composer require '.escapeshellarg($package.':'.($request->constraint ?? '*')),
                ExtensionAction::Remove => 'composer remove '.escapeshellarg($package),
                default => null,
            },
        ];
    }

    /**
     * Returns the extensions folder, or null when this server has none.
     */
    public function root(): ?string
    {
        $path = config('teme.extensions.path');

        return is_string($path) && $path !== '' ? rtrim($path, '/') : null;
    }

    /**
     * Returns the folder of the release `current` points at, or null when there is none.
     */
    public function currentRelease(): ?string
    {
        $root = $this->root();
        // readlink(), never realpath(): the realpath cache would keep the old release.
        $target = $root === null ? false : @readlink($root.'/current');

        if ($target === false || preg_match(self::RELEASE_ID, basename($target)) !== 1) {
            return null;
        }

        $release = $root.'/releases/'.basename($target);

        return is_dir($release) ? $release : null;
    }

    /**
     * Returns the IDs of the releases kept before the current one, newest first.
     *
     * @return list<string>
     */
    public function earlierReleases(): array
    {
        $root = $this->root();

        if ($root === null || ! is_dir($root.'/releases')) {
            return [];
        }

        $current = $this->currentRelease();
        $ids = array_values(array_filter(
            scandir($root.'/releases') ?: [],
            fn (string $id): bool => preg_match(self::RELEASE_ID, $id) === 1 && is_dir("{$root}/releases/{$id}") && ($current === null || basename($current) !== $id),
        ));
        rsort($ids);

        return $ids;
    }

    /**
     * Returns every plugin and theme in the current release, by package name: its type, version, its
     * plugin or theme code, the source kind it was asked from, and whether it was asked for by name
     * (else it came in as another package's dependency).
     *
     * @return array<string, array{name: string, type: string, version: string, code: ?string, source: ?string, requested: bool}>
     */
    public function folderPackages(): array
    {
        $release = $this->currentRelease();

        if ($release === null) {
            return [];
        }

        $project = self::json($release.'/composer.json');
        $required = is_array($project['require'] ?? null) ? $project['require'] : [];
        $sources = is_array($project['extra']['teme']['sources'] ?? null) ? $project['extra']['teme']['sources'] : [];
        $packages = [];

        foreach (self::installed($release.'/vendor/composer/installed.json') as $name => $package) {
            $type = is_string($package['type'] ?? null) ? $package['type'] : '';

            if ($type !== 'teme-plugin' && $type !== 'teme-theme') {
                continue;
            }

            $manifest = self::json("{$release}/vendor/{$name}/".($type === 'teme-plugin' ? 'teme-plugin.json' : 'teme-theme.json'));
            $source = $sources[$name] ?? null;

            $packages[$name] = [
                'name' => $name,
                'type' => $type,
                'version' => is_string($package['version'] ?? null) ? $package['version'] : '',
                'code' => is_string($manifest['code'] ?? null) ? $manifest['code'] : null,
                'source' => is_string($source) ? $source : null,
                'requested' => array_key_exists($name, $required),
            ];
        }

        return $packages;
    }

    /**
     * Whether a package of the current release came from the saved source with this ID or address: its
     * repository is in the release's composer.json, or the release records the source by its ID.
     */
    public function releaseUsesSource(string $id, ?string $url): bool
    {
        $release = $this->currentRelease();

        if ($release === null) {
            return false;
        }

        $project = self::json($release.'/composer.json');

        foreach (is_array($project['repositories'] ?? null) ? $project['repositories'] : [] as $repository) {
            if ($url !== null && is_array($repository) && is_string($repository['url'] ?? null) && rtrim($repository['url'], '/') === rtrim($url, '/')) {
                return true;
            }
        }

        $recorded = json_encode($project['extra']['teme'] ?? [], JSON_UNESCAPED_SLASHES);

        return is_string($recorded) && str_contains($recorded, '"'.$id.'"');
    }

    /**
     * Whether the image itself has this package (it is part of Teme's build): installed with Teme, the
     * project itself, or a name one of its packages replaces.
     */
    public function imageHas(string $name): bool
    {
        $root = self::json(base_path('composer.json'));

        if (($root['name'] ?? null) === $name) {
            return true;
        }

        foreach (self::installed((Env::get('COMPOSER_VENDOR_DIR') ?: base_path('vendor')).'/composer/installed.json') as $packageName => $package) {
            if ($packageName === $name || array_key_exists($name, is_array($package['replace'] ?? null) ? $package['replace'] : [])) {
                return true;
            }
        }

        return false;
    }

    /**
     * One request as the list shows it.
     *
     * @return array{id: string, action: string, kind: string, package: ?string, constraint: ?string, sourceKind: ?string, status: string, step: ?string, error: ?string, requestedBy: ?string, requestedAt: string, finishedAt: ?string}
     */
    private function summary(ExtensionRequest $request): array
    {
        return [
            'id' => $request->id,
            'action' => $request->action->value,
            'kind' => $request->kind->value,
            'package' => $request->package,
            'constraint' => $request->constraint,
            'sourceKind' => $request->sourceKind,
            'status' => $request->status->value,
            'step' => $request->step?->value,
            'error' => $request->error,
            'requestedBy' => $this->person($request->requestedBy),
            'requestedAt' => $request->requestedAt->toIso8601String(),
            'finishedAt' => $request->finishedAt?->toIso8601String(),
        ];
    }

    /**
     * One request as the request sheet draws it: the list's line, its steps, what waits for agreement,
     * the installer's report, what may be done with it, and the commands that carry it out by hand.
     *
     * @return array<string, mixed>
     */
    private function request(ExtensionRequest $request): array
    {
        $open = $request->status->isOpen();

        return [
            ...$this->summary($request),
            'steps' => array_map(fn (array $entry): array => [
                'step' => $entry['step'],
                'state' => $entry['state'],
                'startedAt' => $entry['startedAt'] ?? null,
                'finishedAt' => $entry['finishedAt'] ?? null,
                'detail' => $entry['detail'] ?? null,
            ], $request->steps),
            'needs' => $request->needs,
            'agreed' => $request->agreed,
            'report' => $request->report,
            'startedAt' => $request->startedAt?->toIso8601String(),
            'canCancel' => $request->status === RequestStatus::Requested || $request->status === RequestStatus::Waiting,
            'canAgree' => $request->status === RequestStatus::Waiting && $request->needs !== [],
            'commands' => $open ? $this->commands($request) : null,
        ];
    }

    /**
     * When the plugins and themes installed from the admin are held, because the current release wasn't
     * checked against this image: the release, and why the last rebuild failed, naming what blocks.
     *
     * @return array{release: string, reason: ?string}|null
     */
    private function held(): ?array
    {
        if (! defined('TEME_EXTENSIONS_HELD')) {
            return null;
        }

        $reason = DB::table('extension_requests')->where('action', ExtensionAction::Rebuild->value)->where('status', RequestStatus::Failed->value)
            ->orderByDesc('requested_at')->value('error');

        return ['release' => basename((string) constant('TEME_EXTENSIONS_HELD')), 'reason' => is_string($reason) ? $reason : null];
    }

    /**
     * The saved sources, never with their keys: only whether one is kept.
     *
     * @return list<array{id: string, kind: string, label: string, url: ?string, username: ?string, hasKey: bool, createdAt: string}>
     */
    private function sources(): array
    {
        return DB::table('extension_sources')->orderBy('label')->orderBy('id')
            ->get(['id', 'kind', 'label', 'url', 'username', DB::raw('credential IS NOT NULL AS has_key'), 'created_at'])
            ->map(fn (object $source): array => [
                'id' => (string) $source->id,
                'kind' => (string) $source->kind,
                'label' => (string) $source->label,
                'url' => $source->url === null ? null : (string) $source->url,
                'username' => $source->username === null ? null : (string) $source->username,
                'hasKey' => (bool) $source->has_key,
                'createdAt' => CarbonImmutable::parse((string) $source->created_at)->toIso8601String(),
            ])->values()->all();
    }

    /**
     * The name of the person who asked, or null for the operator or Teme.
     */
    private function person(?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $name = DB::table('users')->where('id', $id)->value('name');

        return is_string($name) ? $name : null;
    }

    /**
     * Returns a Composer installed.json's packages by name.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function installed(string $file): array
    {
        $installed = self::json($file);
        $packages = $installed['packages'] ?? $installed;
        $byName = [];

        foreach (is_array($packages) ? $packages : [] as $package) {
            if (is_array($package) && is_string($package['name'] ?? null)) {
                /** @var array<string, mixed> $package */
                $byName[$package['name']] = $package;
            }
        }

        return $byName;
    }

    /**
     * Returns a JSON file's object, or an empty array when it is missing or isn't one.
     *
     * @return array<mixed>
     */
    private static function json(string $file): array
    {
        $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
