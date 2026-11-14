<?php

declare(strict_types=1);

namespace App\Domain\Cards;

/**
 * One attribute a card stores: its name, the rules a submitted value must
 * pass, and the value it takes when the editor sends none.
 */
final readonly class CardAttribute
{
    /**
     * @param  list<mixed>  $rules  Laravel validation rules
     */
    public function __construct(
        public string $name,
        public array $rules,
        public mixed $default = null,
    ) {}
}
