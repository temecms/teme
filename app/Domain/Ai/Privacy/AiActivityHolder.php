<?php

/**
 * The record of a person's AI requests: what was asked for and its figures,
 * never a prompt or an answer, which aren't kept. Exported, and kept on
 * erasure, since it is unlinked when the account is deleted.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai\Privacy;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Privacy\ErasePage;
use Teme\Contracts\Privacy\ExportGroup;
use Teme\Contracts\Privacy\ExportPage;
use Teme\Contracts\Privacy\PersonalDataHolder;
use Teme\Contracts\Privacy\PersonalDataSubject;

final class AiActivityHolder implements PersonalDataHolder
{
    private const int EXPORT_PAGE = 200;

    public function key(): string
    {
        return 'ai-activity';
    }

    public function label(): string
    {
        return __('AI activity');
    }

    public function export(PersonalDataSubject $subject, int $page): ExportPage
    {
        if ($subject->accountId === null) {
            return new ExportPage([], true);
        }

        $rows = DB::table('ai_activity')
            ->where('user_id', (int) $subject->accountId)
            ->orderBy('id')
            ->offset(($page - 1) * self::EXPORT_PAGE)
            ->limit(self::EXPORT_PAGE + 1)
            ->get(['task', 'created_at', ...array_keys($this->figures())]);

        $groups = [];

        foreach ($rows->take(self::EXPORT_PAGE) as $row) {
            $values = [
                __('Task') => (string) $row->task,
                __('Date') => CarbonImmutable::parse((string) $row->created_at)->toIso8601String(),
            ];

            foreach ($this->figures() as $column => $label) {
                if ($row->{$column} !== null) {
                    $values[$label] = (string) $row->{$column};
                }
            }

            $groups[] = new ExportGroup(__('AI request'), array_filter($values, fn (string $value): bool => $value !== ''));
        }

        return new ExportPage($groups, $rows->count() <= self::EXPORT_PAGE);
    }

    public function erase(PersonalDataSubject $subject, int $page): ErasePage
    {
        $kept = $subject->accountId === null ? 0 : DB::table('ai_activity')->where('user_id', (int) $subject->accountId)->count();

        return new ErasePage(0, $kept, $kept === 0 ? [] : [__('This is unlinked from the account when the account is deleted.')], true);
    }

    /**
     * Returns the row's figures exported, by column, each with its label: named
     * one by one, so a column added later is never exported unless it is
     * added here.
     *
     * @return array<string, string>
     */
    private function figures(): array
    {
        return [
            'variant' => __('Variant'),
            'provider' => __('Provider'),
            'model' => __('Model'),
            'prompt_key' => __('Prompt'),
            'prompt_version' => __('Prompt version'),
            'prompt_hash' => __('Prompt hash'),
            'subject_type' => __('Used on'),
            'subject_id' => __('Used on ID'),
            'outcome' => __('Outcome'),
            'error_type' => __('Error'),
            'input_tokens' => __('Input tokens'),
            'output_tokens' => __('Output tokens'),
            'cache_read_tokens' => __('Cached tokens read'),
            'cache_write_tokens' => __('Cached tokens written'),
            'cost_microunits' => __('Cost in millionths'),
            'latency_ms' => __('Time taken in milliseconds'),
            'revision_id' => __('Revision'),
        ];
    }
}
