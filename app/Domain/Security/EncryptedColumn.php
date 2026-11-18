<?php

/**
 * One column whose every value Teme keeps encrypted under APP_KEY: its
 * table, the unique key its rows are paged and named by, the form inside,
 * and the words people read about it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Security;

final readonly class EncryptedColumn
{
    public function __construct(
        public string $table,
        public string $column,
        public string $key,
        public EncryptedForm $form,
        public string $label,
        public string $row,
    ) {}

    /**
     * The column as `table.column`.
     */
    public function name(): string
    {
        return $this->table.'.'.$this->column;
    }
}
