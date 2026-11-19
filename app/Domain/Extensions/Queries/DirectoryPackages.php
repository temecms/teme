<?php

/**
 * The plugin and theme directory as Add plugin and Add theme read it: the
 * last index whose signature was checked, searched by words and kind, and
 * one package's detail file, checked against the index's hash for it. It
 * reads only what RecordDirectoryIndex kept, and the detail files it names.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions\Queries;

use App\Domain\Extensions\Directory\DirectoryIndex;
use App\Domain\Extensions\Directory\DirectoryReader;
use App\Domain\Extensions\Directory\DirectoryRefused;
use App\Domain\Extensions\Directory\DirectorySettings;
use App\Domain\Extensions\ExtensionKind;
use App\Domain\Plugins\PluginWords;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use JsonException;
use Teme\Contracts\Plugins\Capability;

final readonly class DirectoryPackages
{
    /** The most packages a search answers with. */
    public const int MOST_RESULTS = 60;

    /** Capabilities whose sentence names what the plugin declares, so the directory's own words are shown. */
    private const array DECLARED = [Capability::Ai->value, Capability::Http->value, Capability::ImportWordPress->value, Capability::NoticesSend->value];

    public function __construct(private DirectoryReader $reader) {}

    /**
     * Whether the directory is switched on for this site and this Teme holds a key to check it with.
     */
    public function enabled(): bool
    {
        return DirectorySettings::enabled() && $this->reader->keys() !== [];
    }

    /**
     * Returns the last ask's result: the kept index's number, when it was
     * made, runs out and was last asked for, and the last refusal.
     *
     * @return array{sequence: ?int, generated: ?string, expires: ?string, checked: ?string, succeeded: ?string, error: ?string}
     */
    public function state(): array
    {
        $row = DB::table('directory_index')->where('id', 1)->first(['sequence', 'generated_at', 'expires_at', 'checked_at', 'succeeded_at', 'error']);

        return [
            'sequence' => $row?->sequence === null ? null : (int) $row->sequence,
            'generated' => self::iso($row?->generated_at),
            'expires' => self::iso($row?->expires_at),
            'checked' => self::iso($row?->checked_at),
            'succeeded' => self::iso($row?->succeeded_at),
            'error' => $row?->error === null ? null : (string) $row->error,
        ];
    }

    /**
     * Returns the kept index, or null when none was ever kept. It may have run out.
     */
    public function kept(): ?DirectoryIndex
    {
        $document = $this->keptDocument();

        return $document === null ? null : DirectoryIndex::read($document);
    }

    /**
     * Returns the kept index.json as decoded, or null when none was ever kept. It may have run out.
     *
     * @return array<mixed>|null
     */
    public function keptDocument(): ?array
    {
        $json = DB::table('directory_index')->where('id', 1)->value('index');

        if (! is_string($json)) {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Returns a listed package's detail file, decoded, once it matches the usable index's hash for it.
     *
     * @return array<mixed>
     *
     * @throws DirectoryRefused
     */
    public function detailOf(string $name, ?ExtensionKind $kind = null): array
    {
        $listed = $this->usable()->package($name, $kind) ?? throw DirectoryRefused::notListed($name);

        return $this->detailFile($listed);
    }

    /**
     * Returns the kept index when it may be installed from now.
     *
     * @throws DirectoryRefused when the directory is off, none was kept, or it has run out
     */
    public function usable(): DirectoryIndex
    {
        if (! DirectorySettings::enabled()) {
            throw DirectoryRefused::off();
        }

        if ($this->reader->keys() === []) {
            throw DirectoryRefused::noKey();
        }

        $index = $this->kept() ?? throw DirectoryRefused::notYet();

        if ($index->hasExpired()) {
            throw DirectoryRefused::expired($index->expires->toDateString());
        }

        return $index;
    }

    /**
     * Returns the listed plugins or themes whose name, label, summary or
     * category hold every word of $query, the team's picks first, then by
     * label; every one of the kind for no words. Or the reason none can be read.
     *
     * @return array{results: list<array{name: string, label: string, summary: string, author: string, topics: list<string>, picked: bool, latest: string, updated: string, worksWith: list<string>, icon: ?string}>, error: ?string}
     */
    public function search(string $query, ExtensionKind $kind): array
    {
        try {
            $index = $this->usable();
        } catch (DirectoryRefused $refused) {
            return ['results' => [], 'error' => $refused->getMessage()];
        }

        $words = array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim($query))) ?: [], fn (string $word): bool => $word !== ''));
        $results = [];

        foreach ($index->packages as $package) {
            if ($package['kind'] !== $kind->value) {
                continue;
            }

            $card = $this->card($package);
            $haystack = mb_strtolower(implode(' ', [$card['name'], $card['label'], $card['summary'], $card['author'], ...$card['topics']]));

            foreach ($words as $word) {
                if (! str_contains($haystack, $word)) {
                    continue 2;
                }
            }

            $results[] = $card;
        }

        usort($results, fn (array $a, array $b): int => [! $a['picked'], mb_strtolower($a['label'])] <=> [! $b['picked'], mb_strtolower($b['label'])]);

        return ['results' => array_slice($results, 0, self::MOST_RESULTS), 'error' => null];
    }

    /**
     * Returns one listed package's detail: its card, README and changelog
     * (Markdown), screenshots, the permissions it asks for in plain words,
     * links and releases, read from its detail file once that matches the
     * signed index. Or the reason it can't be read.
     *
     * @return array{package: array<string, mixed>|null, error: ?string}
     */
    public function detail(string $name, ExtensionKind $kind): array
    {
        try {
            $index = $this->usable();
            $listed = $index->package($name, $kind) ?? throw DirectoryRefused::notListed($name);
            $detail = $this->detailFile($listed);
        } catch (DirectoryRefused $refused) {
            return ['package' => null, 'error' => $refused->getMessage()];
        }

        $releases = [];

        foreach (is_array($detail['releases'] ?? null) ? $detail['releases'] : [] as $release) {
            if (is_array($release) && is_string($release['version'] ?? null) && ($release['held'] ?? false) !== true) {
                $releases[] = [
                    'version' => $release['version'],
                    'date' => is_string($release['date'] ?? null) ? $release['date'] : null,
                    'worksWith' => self::strings($release['works_with'] ?? []),
                ];
            }
        }

        $screenshots = [];

        foreach (is_array($detail['screenshots'] ?? null) ? $detail['screenshots'] : [] as $shot) {
            $url = is_array($shot) ? ($shot['url'] ?? null) : null;

            if ($this->reader->isOwnPicture($url)) {
                $screenshots[] = ['url' => (string) $url, 'caption' => is_string($shot['caption'] ?? null) ? $shot['caption'] : ''];
            }
        }

        $links = [];

        foreach (['source', 'issues', 'docs', 'support'] as $link) {
            $links[$link] = self::https($detail['links'][$link] ?? null);
        }

        return ['package' => [
            ...$this->card($listed),
            'readme' => is_string($detail['readme'] ?? null) ? $detail['readme'] : '',
            'changelog' => is_string($detail['changelog'] ?? null) ? $detail['changelog'] : '',
            'screenshots' => $screenshots,
            'permissions' => self::permissions($detail['capabilities'] ?? []),
            'links' => $links,
            'stars' => is_int($detail['stars'] ?? null) ? $detail['stars'] : null,
            'issues' => is_int($detail['issues'] ?? null) ? $detail['issues'] : null,
            'releases' => $releases,
        ], 'error' => null];
    }

    /**
     * Returns composer/packages.json's bytes, fetched now and checked against the usable index's hash.
     *
     * @throws DirectoryRefused
     */
    public function composerRepository(): string
    {
        $index = $this->usable();

        return $this->reader->file($index->composerPath, $index->composerSha256);
    }

    /**
     * The detail file, decoded; kept by its hash, so a file kept is the one the index names.
     *
     * @param  array<string, mixed>  $listed
     *
     * @return array<mixed>
     *
     * @throws DirectoryRefused
     */
    private function detailFile(array $listed): array
    {
        $sha256 = (string) $listed['detail_sha256'];
        $key = 'teme.extensions.directory.detail.'.$sha256;
        $kept = Cache::get($key);

        if (is_array($kept)) {
            return $kept;
        }

        $bytes = $this->reader->file((string) $listed['detail'], $sha256);

        try {
            $decoded = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw DirectoryRefused::malformed((string) $listed['detail']);
        }

        if (! is_array($decoded)) {
            throw DirectoryRefused::malformed((string) $listed['detail']);
        }

        Cache::put($key, $decoded, now()->addDay());

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $package
     *
     * @return array{name: string, label: string, summary: string, author: string, topics: list<string>, picked: bool, latest: string, updated: string, worksWith: list<string>, icon: ?string}
     */
    private function card(array $package): array
    {
        $name = (string) $package['name'];
        $author = is_array($package['author'] ?? null) ? ($package['author']['name'] ?? null) : null;

        return [
            'name' => $name,
            'label' => is_string($package['label'] ?? null) && $package['label'] !== '' ? $package['label'] : $name,
            'summary' => is_string($package['summary'] ?? null) ? $package['summary'] : '',
            'author' => is_string($author) ? $author : '',
            'topics' => self::strings($package['categories'] ?? []),
            'picked' => ($package['picked'] ?? false) === true,
            'latest' => is_string($package['latest'] ?? null) ? $package['latest'] : '',
            'updated' => is_string($package['updated'] ?? null) ? $package['updated'] : '',
            'worksWith' => self::strings($package['works_with'] ?? []),
            'icon' => $this->reader->isOwnPicture($package['icon'] ?? null) ? (string) $package['icon'] : null,
        ];
    }

    /**
     * Each permission the package asks for, in Teme's own words where Teme has words for it.
     *
     * @return list<array{key: string, words: string}>
     */
    private static function permissions(mixed $capabilities): array
    {
        $permissions = [];

        foreach (is_array($capabilities) ? $capabilities : [] as $capability) {
            $key = is_array($capability) ? ($capability['key'] ?? null) : null;

            if (! is_string($key) || $key === '') {
                continue;
            }

            $given = is_string($capability['words'] ?? null) ? $capability['words'] : $key;
            $known = Capability::tryFrom($key) !== null && ! in_array($key, self::DECLARED, true);
            $permissions[] = ['key' => $key, 'words' => $known ? PluginWords::capability($key) : $given];
        }

        return $permissions;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter($values, is_string(...))) : [];
    }

    private static function https(mixed $url): ?string
    {
        return is_string($url) && str_starts_with($url, 'https://') ? $url : null;
    }

    private static function iso(mixed $moment): ?string
    {
        return $moment === null ? null : CarbonImmutable::parse((string) $moment)->toIso8601ZuluString();
    }
}
