<?php

/**
 * Keeps the security-log lines a plugin writes in a test, so the test can
 * read them back, without writing to the site's security log.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Testing;

use InvalidArgumentException;
use Teme\Contracts\Security\SecurityLines;

/**
 * Checks the kind, the text and the address the way the real door does, and
 * keeps each accepted line in memory. Gives up after the hourly limit when
 * one is set, as the real door does.
 *
 * @since next
 */
final class FakeSecurityLines implements SecurityLines
{
    /** @var list<array{kind: string, text: string, userId: int|string|null, address: ?string}> */
    private array $lines = [];

    /**
     * @param  list<string>  $kinds  the kinds the fake plugin declares
     * @param  int|null  $mostPerHour  the lines it may write an hour, or no limit
     *
     * @since next
     */
    public function __construct(private readonly array $kinds, private readonly ?int $mostPerHour = null) {}

    /**
     * @since next
     */
    public function write(string $kind, string $text, int|string|null $userId = null, ?string $address = null): bool
    {
        if (! in_array($kind, $this->kinds, true)) {
            throw new InvalidArgumentException("The kind {$kind} is not declared.");
        }

        $trimmed = trim($text);

        if ($trimmed === '' || mb_strlen($trimmed) > 200) {
            throw new InvalidArgumentException('A line’s text is 1 to 200 characters.');
        }

        if ($address !== null && filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('A line’s address must be an IP address.');
        }

        if ($this->mostPerHour !== null && count($this->lines) >= $this->mostPerHour) {
            return false;
        }

        $this->lines[] = ['kind' => $kind, 'text' => $trimmed, 'userId' => $userId, 'address' => $address];

        return true;
    }

    /**
     * The lines accepted so far, oldest first.
     *
     * @return list<array{kind: string, text: string, userId: int|string|null, address: ?string}>
     *
     * @since next
     */
    public function lines(): array
    {
        return $this->lines;
    }
}
