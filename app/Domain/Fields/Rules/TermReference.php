<?php

/**
 * One term field value: the ID of a term this site has, in a hierarchical
 * taxonomy, as a primary category is (I-295).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fields\Rules;

use App\Domain\Types\TaxonomyRegistry;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

final class TermReference implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $taxonomy = is_string($value) ? DB::table('terms')->where('id', $value)->value('taxonomy') : null;
        $taxonomies = app(TaxonomyRegistry::class);

        if (! is_string($taxonomy) || ! $taxonomies->has($taxonomy) || ! $taxonomies->get($taxonomy)->hierarchical) {
            $fail(__('Choose a category this site has.'));
        }
    }
}
