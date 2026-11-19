<?php

/**
 * Takes one step through a request to erase a person's personal data
 * (I-3375): the next holder's next page, erased or kept with its reason in
 * the step's own transaction. What a page changes on public pages is purged
 * after that step's commit, never after a rollback (rule 6); the last step
 * emails the person what was done when the request asks.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Privacy\Operations;

use App\Domain\Privacy\Models\PrivacyRequest;
use App\Domain\Privacy\Notifications\PersonalDataErased;
use App\Domain\Privacy\Operations\Concerns\StepsThroughHolders;
use App\Domain\Privacy\PersonalDataHolders;
use App\Domain\Privacy\PrivacyStep;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\Notification;
use Teme\Contracts\Privacy\PersonalDataHolder;
use Teme\Contracts\Privacy\PersonalDataSubject;

final class RunPrivacyErasure extends BaseOperation
{
    use StepsThroughHolders;

    protected function kind(): string
    {
        return PrivacyRequest::ERASE;
    }

    protected function askPage(PrivacyRequest $request, PersonalDataHolder $holder, PersonalDataSubject $subject, array $entry): array
    {
        $page = (int) $entry['page'] + 1;
        $answer = $holder->erase($subject, $page);
        $reasons = $entry['reasons'];

        foreach ($answer->reasons as $reason) {
            if (! in_array($reason, $reasons, true)) {
                $reasons[] = $reason;
            }
        }

        $entry['page'] = $page;
        $entry['removed'] = (int) $entry['removed'] + $answer->removed;
        $entry['retained'] = (int) $entry['retained'] + $answer->retained;
        $entry['reasons'] = $reasons;
        $entry['done'] = $answer->done;

        return [$entry, $answer->removed > 0 || $answer->retained > 0];
    }

    protected function finish(PrivacyRequest $request): void
    {
        //
    }

    protected function afterCommit(mixed $result): void
    {
        /** @var PrivacyStep $result */
        if (! $result->done) {
            return;
        }

        $request = PrivacyRequest::query()->find($result->requestId);

        if ($request !== null && $request->reply && $request->email !== null) {
            Notification::route('mail', $request->email)->notify(new PersonalDataErased($this->reasonsForEmail($request->results)));
        }
    }

    /**
     * The reasons the email lists, each once: core's own sentences, and in
     * place of a plugin's reasons, one sentence of core's; a missing plugin
     * is named by its label, which the notice makes plain.
     *
     * @param  list<array<string, mixed>>  $results
     *
     * @return list<string>
     */
    private function reasonsForEmail(array $results): array
    {
        $core = app(PersonalDataHolders::class)->coreKeys();
        $reasons = [];

        foreach ($results as $entry) {
            if (($entry['missing'] ?? false) === true) {
                // The label is a plugin's own words; the notice makes it plain (MailText).
                $reasons[] = __('A plugin that held data when the request started was no longer installed: :label.', ['label' => $entry['label']]);
            } elseif (in_array($entry['key'], $core, true)) {
                array_push($reasons, ...$entry['reasons']);
            } elseif ($entry['reasons'] !== []) {
                $reasons[] = __("Some data held by an installed plugin was kept. The site's administrators can tell you why.");
            }
        }

        return array_values(array_unique($reasons));
    }
}
