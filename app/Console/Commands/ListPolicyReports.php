<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Site\Policy\Reports\Queries\PolicyReportList;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * teme:csp:reports — lists what browsers reported the public site's policy
 * blocked, or would block, in the last days (I-3375): the page to read
 * before the policy is enforced.
 */
final class ListPolicyReports extends Command
{
    /** @var string */
    protected $signature = 'teme:csp:reports {--days=7 : How many days back, 1 to 365}';

    /** @var string */
    protected $description = 'List what the public site\'s content security policy blocked, or would block, lately';

    public function handle(PolicyReportList $reports): int
    {
        $days = $this->option('days');

        if (! is_string($days) || preg_match('/^[0-9]+$/', $days) !== 1 || (int) $days < 1 || (int) $days > 365) {
            $this->error(__('--days takes a whole number from 1 to 365.'));

            return self::FAILURE;
        }

        $rows = $reports->recent(CarbonImmutable::now()->subDays((int) $days), 100);

        if ($rows === []) {
            $this->info((int) $days === 1 ? __('Nothing was reported in the last day.') : __('Nothing was reported in the last :days days.', ['days' => (int) $days]));

            return self::SUCCESS;
        }

        $this->table(
            [__('Directive'), __('Blocked'), __('Page'), __('Times'), __('Last seen'), __('Mode')],
            array_map(fn (object $row): array => array_map(self::plain(...), [
                $row->directive,
                $row->blocked,
                $row->page,
                (string) $row->count,
                CarbonImmutable::parse($row->last_seen_at)->format('Y-m-d H:i'),
                $row->disposition === 'enforce' ? __('enforced') : __('report only'),
            ]), $rows),
        );

        return self::SUCCESS;
    }

    /**
     * A cell as plain text: without a control or other non-printing
     * character, and with the console's own tags (<href=…>, <fg=…>) escaped
     * so they print as they are. Whatever the reader promised, a row may hold
     * anything a table does.
     */
    private static function plain(string $cell): string
    {
        return OutputFormatter::escape(preg_replace('/\p{C}/u', '', $cell) ?? '');
    }
}
