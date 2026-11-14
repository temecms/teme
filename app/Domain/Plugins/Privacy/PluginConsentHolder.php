<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Privacy;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Privacy\ErasePage;
use Teme\Contracts\Privacy\ExportGroup;
use Teme\Contracts\Privacy\ExportPage;
use Teme\Contracts\Privacy\PersonalDataHolder;
use Teme\Contracts\Privacy\PersonalDataSubject;

/**
 * The plugins a person agreed to enable or disabled: exported, and kept on
 * erasure, since the record is unlinked when the account is deleted.
 */
final class PluginConsentHolder implements PersonalDataHolder
{
    public function key(): string
    {
        return 'plugin-consent';
    }

    public function label(): string
    {
        return __('Plugins');
    }

    public function export(PersonalDataSubject $subject, int $page): ExportPage
    {
        if ($subject->accountId === null) {
            return new ExportPage([], true);
        }

        $account = (int) $subject->accountId;
        $groups = [];

        foreach ($this->theirs($account)->orderBy('id')->get(['name', 'consented_by', 'consented_at', 'disabled_by', 'disabled_at']) as $row) {
            if ((int) $row->consented_by === $account) {
                $groups[] = $this->group((string) $row->name, __('Agreed to enable it'), $row->consented_at);
            }

            if ((int) $row->disabled_by === $account) {
                $groups[] = $this->group((string) $row->name, __('Disabled it'), $row->disabled_at);
            }
        }

        return new ExportPage($groups, true);
    }

    public function erase(PersonalDataSubject $subject, int $page): ErasePage
    {
        $kept = $subject->accountId === null ? 0 : $this->theirs((int) $subject->accountId)->count();

        return new ErasePage(0, $kept, $kept === 0 ? [] : [__('This is unlinked from the account when the account is deleted.')], true);
    }

    private function theirs(int $account): Builder
    {
        return DB::table('plugins')->where(fn (Builder $query) => $query->where('consented_by', $account)->orWhere('disabled_by', $account));
    }

    private function group(string $plugin, string $what, mixed $when): ExportGroup
    {
        return new ExportGroup(__('Plugin'), array_filter([
            __('Plugin') => $plugin,
            __('What you did') => $what,
            __('When') => $when === null ? '' : CarbonImmutable::parse((string) $when)->toIso8601String(),
        ], fn (string $value): bool => $value !== ''));
    }
}
