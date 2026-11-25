<?php

/**
 * One media field value: an existing media item of an allowed kind, with an
 * optional alt-text override and, where the field allows it, a decorative
 * mark. A trashed item still counts as existing, so a post whose image was
 * trashed can still be saved.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fields\Rules;

use App\Domain\Media\OutsideImage;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

final class MediaReference implements ValidationRule
{
    /**
     * @param  list<string>  $kinds
     */
    public function __construct(private readonly array $kinds, private readonly bool $allowsDecorative = true) {}

    /**
     * An outside picture's address is the web's, its size whole numbers and its alt text short.
     *
     * @param  array<mixed>  $value
     */
    private function outside(array $value, Closure $fail): void
    {
        if (! is_string($value['source']) || ! OutsideImage::isWebAddress($value['source'])) {
            $fail(__('Choose a file from the media library.'));

            return;
        }

        foreach (['width', 'height'] as $key) {
            if (($value[$key] ?? null) !== null && (! is_int($value[$key]) || $value[$key] < 1 || $value[$key] > 100000)) {
                $fail(__('Choose a file from the media library.'));

                return;
            }
        }

        $alt = $value['alt'] ?? null;

        if ($alt !== null && (! is_string($alt) || mb_strlen($alt) > 1000)) {
            $fail(__('Alt text is at most 1,000 characters.'));
        }
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail(__('Choose a file from the media library.'));

            return;
        }

        $id = $value['id'] ?? '';

        // An outside picture: the old site's address, kept until the import copies the file (#716).
        if ($id === '' && array_key_exists('source', $value)) {
            $this->outside($value, $fail);

            return;
        }

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
