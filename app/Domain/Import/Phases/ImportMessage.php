<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases;

use App\Domain\Import\ImportMessageLevel;
use InvalidArgumentException;

/**
 * A warning or an error about one object, or a run (I-345): a code of
 * lower-case words joined by dots, the words people read, and a flat
 * context of plain values. Never a password hash, an email address, an IP
 * address or article text.
 */
final readonly class ImportMessage
{
    /**
     * @param  array<int|string, mixed>  $context  plain values only, checked below
     */
    public function __construct(
        public string $code,
        public string $message,
        public array $context = [],
        public ImportMessageLevel $level = ImportMessageLevel::Warning,
    ) {
        if (strlen($code) > 80 || preg_match('/^[a-z][a-z0-9-]*(\.[a-z][a-z0-9-]*)+$/', $code) !== 1) {
            throw new InvalidArgumentException("An import message's code [{$code}] must be lower-case words joined by dots.");
        }

        if (trim($message) === '' || mb_strlen($message) > 1000) {
            throw new InvalidArgumentException('An import message needs words, at most 1,000 characters.');
        }

        $count = 0;

        foreach ($context as $key => $value) {
            $plain = $value === null || is_scalar($value)
                || (is_array($value) && array_is_list($value) && array_filter($value, fn (mixed $part): bool => ! is_scalar($part)) === []);

            if (++$count > 20 || ! is_string($key) || ! $plain) {
                throw new InvalidArgumentException("An import message's context holds at most 20 plain values: [{$key}].");
            }
        }
    }

    /**
     * @param  array<int|string, mixed>  $context  plain values only, checked below
     */
    public static function error(string $code, string $message, array $context = []): self
    {
        return new self($code, $message, $context, ImportMessageLevel::Error);
    }
}
