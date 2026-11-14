<?php

declare(strict_types=1);

namespace App\Domain\Languages\Operations;

use App\Domain\Languages\LanguageLock;
use App\Domain\Languages\Operations\Concerns\WritesSiteLanguages;
use App\Domain\Languages\Operations\Input\RemoveSiteLanguageInput;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Removes one of the site's other languages (D10), refused while any item,
 * in the trash too, or any term is in it, naming how many. Its words in
 * settings, once there are any, stay where they are.
 */
final class RemoveSiteLanguage extends BaseOperation
{
    use WritesSiteLanguages;

    protected function authorize(OperationInput $input): void
    {
        /** @var RemoveSiteLanguageInput $input */
        $this->mayManage($input->actorId);
    }

    protected function validate(OperationInput $input): void
    {
        /** @var RemoveSiteLanguageInput $input */
        if (! in_array($input->tag, app(SiteLanguages::class)->others(), true)) {
            throw ValidationException::withMessages(['language' => __(':language isn\'t one of your site\'s languages.', ['language' => self::named($input->tag)])]);
        }
    }

    protected function perform(OperationInput $input): string
    {
        /** @var RemoveSiteLanguageInput $input */
        // Before anything else, so no translation into it commits while it goes (D5).
        LanguageLock::exclusive();
        $this->refuseWhileUsed($input->tag);

        $lists = $this->current();
        $lists['others'] = array_values(array_diff($lists['others'], [$input->tag]));
        unset($lists['prefixes'][$input->tag]);
        $lists['hidden'] = array_values(array_diff($lists['hidden'], [$input->tag]));

        $this->store($lists, $input->expectedVersion, $input->actorId);

        return $input->tag;
    }

    protected function afterCommit(mixed $result): void
    {
        app()->forgetInstance(SiteLanguages::class);
    }

    /**
     * Refuses while items, in the trash too, or terms are in the language, naming how many.
     *
     * @throws ValidationException
     */
    private function refuseWhileUsed(string $tag): void
    {
        $items = DB::table('contents')->where('language', $tag)->count();
        $terms = DB::table('terms')->where('language', $tag)->count();

        if ($items === 0 && $terms === 0) {
            return;
        }

        $itemWords = $items === 1 ? __('1 post or page') : __(':count posts and pages', ['count' => $items]);
        $termWords = $terms === 1 ? __('1 term') : __(':count terms', ['count' => $terms]);
        $language = self::named($tag);

        throw ValidationException::withMessages(['language' => match (true) {
            $items > 0 && $terms > 0 => __(':items and :terms are in :language. Move them to another language, or delete them, first.', ['items' => $itemWords, 'terms' => $termWords, 'language' => $language]),
            $items + $terms === 1 => __(':things is in :language. Move them to another language, or delete them, first.', ['things' => $items === 1 ? $itemWords : $termWords, 'language' => $language]),
            default => __(':things are in :language. Move them to another language, or delete them, first.', ['things' => $items > 0 ? $itemWords : $termWords, 'language' => $language]),
        }]);
    }
}
