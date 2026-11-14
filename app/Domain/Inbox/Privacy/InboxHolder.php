<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Privacy;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Privacy\ErasePage;
use Teme\Contracts\Privacy\ExportGroup;
use Teme\Contracts\Privacy\ExportPage;
use Teme\Contracts\Privacy\PersonalDataHolder;
use Teme\Contracts\Privacy\PersonalDataSubject;

/**
 * A person's inbox: exported, and kept on erasure, since it goes with the
 * account when the account is deleted.
 */
final class InboxHolder implements PersonalDataHolder
{
    private const int EXPORT_PAGE = 200;

    public function key(): string
    {
        return 'inbox';
    }

    public function label(): string
    {
        return __('Inbox');
    }

    public function export(PersonalDataSubject $subject, int $page): ExportPage
    {
        if ($subject->accountId === null) {
            return new ExportPage([], true);
        }

        $rows = DB::table('inbox_items')
            ->where('user_id', (int) $subject->accountId)
            ->orderBy('id')
            ->offset(($page - 1) * self::EXPORT_PAGE)
            ->limit(self::EXPORT_PAGE + 1)
            ->get(['body', 'read_at', 'created_at']);

        $groups = [];

        foreach ($rows->take(self::EXPORT_PAGE) as $row) {
            $body = json_decode((string) $row->body, true, flags: JSON_THROW_ON_ERROR);

            $groups[] = new ExportGroup(__('Inbox item'), array_filter([
                __('Title') => (string) (is_array($body) ? ($body['title'] ?? '') : ''),
                __('Text') => (string) (is_array($body) ? ($body['text'] ?? '') : ''),
                __('Received') => CarbonImmutable::parse((string) $row->created_at)->toIso8601String(),
                __('Read') => $row->read_at === null ? __('No') : __('Yes'),
            ], fn (string $value): bool => $value !== ''));
        }

        return new ExportPage($groups, $rows->count() <= self::EXPORT_PAGE);
    }

    public function erase(PersonalDataSubject $subject, int $page): ErasePage
    {
        $kept = $subject->accountId === null ? 0 : DB::table('inbox_items')->where('user_id', (int) $subject->accountId)->count();

        return new ErasePage(0, $kept, $kept === 0 ? [] : [__('This goes when the account is deleted.')], true);
    }
}
