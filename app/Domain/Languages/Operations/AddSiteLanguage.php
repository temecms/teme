<?php

/**
 * Adds a language to the site (D10): last among the other languages, with
 * its address prefix, shown to readers or hidden while it is translated.
 * Nothing has an address in it yet, so nothing moves.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Languages\Operations;

use App\Domain\Languages\Operations\Concerns\WritesSiteLanguages;
use App\Domain\Languages\Operations\Input\AddSiteLanguageInput;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class AddSiteLanguage extends BaseOperation
{
    use WritesSiteLanguages;

    protected function authorize(OperationInput $input): void
    {
        /** @var AddSiteLanguageInput $input */
        $this->mayManage($input->actorId);
    }

    protected function validate(OperationInput $input): void
    {
        /** @var AddSiteLanguageInput $input */
        $languages = app(SiteLanguages::class);

        if (! array_key_exists($input->tag, (array) config('languages'))) {
            throw ValidationException::withMessages(['language' => __('Choose a language.')]);
        }

        if ($input->tag === $languages->defaultTag()) {
            throw ValidationException::withMessages(['language' => __(':language is your site\'s default language.', ['language' => self::named($input->tag)])]);
        }

        if ($languages->has($input->tag)) {
            throw ValidationException::withMessages(['language' => __(':language is already one of your site\'s languages.', ['language' => self::named($input->tag)])]);
        }

        $this->checkPrefix($input->prefix, null);
    }

    protected function perform(OperationInput $input): string
    {
        /** @var AddSiteLanguageInput $input */
        $lists = $this->current();
        $lists['others'][] = $input->tag;
        $lists['prefixes'][$input->tag] = $input->prefix;

        if (! $input->shown) {
            $lists['hidden'][] = $input->tag;
        }

        $this->store($lists, $input->expectedVersion, $input->actorId);

        return $input->tag;
    }

    protected function afterCommit(mixed $result): void
    {
        app()->forgetInstance(SiteLanguages::class);
    }
}
