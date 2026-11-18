<?php

/**
 * The Dokploy template's published files, read by the updater: compose-hashes.json, which lists the hash of every
 * compose file the template has released and of its current one, and the current compose file beside it. Read
 * from TEME_DOKPLOY_COMPOSE_HASHES_URL, by default the template repository's raw address, over https only.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Updates\Updater;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final readonly class ComposeTemplate
{
    /** Where the template keeps its compose file, beside compose-hashes.json. */
    public const string COMPOSE_PATH = 'blueprints/teme/docker-compose.yml';

    /** The most of either file the updater reads, in bytes. */
    private const int LIMIT = 262144;

    /**
     * Returns the hash of the template's current compose file and of every one it released.
     *
     * @return array{current: string, released: list<string>}
     *
     * @throws PlatformRefused
     */
    public function hashes(): array
    {
        $address = $this->hashesAddress();
        $unreadable = new PlatformRefused("Teme couldn’t read the list of released compose files at {$address}, so nothing was changed.");
        $list = json_decode($this->read($address) ?? throw $unreadable, true);
        $current = is_array($list) ? ($list['current'] ?? null) : null;
        $released = is_array($list) ? ($list['released'] ?? null) : null;

        if (! is_string($current) || preg_match(ComposeHash::PATTERN, $current) !== 1 || ! is_array($released) || ! array_is_list($released)) {
            throw $unreadable;
        }

        $released = array_values(array_filter($released, fn (mixed $hash): bool => is_string($hash) && preg_match(ComposeHash::PATTERN, $hash) === 1));

        return ['current' => $current, 'released' => in_array($current, $released, true) ? $released : [...$released, $current]];
    }

    /**
     * Returns the template's current compose file, checked against the hash its list names.
     *
     * @throws PlatformRefused
     */
    public function current(string $hash): string
    {
        $address = $this->composeAddress();
        $text = $this->read($address)
            ?? throw new PlatformRefused("Teme couldn’t read the template’s compose file at {$address}, so nothing was changed.");

        if (ComposeHash::of($text) !== $hash) {
            throw new PlatformRefused("The template served a compose file at {$address} that its list doesn’t name as current, so nothing was changed.");
        }

        return $text;
    }

    /**
     * The address of the template's current compose file, where a person can copy it.
     *
     * @throws PlatformRefused
     */
    public function composeAddress(): string
    {
        return substr($this->hashesAddress(), 0, (int) strrpos($this->hashesAddress(), '/') + 1).self::COMPOSE_PATH;
    }

    /**
     * Returns the list's address, set by the server alone (never in the admin): https, to a public name, never an IP
     * address, a name without a dot, or a name kept for a private network.
     *
     * @throws PlatformRefused
     */
    private function hashesAddress(): string
    {
        $address = config('teme.updater.dokploy.compose_hashes_url');
        $parts = is_string($address) ? parse_url($address) : false;
        $host = is_array($parts) ? strtolower(trim((string) ($parts['host'] ?? ''), '[]')) : '';

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])
            || ! str_ends_with((string) ($parts['path'] ?? ''), '/compose-hashes.json') || ! self::isPublicName($host)) {
            throw new PlatformRefused('TEME_DOKPLOY_COMPOSE_HASHES_URL must be an https:// address of a compose-hashes.json on a public name, so nothing was changed.');
        }

        return (string) $address;
    }

    /**
     * Whether the host is a name on the public internet: not an IP address, it has a dot, and it isn't localhost or
     * under a suffix kept for private networks.
     */
    private static function isPublicName(string $host): bool
    {
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false || ! str_contains($host, '.')) {
            return false;
        }

        foreach (['localhost', '.localhost', '.local', '.internal', '.home.arpa', '.lan', '.test', '.invalid'] as $private) {
            if ($host === ltrim($private, '.') || str_ends_with($host, $private)) {
                return false;
            }
        }

        return preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/D', $host) === 1;
    }

    /**
     * Returns the file's text, or null when it can't be read whole.
     */
    private function read(string $address): ?string
    {
        try {
            $response = Http::withUserAgent('Teme')->withoutRedirecting()->connectTimeout(5)->timeout(15)->get($address);
        } catch (ConnectionException) {
            return null;
        }

        $text = $response->body();

        return $response->status() === 200 && strlen($text) <= self::LIMIT ? $text : null;
    }
}
