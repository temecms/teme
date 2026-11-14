<?php

declare(strict_types=1);

namespace App\Domain\Fields\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * One canonical choice (I-292): nothing, for the item itself; another item,
 * by its ID; or an address on another site. An address on this site is
 * refused, since it must be stored as its item (rule 9), and an address is
 * checked as the head's link will check it, so a page never fails to draw.
 */
final class CanonicalChoice implements ValidationRule
{
    /** What an outside address may be: absolute, http or https, nothing that could end an attribute. */
    public const string ADDRESS = '~^https?://[^\s"\'<>`]+$~i';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // The field before it was a pair held a plain address, or nothing.
        if (is_string($value)) {
            $value = trim($value) === '' ? [] : ['address' => $value];
        }

        if (! is_array($value) || count($value) > 1 || array_diff(array_keys($value), ['item', 'address']) !== []) {
            $fail(__('Choose this item, another item, or an address on another site.'));

            return;
        }

        if (array_key_exists('item', $value)) {
            $live = is_string($value['item']) && DB::table('contents')->where('id', $value['item'])->whereNull('deleted_at')->exists();

            if (! $live) {
                $fail(__('Choose an item that hasn’t been deleted.'));
            }

            return;
        }

        if (! array_key_exists('address', $value)) {
            return;
        }

        $address = is_string($value['address']) ? trim($value['address']) : '';

        if (strlen($address) > 2048 || preg_match(self::ADDRESS, $address) !== 1 || filter_var($address, FILTER_VALIDATE_URL) === false) {
            $fail(__('Enter the whole address, starting https://.'));

            return;
        }

        $host = strtolower((string) parse_url($address, PHP_URL_HOST));

        if ($host !== '' && $host === strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST))) {
            $fail(__('That address is on this site. Choose its item instead, so the link survives a move.'));
        }
    }
}
