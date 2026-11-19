<?php

/**
 * A recovery key (I-2401): 32 characters of Crockford's base 32, 160
 * bits, shown as eight groups of four joined by hyphens, which is also the
 * password restic opens the backups with. Teme shows one once and keeps
 * none: this object hides its value when dumped and refuses to be
 * serialized, so it can't reach a session, a queue or a log by accident.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups;

use Illuminate\Validation\ValidationException;
use LogicException;
use SensitiveParameter;

final readonly class RecoveryKey
{
    public const string ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const int GROUPS = 8;

    public const int GROUP_LENGTH = 4;

    private function __construct(#[SensitiveParameter] private string $characters) {}

    public static function generate(): self
    {
        $characters = '';

        for ($i = 0; $i < self::GROUPS * self::GROUP_LENGTH; $i++) {
            $characters .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return new self($characters);
    }

    /**
     * Reads a key as a person typed or pasted it: any case, spaces and
     * hyphens anywhere, and the letters people mistake for digits.
     *
     * @throws ValidationException
     */
    public static function fromTyped(#[SensitiveParameter] string $typed): self
    {
        $characters = self::normalize($typed);

        if (strlen($characters) !== self::GROUPS * self::GROUP_LENGTH) {
            throw ValidationException::withMessages(['recovery_key' => 'A recovery key is eight groups of four letters and digits, separated by hyphens.']);
        }

        return new self($characters);
    }

    /**
     * One group as a person typed it, read as fromTyped() reads a key: an
     * empty string when it can't be a group.
     */
    public static function normalizeGroup(#[SensitiveParameter] string $typed): string
    {
        $characters = self::normalize($typed);

        return strlen($characters) === self::GROUP_LENGTH ? $characters : '';
    }

    /**
     * The key as it is shown and printed, and as restic is given it.
     */
    public function value(): string
    {
        return implode('-', $this->groups());
    }

    /**
     * The eight groups, in order.
     *
     * @return list<string>
     */
    public function groups(): array
    {
        return str_split($this->characters, self::GROUP_LENGTH);
    }

    /**
     * The group at a position from 1 to 8.
     */
    public function group(int $position): string
    {
        if ($position < 1 || $position > self::GROUPS) {
            throw new LogicException("A recovery key has no group {$position}.");
        }

        return $this->groups()[$position - 1];
    }

    /**
     * @return array{value: string}
     */
    public function __debugInfo(): array
    {
        return ['value' => '[hidden]'];
    }

    /**
     * @return array<never>
     */
    public function __serialize(): array
    {
        throw new LogicException('A recovery key is never serialized.');
    }

    /**
     * @param  array<mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A recovery key is never serialized.');
    }

    /**
     * Upper case, without spaces or hyphens, O read as 0 and I and L as 1
     * (Crockford's base 32); an empty string when anything else is left.
     */
    private static function normalize(#[SensitiveParameter] string $typed): string
    {
        $characters = strtr(strtoupper((string) preg_replace('/[\s-]+/u', '', $typed)), ['O' => '0', 'I' => '1', 'L' => '1']);

        return preg_match('/^['.self::ALPHABET.']+$/', $characters) === 1 ? $characters : '';
    }
}
