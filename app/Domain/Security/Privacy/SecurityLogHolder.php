<?php

declare(strict_types=1);

namespace App\Domain\Security\Privacy;

use App\Domain\Security\SecurityLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Privacy\ErasePage;
use Teme\Contracts\Privacy\ExportGroup;
use Teme\Contracts\Privacy\ExportPage;
use Teme\Contracts\Privacy\PersonalDataHolder;
use Teme\Contracts\Privacy\PersonalDataSubject;

/**
 * The security log's entries about a person, found by their account or by
 * their address's hashed subject: exported, and kept on erasure, since the
 * log protects the site and prunes itself.
 */
final class SecurityLogHolder implements PersonalDataHolder
{
    private const int EXPORT_PAGE = 500;

    public function key(): string
    {
        return 'security-log';
    }

    public function label(): string
    {
        return __('Security log');
    }

    public function export(PersonalDataSubject $subject, int $page): ExportPage
    {
        $rows = $this->theirs($subject)
            ->orderBy('id')
            ->offset(($page - 1) * self::EXPORT_PAGE)
            ->limit(self::EXPORT_PAGE + 1)
            ->get(['type', 'first_at', 'last_at', 'count', 'ip']);

        $groups = [];

        foreach ($rows->take(self::EXPORT_PAGE) as $row) {
            $groups[] = new ExportGroup(__('Security event'), array_filter([
                __('Event') => (string) $row->type,
                __('First') => CarbonImmutable::parse((string) $row->first_at)->toIso8601String(),
                __('Last') => CarbonImmutable::parse((string) $row->last_at)->toIso8601String(),
                __('Times') => (string) $row->count,
                __('IP address') => (string) $row->ip,
            ], fn (string $value): bool => $value !== ''));
        }

        return new ExportPage($groups, $rows->count() <= self::EXPORT_PAGE);
    }

    public function erase(PersonalDataSubject $subject, int $page): ErasePage
    {
        $kept = $this->theirs($subject)->count();

        return new ErasePage(0, $kept, $kept === 0 ? [] : [__('The security log is kept to protect the site. Its entries are deleted after 365 days.')], true);
    }

    private function theirs(PersonalDataSubject $subject): Builder
    {
        return DB::table('security_events')->where(function (Builder $query) use ($subject): void {
            $query->where('subject', SecurityLog::subject(null, $subject->email));

            if ($subject->accountId !== null) {
                $query->orWhere('user_id', (int) $subject->accountId);
            }
        });
    }
}
