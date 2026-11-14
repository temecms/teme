<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * What it takes to check, later, that a person wrote a shown recovery key
 * down (I-2401): two positions, an HMAC of the groups at them under
 * APP_KEY, and the key's restic ID at each place, so the key can be
 * recorded once they are typed back. It holds no part of the key, and its
 * caller keeps it between requests: Tools › Backups in the session.
 */
final readonly class RecoveryKeyChallenge
{
    /**
     * @param  array{int, int}  $positions
     * @param  array<string, string>  $pending  place ID => the new key's restic ID there
     */
    public function __construct(
        public RecoveryKeyPurpose $purpose,
        public string $label,
        public array $positions,
        public string $digest,
        public array $pending,
    ) {}

    /**
     * @param  array{int, int}  $positions
     * @param  array<string, string>  $pending
     */
    public static function for(RecoveryKeyPurpose $purpose, string $label, RecoveryKey $key, array $positions, array $pending): self
    {
        return new self($purpose, $label, $positions, self::digest($purpose, $label, $positions, $pending, [$key->group($positions[0]), $key->group($positions[1])]), $pending);
    }

    /**
     * Whether the groups typed at the two positions are the key's.
     *
     * @param  array<int, string>  $typed  position => the group as typed
     */
    public function matches(#[SensitiveParameter] array $typed): bool
    {
        $groups = [];

        foreach ($this->positions as $position) {
            $groups[] = RecoveryKey::normalizeGroup((string) ($typed[$position] ?? ''));
        }

        return ! in_array('', $groups, true)
            && hash_equals($this->digest, self::digest($this->purpose, $this->label, $this->positions, $this->pending, $groups));
    }

    /**
     * The challenge as plain values, for a session.
     *
     * @return array{purpose: string, label: string, positions: array{int, int}, digest: string, pending: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'purpose' => $this->purpose->value,
            'label' => $this->label,
            'positions' => $this->positions,
            'digest' => $this->digest,
            'pending' => $this->pending,
        ];
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): self
    {
        $purpose = RecoveryKeyPurpose::tryFrom((string) ($stored['purpose'] ?? ''));
        $label = $stored['label'] ?? null;
        $positions = $stored['positions'] ?? null;
        $digest = $stored['digest'] ?? null;
        $pending = $stored['pending'] ?? null;

        $wellFormed = $purpose !== null
            && is_string($label) && $label !== ''
            && is_array($positions) && array_is_list($positions) && count($positions) === 2
            && is_int($positions[0]) && is_int($positions[1])
            && $positions[0] >= 1 && $positions[0] < $positions[1] && $positions[1] <= RecoveryKey::GROUPS
            && is_string($digest) && preg_match('/^[0-9a-f]{64}$/', $digest) === 1
            && is_array($pending) && $pending !== [];

        foreach (is_array($pending) ? $pending : [] as $place => $resticKeyId) {
            $wellFormed = $wellFormed && is_string($place) && is_string($resticKeyId) && preg_match('/^[0-9a-f]{64}$/', $resticKeyId) === 1;
        }

        if (! $wellFormed) {
            throw new InvalidArgumentException('That isn’t a recovery key challenge.');
        }

        /**
         * @var array{int, int} $positions
         * @var array<string, string> $pending
         */
        return new self($purpose, $label, $positions, $digest, $pending);
    }

    /**
     * The HMAC of everything the challenge says, and the two groups, so no
     * part of it can be changed without the check failing.
     *
     * @param  array{int, int}  $positions
     * @param  array<string, string>  $pending
     * @param  list<string>  $groups
     */
    private static function digest(RecoveryKeyPurpose $purpose, string $label, array $positions, array $pending, #[SensitiveParameter] array $groups): string
    {
        ksort($pending, SORT_STRING);

        return hash_hmac('sha256', implode('|', [
            'teme.recovery-key',
            $purpose->value,
            $label,
            implode(',', $positions),
            json_encode($pending, JSON_THROW_ON_ERROR),
            implode('-', $groups),
        ]), (string) config('app.key'));
    }
}
