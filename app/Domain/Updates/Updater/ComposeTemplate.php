<?php

declare(strict_types=1);

namespace App\Domain\Updates\Updater;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The Dokploy template's published files, read by the updater: compose-hashes.json, which lists the hash of every
 * compose file the template has released and of its current one, and the current compose file beside it. Read
 * from TEME_DOKPLOY_COMPOSE_HASHES_URL, by default the template repository's raw address, over https only.
 */
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
     * @throws PlatformRefused
     */
    private function hashesAddress(): string
    {
        $address = config('teme.updater.dokploy.compose_hashes_url');
        $parts = is_string($address) ? parse_url($address) : false;

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['query'])
            || ! str_ends_with((string) ($parts['path'] ?? ''), '/compose-hashes.json')) {
            throw new PlatformRefused('TEME_DOKPLOY_COMPOSE_HASHES_URL must be an https:// address of a compose-hashes.json, so nothing was changed.');
        }

        return (string) $address;
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
