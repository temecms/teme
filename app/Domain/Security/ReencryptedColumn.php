<?php

declare(strict_types=1);

namespace App\Domain\Security;

/**
 * What one run did to one registered column: how many values it wrote again,
 * how many were already under this server's key, and the rows no key opened.
 * It holds counts and row keys, never a value or anything from inside one.
 */
final readonly class ReencryptedColumn
{
    /**
     * @param  list<string>  $unopened  the keys of the rows no key opened, in key order
     */
    public function __construct(
        public EncryptedColumn $column,
        public int $rewritten,
        public int $current,
        public array $unopened,
    ) {}

    /**
     * The column's line in a report, in the words people read.
     */
    public function line(): string
    {
        $parts = [];

        if ($this->rewritten > 0) {
            $parts[] = "{$this->rewritten} re-encrypted";
        }

        if ($this->current > 0) {
            $parts[] = "{$this->current} already under this server’s key";
        }

        if ($this->unopened !== []) {
            $count = count($this->unopened);
            $named = array_map(
                fn (string $id): string => "{$this->column->row} {$id}",
                array_slice($this->unopened, 0, 10),
            );
            $more = $count > 10 ? ' and '.($count - 10).' more' : '';

            $parts[] = trans_choice(':count opens with no key given|:count open with no key given', $count)
                .' ('.implode(', ', $named).$more.')';
        }

        return $this->column->label.': '.($parts === [] ? 'none kept' : implode(', ', $parts));
    }
}
