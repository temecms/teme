<?php

/**
 * One step through a privacy request: the next page of the first holder not
 * yet done, asked and recorded in the step's own transaction, so a page is
 * written and recorded together or not at all; with none left, the request
 * is finished. A holder that throws, or a plugin's listener that fails,
 * rolls the step back.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Privacy\Operations\Concerns;

use App\Domain\Privacy\Models\PrivacyRequest;
use App\Domain\Privacy\Operations\Input\RunPrivacyErasureInput;
use App\Domain\Privacy\Operations\Input\RunPrivacyExportInput;
use App\Domain\Privacy\Operations\RecordPrivacyRequest;
use App\Domain\Privacy\PersonalDataHolders;
use App\Domain\Privacy\PrivacyStep;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Privacy\PersonalDataHolder;
use Teme\Contracts\Privacy\PersonalDataSubject;

trait StepsThroughHolders
{
    use AuthorizesPrivacyWork;

    /** How many empty pages that aren't done a holder may answer in a row. */
    private const int MOST_IDLE = 3;

    /**
     * The most pages one holder is asked for in one request: far more than
     * any holder's real pages (comments come 100 to an export page and 200
     * to an erase page), so only a holder that never ends reaches it.
     */
    public const int MOST_PAGES = 10_000;

    /**
     * Returns 'export' or 'erase': the kind of request this Operation steps.
     */
    abstract protected function kind(): string;

    /**
     * Asks the holder for the entry's next page and returns the entry as it
     * stands after it, and whether the page held anything.
     *
     * @param  array<string, mixed>  $entry
     *
     * @return array{0: array<string, mixed>, 1: bool}
     */
    abstract protected function askPage(PrivacyRequest $request, PersonalDataHolder $holder, PersonalDataSubject $subject, array $entry): array;

    /**
     * The request's last work once every holder is done, before it is
     * marked done.
     */
    abstract protected function finish(PrivacyRequest $request): void;

    protected function validate(OperationInput $input): void
    {
        /** @var RunPrivacyExportInput|RunPrivacyErasureInput $input */
        Validator::make(
            ['request' => $input->requestId],
            ['request' => ['required', 'string', Rule::exists('privacy_requests', 'id')->where('kind', $this->kind())]],
        )->validate();
    }

    protected function perform(OperationInput $input): PrivacyStep
    {
        /** @var RunPrivacyExportInput|RunPrivacyErasureInput $input */
        $request = PrivacyRequest::query()->lockForUpdate()->findOrFail($input->requestId);

        if (in_array($request->status, [PrivacyRequest::DONE, PrivacyRequest::FAILED], true)) {
            throw ValidationException::withMessages(['request' => __('This request has already ended.')]);
        }

        if ($request->email === null) {
            throw ValidationException::withMessages(['request' => __('This request is too old to run. Record a new one.')]);
        }

        $request->status = PrivacyRequest::RUNNING;
        $holders = app(PersonalDataHolders::class)->byKey();
        $results = $request->results;
        $known = array_column($results, 'key');

        // A holder offered since the request was recorded, a plugin enabled
        // since, is asked in its turn rather than skipped.
        foreach ($holders as $key => $holder) {
            if (! in_array($key, $known, true)) {
                $results[] = RecordPrivacyRequest::entry($this->kind(), $key, $holder->label());
            }
        }

        $index = null;

        foreach ($results as $place => $entry) {
            if ($entry['done'] !== true) {
                $index = $place;

                break;
            }
        }

        if ($index === null) {
            $request->results = $results;
            $this->finish($request);
            $request->status = PrivacyRequest::DONE;
            $request->completed_at = now()->toImmutable();
            $request->save();

            return new PrivacyStep(true, null, PrivacyRequest::DONE, $request->id);
        }

        $entry = $results[$index];
        $holder = $holders[$entry['key']] ?? null;

        if ($holder === null) {
            $entry = $this->missing($entry);
        } else {
            if ((int) $entry['page'] >= self::MOST_PAGES) {
                throw new RuntimeException(__('The holder :label was asked for :count pages and never said it was done.', ['label' => $entry['label'], 'count' => number_format(self::MOST_PAGES)]));
            }

            $subject = new PersonalDataSubject($request->email, $request->user_id === null ? null : (string) $request->user_id);
            [$entry, $held] = $this->askPage($request, $holder, $subject, $entry);
            $entry = $this->watchIdle($entry, $held);
        }

        $results[$index] = $entry;
        $request->results = $results;
        $request->save();

        return new PrivacyStep(false, (string) $entry['key'], null, $request->id);
    }

    /**
     * A holder that held data when the request started but is no longer
     * offered: a plugin disabled since. It is marked done and said.
     *
     * @param  array<string, mixed>  $entry
     *
     * @return array<string, mixed>
     */
    private function missing(array $entry): array
    {
        $entry['done'] = true;
        $entry['missing'] = true;

        if ($this->kind() === PrivacyRequest::ERASE) {
            $entry['reasons'] = [...$entry['reasons'], __('A plugin that held data when the request started was no longer installed: :label.', ['label' => $entry['label']])];
        }

        return $entry;
    }

    /**
     * Counts the empty pages a holder answers without saying it is done, so
     * one that never ends can't loop the request for ever.
     *
     * @param  array<string, mixed>  $entry
     *
     * @return array<string, mixed>
     */
    private function watchIdle(array $entry, bool $held): array
    {
        if ($held || $entry['done'] === true) {
            if (isset($entry['idle'])) {
                $entry['idle'] = 0;
            }

            return $entry;
        }

        $entry['idle'] = (int) ($entry['idle'] ?? 0) + 1;

        if ($entry['idle'] >= self::MOST_IDLE) {
            throw new RuntimeException(__('The holder :label answered three pages with nothing in them and never said it was done.', ['label' => $entry['label']]));
        }

        return $entry;
    }
}
