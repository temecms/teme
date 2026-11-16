<?php

declare(strict_types=1);

namespace App\Domain\Languages\Operations;

use App\Domain\Languages\Operations\Concerns\WritesSiteLanguages;
use App\Domain\Languages\Operations\Input\ReorderSiteLanguagesInput;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Puts the site's other languages in a new order, the order the language
 * switcher lists them in (docs/proposals/multilingual-screens.md). One
 * settings change, versioned and logged; the default language stays first.
 * Every page that drew a switcher read the languages, so the writer's
 * purge of the settings tag after commit reaches them all.
 */
final class ReorderSiteLanguages extends BaseOperation
{
    use WritesSiteLanguages;

    protected function authorize(OperationInput $input): void
    {
        /** @var ReorderSiteLanguagesInput $input */
        $this->mayManage($input->actorId);
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ReorderSiteLanguagesInput $input */
        $this->refuseWrongSet($input->tags, app(SiteLanguages::class)->others());
    }

    protected function perform(OperationInput $input): null
    {
        /** @var ReorderSiteLanguagesInput $input */
        $lists = $this->current();
        // Again under the row's lock: a language added or removed since validate() changes the set.
        $this->refuseWrongSet($input->tags, $lists['others']);
        $lists['others'] = $input->tags;

        $this->store($lists, $input->expectedVersion, $input->actorId);

        return null;
    }

    protected function afterCommit(mixed $result): void
    {
        app()->forgetInstance(SiteLanguages::class);
    }

    /**
     * Refuses an order that isn't every other language exactly once.
     *
     * @param  list<string>  $tags
     * @param  list<string>  $others
     *
     * @throws ValidationException
     */
    private function refuseWrongSet(array $tags, array $others): void
    {
        $sorted = $tags;
        $expected = $others;
        sort($sorted);
        sort($expected);

        if ($others === [] || $sorted !== $expected) {
            throw ValidationException::withMessages(['order' => __('Put each of the site\'s other languages in the order once.')]);
        }
    }
}
