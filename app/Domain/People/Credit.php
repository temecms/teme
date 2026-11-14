<?php

declare(strict_types=1);

namespace App\Domain\People;

/**
 * One credit, named as the row of paths that holds its author page names
 * its holder: an account by the account's ID, or a person with no account
 * by the person's ID (I-3366).
 */
final readonly class Credit
{
    /** Someone with an account, credited by a byline or as the item's owner. */
    public const string ACCOUNT = 'user';

    /** A person with no account. */
    public const string PERSON = 'person';

    private function __construct(public string $kind, public string $id) {}

    /**
     * An account, by its ID.
     */
    public static function account(int $userId): self
    {
        return new self(self::ACCOUNT, (string) $userId);
    }

    /**
     * A person with no account, by their ID.
     */
    public static function person(string $personId): self
    {
        return new self(self::PERSON, $personId);
    }

    /**
     * The credit as SetBylines takes it and the console prints it, such as
     * `user:12` or `person:01k…`.
     */
    public function key(): string
    {
        return $this->kind.':'.$this->id;
    }

    /**
     * The credit a key names, or null for anything else.
     */
    public static function fromKey(string $key): ?self
    {
        if (preg_match('/^user:([1-9][0-9]{0,17})\z/', $key, $match) === 1) {
            return self::account((int) $match[1]);
        }

        // A lowercase ULID, as HasUlids writes them (I-418).
        if (preg_match('/^person:([0-9a-hjkmnp-tv-z]{26})\z/', $key, $match) === 1) {
            return self::person($match[1]);
        }

        return null;
    }
}
