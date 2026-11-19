<?php

/**
 * The application keys a value may have been encrypted under before this
 * server's (I-359): a restored backup's key and its previous keys, or the
 * key an operator is moving away from. Held in memory only: it hides its
 * keys when dumped and refuses to be serialized, so none reaches a log, a
 * session or a queue, and no refusal repeats what it was given.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Security;

use Illuminate\Encryption\Encrypter;
use Illuminate\Validation\ValidationException;
use LogicException;
use SensitiveParameter;

final readonly class OldKeys
{
    public const string NOT_A_KEY = 'That isn’t an application key. Give it as the old server’s environment had it: base64: and the characters after it.';

    public const string NO_KEY = 'No application key was given.';

    public const string NOT_APP_KEY_JSON = 'That isn’t a backup’s app-key.json: it needs a "key", and "previous_keys" as a list.';

    /**
     * @param  list<string>  $keys  the keys' raw bytes, without duplicates
     */
    private function __construct(#[SensitiveParameter] private array $keys) {}

    /**
     * No key: only this server's APP_PREVIOUS_KEYS are tried.
     */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Keys as an operator gives them: one to a line, each as an environment
     * holds it; a .env file's APP_KEY=… and APP_PREVIOUS_KEYS=… lines (with
     * their commas) count too, and its other lines are passed over.
     *
     * @throws ValidationException
     */
    public static function fromText(#[SensitiveParameter] string $text): self
    {
        $keys = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^(APP_KEY|APP_PREVIOUS_KEYS)\s*=\s*(.*)$/', $line, $match) === 1) {
                $value = trim($match[2], " \t\"'");
                $typed = $match[1] === 'APP_PREVIOUS_KEYS' ? explode(',', $value) : [$value];
            } elseif (preg_match('/^[A-Z][A-Z0-9_]*\s*=/', $line) === 1) {
                // Another variable of a .env file: the old server's whole
                // file may be given.
                continue;
            } else {
                $typed = [$line];
            }

            foreach ($typed as $one) {
                if (trim($one) !== '') {
                    $keys[] = self::parse($one);
                }
            }
        }

        if ($keys === []) {
            throw ValidationException::withMessages(['old_key' => self::NO_KEY]);
        }

        return new self(array_values(array_unique($keys)));
    }

    /**
     * A backup's app-key.json, as BackupSite writes it:
     * {"key": "base64:…", "previous_keys": ["base64:…"]}.
     *
     * @throws ValidationException
     */
    public static function fromAppKeyJson(#[SensitiveParameter] string $json): self
    {
        $document = json_decode($json, true);
        $previous = is_array($document) ? ($document['previous_keys'] ?? []) : null;

        if (! is_array($document) || ! is_string($document['key'] ?? null) || ! is_array($previous) || ! array_is_list($previous)) {
            throw ValidationException::withMessages(['old_key' => self::NOT_APP_KEY_JSON]);
        }

        $keys = [self::parse($document['key'])];

        foreach ($previous as $typed) {
            if (! is_string($typed)) {
                throw ValidationException::withMessages(['old_key' => self::NOT_APP_KEY_JSON]);
            }

            $keys[] = self::parse($typed);
        }

        return new self(array_values(array_unique($keys)));
    }

    /**
     * A key file's contents: app-key.json when it is a JSON object, keys on
     * lines otherwise.
     *
     * @throws ValidationException
     */
    public static function fromFileContents(#[SensitiveParameter] string $contents): self
    {
        return str_starts_with(ltrim($contents), '{') ? self::fromAppKeyJson($contents) : self::fromText($contents);
    }

    /**
     * Why a key can't be used with this cipher, or null when every key can.
     */
    public function problem(string $cipher): ?string
    {
        foreach ($this->keys as $key) {
            if (! Encrypter::supported($key, $cipher)) {
                return "That key doesn’t fit this server’s cipher, {$cipher}. Give the APP_KEY exactly as the old server had it.";
            }
        }

        return null;
    }

    /**
     * An encrypter that opens with these keys and the server's previous
     * keys, never with the server's own key; null when no other key is left.
     *
     * @param  list<string>  $serverPreviousKeys  raw bytes, as Encrypter::getPreviousKeys() gives them
     */
    public function encrypter(string $cipher, #[SensitiveParameter] string $serverKey, #[SensitiveParameter] array $serverPreviousKeys): ?Encrypter
    {
        $keys = array_values(array_filter(
            array_unique([...$this->keys, ...$serverPreviousKeys]),
            fn (string $key): bool => ! hash_equals($serverKey, $key),
        ));

        if ($keys === []) {
            return null;
        }

        return (new Encrypter($keys[0], $cipher))->previousKeys(array_slice($keys, 1));
    }

    /**
     * How many keys were given.
     */
    public function count(): int
    {
        return count($this->keys);
    }

    /**
     * @return array{keys: string}
     */
    public function __debugInfo(): array
    {
        return ['keys' => count($this->keys).' hidden'];
    }

    /**
     * @return array<never>
     */
    public function __serialize(): array
    {
        throw new LogicException('Old keys are never serialized.');
    }

    /**
     * @param  array<mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Old keys are never serialized.');
    }

    /**
     * A key's raw bytes, read as Laravel reads APP_KEY; 44 characters of
     * base64 that decode to a key's length count too, since keys are often
     * copied without their prefix.
     *
     * @throws ValidationException
     */
    private static function parse(#[SensitiveParameter] string $typed): string
    {
        $typed = trim($typed);

        if (str_starts_with($typed, 'base64:')) {
            $key = base64_decode(substr($typed, 7), true);

            if ($key === false || $key === '') {
                throw ValidationException::withMessages(['old_key' => self::NOT_A_KEY]);
            }

            return $key;
        }

        $decoded = base64_decode($typed, true);

        if (strlen($typed) !== 32 && is_string($decoded) && in_array(strlen($decoded), [16, 32], true)) {
            return $decoded;
        }

        if ($typed === '') {
            throw ValidationException::withMessages(['old_key' => self::NOT_A_KEY]);
        }

        return $typed;
    }
}
