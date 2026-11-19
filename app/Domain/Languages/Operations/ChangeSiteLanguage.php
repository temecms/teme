<?php

/**
 * Changes one of the site's other languages (D10): its address prefix, whose
 * every address then moves in the background with the old ones kept as
 * history, and whether readers are shown it, which moves nothing.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Languages\Operations;

use App\Domain\Addresses\Jobs\RecomputeAddresses;
use App\Domain\Languages\Operations\Concerns\WritesSiteLanguages;
use App\Domain\Languages\Operations\Input\ChangeSiteLanguageInput;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\JobActor;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class ChangeSiteLanguage extends BaseOperation
{
    use WritesSiteLanguages;

    protected function authorize(OperationInput $input): void
    {
        /** @var ChangeSiteLanguageInput $input */
        $this->mayManage($input->actorId);
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ChangeSiteLanguageInput $input */
        if (! in_array($input->tag, app(SiteLanguages::class)->others(), true)) {
            throw ValidationException::withMessages(['language' => __(':language isn\'t one of your site\'s languages.', ['language' => self::named($input->tag)])]);
        }

        $this->checkPrefix($input->prefix, $input->tag);
    }

    /**
     * @return array{language: string, moved: bool, actorId: string|null}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var ChangeSiteLanguageInput $input */
        $lists = $this->current();
        $moved = ($lists['prefixes'][$input->tag] ?? mb_strtolower($input->tag)) !== $input->prefix;
        $lists['prefixes'][$input->tag] = $input->prefix;
        $lists['hidden'] = array_values(array_diff($lists['hidden'], [$input->tag]));

        if (! $input->shown) {
            $lists['hidden'][] = $input->tag;
        }

        $this->store($lists, $input->expectedVersion, $input->actorId);

        return ['language' => $input->tag, 'moved' => $moved, 'actorId' => $input->actorId];
    }

    /**
     * After commit: forget this request's languages, then move the
     * language's addresses, which must read the new prefix.
     */
    protected function afterCommit(mixed $result): void
    {
        /** @var array{language: string, moved: bool, actorId: string|null} $result */
        app()->forgetInstance(SiteLanguages::class);

        if ($result['moved']) {
            RecomputeAddresses::dispatch(JobActor::of($result['actorId']), language: $result['language']);
        }
    }
}
