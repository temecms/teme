<?php

/**
 * Reads the plugin and theme directory from its configured address: the
 * index and its Ed25519 signature, checked against the keys written into
 * this Teme, and any file the index names, checked against its SHA-256.
 * It reads only; what it finds is kept by RecordDirectoryIndex.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions\Directory;

use App\Domain\Updates\GitHubReleases;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SodiumException;

final readonly class DirectoryReader
{
    /** The address read when none is configured. */
    public const string DEFAULT_ADDRESS = 'https://temecms.github.io/directory/';

    /** The most bytes index.json may hold. */
    public const int MOST_INDEX_BYTES = 8_388_608;

    /** The most bytes any other file may hold. */
    public const int MOST_FILE_BYTES = 4_194_304;

    /** The most bytes the signature file may hold. */
    private const int MOST_SIGNATURE_BYTES = 512;

    /**
     * The directory's address, https, ending in a slash.
     */
    public function address(): string
    {
        $address = config('teme.directory.address');
        $address = is_string($address) && $address !== '' ? $address : self::DEFAULT_ADDRESS;

        return rtrim($address, '/').'/';
    }

    /**
     * The directory address's origin, such as https://temecms.github.io, or
     * an empty string when the address isn't https.
     */
    public static function origin(): string
    {
        $address = config('teme.directory.address');
        $parts = parse_url(is_string($address) && $address !== '' ? $address : self::DEFAULT_ADDRESS);

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host']) || preg_match('/^[A-Za-z0-9.-]+$/', $parts['host']) !== 1) {
            return '';
        }

        return 'https://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * Whether a picture's address is on the directory's own address, the only
     * place the admin shows the directory's pictures from.
     */
    public function isOwnPicture(mixed $url): bool
    {
        return is_string($url) && str_starts_with($url, $this->address()) && preg_match('/[\s"\'<>]/', $url) !== 1;
    }

    /**
     * Returns the public keys an index may be signed with, each 32 raw bytes;
     * a placeholder or a value that isn't a key is left out.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = [];

        foreach ((array) config('teme.directory.public_keys', []) as $key) {
            $raw = is_string($key) ? base64_decode(trim($key), true) : false;

            if (is_string($raw) && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $keys[] = $raw;
            }
        }

        return $keys;
    }

    /**
     * Fetches the index and its signature and returns the index, once the
     * signature is one of the keys', it hasn't run out, and its number isn't
     * below $seen.
     *
     * @return array{index: DirectoryIndex, document: array<mixed>}
     *
     * @throws DirectoryRefused with the reason people read
     */
    public function index(?int $seen = null, ?CarbonImmutable $at = null): array
    {
        $keys = $this->keys();

        if ($keys === []) {
            throw DirectoryRefused::noKey();
        }

        $bytes = $this->get('index.json', self::MOST_INDEX_BYTES);
        $signature = base64_decode(trim($this->get('index.json.sig', self::MOST_SIGNATURE_BYTES)), true);

        if (! is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || ! $this->signedByOneOf($bytes, $signature, $keys)) {
            throw DirectoryRefused::signature();
        }

        $index = DirectoryIndex::read($bytes);

        if ($seen !== null && $index->sequence < $seen) {
            throw DirectoryRefused::older($index->sequence, $seen);
        }

        if ($index->hasExpired($at)) {
            throw DirectoryRefused::expired($index->expires->toDateString());
        }

        /** @var array<mixed> $document */
        $document = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);

        return ['index' => $index, 'document' => $document];
    }

    /**
     * Fetches a file the signed index names and returns its bytes once they
     * match the index's SHA-256 for it.
     *
     * @throws DirectoryRefused with the reason people read
     */
    public function file(string $path, string $sha256): string
    {
        $bytes = $this->get($path, self::MOST_FILE_BYTES);

        if (! hash_equals(strtolower($sha256), hash('sha256', $bytes))) {
            throw DirectoryRefused::tampered($path);
        }

        return $bytes;
    }

    /**
     * @param  list<string>  $keys
     */
    private function signedByOneOf(string $bytes, string $signature, array $keys): bool
    {
        foreach ($keys as $key) {
            try {
                if (sodium_crypto_sign_verify_detached($signature, $bytes, $key)) {
                    return true;
                }
            } catch (SodiumException) {
                continue;
            }
        }

        return false;
    }

    /**
     * @throws DirectoryRefused
     */
    private function get(string $path, int $most): string
    {
        if (self::origin() === '') {
            throw DirectoryRefused::unreachable($path);
        }

        try {
            $response = Http::withUserAgent(GitHubReleases::USER_AGENT)->timeout(10)->get($this->address().$path);
        } catch (ConnectionException) {
            throw DirectoryRefused::unreachable($path);
        }

        if (! $response->successful()) {
            throw DirectoryRefused::unreachable($path);
        }

        $body = $response->body();

        if (strlen($body) > $most) {
            throw DirectoryRefused::tooLarge($path);
        }

        return $body;
    }
}
