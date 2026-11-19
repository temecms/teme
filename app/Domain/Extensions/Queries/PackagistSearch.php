<?php

/**
 * Packagist's plugins and themes for the Add sheet (I-3462): a search by words and type, and a package's
 * stable versions, newest first, each answer kept ten minutes. Off when the operator turns Packagist off.
 * It reads only; what is installed is checked again by the installer.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions\Queries;

use App\Domain\Extensions\ExtensionKind;
use App\Domain\Extensions\Operations\RequestExtensionChange;
use App\Domain\Updates\GitHubReleases;
use Composer\Semver\VersionParser;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final readonly class PackagistSearch
{
    /** What the sheet says when Packagist doesn't answer. */
    public const string FAILED = 'Packagist didn’t answer. Try again, or use another tab.';

    private const string SEARCH = 'https://packagist.org/search.json';

    private const string VERSIONS = 'https://repo.packagist.org/p2/';

    private const int MINUTES = 10;

    /**
     * Whether searching Packagist, and installing from it by name, is on for this server.
     */
    public function enabled(): bool
    {
        return (bool) config('teme.extensions.packagist');
    }

    /**
     * Returns the plugins or themes whose name or description match $query, at most fifteen, or the
     * reason none could be read.
     *
     * @return array{results: list<array{name: string, description: string, downloads: int, url: ?string}>, error: ?string}
     */
    public function search(string $query, ExtensionKind $kind): array
    {
        $query = trim($query);

        if (! $this->enabled() || $query === '') {
            return ['results' => [], 'error' => null];
        }

        $key = 'teme.extensions.packagist.search.'.hash('sha256', $kind->value."\0".$query);
        $results = Cache::get($key);

        if (is_array($results)) {
            /** @var list<array{name: string, description: string, downloads: int, url: ?string}> $results */
            return ['results' => $results, 'error' => null];
        }

        $body = $this->get(self::SEARCH, ['q' => mb_substr($query, 0, 100), 'type' => $kind->packageType(), 'per_page' => 15]);

        if ($body === null || ! is_array($body['results'] ?? null)) {
            return ['results' => [], 'error' => __(self::FAILED)];
        }

        $results = [];

        foreach ($body['results'] as $result) {
            if (! is_array($result) || ! is_string($result['name'] ?? null)) {
                continue;
            }

            $url = $result['repository'] ?? $result['url'] ?? null;
            $results[] = [
                'name' => $result['name'],
                'description' => is_string($result['description'] ?? null) ? $result['description'] : '',
                'downloads' => is_int($result['downloads'] ?? null) ? $result['downloads'] : 0,
                'url' => is_string($url) && str_starts_with($url, 'https://') ? $url : null,
            ];
        }

        $results = array_slice($results, 0, 15);
        Cache::put($key, $results, now()->addMinutes(self::MINUTES));

        return ['results' => $results, 'error' => null];
    }

    /**
     * Returns $name's stable versions on Packagist, newest first, or the reason they couldn't be read.
     *
     * @return array{versions: list<string>, error: ?string}
     */
    public function versions(string $name): array
    {
        if (! $this->enabled() || preg_match(RequestExtensionChange::PACKAGE_NAME, $name) !== 1) {
            return ['versions' => [], 'error' => null];
        }

        $key = 'teme.extensions.packagist.versions.'.hash('sha256', $name);
        $versions = Cache::get($key);

        if (is_array($versions)) {
            /** @var list<string> $versions */
            return ['versions' => $versions, 'error' => null];
        }

        $body = $this->get(self::VERSIONS.$name.'.json', []);
        $entries = $body['packages'][$name] ?? null;

        if (! is_array($entries)) {
            return ['versions' => [], 'error' => __(self::FAILED)];
        }

        $versions = [];

        foreach ($entries as $entry) {
            $version = is_array($entry) ? ($entry['version'] ?? null) : null;

            if (is_string($version) && VersionParser::parseStability($version) === 'stable') {
                $versions[] = $version;
            }
        }

        // Packagist lists a package's versions newest first; kept as given.
        $versions = array_values(array_unique($versions));
        Cache::put($key, $versions, now()->addMinutes(self::MINUTES));

        return ['versions' => $versions, 'error' => null];
    }

    /**
     * The JSON body Packagist answered with, or null when it didn't answer, or not with JSON.
     *
     * @param  array<string, mixed>  $query
     *
     * @return array<mixed>|null
     */
    private function get(string $url, array $query): ?array
    {
        try {
            $response = Http::withUserAgent(GitHubReleases::USER_AGENT)->acceptJson()->timeout(5)->get($url, $query);
        } catch (ConnectionException) {
            return null;
        }

        $body = $response->successful() ? $response->json() : null;

        return is_array($body) ? $body : null;
    }
}
