<?php

/**
 * The Imported content card's list as a validation rule on its `markup`
 * attribute, so DocumentValidator runs it on every save, an autosave's
 * included (I-329).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cards\Imported;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final readonly class MarkupRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // A value that isn't a list is for the `array` and `list` rules to refuse.
        if (! is_array($value) || ! array_is_list($value)) {
            return;
        }

        $problem = MarkupTree::problem($value);

        if ($problem !== null) {
            $fail($problem);
        }
    }
}
