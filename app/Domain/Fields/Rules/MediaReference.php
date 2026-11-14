<?php

declare(strict_types=1);

namespace App\Domain\Fields\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * One media field value: an existing media item of an allowed kind, with an
 * optional alt-text override and, where the field allows it, a decorative
 * mark. A trashed item still counts as existing, so a post whose image was
 * trashed can still be saved.
 */
final class MediaReference implements ValidationRule
{
    /**
     * @param  list<string>  $kinds
     */
    public function __construct(private readonly array $kinds, private readonly bool $allowsDecorative = true) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail(__('Choose a file from the media library.'));

            return;
        }

        $id = $value['id'] ?? '';

        if ($id === '') {
            return;
        }

        if (! is_string($id) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id) !== 1) {
            $fail(__('Choose a file from the media library.'));

            return;
        }

        $kind = DB::table('media')->where('id', $id)->value('kind');

        if ($kind === null || ! in_array($kind, $this->kinds, true)) {
            $fail(__('Choose :kinds from the media library.', ['kinds' => implode(' or ', array_map(fn (string $kind): string => __("an {$kind}"), $this->kinds))]));

            return;
        }

        $alt = $value['alt'] ?? null;

        if ($alt !== null && (! is_string($alt) || mb_strlen($alt) > 1000)) {
            $fail(__('Alt text is at most 1,000 characters.'));
        }

        $decorative = filter_var($value['decorative'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($decorative === null) {
            $fail(__('Say whether the image is decorative.'));
        } elseif ($decorative && ! $this->allowsDecorative) {
            $fail(__('This image needs alt text; it cannot be marked decorative.'));
        }
    }
}
