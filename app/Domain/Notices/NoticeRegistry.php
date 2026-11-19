<?php

/**
 * Every kind of notice, declared once (I-3375). Each declaration is checked
 * as it arrives, as PreferenceRegistry checks preferences: the key's shape,
 * no duplicate, no key that is the prefix of another, known channels and
 * defaults among them. Core's own rules beside a kind live here too, never
 * in the contract.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Notices;

use InvalidArgumentException;
use Teme\Contracts\Notices\NoticeKind;
use Teme\Contracts\Notices\NoticeRegistrar;

final class NoticeRegistry implements NoticeRegistrar
{
    private const string KEY = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/';

    /** @var array<string, NoticeKind> */
    private array $kinds = [];

    /** @var array<string, NoticeRules> */
    private array $rules = [];

    public function register(NoticeKind $kind): void
    {
        $key = $kind->key;

        if (preg_match(self::KEY, $key) !== 1) {
            throw new InvalidArgumentException("Notice kind [{$key}] must be lower-case words and underscores, in two parts or more, joined by dots.");
        }

        if (isset($this->kinds[$key])) {
            throw new InvalidArgumentException("Notice kind [{$key}] is already declared.");
        }

        foreach (array_keys($this->kinds) as $existing) {
            if (str_starts_with($existing, $key.'.') || str_starts_with($key, $existing.'.')) {
                throw new InvalidArgumentException("Notice kinds [{$key}] and [{$existing}] cannot both be declared: one is the prefix of the other.");
            }
        }

        if (trim($kind->label) === '') {
            throw new InvalidArgumentException("Notice kind [{$key}] needs a label.");
        }

        if ($kind->audience === '') {
            throw new InvalidArgumentException("Notice kind [{$key}] needs an audience.");
        }

        if ($kind->channels === [] || count(array_unique($kind->channels)) !== count($kind->channels)) {
            throw new InvalidArgumentException("Notice kind [{$key}] has an unknown channel [".implode(', ', $kind->channels).'].');
        }

        foreach ($kind->channels as $channel) {
            if (! in_array($channel, [NoticeKind::MAIL, NoticeKind::INBOX], true)) {
                throw new InvalidArgumentException("Notice kind [{$key}] has an unknown channel [{$channel}].");
            }
        }

        if (array_diff($kind->defaults, $kind->channels) !== []) {
            throw new InvalidArgumentException("Notice kind [{$key}] has a default that isn't one of its channels.");
        }

        if ($kind->required && array_diff($kind->channels, $kind->defaults) !== []) {
            throw new InvalidArgumentException("Notice kind [{$key}] is required, so every channel is on by default.");
        }

        $this->kinds[$key] = $kind;
    }

    /**
     * The kind with this key, or null when nothing declares it.
     */
    public function get(string $key): ?NoticeKind
    {
        return $this->kinds[$key] ?? null;
    }

    /**
     * Every kind, in the order they were registered.
     *
     * @return array<string, NoticeKind>
     */
    public function all(): array
    {
        return $this->kinds;
    }

    /**
     * The kinds a person may choose about, in registration order: every kind
     * that isn't required.
     *
     * @return list<NoticeKind>
     */
    public function listed(): array
    {
        return array_values(array_filter($this->kinds, fn (NoticeKind $kind): bool => ! $kind->required));
    }

    /**
     * Core's own rules beside a declared kind.
     */
    public function rules(string $key, NoticeRules $rules): void
    {
        $this->rules[$key] = $rules;
    }

    /**
     * The rules beside this kind, or an empty set when it has none.
     */
    public function rulesFor(string $key): NoticeRules
    {
        return $this->rules[$key] ?? new NoticeRules;
    }
}
