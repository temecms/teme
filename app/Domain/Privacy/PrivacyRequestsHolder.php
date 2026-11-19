<?php

/**
 * The record of a person's erasure requests, found by their address, or by
 * its hash once the address is hidden: exported with what each did, and
 * kept on erasure, since the record is pruned on its own clock (I-3375).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Privacy;

use App\Domain\Privacy\Models\PrivacyRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Privacy\ErasePage;
use Teme\Contracts\Privacy\ExportGroup;
use Teme\Contracts\Privacy\ExportPage;
use Teme\Contracts\Privacy\PersonalDataHolder;
use Teme\Contracts\Privacy\PersonalDataSubject;

final class PrivacyRequestsHolder implements PersonalDataHolder
{
    public function key(): string
    {
        return 'privacy-requests';
    }

    public function label(): string
    {
        return __('Privacy requests');
    }

    public function export(PersonalDataSubject $subject, int $page): ExportPage
    {
        // A record is gone after a year, so a person's few fit on one page.
        $rows = $this->theirs($subject)->orderBy('created_at')->orderBy('id')
            ->get(['status', 'note', 'failure', 'results', 'created_at', 'completed_at']);
        $groups = [];

        foreach ($rows as $row) {
            $values = [
                __('Recorded') => CarbonImmutable::parse((string) $row->created_at)->toIso8601String(),
                __('Ended') => $row->completed_at === null ? '' : CarbonImmutable::parse((string) $row->completed_at)->toIso8601String(),
                __('Status') => match ($row->status) {
                    PrivacyRequest::DONE => __('Done'),
                    PrivacyRequest::FAILED => __('Stopped'),
                    default => __('Not finished'),
                },
                __('Why it stopped') => (string) $row->failure,
                __('Note') => (string) $row->note,
            ];

            foreach (json_decode((string) $row->results, true, flags: JSON_THROW_ON_ERROR) as $entry) {
                $values[(string) $entry['label']] = __(':removed removed, :retained kept.', ['removed' => $entry['removed'], 'retained' => $entry['retained']]);
            }

            $groups[] = new ExportGroup(__('Erasure request'), array_filter($values, fn (string $value): bool => $value !== ''));
        }

        return new ExportPage($groups, true);
    }

    public function erase(PersonalDataSubject $subject, int $page): ErasePage
    {
        $kept = $this->theirs($subject)->count();

        return new ErasePage(0, $kept, $kept === 0 ? [] : [__('The record of each request is kept for a year, its address hidden after 30 days.')], true);
    }

    private function theirs(PersonalDataSubject $subject): Builder
    {
        return DB::table('privacy_requests')
            ->where('kind', PrivacyRequest::ERASE)
            ->where(fn (Builder $query) => $query->where('email', $subject->email)->orWhere('email_hash', PrivacyRequest::hashOf($subject->email)));
    }
}
