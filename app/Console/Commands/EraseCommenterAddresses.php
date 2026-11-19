<?php

/**
 * teme:comments:erase-addresses — erases the IP address and browser of every
 * comment that arrived keep_ip_days ago or earlier, a chunk at a time, through
 * EraseCommentAddresses as the operator (I-270). Runs daily.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Comments\Operations\EraseCommentAddresses;
use App\Domain\Comments\Operations\Input\EraseCommentAddressesInput;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;
use Teme\Contracts\Settings\SettingsReader;

final class EraseCommenterAddresses extends Command
{
    /** @var string */
    protected $signature = 'teme:comments:erase-addresses {--chunk=1000 : Comments per chunk}';

    /** @var string */
    protected $description = 'Erase commenters\' IP addresses and browsers kept longer than Settings › Discussion allows';

    public function handle(EraseCommentAddresses $erase, SettingsReader $settings): int
    {
        // The operator, as teme:trash:purge is (operations.md, The operator).
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        $arrivedBefore = now()->subDays($settings->int('discussion', 'keep_ip_days'));
        $chunk = max(1, min(10_000, (int) $this->option('chunk')));
        $erased = 0;

        // A chunk that erased fewer than it could found no more rows to erase.
        do {
            $done = $erase->execute(new EraseCommentAddressesInput($arrivedBefore, $chunk));
            $erased += $done;
        } while ($done === $chunk);

        $this->info(trans_choice('Erased the IP address and browser of :count comment.|Erased the IP address and browser of :count comments.', $erased));

        return self::SUCCESS;
    }
}
