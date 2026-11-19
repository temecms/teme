<?php

/**
 * `teme:media:bucket-check`: proves the media buckets' setup before any
 * media moves there (I-3456). It
 * changes no database state, so it acts as no one.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Media\Storage\BucketCheck;
use Illuminate\Console\Command;

final class CheckMediaBucket extends Command
{
    /** @var string */
    protected $signature = 'teme:media:bucket-check
        {--only= : public or originals, to check one bucket}';

    /** @var string */
    protected $description = 'Check that the media buckets can be written, read and served as Teme needs';

    public function handle(BucketCheck $check): int
    {
        $only = (string) $this->option('only');

        if (! in_array($only, ['', 'public', 'originals'], true)) {
            $this->error(__('--only takes public or originals.'));

            return self::FAILURE;
        }

        /** @var list<'public'|'originals'> $roles */
        $roles = $only === '' ? ['public', 'originals'] : [$only];
        $refusals = BucketCheck::refusals($roles);

        foreach ($refusals as $refusal) {
            $this->error($refusal);
        }

        if ($refusals !== []) {
            return self::FAILURE;
        }

        $report = $check->run($roles);

        foreach ($report['lines'] as $line) {
            match ($line['state']) {
                'ok' => $this->line($line['line']),
                'warning' => $this->warn($line['line']),
                'failed' => $this->error($line['line']),
            };
        }

        if (! $report['passed']) {
            $this->error(__('The buckets aren’t ready. Fix what failed above, then run this again.'));

            return self::FAILURE;
        }

        $this->info(__('The buckets are ready for media.'));

        return self::SUCCESS;
    }
}
