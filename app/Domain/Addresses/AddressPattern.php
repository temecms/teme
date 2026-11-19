<?php

/**
 * A post address pattern made of fixed text and tokens, such as `/{year}/{month}/{slug}/` (I-393).
 * Parsed once, it renders any post's address and says whether addresses end in a slash.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Addresses;

use InvalidArgumentException;

final readonly class AddressPattern
{
    public const string POST_NAME = '/{slug}/';

    public const string DAY_AND_NAME = '/{year}/{month}/{day}/{slug}/';

    public const string MONTH_AND_NAME = '/{year}/{month}/{slug}/';

    public const string NUMERIC = '/archives/{id}/';

    /** The token for the item's primary category (I-869). */
    public const string CATEGORY = 'category';

    /** The token for the slugs of the item's parents that have an address, the top first (I-3363). */
    public const string PARENT = 'parent';

    /** @var list<string> */
    public const array TOKENS = ['slug', 'year', 'month', 'day', 'id', 'category', 'author', 'parent'];

    /**
     * @param  list<string>  $segments  each segment is fixed text or a token name in braces
     */
    private function __construct(private string $pattern, private array $segments, private bool $slash) {}

    /**
     * The pattern, checked: letters, numbers, hyphens, underscores and
     * slashes around known tokens, with `{slug}` or `{id}` so it names one
     * item. Throws InvalidArgumentException with the reason otherwise.
     */
    public static function parse(string $pattern): self
    {
        $trimmed = trim($pattern);

        if ($trimmed === '' || preg_match('#^/?(?:[a-z0-9_\-]+|\{[a-z]+\})(?:/(?:[a-z0-9_\-]+|\{[a-z]+\}))*/?$#', $trimmed) !== 1) {
            throw new InvalidArgumentException(__('Use letters, numbers, hyphens, slashes and tokens only.'));
        }

        $segments = array_values(array_filter(explode('/', trim($trimmed, '/')), fn (string $segment): bool => $segment !== ''));
        $tokens = [];

        foreach ($segments as $segment) {
            if (preg_match('/^\{([a-z]+)\}$/', $segment, $match) === 1) {
                if (! in_array($match[1], self::TOKENS, true)) {
                    throw new InvalidArgumentException(__('Unknown token {:token}.', ['token' => $match[1]]));
                }

                $tokens[] = $match[1];
            }
        }

        if (! in_array('slug', $tokens, true) && ! in_array('id', $tokens, true)) {
            throw new InvalidArgumentException(__('A post address needs {slug} or {id}.'));
        }

        // The parents' slugs lead to the item, never follow it (I-3363).
        $parent = array_search(self::PARENT, $tokens, true);

        if ($parent !== false && array_filter(array_slice($tokens, 0, $parent), fn (string $token): bool => in_array($token, ['slug', 'id'], true)) !== []) {
            throw new InvalidArgumentException(__('Put {parent} before {slug}.'));
        }

        return new self($trimmed, $segments, str_ends_with($trimmed, '/'));
    }

    /**
     * The address for these token values, with no leading or trailing slash,
     * lower-cased as every address is stored. A token with no value leaves
     * no segment.
     *
     * @param  array<string, string>  $values
     */
    public function render(array $values): string
    {
        $parts = [];

        foreach ($this->segments as $segment) {
            if (preg_match('/^\{([a-z]+)\}$/', $segment, $match) === 1) {
                $value = trim($values[$match[1]] ?? '', '/');

                if ($value !== '') {
                    $parts[] = $value;
                }
            } else {
                $parts[] = $segment;
            }
        }

        return mb_strtolower(implode('/', $parts));
    }

    /**
     * Whether addresses made from this pattern end in a slash.
     */
    public function trailingSlash(): bool
    {
        return $this->slash;
    }

    /**
     * The tokens the pattern uses, in order.
     *
     * @return list<string>
     */
    public function tokens(): array
    {
        $tokens = [];

        foreach ($this->segments as $segment) {
            if (preg_match('/^\{([a-z]+)\}$/', $segment, $match) === 1) {
                $tokens[] = $match[1];
            }
        }

        return $tokens;
    }

    /**
     * The fixed text before the first token, such as `archives` in
     * `/archives/{id}/`, or '' when the pattern starts with a token.
     */
    public function firstFixedSegment(): string
    {
        $first = $this->segments[0] ?? '';

        return str_starts_with($first, '{') ? '' : $first;
    }

    /**
     * The pattern as it was written.
     */
    public function toString(): string
    {
        return $this->pattern;
    }
}
