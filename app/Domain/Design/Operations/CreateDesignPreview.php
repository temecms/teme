<?php

/**
 * A link that opens one of the site's pages with Settings › Design's drafts
 * shown: signed and expiring within the hour, and granting nothing else.
 * Only a site-relative address is taken, so it can't lead anywhere else.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Design\Operations;

use App\Domain\Design\Operations\Concerns\ManagesDesign;
use App\Domain\Design\Operations\Input\CreateDesignPreviewInput;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class CreateDesignPreview extends BaseOperation
{
    use ManagesDesign;

    /** The query parameter that asks for the drafts, which DesignPreviewLink checks. */
    public const string PARAMETER = 'teme_design_preview';

    private const int MINUTES = 60;

    /** One leading slash, not two, and no scheme, backslash, space, query or fragment. */
    private const string PATH = '~^/(?!/)[^?#\\\\\s]*$~u';

    protected function authorize(OperationInput $input): void
    {
        /** @var CreateDesignPreviewInput $input */
        $this->authorizeDesign($input);
    }

    protected function validate(OperationInput $input): void
    {
        /** @var CreateDesignPreviewInput $input */
        if (preg_match(self::PATH, $input->path) !== 1) {
            throw ValidationException::withMessages(['path' => __('A preview opens a page of this site: give its address from the site\'s root, such as /news/.')]);
        }
    }

    /**
     * @return string the absolute, signed link
     */
    protected function perform(OperationInput $input): string
    {
        /** @var CreateDesignPreviewInput $input */
        // As a browser sends it, so the path the signature is checked
        // against matches: a Unicode slug's letters percent-encoded.
        $path = (string) preg_replace_callback('/[^\x21-\x7E]/u', fn (array $match): string => rawurlencode($match[0]), $input->path);

        // Built as Addresses::urlFor() builds one: url($path) would trim the
        // trailing slash, and the page would answer with a 301.
        $address = rtrim(url('/'), '/').$path;

        // Signed as UrlGenerator::signedRoute() signs, keys sorted: a public
        // address has no route name. hasValidSignature() checks the address
        // with its trailing slash trimmed, so only what's signed loses it.
        $query = http_build_query(['expires' => now()->addMinutes(self::MINUTES)->getTimestamp(), self::PARAMETER => 1]);
        $signature = hash_hmac('sha256', rtrim($address, '/').'?'.$query, (string) config('app.key'));

        return $address.'?'.$query.'&signature='.$signature;
    }
}
